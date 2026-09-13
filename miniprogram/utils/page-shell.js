function avatarLabel() {
  const app = getApp();
  const session = app.globalData.session && app.globalData.session.get();
  const nickname = session && session.user && session.user.nickname;
  return nickname ? String(nickname).slice(0, 1) : '我';
}

function openSettings() { wx.navigateTo({ url: '/pages/settings/index' }); }

module.exports = { avatarLabel, openSettings };
