Component({
  properties: { open: Boolean, title: String, confirmText: { type: String, value: '确定' } },
  methods: { noop() {}, close() { this.triggerEvent('close'); }, confirm() { this.triggerEvent('confirm'); } },
});
