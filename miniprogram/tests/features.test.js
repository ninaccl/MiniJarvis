const test = require('node:test');
const assert = require('node:assert/strict');
const { recipePayload, quantity, stockTotals, selectionRows, taskCards, deadlineParts, dueInstant } = require('../utils/feature-data');
const { mediaUrl, run, ready } = require('../utils/feature-page');
const { subscriptionTemplate } = require('../utils/subscriptions');

test('subscription types remain stable when only the inventory template is configured', () => {
  assert.equal(subscriptionTemplate({ subscriptionTemplates: { inventory_expiry: 'expiry-id' } }, 'inventory_expiry'), 'expiry-id');
  assert.equal(subscriptionTemplate({ subscriptionTemplateIds: ['', 'expiry-id'] }, 'inventory_expiry'), 'expiry-id');
  assert.equal(subscriptionTemplate({ subscriptionTemplates: { inventory_expiry: 'expiry-id' } }, 'task_due'), '');
});

test('media URLs use the configured API origin, and write failures keep sheets open', async () => {
  global.getApp = () => ({ globalData: { config: { baseUrl: 'https://kitchen.test/api/v1' } } });
  assert.equal(mediaUrl('/uploads/a.png'), 'https://kitchen.test/uploads/a.png');
  const page = { data: { working: false, sheet: 'stock' }, setData(value) { Object.assign(this.data, value); } };
  await run(page, async () => { throw new Error('保存失败'); });
  assert.equal(page.data.error, '保存失败');
  assert.equal(page.data.sheet, 'stock');
  assert.equal(page.data.working, false);
});
test('feature pages wait for login and redirect users without membership', async () => {
  const routes = [];
  global.wx = { reLaunch: x => routes.push(x.url) };
  global.getApp = () => ({ globalData: { ready: Promise.resolve(), session: { get: () => ({ household: null }) } } });
  assert.equal(await ready(), false);
  assert.deepEqual(routes, ['/pages/onboarding/index']);
});

test('recipe form produces replacement payload and keeps presence-only ingredients unitless', () => {
  const payload = recipePayload({ title: ' 炒菜 ', category_id: 2, default_servings: '2', ingredients: [{ ingredient_id: 9, name: '盐', quantity: '', unit_code: 'g' }], links: [] });
  assert.equal(payload.title, '炒菜');
  assert.equal(payload.default_servings, 2);
  assert.deepEqual(payload.ingredients[0], { name: '盐', quantity: null, unit_code: null, note: null });
  assert.throws(() => recipePayload({ ...payload, ingredients: [] }), /1–50/);
  assert.throws(() => recipePayload({ ...payload, links: [{ platform: 'other', url: 'http://unsafe.test' }] }), /HTTPS/);
});
test('decimal validation rejects zero, infinity, negatives and excess precision', () => {
  assert.equal(quantity('0.1250'), '0.1250');
  for (const value of ['', '0', '-1', 'Infinity', '1.00001', '10000000000']) assert.throws(() => quantity(value));
});
test('stock totals group canonical units separately and exclude expired and empty stock', () => {
  assert.deepEqual(stockTotals([
    { ingredient_id: 1, ingredient_name: '米', base_quantity: '0.1', base_unit_code: 'g', status: 'active' },
    { ingredient_id: 1, ingredient_name: '米', base_quantity: '0.2', base_unit_code: 'g', status: 'expiring' },
    { ingredient_id: 1, ingredient_name: '米', base_quantity: '9', base_unit_code: 'g', status: 'expired' },
    { ingredient_id: 1, ingredient_name: '米', base_quantity: '2', base_unit_code: 'pack', status: 'active' },
  ]).map(x => [x.quantity, x.unit_code]), [['0.3', 'g'], ['2', 'pack']]);
});
test('selection matrix keeps non-contiguous dates and meals', () => {
  const result = selectionRows([{ date: '2026-09-13', meals: ['breakfast'] }, { date: '2026-09-17', meals: ['dinner'] }]);
  assert.deepEqual(result.map(x => x.choices.map(c => c.checked)), [[true, false, false], [false, false, true]]);
});
test('task filtering retains parent context and only matching children; deadlines use Shanghai time', () => {
  const now = new Date('2026-09-13T01:00:00Z');
  const rows = [ { id: 1, parent_id: null, status: 'pending', title: '做饭' }, { id: 2, parent_id: 1, status: 'pending', assignee_user_id: 7, due_at: '2026-09-13T02:00:00Z' }, { id: 3, parent_id: 1, status: 'completed' } ];
  const cards = taskCards(rows, 'pending', 7, now);
  assert.equal(cards.length, 1);
  assert.equal(cards[0].children.length, 1);
  assert.equal(cards[0].children[0].dueLabel, '即将到期');
  assert.deepEqual(deadlineParts('2026-09-13T02:30:00.000000Z'), { date: '2026-09-13', time: '10:30' });
  assert.equal(dueInstant('2026-09-13', '10:30'), '2026-09-13T10:30:00+08:00');
});
