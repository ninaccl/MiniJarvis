const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');

const { localDate, todayAndTomorrow } = require('../utils/date');
const { defaultPurchaseSelections } = require('../utils/purchase-selection');
const { normalizeResponse, createApiClient, ApiError } = require('../services/api');
const { createSessionStore } = require('../services/session');
const { openExternalLink } = require('../utils/external-link');

function createWxDouble({ requests = [], loginCode = 'fresh-code', loginDelay = 0 } = {}) {
  const storage = new Map();
  const calls = { requests: [], logins: 0, navigations: [], copies: [], toasts: [] };
  return {
    calls,
    setStorageSync(key, value) { storage.set(key, value); },
    getStorageSync(key) { return storage.get(key); },
    removeStorageSync(key) { storage.delete(key); },
    request(options) {
      calls.requests.push(options);
      const result = requests.shift() || { statusCode: 200, data: { success: true, data: {} } };
      queueMicrotask(() => result.fail ? options.fail(result.fail) : options.success(result));
    },
    uploadFile(options) {
      calls.requests.push(options);
      const result = requests.shift() || { statusCode: 200, data: JSON.stringify({ success: true, data: {} }) };
      queueMicrotask(() => result.fail ? options.fail(result.fail) : options.success(result));
    },
    login(options) {
      calls.logins += 1;
      setTimeout(() => options.success({ code: loginCode }), loginDelay);
    },
    navigateTo(options) { calls.navigations.push(options); options.success && options.success(); },
    setClipboardData(options) { calls.copies.push(options.data); options.success && options.success(); },
    showToast(options) { calls.toasts.push(options); },
  };
}

test('date helpers use Shanghai calendar dates and include today then tomorrow', () => {
  const instant = new Date('2026-09-13T16:30:00.000Z');
  assert.equal(localDate(instant), '2026-09-14');
  assert.deepEqual(todayAndTomorrow(new Date('2026-09-12T17:00:00.000Z')), ['2026-09-13', '2026-09-14']);
});

test('purchase defaults select breakfast lunch and dinner for today and tomorrow', () => {
  assert.deepEqual(defaultPurchaseSelections(new Date('2026-09-12T17:00:00.000Z')), [
    { date: '2026-09-13', meals: ['breakfast', 'lunch', 'dinner'] },
    { date: '2026-09-14', meals: ['breakfast', 'lunch', 'dinner'] },
  ]);
});

test('normalizes successful and failed backend envelopes into data or a useful error', () => {
  assert.deepEqual(normalizeResponse({ statusCode: 200, data: { success: true, data: { id: 7 } } }), { id: 7 });
  assert.throws(
    () => normalizeResponse({ statusCode: 422, data: { success: false, error: { code: 'VALIDATION_FAILED', message: 'Name required.', fields: { name: 'Required.' } } } }),
    (error) => error instanceof ApiError && error.status === 422 && error.code === 'VALIDATION_FAILED' && error.fields.name === 'Required.',
  );
});

test('a 401 refreshes auth once and exposes the retried client result', async () => {
  const wx = createWxDouble({ requests: [
    { statusCode: 401, data: { success: false, error: { code: 'AUTHENTICATION_REQUIRED', message: 'Expired.' } } },
    { statusCode: 200, data: { success: true, data: { household: 'Kitchen' } } },
  ] });
  const session = createSessionStore(wx);
  session.set({ token: 'old-token', user: { id: 1 } });
  const api = createApiClient({ wx, baseUrl: 'https://api.example.test/api/v1', session, reauthenticate: async () => session.set({ token: 'new-token', user: { id: 1 } }) });

  assert.deepEqual(await api.get('/households/current'), { household: 'Kitchen' });
  assert.equal(wx.calls.requests.length, 2);
  assert.equal(wx.calls.requests[1].header.Authorization, 'Bearer new-token');
});

test('concurrent 401 responses share one WeChat re-login and each request resolves', async () => {
  const wx = createWxDouble({ requests: [
    { statusCode: 401, data: { success: false, error: { code: 'AUTHENTICATION_REQUIRED', message: 'Expired.' } } },
    { statusCode: 401, data: { success: false, error: { code: 'AUTHENTICATION_REQUIRED', message: 'Expired.' } } },
    { statusCode: 200, data: { success: true, data: { id: 'one' } } },
    { statusCode: 200, data: { success: true, data: { id: 'two' } } },
  ] });
  const session = createSessionStore(wx);
  session.set({ token: 'expired', user: { id: 1 } });
  let refreshes = 0;
  const api = createApiClient({
    wx,
    baseUrl: 'https://api.example.test/api/v1',
    session,
    reauthenticate: async () => {
      refreshes += 1;
      const code = await new Promise((resolve) => wx.login({ success: (result) => resolve(result.code) }));
      assert.equal(code, 'fresh-code');
      session.set({ token: 'renewed', user: { id: 1 } });
    },
  });

  assert.deepEqual(await Promise.all([api.get('/one'), api.get('/two')]), [{ id: 'one' }, { id: 'two' }]);
  assert.equal(refreshes, 1);
  assert.equal(wx.calls.logins, 1);
});

test('a second 401 clears the persisted session and does not loop', async () => {
  const wx = createWxDouble({ requests: [
    { statusCode: 401, data: { success: false, error: { code: 'AUTHENTICATION_REQUIRED', message: 'Expired.' } } },
    { statusCode: 401, data: { success: false, error: { code: 'AUTHENTICATION_REQUIRED', message: 'Still expired.' } } },
  ] });
  const session = createSessionStore(wx);
  session.set({ token: 'old-token', user: { id: 1 } });
  let refreshes = 0;
  const api = createApiClient({ wx, baseUrl: 'https://api.example.test/api/v1', session, reauthenticate: async () => { refreshes += 1; session.set({ token: 'new-token', user: { id: 1 } }); } });

  await assert.rejects(api.get('/tasks'), (error) => error instanceof ApiError && error.status === 401);
  assert.equal(refreshes, 1);
  assert.equal(wx.calls.requests.length, 2);
  assert.equal(session.get(), null);
});

test('external links fall back to copying when mini-program navigation is unavailable', async () => {
  const wx = createWxDouble();
  const outcome = await openExternalLink(wx, 'https://example.com/recipe', null);
  assert.equal(outcome, 'copied');
  assert.deepEqual(wx.calls.copies, ['https://example.com/recipe']);
  assert.equal(wx.calls.toasts[0].title, '链接已复制');
});

test('app configuration references five existing tab pages and ten local distinct PNG icons', () => {
  const root = path.resolve(__dirname, '..');
  const app = JSON.parse(fs.readFileSync(path.join(root, 'app.json'), 'utf8'));
  assert.equal(app.tabBar.list.length, 5);
  const iconHashes = new Set();
  for (const tab of app.tabBar.list) {
    assert.ok(fs.existsSync(path.join(root, `${tab.pagePath}.js`)), `${tab.pagePath}.js must exist`);
    assert.ok(fs.existsSync(path.join(root, tab.iconPath)), `${tab.iconPath} must exist`);
    assert.ok(fs.existsSync(path.join(root, tab.selectedIconPath)), `${tab.selectedIconPath} must exist`);
    for (const icon of [tab.iconPath, tab.selectedIconPath]) {
      const bytes = fs.readFileSync(path.join(root, icon));
      assert.ok(bytes.length > 64 && bytes.subarray(0, 8).equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])));
      iconHashes.add(bytes.toString('base64'));
    }
  }
  assert.equal(iconHashes.size, 10);
  for (const page of app.pages) assert.ok(fs.existsSync(path.join(root, `${page}.js`)), `${page}.js must exist`);
});
