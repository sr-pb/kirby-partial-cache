import CacheButton from "./components/fields/CacheButton.vue";
import IndexButton from "./components/fields/IndexButton.vue";

panel.plugin("sr/panel-button", {
    fields: {
        cachebutton: CacheButton,
        indexbutton: IndexButton
    }
});
  
  