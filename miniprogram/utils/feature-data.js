const { localDate } = require('./date');
const meals = [{ code: 'breakfast', name: '早餐' }, { code: 'lunch', name: '午餐' }, { code: 'dinner', name: '晚餐' }];
// Unit codes are the public contract seeded by database/init.sql; no server imports.
const units = [{ code: 'g', name: '克' }, { code: 'kg', name: '千克' }, { code: 'ml', name: '毫升' }, { code: 'l', name: '升' }, { code: 'piece', name: '个' }, { code: 'pack', name: '包' }, { code: 'box', name: '盒' }, { code: 'bunch', name: '把' }, { code: 'tbsp', name: '汤匙' }, { code: 'tsp', name: '茶匙' }];
function quantity(value) {
  const text = String(value == null ? '' : value).trim();
  if (!/^\d{1,10}(\.\d{1,4})?$/.test(text) || Number(text) <= 0 || Number(text) >= 10000000000) throw new Error('数量须大于 0，最多四位小数');
  return text;
}
function recipePayload(form) {
  const title = String(form.title || '').trim();
  if (!title || title.length > 100) throw new Error('请填写 1–100 字菜谱名称');
  if (!Number.isInteger(Number(form.category_id)) || Number(form.category_id) < 1) throw new Error('请选择分类');
  if (Number(form.default_servings) < 1 || Number(form.default_servings) > 100 || !Number.isFinite(Number(form.default_servings))) throw new Error('份数须为 1–100');
  if (!form.ingredients || form.ingredients.length < 1 || form.ingredients.length > 50) throw new Error('请填写 1–50 行食材');
  if ((form.links || []).length > 5) throw new Error('最多添加 5 个链接');
  const seen = new Set();
  const ingredients = form.ingredients.map(row => {
    const name = String(row.name || '').trim();
    const key = name.replace(/\s+/g, ' ').toLowerCase();
    if (!name || name.length > 120 || seen.has(key)) throw new Error('食材名称不能为空或重复，最多 120 字');
    seen.add(key);
    const amount = row.quantity == null || String(row.quantity).trim() === '' ? null : quantity(row.quantity);
    if (amount && !units.some(u => u.code === row.unit_code)) throw new Error('请选择食材单位');
    return { name, quantity: amount, unit_code: amount ? row.unit_code : null, note: row.note || null };
  });
  const links = (form.links || []).map(row => {
    if (!/^https:\/\/[^\s/@]+(?:[/:?#][^\s]*)?$/i.test(row.url || '') || row.url.length > 2048) throw new Error('请填写有效的 HTTPS 链接');
    return { platform: row.platform || 'other', url: row.url.trim(), miniapp_app_id: row.miniapp_app_id || null, miniapp_path: row.miniapp_path || null };
  });
  return { title, category_id: Number(form.category_id), default_servings: Number(form.default_servings), description: form.description || '', instructions: form.instructions || '', cover_url: form.cover_url || null, ingredients, links };
}
function stockTotals(batches) {
  const groups = new Map();
  batches.filter(b => b.status !== 'expired' && Number(b.base_quantity) > 0).forEach(b => {
    const key = b.ingredient_id + '|' + b.base_unit_code;
    const row = groups.get(key) || { key, name: b.ingredient_name, unit_code: b.base_unit_code, scaled: 0 };
    row.scaled += Math.round(Number(b.base_quantity) * 10000);
    groups.set(key, row);
  });
  return Array.from(groups.values()).map(({ scaled, ...row }) => ({ ...row, quantity: String(scaled / 10000) }));
}
function selectionRows(selections) {
  return selections.map(row => ({ ...row, choices: meals.map(meal => ({ ...meal, checked: row.meals.includes(meal.code) })) }));
}
function deadlineParts(instant) {
  if (!instant) return { date: '', time: '09:00' };
  const parsed = new Date(instant.replace(/(\.\d{3})\d+/, '$1'));
  const shifted = new Date(parsed.getTime() + 8 * 3600000);
  return { date: localDate(parsed), time: shifted.toISOString().slice(11, 16) };
}
function dueInstant(date, time) { return date ? `${date}T${time || '09:00'}:00+08:00` : null; }
function taskCards(rows, status, assignee, now = new Date()) {
  const decorate = row => {
    const due = row.due_at ? new Date(row.due_at.replace(/(\.\d{3})\d+/, '$1')).getTime() : Infinity;
    const parts = deadlineParts(row.due_at);
    return { ...row, dueText: parts.date ? `${parts.date} ${parts.time}` : '', dueLabel: row.status !== 'pending' ? '' : due < now.getTime() ? '已逾期' : due <= now.getTime() + 86400000 ? '即将到期' : '' };
  };
  const match = row => (status === 'all' || row.status === status) && (!assignee || row.assignee_user_id === Number(assignee));
  return rows.filter(row => row.parent_id == null).map(row => ({ ...decorate(row), children: rows.filter(child => child.parent_id === row.id && match(child)).map(decorate), matchesFilter: match(row) })).filter(row => row.matchesFilter || row.children.length);
}
module.exports = { meals, units, quantity, recipePayload, stockTotals, selectionRows, deadlineParts, dueInstant, taskCards };
