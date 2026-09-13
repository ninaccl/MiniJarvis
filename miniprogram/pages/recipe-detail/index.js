const { api, ready, mediaUrl, run, confirm } = require('../../utils/feature-page');
const { openExternalLink } = require('../../utils/external-link');
Page({
  data: { id: 0, recipe: null, loading: true, working: false, error: '' },
  onLoad(options) { this.setData({ id: Number(options.id) }); },
  async onShow() { if (await ready()) this.load(); },
  async load() {
    this.setData({ loading: true, error: '' });
    try { const recipe = await api().get('/recipes/' + this.data.id); this.setData({ recipe: { ...recipe, cover: mediaUrl(recipe.cover_url) } }); }
    catch (error) { this.setData({ error: error.message }); }
    finally { this.setData({ loading: false }); }
  },
  edit() { wx.navigateTo({ url: '/pages/recipe-edit/index?id=' + this.data.id }); },
  async remove() {
    if (!(await confirm('删除这道菜谱？已保存的历史采购清单将保留。'))) return;
    await run(this, async () => { await api().delete('/recipes/' + this.data.id); wx.navigateBack(); });
  },
  openLink(event) {
    const link = this.data.recipe.links[event.currentTarget.dataset.index];
    const configured = getApp().globalData.config.externalPrograms || {};
    const target = link.miniapp_app_id && link.miniapp_path ? { appId: link.miniapp_app_id, path: link.miniapp_path } : configured[link.platform];
    return openExternalLink(wx, link.url, target);
  },
});
