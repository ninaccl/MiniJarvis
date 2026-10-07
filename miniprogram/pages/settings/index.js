const { ApiError } = require('../../services/api');
const { subscriptionTemplate } = require('../../utils/subscriptions');
const { mediaUrl } = require('../../utils/feature-page');

function app() { return getApp(); }
function confirm(content) {
  return new Promise((resolve) => wx.showModal({ title: '确认操作', content, confirmColor: '#FF3B30', success: (result) => resolve(Boolean(result.confirm)), fail: () => resolve(false) }));
}

Page({
  data: { loading: true, error: '', household: null, households: [], members: [], preferences: { task_due: false, inventory_expiry: false }, inviteCode: '', profileNickname: '', profileAvatar: '', profileAvatarFile: '', working: false },

  onShow() { this.load(); },

  async load() {
    const session = app().globalData.session && app().globalData.session.get();
    if (!session) return;
    this.setData({ loading: true, error: '' });
    try {
      const [current, preferences, listed] = await Promise.all([
        app().globalData.api.get('/households/current'),
        app().globalData.api.get('/notifications/preferences'),
        app().globalData.api.get('/households', { includeHousehold: false }),
      ]);
      const members = (current.members || []).map((member) => {
        const nickname = String(member.nickname || '').trim();
        return {
          ...member,
          displayName: nickname || `成员 ${member.user_id}`,
          avatarInitial: nickname ? Array.from(nickname)[0] : String(member.user_id).slice(-2),
          avatarUrl: member.avatar_url ? mediaUrl(member.avatar_url) : '',
        };
      });
      app().globalData.members = members;
      app().globalData.session.patch({ household: current.household });
      this.setData({ household: current.household, households: listed.households || [], members, preferences, inviteCode: app().globalData.initialInviteCode || '', profileNickname: session.user && session.user.nickname || '', profileAvatar: session.user && session.user.avatar_url ? mediaUrl(session.user.avatar_url) : '', profileAvatarFile: '', loading: false });
    } catch (error) {
      if (error instanceof ApiError && error.code === 'HOUSEHOLD_MEMBERSHIP_REQUIRED') {
        try {
          const current = await app().refreshHousehold();
          return wx.reLaunch({ url: current ? '/pages/recipes/index' : '/pages/onboarding/index' });
        } catch (_) { /* Show original error. */ }
      }
      this.setData({ error: error.message || '设置加载失败。', loading: false });
    }
  },

  addHousehold() { wx.navigateTo({ url: '/pages/onboarding/index?from=settings' }); },

  chooseAvatar(event) {
    const avatarUrl = event.detail && event.detail.avatarUrl;
    if (!avatarUrl) return;
    this.setData({ profileAvatar: avatarUrl, profileAvatarFile: avatarUrl });
    return this.saveProfile({ allowEmptyNickname: true, silent: true });
  },

  editNickname(event) { this.setData({ profileNickname: event.detail.value }); },

  confirmNickname(event) {
    const nickname = String(event.detail && event.detail.value || '').trim();
    if (!nickname) return;
    this.setData({ profileNickname: nickname });
    return this.saveProfile({ silent: true });
  },

  saveProfile(options = {}) {
    const { allowEmptyNickname = false, silent = false } = options;
    const nickname = String(this.data.profileNickname || '').trim();
    const avatarFile = this.data.profileAvatarFile;
    if (!nickname && !allowEmptyNickname) return wx.showToast({ title: '请选用微信昵称', icon: 'none' });
    const persist = async () => {
      this.setData({ working: true });
      try {
        const current = app().globalData.session.get().user;
        if (nickname === String(current.nickname || '') && (!avatarFile || avatarFile === this._lastUploadedFile)) return;
        let avatarUrl = current.avatar_url;
        if (avatarFile && avatarFile !== this._lastUploadedFile) {
          const uploaded = await app().globalData.api.upload('/uploads/images', avatarFile);
          avatarUrl = mediaUrl(uploaded.url);
          this._lastUploadedFile = avatarFile;
          this._lastUploadedUrl = avatarUrl;
        } else if (avatarFile && avatarFile === this._lastUploadedFile) {
          avatarUrl = this._lastUploadedUrl || avatarUrl;
        }
        await app().login({ route: false, profile: { nickname, avatar_url: avatarUrl } });
        await this.load();
        if (!silent) wx.showToast({ title: '资料已保存', icon: 'success' });
      } catch (error) { wx.showToast({ title: error.message || '保存失败', icon: 'none' }); }
      finally { this.setData({ working: false }); }
    };
    this._profileSaveQueue = (this._profileSaveQueue || Promise.resolve()).then(persist, persist);
    return this._profileSaveQueue;
  },

  async switchHousehold(event) {
    const household = this.data.households.find((item) => item.id === Number(event.currentTarget.dataset.id));
    if (!household || household.id === this.data.household.id) return;
    try {
      await app().selectHousehold(household);
      app().globalData.initialInviteCode = '';
      this.setData({ inviteCode: '' });
      wx.switchTab({ url: '/pages/recipes/index' });
    } catch (error) { wx.showToast({ title: error.message || '切换失败', icon: 'none' }); }
  },

  async leaveHousehold() {
    if (!(await confirm(`确定退出「${this.data.household.name}」吗？`))) return;
    await this.finishMembership(() => app().globalData.api.delete('/households/current/membership'));
  },

  async dissolveHousehold() {
    if (!(await confirm(`确定解散「${this.data.household.name}」吗？家庭内全部数据将被永久删除。`))) return;
    await this.finishMembership(() => app().globalData.api.delete('/households/current'));
  },

  async finishMembership(action) {
    this.setData({ working: true });
    try {
      await action();
      app().globalData.session.patch({ household: null });
      const current = await app().refreshHousehold();
      app().globalData.initialInviteCode = '';
      wx.reLaunch({ url: current ? '/pages/recipes/index' : '/pages/onboarding/index' });
    } catch (error) { wx.showToast({ title: error.message || '操作失败', icon: 'none' }); }
    finally { this.setData({ working: false }); }
  },

  async resetInvite() {
    if (!(await confirm('邀请码会立即失效并生成新的邀请码。'))) return;
    this.setData({ working: true });
    try {
      const result = await app().globalData.api.post('/households/invite/reset', {});
      this.setData({ inviteCode: result.invite_code });
    } catch (error) { wx.showToast({ title: error.message || '重置失败', icon: 'none' }); }
    finally { this.setData({ working: false }); }
  },

  copyInvite() {
    if (!this.data.inviteCode) return;
    wx.setClipboardData({
      data: this.data.inviteCode,
      success: () => wx.showToast({ title: '邀请码已复制', icon: 'success' }),
      fail: () => wx.showToast({ title: '复制失败，请重试', icon: 'none' }),
    });
  },

  async removeMember(event) {
    const member = event.currentTarget.dataset.member;
    if (!member || !(await confirm(`确定移除 ${member.nickname || '该成员'} 吗？`))) return;
    try {
      await app().globalData.api.delete(`/households/members/${member.user_id}`);
      await this.load();
    } catch (error) { wx.showToast({ title: error.message || '移除失败', icon: 'none' }); }
  },

  async togglePreference(event) {
    const key = event.currentTarget.dataset.key;
    const value = event.detail.value;
    try {
      const preferences = await app().globalData.api.patch('/notifications/preferences', { [key]: value });
      this.setData({ preferences });
    } catch (error) { wx.showToast({ title: error.message || '保存失败', icon: 'none' }); }
  },

  requestSubscription(event) {
    const type = event.currentTarget.dataset.type;
    const id = subscriptionTemplate(app().globalData.config, type);
    if (!id || typeof wx.requestSubscribeMessage !== 'function') return wx.showToast({ title: '请先配置订阅消息模板', icon: 'none' });
    wx.requestSubscribeMessage({
      tmplIds: [id],
      success: async (result) => {
        try {
          const reply = result[id];
          if (!reply) return;
          await app().globalData.api.post('/notifications/subscription-grants', { template_type: type, result: reply === 'accept' ? 'accept' : 'reject' });
          wx.showToast({ title: '订阅偏好已记录', icon: 'success' });
        } catch (error) { wx.showToast({ title: '授权记录失败，请重新授权', icon: 'none' }); }
      },
      fail: () => wx.showToast({ title: '未完成订阅授权', icon: 'none' }),
    });
  },
});
