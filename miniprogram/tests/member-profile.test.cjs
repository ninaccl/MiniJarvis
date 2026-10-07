const assert = require('node:assert/strict');
const test = require('node:test');

function loadSettings(app, wx = {}) {
  let definition;
  global.getApp = () => app;
  global.wx = wx;
  global.Page = (page) => { definition = page; };
  const path = require.resolve('../pages/settings/index.js');
  delete require.cache[path];
  require(path);
  return {
    ...definition,
    data: { ...definition.data },
    setData(patch) { Object.assign(this.data, patch); },
  };
}

test('member list shows saved WeChat nickname and avatar, with distinct fallback for missing profiles', async () => {
  const app = { globalData: {
    config: { baseUrl: 'https://www.sunhx.cn/backend/public/index.php/api/v1' },
    session: { get: () => ({ user: { id: 4, nickname: '小明', avatar_url: 'https://example.test/me.png' } }), patch() {} },
    api: { get: async (path) => ({
      '/households/current': { household: { id: 3, name: '家', role: 'owner' }, members: [
        { household_id: 3, user_id: 4, role: 'owner', nickname: '小明', avatar_url: 'https://example.test/me.png' },
        { household_id: 3, user_id: 7, role: 'member', nickname: null, avatar_url: null },
      ] },
      '/notifications/preferences': { task_due: false, inventory_expiry: false },
      '/households': { households: [{ id: 3, name: '家', role: 'owner' }] },
    })[path] },
  } };
  const page = loadSettings(app);
  await page.load();
  assert.equal(page.data.members[0].displayName, '小明');
  assert.equal(page.data.members[0].avatarUrl, 'https://example.test/me.png');
  assert.equal(page.data.members[1].displayName, '成员 7');
  assert.equal(page.data.members[1].avatarInitial, '7');
});

test('login saves a selected nickname and uploaded avatar URL', async () => {
  let definition;
  global.App = (app) => { definition = app; };
  const path = require.resolve('../app.js');
  delete require.cache[path];
  require(path);
  const sent = [];
  let saved = { token: 'old', user: { id: 4, nickname: null, avatar_url: null }, household: { id: 3 } };
  const instance = {
    ...definition,
    globalData: {
      config: { localLoginCode: 'dev:alice' },
      session: { get: () => saved, set: (next) => { saved = next; } },
      api: { post: async (_path, body) => {
        sent.push(body);
        return { access_token: 'new', user: { id: 4, nickname: body.nickname, avatar_url: body.avatar_url } };
      } },
    },
    refreshHousehold: async () => {},
  };
  await instance.login({ profile: { nickname: '小明', avatar_url: 'https://example.test/me.png' } });
  assert.equal(sent[0].nickname, '小明');
  assert.equal(sent[0].avatar_url, 'https://example.test/me.png');
  assert.equal(saved.user.nickname, '小明');
});

test('saving selected WeChat avatar uploads it before updating the member profile', async () => {
  const events = [];
  const app = { globalData: {
    config: { baseUrl: 'https://www.sunhx.cn/backend/public/index.php/api/v1' },
    session: { get: () => ({ user: { id: 4, nickname: null, avatar_url: null } }) },
    api: { upload: async (path, file) => {
      events.push([path, file]);
      return { url: '/uploads/2026/10/avatar.png', mime_type: 'image/png', size: 100 };
    } },
  }, login: async (options) => { events.push(options.profile); } };
  const toasts = [];
  const page = loadSettings(app, { showToast: (options) => toasts.push(options) });
  page.data.profileNickname = '小明';
  page.data.profileAvatarFile = 'wxfile://selected-avatar.png';
  page.load = async () => {};
  assert.equal(typeof page.saveProfile, 'function');
  await page.saveProfile();
  assert.deepEqual(events[0], ['/uploads/images', 'wxfile://selected-avatar.png']);
  assert.deepEqual(events[1], {
    nickname: '小明',
    avatar_url: 'https://www.sunhx.cn/backend/public/uploads/2026/10/avatar.png',
  });
  assert.equal(toasts[0].title, '资料已保存');
});

test('choosing an avatar immediately syncs it to household members without a nickname', async () => {
  const events = [];
  let user = { id: 4, nickname: null, avatar_url: null };
  const app = { globalData: {
    config: { baseUrl: 'https://www.sunhx.cn/backend/public/index.php/api/v1' },
    session: { get: () => ({ user }) },
    api: { upload: async (_path, file) => {
      events.push(file);
      return { url: '/uploads/avatar.png' };
    } },
  }, login: async ({ profile }) => { events.push(profile); user = { ...user, ...profile }; } };
  const page = loadSettings(app, { showToast() {} });
  page.load = async () => {};
  await page.chooseAvatar({ detail: { avatarUrl: 'wxfile://cat.png' } });
  assert.deepEqual(events, ['wxfile://cat.png', { nickname: '', avatar_url: 'https://www.sunhx.cn/backend/public/uploads/avatar.png' }]);
});

test('selecting the WeChat nickname automatically syncs it to household members', async () => {
  const events = [];
  let user = { id: 4, nickname: null, avatar_url: 'https://example.test/cat.png' };
  const app = { globalData: {
    config: { baseUrl: 'https://www.sunhx.cn/backend/public/index.php/api/v1' },
    session: { get: () => ({ user }) },
  }, login: async ({ profile }) => { events.push(profile); user = { ...user, ...profile }; } };
  const page = loadSettings(app, { showToast() {} });
  page.load = async () => {};
  page.editNickname({ detail: { value: '阿明' } });
  await page.confirmNickname({ detail: { value: '阿明' } });
  assert.deepEqual(events, [{ nickname: '阿明', avatar_url: 'https://example.test/cat.png' }]);
});
