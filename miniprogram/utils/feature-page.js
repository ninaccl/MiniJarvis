function api() { return getApp().globalData.api; }
function mediaUrl(url) {
  if (!url) return '';
  const base = getApp().globalData.config.baseUrl.replace(/\/api\/v1\/?$/, '').replace(/\/index\.php\/?$/, '');
  return url.startsWith('/') ? base + url : url;
}
async function ready() {
  const app = getApp();
  await app.globalData.ready;
  const session = app.globalData.session && app.globalData.session.get();
  if (!session || !session.household) { wx.reLaunch({ url: '/pages/onboarding/index' }); return false; }
  return true;
}
async function run(page, operation) {
  if (page.data.working) return false;
  page.setData({ working: true, error: '' });
  try { await operation(); return true; }
  catch (error) {
    const fields = Object.keys(error.fields || {});
    page.setData({ error: error.status === 422 ? `请检查填写内容${fields.length ? '：' + fields.join('、') : ''}` : error.message || '操作失败，请重试' });
    return false;
  } finally { page.setData({ working: false }); }
}
function confirm(content) {
  return new Promise(resolve => wx.showModal({ title: '确认操作', content, confirmColor: '#FF3B30', success: result => resolve(!!result.confirm), fail: () => resolve(false) }));
}
function input(event) { this.setData({ [event.currentTarget.dataset.field]: event.detail.value }); }
function closeSheet() { if (!this.data.working) this.setData({ sheet: '', error: '' }); }
module.exports = { api, mediaUrl, ready, run, confirm, input, closeSheet };