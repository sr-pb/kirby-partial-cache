<template>
    <k-field :label="label">
        <k-button :icon="button.icon" variant="filled" :theme="button.theme" @click="onClick" :text="this.button.text"
            :class="['sr-panel-button', button.state]" />
    </k-field>
</template>

<script>
export default {
    props: {
        label: String,
        text: String,
    },
    data() {
        return {
            button: {
                icon: 'map',
                theme: null,
                text: 'Index site'
            },
        }
    },
    methods: {
        onClick() {
            this.button.icon = 'loader';

            this.$api.get('c/index/')
                .then((response) => {
                    this.button.theme = 'positive';
                    this.button.icon = 'check';
                    this.button.text = 'Indexed ' + response.count + ' pages'

                    setTimeout(this.resetButton, 2000);
                })
                .catch((error) => {
                    console.error('Error:', error);
                });
        },
        resetButton() {
            this.button.icon = 'map';
            this.button.theme = null;
            this.button.text = 'Index site'
        },
    }
};
</script>