// Copy this file to env.js for a local or production Mini Program build. Do not commit env.js.
module.exports = {
  baseUrl: 'https://api.your-domain.example/api/v1',
  // Set the task and inventory subscription template IDs issued in WeChat admin.
  subscriptionTemplates: { task_due: '', inventory_expiry: '' },
  // Local development only: set e.g. dev:alice with APP_ENV=local backend.
  // Keep empty in every production build.
  localLoginCode: '',
  // Optional configured app routes for supported external recipe providers.
  externalPrograms: {
    douyin: { appId: '', path: '' },
    bilibili: { appId: '', path: '' },
  },
};
