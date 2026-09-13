import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const application = readFileSync(new URL('../../backend/src/Http/Application.php', import.meta.url), 'utf8');
const bootstrap = readFileSync(new URL('../../backend/public/index.php', import.meta.url), 'utf8');
const schema = readFileSync(new URL('../init.sql', import.meta.url), 'utf8');

test('task 4 authenticated meal-plan and shopping-list routes are registered', () => {
  const routes = [
    ['GET', '/api/v1/meal-plan'],
    ['POST', '/api/v1/meal-plan/entries'],
    ['PATCH', '/api/v1/meal-plan/entries/{id}'],
    ['DELETE', '/api/v1/meal-plan/entries/{id}'],
    ['POST', '/api/v1/shopping-lists'],
    ['GET', '/api/v1/shopping-lists'],
    ['GET', '/api/v1/shopping-lists/{id}'],
    ['PATCH', '/api/v1/shopping-lists/{id}/items/{item_id}'],
    ['PATCH', '/api/v1/shopping-lists/{id}'],
    ['POST', '/api/v1/shopping-lists/{id}/items/{item_id}/stock'],
  ];

  for (const [method, path] of routes) {
    const escapedPath = path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    assert.match(application, new RegExp(`\\$router->add\\('${method}', '${escapedPath}', \\$protected\\(`));
  }
});

test('task 4 services are composed into the HTTP bootstrap', () => {
  assert.match(bootstrap, /new MealPlanController\(/);
  assert.match(bootstrap, /new ShoppingListController\(/);
  assert.match(bootstrap, /new PdoMealPlanRepository\(/);
  assert.match(bootstrap, /new PdoShoppingListRepository\(/);
});

test('shopping schema stores immutable requirement and stock-in snapshots', () => {
  assert.match(schema, /selection_snapshot JSON NOT NULL/);
  assert.match(schema, /required_quantity DECIMAL\(18,4\) NULL/);
  assert.match(schema, /inventory_offset DECIMAL\(18,4\) NULL/);
  assert.match(schema, /source_summary JSON NOT NULL/);
  assert.match(schema, /stocked_at TIMESTAMP\(6\) NULL/);
  assert.match(schema, /stocked_batch_id BIGINT UNSIGNED NULL/);
  assert.doesNotMatch(schema, /meal_type IN \([^)]*snack/);
});
