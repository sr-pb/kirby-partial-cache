<template>
  <k-field :label="label">
    <k-button
        :icon="button.icon"
        variant="filled"
        :theme="button.theme"
        @click="onClick"
        :text="this.button.text"
        :class="['sr-panel-button', button.state]"
    />
  </k-field>
</template>

<script>
export default {
  props: {
    label: String,
    text: String,
    cache: String,
  },
  data() {
		return {
			button: {
        icon: 'trash',
        theme: null,
        text: 'Flush cache'
			},
    }
  },
  methods: {
    onClick() {
      this.button.icon = 'loader';
        
      this.$api.get('c/clear/' + this.cache).then((response) => {

        this.button.theme = 'positive';
        this.button.icon = 'check';
        this.button.text = 'Cache flushed'

        setTimeout(this.resetButton, 2000);

      })
      .catch((error) => {
          console.error('Error:', error);
      });
        
    },
    resetButton() {
			this.button.icon = 'trash';
			this.button.theme = null;
      this.button.text = 'Flush cache'
		},
  }
};
</script>