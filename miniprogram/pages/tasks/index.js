const { avatarLabel, openSettings } = require('../../utils/page-shell');
const { api, ready, run, confirm, input, closeSheet } = require('../../utils/feature-page');
const { taskCards, deadlineParts, dueInstant } = require('../../utils/feature-data');
Page({
  data: { avatar: '我', rows: [], cards: [], members: [{ user_id: null, nickname: '未指派' }], filterMembers: [{ user_id: null, nickname: '所有成员' }], memberIndex: 0, filterIndex: 0, status: 'pending', loading: false, working: false, error: '', sheet: '', form: {}, date: '', time: '09:00' },
  async onShow() { if (await ready()) { this.setData({ avatar: avatarLabel() }); this.load(); } },
  openSettings, input, closeSheet,
  async load() {
    this.setData({ loading: true, error: '' });
    try {
      const [rows, current] = await Promise.all([api().get('/tasks?status=all'), api().get('/households/current')]);
      const members = current.members.map(row => ({ ...row, nickname: row.nickname || '家庭成员' }));
      const prior = this.data.filterMembers[this.data.filterIndex];
      const filterMembers = [{ user_id: null, nickname: '所有成员' }].concat(members);
      this.setData({ rows, members: [{ user_id: null, nickname: '未指派' }].concat(members), filterMembers, filterIndex: Math.max(0, filterMembers.findIndex(row => row.user_id === (prior && prior.user_id))) });
      this.renderCards();
    } catch (error) { this.setData({ error: error.message }); }
    finally { this.setData({ loading: false }); }
  },
  renderCards() {
    const assignee = this.data.filterMembers[this.data.filterIndex].user_id;
    const names = this.data.members;
    const rows = this.data.rows.map(row => ({ ...row, assigneeName: (names.find(member => member.user_id === row.assignee_user_id) || { nickname: '未指派' }).nickname }));
    this.setData({ cards: taskCards(rows, this.data.status, assignee) });
  },
  filter(event) { this.setData({ status: event.currentTarget.dataset.status }); this.renderCards(); },
  filterMember(event) { this.setData({ filterIndex: Number(event.detail.value) }); this.renderCards(); },
  create(event) {
    const parentId = Number(event.currentTarget.dataset.parent) || null;
    this.setData({ sheet: 'task', error: '', form: { title: '', parent_id: parentId, assignee_user_id: null }, memberIndex: 0, date: '', time: '09:00' });
  },
  edit(event) {
    const form = this.data.rows.find(row => row.id === Number(event.currentTarget.dataset.id));
    const parts = deadlineParts(form.due_at);
    this.setData({ sheet: 'task', error: '', form: { ...form }, memberIndex: Math.max(0, this.data.members.findIndex(row => row.user_id === form.assignee_user_id)), date: parts.date, time: parts.time });
  },
  member(event) { const index = Number(event.detail.value); this.setData({ memberIndex: index, 'form.assignee_user_id': this.data.members[index].user_id }); },
  clearDue() { this.setData({ date: '', time: '09:00' }); },
  save() {
    return run(this, async () => {
      const form = this.data.form;
      if (!form.title.trim() || form.title.trim().length > 200) throw new Error('请填写 1–200 字任务名称');
      const payload = { title: form.title.trim(), parent_id: form.parent_id, assignee_user_id: form.assignee_user_id, due_at: dueInstant(this.data.date, this.data.time) };
      if (form.id) await api().patch('/tasks/' + form.id, payload); else await api().post('/tasks', payload);
      this.setData({ sheet: '' }); await this.load();
    });
  },
  toggle(event) {
    return run(this, async () => {
      await api().patch('/tasks/' + event.currentTarget.dataset.id, { status: event.detail.checked ? 'completed' : 'pending' });
      await this.load();
    });
  },
  async remove() {
    if (!(await confirm('删除这项任务？父任务下的子任务也会删除。'))) return;
    return run(this, async () => { await api().delete('/tasks/' + this.data.form.id); this.setData({ sheet: '' }); await this.load(); });
  },
});
