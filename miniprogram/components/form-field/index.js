Component({
  properties: { label: String, value: String, placeholder: String, maxlength: { type: Number, value: 120 } },
  methods: { change(event) { this.triggerEvent('change', { value: event.detail.value }); } },
});
