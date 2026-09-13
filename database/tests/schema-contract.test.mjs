import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const sql = readFileSync(new URL('../init.sql', import.meta.url), 'utf8').replace(/\s+/g, ' ');
const envExample = readFileSync(new URL('../../backend/.env.example', import.meta.url), 'utf8');
const backendReadme = readFileSync(new URL('../../backend/README.md', import.meta.url), 'utf8');
const databaseReadme = readFileSync(new URL('../README.md', import.meta.url), 'utf8');

function table(name) {
  const match = sql.match(new RegExp(`CREATE TABLE IF NOT EXISTS ${name} \\((.*?)\\) ENGINE=InnoDB;`));
  assert.ok(match, `missing table ${name}`);
  return match[1];
}

test('tenant-owned parent references use composite household foreign keys', () => {
  const expectations = {
    recipe_ingredients: [
      'FOREIGN KEY (household_id, recipe_id) REFERENCES recipes (household_id, id)',
      'FOREIGN KEY (household_id, ingredient_id) REFERENCES ingredients (household_id, id)',
    ],
    recipe_links: ['FOREIGN KEY (household_id, recipe_id) REFERENCES recipes (household_id, id)'],
    link_previews: ['FOREIGN KEY (household_id, recipe_link_id) REFERENCES recipe_links (household_id, id)'],
    inventory_batches: ['FOREIGN KEY (household_id, ingredient_id) REFERENCES ingredients (household_id, id)'],
    inventory_movements: [
      'FOREIGN KEY (household_id, batch_id) REFERENCES inventory_batches (household_id, id)',
      'FOREIGN KEY (household_id, ingredient_id) REFERENCES ingredients (household_id, id)',
    ],
    meal_plan_entries: ['FOREIGN KEY (household_id, recipe_id) REFERENCES recipes (household_id, id)'],
    shopping_list_items: [
      'FOREIGN KEY (household_id, shopping_list_id) REFERENCES shopping_lists (household_id, id)',
      'FOREIGN KEY (household_id, ingredient_id) REFERENCES ingredients (household_id, id)',
      'FOREIGN KEY (household_id, source_recipe_id) REFERENCES recipes (household_id, id)',
    ],
  };

  for (const [name, constraints] of Object.entries(expectations)) {
    const definition = table(name);
    assert.match(definition, /household_id BIGINT UNSIGNED NOT NULL/);
    for (const constraint of constraints) assert.ok(definition.includes(constraint), `${name}: ${constraint}`);
  }
});

test('composite foreign key parents expose matching unique keys', () => {
  for (const name of ['ingredients', 'recipes', 'recipe_links', 'inventory_batches', 'shopping_lists']) {
    assert.match(table(name), new RegExp(`UNIQUE KEY uq_${name}_tenant_id \\(household_id, id\\)`));
  }
});

test('member relationships cannot point across households', () => {
  assert.ok(table('notification_preferences').includes(
    'FOREIGN KEY (household_id, user_id) REFERENCES household_members (household_id, user_id)',
  ));
  assert.ok(table('notification_grants').includes(
    'FOREIGN KEY (household_id, user_id) REFERENCES household_members (household_id, user_id)',
  ));
  assert.ok(table('notification_jobs').includes(
    'FOREIGN KEY (household_id, user_id) REFERENCES household_members (household_id, user_id)',
  ));
  assert.ok(table('tasks').includes(
    'FOREIGN KEY (assigned_household_id, assigned_to) REFERENCES household_members (household_id, user_id) ON DELETE RESTRICT',
  ));
  assert.ok(table('tasks').includes(
    'FOREIGN KEY (household_id, parent_id) REFERENCES tasks (household_id, id) ON DELETE CASCADE',
  ));
  assert.ok(table('shopping_list_items').includes(
    'FOREIGN KEY (checked_household_id, checked_by) REFERENCES household_members (household_id, user_id) ON DELETE RESTRICT',
  ));
  assert.ok(table('shopping_list_items').includes(
    'FOREIGN KEY (stocked_household_id, stocked_by) REFERENCES household_members (household_id, user_id) ON DELETE RESTRICT',
  ));
});

