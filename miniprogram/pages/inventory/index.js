const { avatarLabel, openSettings } = require('../../utils/page-shell');
const { api, ready, run, confirm, input, closeSheet } = require('../../utils/feature-page');
const { units, unitName, quantity, stockTotals } = require('../../utils/feature-data');
const { localDateTime } = require('../../utils/date');
Page({
  data: { avatar: '我', q: '', status: 'all', expiryDays: 15, filters: [{ code: 'all', name: '全部' }, { code: 'active', name: '可用' }, { code: 'expiring', name: '临期' }, { code: 'expired', name: '过期' }], batches: [], totals: [], loading: false, working: false, error: '', sheet: '', form: {}, units, operations: [{ code: 'set', name: '设定余量' }, { code: 'add', name: '增加' }, { code: 'consume', name: '消耗' }], operationIndex: 0, count: '2', matches: [], matched: false, history: [], historyPage: 0, historyMore: true, historyLoading: false },
  async onShow() { if (await ready()) { this.setData({ avatar: avatarLabel(), expiryDays: 15 }); try { const preferences = await api().get('/notifications/preferences'); if (Number.isInteger(preferences.expiry_days)) this.setData({ expiryDays: preferences.expiry_days }); } catch (_) { /* Default remains available if preferences fail. */ } this.load(); } },
  openSettings, input, closeSheet,
  filter(event) { this.setData({ status: event.currentTarget.dataset.status }); this.load(); },
  async load() {
    const generation = this._generation = (this._generation || 0) + 1;
    this.setData({ loading: true, error: '' });
    try {
      const batches = await api().get('/inventory?q=' + encodeURIComponent(this.data.q) + '&status=' + this.data.status + '&expiry_days=' + this.data.expiryDays);
      if (generation === this._generation) this.setData({ batches: batches.map(batch => ({ ...batch, base_unit_name: unitName(batch.base_unit_code) })), totals: stockTotals(batches).map(row => ({ ...row, unit_name: unitName(row.unit_code) })) });
    } catch (error) { this.setData({ error: error.message }); }
    finally { if (generation === this._generation) this.setData({ loading: false }); }
  },
  add() { this.setData({ sheet: 'add', error: '', unitIndex: 0, form: { ingredient_name: '', quantity: '', unit_code: 'g', expiry_date: '', note: '' } }); },
  edit(event) { const batch = event.currentTarget.dataset.batch; this.setData({ sheet: 'edit', error: '', form: { id: batch.id, ingredient_name: batch.ingredient_name, expiry_date: batch.expiry_date || '', note: batch.note || '' } }); },
  adjust(event) { const batch = event.currentTarget.dataset.batch; const unitCode = batch.base_unit_code; this.setData({ sheet: 'move', error: '', operationIndex: 0, unitIndex: Math.max(0, units.findIndex(unit => unit.code === unitCode)), form: { id: batch.id, ingredient_name: batch.ingredient_name, quantity: batch.base_quantity, unit_code: unitCode, operation: 'set', note: '' } }); },
  unit(event) { const index = Number(event.detail.value); this.setData({ unitIndex: index, 'form.unit_code': units[index].code }); },
  operation(event) { const index = Number(event.detail.value); this.setData({ operationIndex: index, 'form.operation': this.data.operations[index].code }); },
  clearExpiry() { this.setData({ 'form.expiry_date': '' }); },
  async remove(event) {
    const batch = event.currentTarget.dataset.batch;
    if (!batch || !(await confirm('删除「' + batch.ingredient_name + '」这批库存？库存流水会保留。'))) return;
    return run(this, async () => { await api().delete('/inventory/batches/' + batch.id); this.setData({ matched: false, matches: [] }); await this.load(); });
  },
  save() {
    return run(this, async () => {
      const form = this.data.form;
      if (this.data.sheet === 'edit') await api().patch('/inventory/batches/' + form.id, { expiry_date: form.expiry_date || null, note: form.note || null });
      else if (this.data.sheet === 'move') await api().post('/inventory/batches/' + form.id + '/movements', { operation: form.operation, quantity: quantity(form.quantity), unit_code: form.unit_code, note: form.note || null });
      else {
        if (!form.ingredient_name.trim()) throw new Error('请填写食材名称');
        await api().post('/inventory/batches', { ingredient_name: form.ingredient_name.trim(), quantity: quantity(form.quantity), unit_code: form.unit_code, expiry_date: form.expiry_date || null, note: form.note || null });
      }
      this.setData({ sheet: '', matched: false, matches: [] }); await this.load();
    });
  },
  match() {
    return run(this, async () => {
      const count = Number(this.data.count);
      if (!Number.isInteger(count) || count < 1 || count > 10) throw new Error('推荐道数须为 1–10');
      const matches = await api().get('/recipes/matches?count=' + count);
      this.setData({ matches: matches.map(match => ({ ...match, recipe_id: match.recipe.id, missing_ingredients: match.missing_ingredients.map(item => ({ ...item, base_unit_name: unitName(item.base_unit_code) })) })), matched: true });
    });
  },
  detail(event) { wx.navigateTo({ url: '/pages/recipe-detail/index?id=' + event.currentTarget.dataset.id }); },
  showHistory() { this.setData({ sheet: 'history', history: [], historyPage: 0, historyMore: true, error: '' }); this.moreHistory(); },
  async moreHistory() {
    if (this.data.historyLoading || !this.data.historyMore) return;
    this.setData({ historyLoading: true, error: '' });
    try { const page = this.data.historyPage + 1; const rows = await api().get('/inventory/movements?page=' + page + '&page_size=20'); this.setData({ history: this.data.history.concat(rows.map(row => ({ ...row, occurredLabel: localDateTime(row.occurred_at), base_unit_name: unitName(row.base_unit_code) }))), historyPage: page, historyMore: rows.length === 20 }); }
    catch (error) { this.setData({ error: error.message }); }
    finally { this.setData({ historyLoading: false }); }
  },
});
