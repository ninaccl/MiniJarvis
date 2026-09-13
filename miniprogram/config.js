const DEFAULT_BASE_URL = 'https://example.invalid/api/v1';

function getConfig() {
  let runtime = {};
  try { runtime = require('./env'); } catch (_) { /* env.js is intentionally optional. */ }
  return {
    baseUrl: DEFAULT_BASE_URL,
    subscriptionTemplateIds: [],
    subscriptionTemplates: {},
    localLoginCode: '',
    externalPrograms: {},
    ...runtime,
  };
}

module.exports = { getConfig };
