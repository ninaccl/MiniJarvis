Component({
  properties: { checked: Boolean, label: String, disabled: Boolean },
  methods: { toggle() { if (!this.properties.disabled) this.triggerEvent('change', { checked: !this.properties.checked }); } },
});
