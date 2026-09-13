function copyFallback(wx, url) {
  return new Promise((resolve) => {
    wx.setClipboardData({
      data: url,
      success() {
        wx.showToast({ title: '链接已复制', icon: 'success' });
        resolve('copied');
      },
      fail() {
        wx.showToast({ title: '暂无法打开链接', icon: 'none' });
        resolve('unavailable');
      },
    });
  });
}

function openExternalLink(wx, url, miniProgram) {
  if (!miniProgram || !miniProgram.appId || !miniProgram.path || typeof wx.navigateToMiniProgram !== 'function') {
    return copyFallback(wx, url);
  }
  return new Promise((resolve) => {
    wx.navigateToMiniProgram({
      appId: miniProgram.appId,
      path: miniProgram.path,
      success: () => resolve('opened'),
      fail: () => copyFallback(wx, url).then(resolve),
    });
  });
}

module.exports = { openExternalLink };
