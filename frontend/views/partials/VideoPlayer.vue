<template>
  <div>
    <div class="modal-card">
      <div class="modal-card-body preview">
        <strong>{{ currentItem.name }}</strong>
        <div class="columns is-mobile">
          <div class="column mainbox">
            <video controls>
              <source :src="videoSrc(currentItem.path)" type="video/mp4">
              Your browser does not support the video tag.
            </video>
          </div>
          <div v-if="videos.length > 1" class="column is-one-fifth sidebox">
            <ul>
              <li v-for="(video, index) in videos" :key="index">
                <a href="#" @click="currentItem = video">{{ video.name }}</a>
              </li>
            </ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
<script>
import _ from 'lodash'

export default {
  name: 'VideoPlayer',
  props: [ 'item' ],
  data() {
    return {
      currentItem: '',
    }
  },
  computed: {
    videos() {
      return _.filter(this.$store.state.cwd.content, o => this.isVideo(o.name))
    },
  },
  mounted() {
    this.currentItem = this.item
  },
  methods: {
    videoSrc(path) {
      return this.getDownloadLink(path)
    },
  },
}
</script>
<style scoped>
@media (min-width: 1100px) {
  .modal-card {
    width: 100%;
    height: 100;
    min-width: 640px;
  }
}

.mainbox {
  height: 70vh;
  display:flex;
  justify-content:center;
  align-items:center;
}

.sidebox {
  overflow-y:auto;
  height: 70vh;
}

.sidebox {
  border-left: 1px solid #dbdbdb;
}

.sidebox a {
  padding: 5px 0 5px 0;
  display: block;
}

</style>