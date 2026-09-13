const { api, ready, mediaUrl, run, input } = require('../../utils/feature-page');
const { units, recipePayload } = require('../../utils/feature-data');
Page({
  data: { id: 0, form: { title: '', description: '', instructions: '', category_id: 0, default_servings: '2', cover_url: '', ingredients: [{ name: '', quantity: '', unit_code: 'g', note: '' }], links: [] }, categories: [], categoryIndex: 0, units, platforms: ['other', 'douyin', 'bilibili', 'xiaohongshu'], preview: null, cover: '', loading: true, working: false, error: '' },
  onLoad(options) { this.setData({ id: Number(options.id) || 0 }); this.load(); },
  async load() {
    if (!(await ready())) return;
    this.setData({ loading: true, error: '' });
    try {
      const categories = await api().get('/categories');
      const form = this.data.id ? await api().get('/recipes/' + this.data.id) : { ...this.data.form, category_id: categories[0] ? categories[0].id : 0 };
      this.setData({ categories, form, categoryIndex: Math.max(0, categories.findIndex(c => c.id === form.category_id)), cover: mediaUrl(form.cover_url) });
    } catch (error) { this.setData({ error: error.message }); }
    finally { this.setData({ loading: false }); }
  },
  input,
  category(event) { const index = Number(event.detail.value); this.setData({ categoryIndex: index, 'form.category_id': this.data.categories[index].id }); },
  unit(event) { this.setData({ ['form.ingredients[' + event.currentTarget.dataset.index + '].unit_code']: units[event.detail.value].code }); },
  platform(event) { this.setData({ ['form.links[' + event.currentTarget.dataset.index + '].platform']: this.data.platforms[event.detail.value] }); },
  addIngredient() { if (this.data.form.ingredients.length < 50) this.setData({ 'form.ingredients': this.data.form.ingredients.concat({ name: '', quantity: '', unit_code: 'g', note: '' }) }); },
  removeIngredient(event) { if (this.data.form.ingredients.length > 1) this.setData({ 'form.ingredients': this.data.form.ingredients.filter((_, i) => i !== Number(event.currentTarget.dataset.index)) }); },
  addLink() { if (this.data.form.links.length < 5) this.setData({ 'form.links': this.data.form.links.concat({ platform: 'other', url: '', miniapp_app_id: '', miniapp_path: '' }) }); },
  removeLink(event) { this.setData({ 'form.links': this.data.form.links.filter((_, i) => i !== Number(event.currentTarget.dataset.index)), preview: null }); },
  chooseCover() {
    if (this.data.working) return;
    wx.chooseMedia({ count: 1, mediaType: ['image'], success: result => run(this, async () => {
      const uploaded = await api().upload('/uploads/images', result.tempFiles[0].tempFilePath);
      this.setData({ 'form.cover_url': uploaded.url, cover: mediaUrl(uploaded.url) });
    }), fail: error => { if (!/cancel/.test(error.errMsg || '')) this.setData({ error: '图片选择失败，请重试' }); } });
  },
  removeCover() { this.setData({ 'form.cover_url': '', cover: '' }); },
  previewLink(event) {
    const url = this.data.form.links[event.currentTarget.dataset.index].url;
    return run(this, async () => {
      this.setData({ preview: null });
      try {
        const preview = await api().post('/link-previews', { url });
        if (!preview.available) { this.setData({ error: '暂时无法解析，可继续手动填写并保存链接' }); return; }
        this.setData({ preview });
      } catch (_) { this.setData({ error: '暂时无法解析，可继续手动填写并保存链接' }); }
    });
  },
  adoptTitle() { this.setData({ 'form.title': this.data.preview.title.slice(0, 100) }); },
  adoptCover() {
    return run(this, async () => {
      const result = await api().post('/link-previews/' + this.data.preview.token + '/adopt', {});
      this.setData({ 'form.cover_url': result.cover_url, cover: mediaUrl(result.cover_url), preview: null });
    });
  },
  save() {
    return run(this, async () => {
      const payload = recipePayload(this.data.form);
      if (this.data.id) await api().patch('/recipes/' + this.data.id, payload);
      else await api().post('/recipes', payload);
      wx.showToast({ title: '菜谱已保存', icon: 'success' });
      wx.navigateBack();
    });
  },
});
