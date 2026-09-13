Component({
  properties: { title: String, subtitle: String, avatar: String },
  methods: { openSettings() { this.triggerEvent('settings'); } },
});
