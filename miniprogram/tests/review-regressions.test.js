const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function page(name) {
  let definition;
  global.Page = value => { definition = value; };
  const file = require.resolve(`../pages/${name}/index`);
  delete require.cache[file];
  require(file);
  definition.data = structuredClone(definition.data);
  definition.setData = value => Object.assign(definition.data, value);
  return definition;
}

test('shopping labels render the ingredient name returned by the API, including the stock sheet', () => {
  const item = { id: 42, ingredient_id: 7, ingredient_name: '西红柿', quantity: '400', unit_code: 'g', checked: false, stocked_at: null, inventory_offset: '100' };
  const shopping = page('shopping');
  shopping.stock({ currentTarget: { dataset: { item } } });
  const template = fs.readFileSync(path.join(__dirname, '../pages/shopping/index.wxml'), 'utf8');
  const itemLabel = template.match(/class="shopping-item"><completion-checkbox label="{{(.*?)}}"/)[1];
  const sheetTitle = template.match(/实际入库 · {{(.*?)}}/)[1];
  assert.equal(vm.runInNewContext(itemLabel, { item }), '西红柿');
  assert.equal(vm.runInNewContext(sheetTitle, shopping.data), '西红柿');
});

test('inventory history renders the API occurred_at instant in Beijing time across midnight', async () => {
  const movement = { id: 1, ingredient_name: '米', operation: 'consume', base_delta: '-100', base_unit_code: 'g', batch_id: 9, occurred_at: '2026-09-13T16:30:00.000000Z', note: null };
  global.getApp = () => ({ globalData: { api: { get: async () => [movement] } } });
  const inventory = page('inventory');
  await inventory.moreHistory();
  const template = fs.readFileSync(path.join(__dirname, '../pages/inventory/index.wxml'), 'utf8');
  const timestamp = template.match(/批次 #{{item.batch_id}} · {{(.*?)}}/)[1];
  assert.equal(vm.runInNewContext(timestamp, { item: inventory.data.history[0] }), '2026-09-14 00:30');
  assert.equal(inventory.data.history[0].occurred_at, movement.occurred_at);
});

test('inventory defaults to setting the current quantity and renders unit names in Chinese', async () => {
  const batch = { id: 3, ingredient_id: 4, ingredient_name: '鸡蛋', base_quantity: '6', base_unit_code: 'piece', display_quantity: '6', display_unit_code: 'piece', status: 'active', expiry_date: null };
  let requested = '';
  global.getApp = () => ({ globalData: { api: { get: async path => { requested = path; return [batch]; } } } });
  const inventory = page('inventory');
  await inventory.load();
  assert.match(requested, /expiry_days=15/);
  assert.equal(inventory.data.batches[0].base_unit_name, '个');
  assert.equal(inventory.data.totals[0].unit_name, '个');
  inventory.adjust({ currentTarget: { dataset: { batch } } });
  assert.equal(inventory.data.form.operation, 'set');
  assert.equal(inventory.data.form.quantity, '6');
  assert.equal(inventory.data.units[inventory.data.unitIndex].name, '个');
});

test('inventory deletion confirms, calls the batch endpoint, and refreshes the list', async () => {
  let deleted = '';
  let loads = 0;
  global.wx = { showModal: ({ success }) => success({ confirm: true }) };
  global.getApp = () => ({ globalData: { api: {
    delete: async path => { deleted = path; },
    get: async () => { loads += 1; return []; },
  } } });
  const inventory = page('inventory');
  await inventory.remove({ currentTarget: { dataset: { batch: { id: 8, ingredient_name: '牛奶' } } } });
  assert.equal(deleted, '/inventory/batches/8');
  assert.equal(loads, 1);
});
