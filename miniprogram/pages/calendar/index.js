const { avatarLabel, openSettings } = require('../../utils/page-shell');
const { api, ready, run, confirm, input, closeSheet } = require('../../utils/feature-page');
const { localDate } = require('../../utils/date');
const { meals, quantity } = require('../../utils/feature-data');
Page({
  data: { avatar: '我', date: '', sections: [], loading: false, error: '', working: false, sheet: '', meal: '', pickerQuery: '', pickerItems: [], pickerPage: 0, pickerMore: true, pickerLoading: false, selected: {}, selectedCount: 0, entry: null, servings: '' },
  async onShow() { if (await ready()) { this.setData({ avatar: avatarLabel(), date: this.data.date || localDate(new Date()) }); this.load(); } },
  input, closeSheet, openSettings,
  dateChange(event) { this.setData({ date: event.detail.value }); this.load(); },
  async load() {
    const generation = this._generation = (this._generation || 0) + 1;
    this.setData({ loading: true, error: '' });
    try {
      const rows = await api().get('/meal-plan?from=' + this.data.date + '&to=' + this.data.date);
      if (generation === this._generation) this.setData({ sections: meals.map(meal => ({ ...meal, entries: rows.filter(row => row.meal === meal.code) })) });
    } catch (error) { this.setData({ error: error.message }); }
    finally { if (generation === this._generation) this.setData({ loading: false }); }
  },
  add(event) { this.setData({ sheet: 'picker', meal: event.currentTarget.dataset.meal, pickerQuery: '', selected: {}, selectedCount: 0, pickerItems: [] }); this.searchPicker(); },
  searchPicker() { this.setData({ pickerPage: 0, pickerMore: true }); return this.fetchPicker(true); },
  morePicker() { return this.fetchPicker(false); },
  async fetchPicker(reset) {
    if (!reset && (this.data.pickerLoading || !this.data.pickerMore)) return;
    const generation = this._pickerGeneration = (this._pickerGeneration || 0) + 1;
    this.setData({ pickerLoading: true, error: '' });
    try {
      const page = reset ? 1 : this.data.pickerPage + 1;
      const rows = await api().get('/recipes?page=' + page + '&page_size=20&q=' + encodeURIComponent(this.data.pickerQuery));
      if (generation === this._pickerGeneration) this.setData({ pickerPage: page, pickerMore: rows.length === 20, pickerItems: (reset ? [] : this.data.pickerItems).concat(rows).map(row => ({ ...row, checked: !!this.data.selected[row.id] })) });
    } catch (error) { this.setData({ error: error.message }); }
    finally { if (generation === this._pickerGeneration) this.setData({ pickerLoading: false }); }
  },
  toggleRecipe(event) {
    const id = event.currentTarget.dataset.id;
    const selected = { ...this.data.selected };
    const recipe = this.data.pickerItems.find(row => row.id === Number(id));
    if (selected[id]) delete selected[id]; else selected[id] = { recipe_id: Number(id), servings: String(recipe.default_servings), title: recipe.title };
    this.setData({ selected, selectedCount: Object.keys(selected).length, pickerItems: this.data.pickerItems.map(row => ({ ...row, checked: !!selected[row.id] })) });
  },
  savePlan() {
    return run(this, async () => {
      const recipes = Object.values(this.data.selected).map(row => ({ recipe_id: row.recipe_id, servings: quantity(row.servings) }));
      if (!recipes.length || recipes.some(row => Number(row.servings) > 100)) throw new Error('请选择菜谱，份数须大于 0 且不超过 100');
      await api().post('/meal-plan/entries', { date: this.data.date, meal: this.data.meal, recipes });
      this.setData({ sheet: '' }); await this.load();
    });
  },
  edit(event) { this.setData({ sheet: 'edit', entry: event.currentTarget.dataset.entry, servings: event.currentTarget.dataset.entry.servings, error: '' }); },
  saveEntry() {
    return run(this, async () => {
      const servings = quantity(this.data.servings);
      if (Number(servings) > 100) throw new Error('份数不能超过 100');
      await api().patch('/meal-plan/entries/' + this.data.entry.id, { servings });
      this.setData({ sheet: '' }); await this.load();
    });
  },
  async deleteEntry() {
    if (!(await confirm('从当天餐次中移除这道菜？'))) return;
    return run(this, async () => { await api().delete('/meal-plan/entries/' + this.data.entry.id); this.setData({ sheet: '' }); await this.load(); });
  },
  detail(event) { wx.navigateTo({ url: '/pages/recipe-detail/index?id=' + event.currentTarget.dataset.id }); },
});
