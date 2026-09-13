import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const application = readFileSync(new URL('../../backend/src/Http/Application.php', import.meta.url), 'utf8');

test('task 3 authenticated inventory and matching routes are registered', () => {
  const routes = [
    ['GET', '/api/v1/inventory'],
    ['POST', '/api/v1/inventory/batches'],
    ['PATCH', '/api/v1/inventory/batches/{id}'],
    ['POST', '/api/v1/inventory/batches/{id}/movements'],
    ['GET', '/api/v1/inventory/movements'],
    ['GET', '/api/v1/recipes/matches'],
  ];

  for (const [method, path] of routes) {
    const escapedPath = path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    assert.match(application, new RegExp(`\\$router->add\\('${method}', '${escapedPath}', \\$protected\\(`));
  }
});
