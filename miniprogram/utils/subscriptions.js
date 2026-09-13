function subscriptionTemplate(config, type) {
  const index = { task_due: 0, inventory_expiry: 1 }[type];
  if (index === undefined) return '';
  const id = (config.subscriptionTemplates || {})[type] || (config.subscriptionTemplateIds || [])[index] || '';
  return id.startsWith('YOUR_') ? '' : id;
}
module.exports = { subscriptionTemplate };
