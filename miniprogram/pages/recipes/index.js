const { avatarLabel, openSettings } = require('../../utils/page-shell');
const { api, ready, mediaUrl, input } = require('../../utils/feature-page');
Page({
  data: { avatar: '我', q: '', categoryId: '', categories: [], items: [], page: 0, more: true, loading: false, error: '' },
  async onShow() { if (await ready()) { this.setData({ avatar: avatarLabel() }); this.load(); } },
  input, openSettings,
  async load() {
    const generation = this._generation = (this._generation || 0) + 1;
    this.setData({ loading: true, error: '', page: 0 });
    try {
      const [categories, rows] = await Promise.all([api().get('/categories'), api().get('/recipes?page=1&page_size=20&q=' + encodeURIComponent(this.data.q) + '&category_id=' + this.data.categoryId)]);
      if (generation !== this._generation) return;
      this.setData({ categories, items: rows.map(row => ({ ...row, cover: mediaUrl(row.cover_url) })), page: 1, more: rows.length === 20 });
    } catch (error) { if (generation === this._generation) this.setData({ error: error.message }); }
    finally { if (generation === this._generation) this.setData({ loading: false }); }
  },
  async loadMore() {
    if (this.data.loading || !this.data.more) return;
    const generation = this._generation;
    this.setData({ loading: true, error: '' });
    try {
      const page = this.data.page + 1;
      const rows = await api().get('/recipes?page=' + page + '&page_size=20&q=' + encodeURIComponent(this.data.q) + '&category_id=' + this.data.categoryId);
      if (generation === this._generation) this.setData({ items: this.data.items.concat(rows.map(row => ({ ...row, cover: mediaUrl(row.cover_url) }))), page, more: rows.length === 20 });
    } catch (error) { this.setData({ error: error.message }); }
    finally { if (generation === this._generation) this.setData({ loading: false }); }
  },
  category(event) { this.setData({ categoryId: event.currentTarget.dataset.id }); this.load(); },
  create() { wx.navigateTo({ url: '/pages/recipe-edit/index' }); },
  detail(event) { wx.navigateTo({ url: '/pages/recipe-detail/index?id=' + event.currentTarget.dataset.id }); },
  onReachBottom() { this.loadMore(); },
});
