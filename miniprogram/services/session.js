const STORAGE_KEY = 'family-kitchen.session.v1';

function normalizeSession(value) {
  if (!value || typeof value !== 'object' || typeof value.token !== 'string' || !value.token) return null;
  return {
    token: value.token,
    user: value.user && typeof value.user === 'object' ? value.user : null,
    household: value.household && typeof value.household === 'object' ? value.household : null,
  };
}

function createSessionStore(wx) {
  return {
    get() { return normalizeSession(wx.getStorageSync(STORAGE_KEY)); },
    set(value) {
      const session = normalizeSession(value);
      if (!session) throw new TypeError('A token is required to save a session.');
      wx.setStorageSync(STORAGE_KEY, session);
      return session;
    },
    patch(patch) {
      const current = this.get();
      if (!current) return null;
      return this.set({ ...current, ...patch });
    },
    clear() { wx.removeStorageSync(STORAGE_KEY); },
  };
}

module.exports = { STORAGE_KEY, createSessionStore, normalizeSession };