test('calendar dates are explicitly Asia Shanghai while instants stay UTC', () => {
  assert.match(envExample, /^CALENDAR_TIMEZONE=Asia\/Shanghai$/m);
  assert.match(backendReadme, /Asia\/Shanghai/);
  assert.match(backendReadme, /instants.*UTC/i);
  assert.match(databaseReadme, /Asia\/Shanghai/);
  assert.match(databaseReadme, /instants.*UTC/i);
});

test('ingredient deletion cannot erase inventory history or recipe usage', () => {
  assert.ok(table('inventory_batches').includes(
    'FOREIGN KEY (household_id, ingredient_id) REFERENCES ingredients (household_id, id) ON DELETE RESTRICT',
  ));
  assert.ok(table('inventory_movements').includes(
    'FOREIGN KEY (household_id, ingredient_id) REFERENCES ingredients (household_id, id) ON DELETE RESTRICT',
  ));
  assert.ok(table('recipe_ingredients').includes(
    'FOREIGN KEY (household_id, ingredient_id) REFERENCES ingredients (household_id, id) ON DELETE RESTRICT',
  ));
  assert.ok(table('recipe_ingredients').includes(
    'FOREIGN KEY (household_id, recipe_id) REFERENCES recipes (household_id, id) ON DELETE CASCADE',
  ));
});

test('recipe ingredients have normalized household identity and link metadata', () => {
  assert.match(table('ingredients'), /normalized_name VARCHAR\(120\) COLLATE utf8mb4_bin NOT NULL/);
  assert.match(table('ingredients'), /UNIQUE KEY uq_ingredients_household_normalized \(household_id, normalized_name\)/);
  assert.match(table('recipe_links'), /platform VARCHAR\(24\).*NOT NULL/);
  assert.match(table('recipe_links'), /miniapp_app_id VARCHAR\(128\) NULL/);
  assert.match(table('recipe_links'), /miniapp_path VARCHAR\(1024\) NULL/);
});

test('link previews persist opaque ownership expiry and single-adoption state', () => {
  const definition = table('link_previews');
  assert.match(definition, /user_id BIGINT UNSIGNED NOT NULL/);
  assert.match(definition, /token_hash CHAR\(64\).*NOT NULL/);
  assert.match(definition, /UNIQUE KEY uq_link_previews_token_hash \(token_hash\)/);
  assert.match(definition, /expires_at TIMESTAMP\(6\) NOT NULL/);
  assert.match(definition, /adopted_at TIMESTAMP\(6\) NULL/);
  assert.match(definition, /image_mime_type VARCHAR\(32\).*NULL/);
  assert.match(definition, /FOREIGN KEY \(household_id, user_id\) REFERENCES household_members \(household_id, user_id\)/);
});

test('inventory stores canonical base amounts and original display amounts', () => {
  const batches = table('inventory_batches');
  assert.match(batches, /quantity DECIMAL\(18,4\) NOT NULL/);
  assert.match(batches, /display_quantity DECIMAL\(14,4\) NOT NULL/);
  assert.match(batches, /display_unit_code VARCHAR\(16\).*NOT NULL/);
  assert.match(batches, /CHECK \(quantity >= 0\)/);
  assert.ok(batches.includes('FOREIGN KEY (display_unit_code) REFERENCES units (code) ON DELETE RESTRICT'));

  const movements = table('inventory_movements');
  assert.match(movements, /quantity DECIMAL\(18,4\) NOT NULL/);
  assert.match(movements, /display_quantity DECIMAL\(14,4\) NOT NULL/);
  assert.match(movements, /display_unit_code VARCHAR\(16\).*NOT NULL/);
  assert.match(movements, /CHECK \(movement_type IN \('add', 'consume', 'set'\)\)/);
  assert.ok(movements.includes('FOREIGN KEY (display_unit_code) REFERENCES units (code) ON DELETE RESTRICT'));
});
