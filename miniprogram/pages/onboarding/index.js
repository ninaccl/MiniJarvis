function app() { return getApp(); }

Page({
  data: { householdName: '', inviteCode: '', working: false, error: '', lastAction: '' },

  onShow() {
    const session = app().globalData.session && app().globalData.session.get();
    if (session && session.household) wx.switchTab({ url: '/pages/recipes/index' });
  },

  onNameChange(event) { this.setData({ householdName: event.detail.value, error: '' }); },
  onInviteChange(event) { this.setData({ inviteCode: event.detail.value.toUpperCase(), error: '' }); },

  async createHousehold() {
    const name = this.data.householdName.trim();
    if (!name) return this.setData({ error: '请输入家庭名称。' });
    await this.submit('createHousehold', () => app().globalData.api.post('/households', { name }));
  },

  async joinHousehold() {
    const inviteCode = this.data.inviteCode.replace(/\s/g, '');
    if (!/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/.test(inviteCode)) return this.setData({ error: '请输入 8 位邀请码。' });
    await this.submit('joinHousehold', () => app().globalData.api.post('/households/join', { invite_code: inviteCode }));
  },

  retry() {
    if (this.data.lastAction === 'joinHousehold') return this.joinHousehold();
    return this.createHousehold();
  },

  async submit(lastAction, action) {
    this.setData({ working: true, error: '', lastAction });
    try {
      const result = await action();
      if (result.invite_code) app().globalData.initialInviteCode = result.invite_code;
      await app().refreshHousehold();
      wx.reLaunch({ url: '/pages/recipes/index' });
    } catch (error) {
      this.setData({ error: error.message || '操作未完成，请稍后重试。' });
    } finally {
      this.setData({ working: false });
    }
  },
});
