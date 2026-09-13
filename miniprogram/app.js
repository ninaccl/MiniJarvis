const { getConfig } = require('./config');
const { createApiClient, ApiError } = require('./services/api');
const { createSessionStore } = require('./services/session');

function wxLogin() {
  return new Promise((resolve, reject) => {
    wx.login({
      success: (result) => result.code ? resolve(result.code) : reject(new Error('WeChat login did not return a code.')),
      fail: reject,
    });
  });
}

App({
  globalData: { api: null, session: null, config: null, members: [], ready: null },

  onLaunch() {
    const config = getConfig();
    const session = createSessionStore(wx);
    this.globalData = { ...this.globalData, config, session };
    this.globalData.api = createApiClient({
      wx,
      baseUrl: config.baseUrl,
      session,
      reauthenticate: () => this.login({ route: false }),
    });
    this.globalData.ready = this.login({ route: true }).catch((error) => {
      this.globalData.lastError = error;
      return null;
    });
  },

  login({ route = false } = {}) {
    if (this._loginPromise) return this._loginPromise;
    this._loginPromise = (async () => {
      const prior = this.globalData.session.get();
      const result = await this.globalData.api.post('/auth/wechat', {
        code: this.globalData.config.localLoginCode || await wxLogin(),
        nickname: prior && prior.user ? prior.user.nickname : undefined,
        avatar_url: prior && prior.user ? prior.user.avatar_url : undefined,
      }, { includeAuth: false, retryAuth: false });
      this.globalData.session.set({ token: result.access_token, user: result.user, household: null });
      await this.refreshHousehold();
      if (route) this.routeForSession();
      return this.globalData.session.get();
    })().finally(() => { this._loginPromise = null; });
    return this._loginPromise;
  },

  async refreshHousehold() {
    try {
      const current = await this.globalData.api.get('/households/current');
      this.globalData.members = current.members || [];
      this.globalData.session.patch({ household: current.household });
      return current;
    } catch (error) {
      if (error instanceof ApiError && error.status === 403 && error.code === 'HOUSEHOLD_MEMBERSHIP_REQUIRED') {
        this.globalData.members = [];
        this.globalData.session.patch({ household: null });
        return null;
      }
      throw error;
    }
  },

  routeForSession() {
    const session = this.globalData.session.get();
    wx.reLaunch({ url: session && session.household ? '/pages/recipes/index' : '/pages/onboarding/index' });
  },
});
