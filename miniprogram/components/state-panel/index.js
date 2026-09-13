Component({
  properties: { type: { type: String, value: 'empty' }, title: String, description: String },
  methods: { retry() { this.triggerEvent('retry'); } },
});
