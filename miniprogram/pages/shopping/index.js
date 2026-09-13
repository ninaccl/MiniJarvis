const { avatarLabel, openSettings } = require('../../utils/page-shell');
const { api, ready, run, input, closeSheet } = require('../../utils/feature-page');
const { defaultPurchaseSelections } = require('../../utils/purchase-selection');
const { selectionRows, units, quantity } = require('../../utils/feature-data');
Page({
  data: { avatar: '我', selections: [], history: [], historyPage: 0, historyMore: true, loading: false, working: false, error: '', list: null, sheet: '', stockItem: null, form: {}, units },
  onLoad() { this.setData({ selections: selectionRows(defaultPurchaseSelections()) }); },
  async onShow() { if (await ready()) { this.setData({ avatar: avatarLabel() }); await this.load(); if (this.data.list) await this.refreshList(); } },
  openSettings, input, closeSheet,
  addDate(event) {
    const date = event.detail.value;
    if (!this.data.selections.some(row => row.date === date)) this.setData({ selections: selectionRows(this.data.selections.concat({ date, meals: ['breakfast', 'lunch', 'dinner'] }).sort((a,b) => a.date.localeCompare(b.date))) });
  },
  removeDate(event) { this.setData({ selections: this.data.selections.filter(row => row.date !== event.currentTarget.dataset.date) }); },
  toggleMeal(event) {
    const { date, meal } = event.currentTarget.dataset;
    this.setData({ selections: selectionRows(this.data.selections.map(row => row.date !== date ? row : { date, meals: row.meals.includes(meal) ? row.meals.filter(code => code !== meal) : row.meals.concat(meal) })) });
  },
  generate() {
    return run(this, async () => {
      const selections = this.data.selections.filter(row => row.meals.length).map(({ date, meals }) => ({ date, meals }));
      if (!selections.length) throw new Error('请至少选择一个日期和餐次');
      const list = await api().post('/shopping-lists', { selections });
      this.setData({ list }); await this.load();
    });
  },
  async load() { this.setData({ history: [], historyPage: 0, historyMore: true }); return this.moreHistory(); },
  async moreHistory() {
    if (this.data.loading || !this.data.historyMore) return;
    this.setData({ loading: true, error: '' });
    try { const page = this.data.historyPage + 1; const rows = await api().get('/shopping-lists?page=' + page + '&page_size=20'); this.setData({ history: this.data.history.concat(rows), historyPage: page, historyMore: rows.length === 20 }); }
    catch (error) { this.setData({ error: error.message }); }
    finally { this.setData({ loading: false }); }
  },
  openList(event) {
    return run(this, async () => { this.setData({ list: await api().get('/shopping-lists/' + event.currentTarget.dataset.id) }); });
  },
  async refreshList() {
    try { this.setData({ list: await api().get('/shopping-lists/' + this.data.list.id) }); }
    catch (error) { this.setData({ error: error.message }); }
  },
  check(event) {
    return run(this, async () => {
      await api().patch('/shopping-lists/' + this.data.list.id + '/items/' + event.currentTarget.dataset.id, { checked: event.detail.checked });
      await this.refreshList();
    });
  },
  complete() {
    return run(this, async () => {
      await api().patch('/shopping-lists/' + this.data.list.id, { status: this.data.list.status === 'completed' ? 'active' : 'completed' });
      await this.refreshList(); await this.load();
    });
  },
  stock(event) {
    const stockItem = event.currentTarget.dataset.item;
    if (stockItem.stocked_at) return;
    this.setData({ sheet: 'stock', stockItem, error: '', form: { quantity: '', unit_code: stockItem.unit_code || 'piece', expiry_date: '', note: '' } });
  },
  unit(event) { this.setData({ 'form.unit_code': units[event.detail.value].code }); },
  clearExpiry() { this.setData({ 'form.expiry_date': '' }); },
  saveStock() {
    return run(this, async () => {
      const form = this.data.form;
      try {
        await api().post('/shopping-lists/' + this.data.list.id + '/items/' + this.data.stockItem.id + '/stock', { quantity: quantity(form.quantity), unit_code: form.unit_code, expiry_date: form.expiry_date || null, note: form.note || null });
        wx.showToast({ title: '已入库', icon: 'success' });
      } catch (error) {
        if (error.status !== 409 || error.code !== 'SHOPPING_ITEM_ALREADY_STOCKED') throw error;
        wx.showToast({ title: '这项已入库，请勿重复操作', icon: 'none' });
      }
      this.setData({ sheet: '' }); await this.refreshList(); await this.load();
    });
  },
});
