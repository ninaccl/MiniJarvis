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
