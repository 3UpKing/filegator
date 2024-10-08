<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Kernel\StreamedResponse;
use Filegator\Services\Archiver\ArchiverInterface;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Session\SessionStorageInterface as Session;
use Filegator\Services\Storage\Filesystem;
use Filegator\Services\Tmpfs\TmpfsInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Mime\MimeTypes;

class DownloadController
{
    protected $auth;

    protected $session;

    protected $config;

    protected $storage;

    public function __construct(Config $config, Session $session, AuthInterface $auth, Filesystem $storage)
    {
        $this->session = $session;
        $this->config = $config;
        $this->auth = $auth;

        $user = $this->auth->user() ?: $this->auth->getGuest();

        $this->storage = $storage;
        $this->storage->setPathPrefix($user->getHomeDir());
    }

    public function download(Request $request, Response $response, StreamedResponse $streamedResponse)
    {
        try {
            $file = $this->storage->readStream((string) base64_decode($request->input('path')));
        } catch (\Exception $e) {
            return $response->redirect('/');
        }

        // Check if the request includes the "Range" header
        $rangeHeader = $request->headers->get('Range');

        if ($rangeHeader) {
            // Parse the "Range" header to get the start and end bytes

            $rangeParts = explode('=', $rangeHeader, 2);
            if (count($rangeParts) === 2 && $rangeParts[0] === 'bytes') {
                $rangeSpecs = explode(',', $rangeParts[1]);

                // Initialize variables for start and end bytes
                $start = null;
                $end = null;

                foreach ($rangeSpecs as $rangeSpec) {
                    $range = explode('-', $rangeSpec);

                    // Handle the case where a single byte is requested, e.g., "bytes=0-0"
                    if (count($range) === 1) {
                        $start = (int)$range[0];
                        $end = $start;
                    }

                    // Handle the case where the end byte is omitted, e.g., "bytes=1000-"
                    elseif ($range[0] !== '') {
                        $start = (int)$range[0];
                        $end = $file['filesize'] - 1;
                    }

                    // Handle the case where both start and end bytes are specified, e.g., "bytes=0-499"
                    else {
                        $start = (int)$range[0];
                        $end = (int)$range[1];
                    }
                }

                // Ensure the start and end values are within valid bounds
                $start = max(0, $start);
                $end = min($end, $file['filesize'] - 1);

                // Calculate the length of the content to be streamed
                $length = $end - $start + 1;

                // Set the appropriate HTTP status code for partial content
                $streamedResponse->setStatusCode(206);

                // Set the "Content-Range" header to specify the range being sent
                $streamedResponse->headers->set('Content-Range', "bytes $start-$end/{$file['filesize']}");

                // Move the file pointer to the start of the requested range
                fseek($file['stream'], $start);
            }
        } else {
            // No "Range" header, treat it as a full download
            $length = $file['filesize'];
        }

        $streamedResponse->setCallback(function () use ($file, $length) {
            set_time_limit(0);
            while (!feof($file['stream']) && $length > 0) {
                // Read and echo data in chunks
                $chunkSize = min(1024 * 8, $length); // Chunk size of 8KB or remaining length
                echo fread($file['stream'], $chunkSize);
                $length -= $chunkSize;
                flush();
            }
            fclose($file['stream']);
        });

        $extension = pathinfo($file['filename'], PATHINFO_EXTENSION);
        $mimes = (new MimeTypes())->getMimeTypes($extension);
        $contentType = !empty($mimes) ? $mimes[0] : 'application/octet-stream';

        $disposition = HeaderUtils::DISPOSITION_ATTACHMENT;

        $download_inline = (array)$this->config->get('download_inline', ['pdf']);
        if (in_array($extension, $download_inline) || in_array('*', $download_inline)) {
            $disposition = HeaderUtils::DISPOSITION_INLINE;
        }

        $contentDisposition = HeaderUtils::makeDisposition($disposition, $file['filename'], 'file');

        $streamedResponse->headers->set('Accept-Ranges', 'bytes');

        $streamedResponse->headers->set(
            'Content-Disposition',
            $contentDisposition
        );
        $streamedResponse->headers->set(
            'Content-Type',
            $contentType
        );
        $streamedResponse->headers->set(
            'Content-Transfer-Encoding',
            'binary'
        );

        $streamedResponse->headers->set('Content-Length', $length);

        // @codeCoverageIgnoreStart
        if (APP_ENV == 'development') {
            $streamedResponse->headers->set(
                'Access-Control-Allow-Origin',
                $request->headers->get('Origin')
            );
            $streamedResponse->headers->set(
                'Access-Control-Allow-Credentials',
                'true'
            );
        }
        // @codeCoverageIgnoreEnd

        $this->session->save();

        $streamedResponse->send();
    }

    public function batchDownloadCreate(Request $request, Response $response, ArchiverInterface $archiver)
    {
        $items = $request->input('items', []);

        $uniqid = $archiver->createArchive($this->storage);

        // close session
        $this->session->save();

        foreach ($items as $item) {
            if ($item->type == 'dir') {
                $archiver->addDirectoryFromStorage($item->path);
            }
            if ($item->type == 'file') {
                $archiver->addFileFromStorage($item->path);
            }
        }

        $archiver->closeArchive();

        return $response->json(['uniqid' => $uniqid]);
    }

    public function batchDownloadStart(Request $request, StreamedResponse $streamedResponse, TmpfsInterface $tmpfs)
    {
        $uniqid = (string) preg_replace('/[^0-9a-zA-Z_]/', '', (string) $request->input('uniqid'));
        $file = $tmpfs->readStream($uniqid);

        $streamedResponse->setCallback(function () use ($file, $tmpfs, $uniqid) {
            // @codeCoverageIgnoreStart
            set_time_limit(0);
            if ($file['stream']) {
                while (! feof($file['stream'])) {
                    echo fread($file['stream'], 1024 * 8);
                    if (ob_get_level() > 0) {ob_flush();}
                    flush();
                }
                fclose($file['stream']);
            }
            $tmpfs->remove($uniqid);
            // @codeCoverageIgnoreEnd
        });

        $streamedResponse->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->config->get('frontend_config.default_archive_name'),
                'archive.zip'
            )
        );
        $streamedResponse->headers->set(
            'Content-Type',
            'application/octet-stream'
        );
        $streamedResponse->headers->set(
            'Content-Transfer-Encoding',
            'binary'
        );
        if (isset($file['filesize'])) {
            $streamedResponse->headers->set(
                'Content-Length',
                $file['filesize']
            );
        }
        // close session so we can continue streaming, note: dev is single-threaded
        $this->session->save();

        $streamedResponse->send();
    }
}
