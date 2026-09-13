import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');
const application = read('backend/src/Http/Application.php');
const bootstrap = read('backend/public/index.php');
const config = read('backend/src/Config/Config.php');
const schema = read('database/init.sql').replace(/\s+/g, ' ');

function table(name) {
  const match = schema.match(new RegExp(`CREATE TABLE IF NOT EXISTS ${name} \\((.*?)\\) ENGINE=InnoDB;`));
  assert.ok(match, `missing table ${name}`);
  return match[1];
}

test('task and notification routes are authenticated and composed', () => {
  for (const [method, path] of [
    ['GET', '/api/v1/tasks'], ['POST', '/api/v1/tasks'],
    ['GET', '/api/v1/tasks/{id}'], ['PATCH', '/api/v1/tasks/{id}'], ['DELETE', '/api/v1/tasks/{id}'],
    ['GET', '/api/v1/notifications/preferences'], ['PATCH', '/api/v1/notifications/preferences'],
    ['POST', '/api/v1/notifications/subscription-grants'],
  ]) {
    const escaped = path.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    assert.match(application, new RegExp(`\\$router->add\\('${method}', '${escaped}', \\$protected\\(`));
  }
  assert.match(bootstrap, /new TaskController\(/);
  assert.match(bootstrap, /new NotificationController\(/);
});

test('notification persistence exposes idempotent events and exact delivery states', () => {
  const jobs = table('notification_jobs');
  assert.match(jobs, /event_key VARCHAR\(191\).*NOT NULL/);
  assert.match(jobs, /UNIQUE KEY uq_notification_jobs_event_key \(event_key\)/);
  assert.match(jobs, /scheduled_at TIMESTAMP\(6\) NOT NULL/);
  assert.match(jobs, /status IN \('pending', 'sending', 'sent', 'permanent_failed', 'cancelled'\)/);
  assert.match(jobs, /payload_snapshot JSON NOT NULL/);

  const grants = table('notification_grants');
  assert.match(grants, /template_type VARCHAR\(32\).*NOT NULL/);
  assert.match(grants, /status IN \('available', 'claimed', 'consumed'\)/);
  assert.match(grants, /claimed_by_job_id BIGINT UNSIGNED NULL/);
});

test('task persistence uses pending/completed and cascading household parent identity', () => {
  const tasks = table('tasks');
  assert.match(tasks, /title VARCHAR\(200\) NOT NULL/);
  assert.match(tasks, /status IN \('pending', 'completed'\)/);
  assert.ok(tasks.includes('FOREIGN KEY (household_id, parent_id) REFERENCES tasks (household_id, id) ON DELETE CASCADE'));
  assert.ok(tasks.includes('FOREIGN KEY (assigned_household_id, assigned_to) REFERENCES household_members (household_id, user_id) ON DELETE RESTRICT'));
});

test('reminder implementation contains concurrency-safe claim, retry, stale-job, and Shanghai expiry behavior', () => {
  const repository = read('backend/src/Notification/PdoNotificationRepository.php');
  const service = read('backend/src/Notification/ReminderService.php');
  const taskService = read('backend/src/Task/TaskService.php');

  assert.match(repository, /FOR UPDATE SKIP LOCKED/);
  assert.match(repository, /status = 'available'.*FOR UPDATE/s);
  assert.match(service, /attempts.*>= 3/s);
  assert.match(service, /skipped_no_grant/);
  assert.match(repository, /restoreGrant/);
  assert.match(service, /Asia\/Shanghai/);
  assert.match(service, /modify\('\+3 days'\)/);
  assert.match(taskService, /modify\('-24 hours'\)/);
  assert.match(taskService, /cancelTaskJobs/);
  assert.match(taskService, /task_due:.*due_at/s);
});

test('WeChat templates are environment configured and cron entrypoint exists', () => {
  for (const key of [
    'WECHAT_TASK_DUE_TEMPLATE_ID', 'WECHAT_EXPIRY_TEMPLATE_ID',
    'WECHAT_TASK_DUE_TITLE_KEY', 'WECHAT_TASK_DUE_TIME_KEY',
    'WECHAT_EXPIRY_SUMMARY_KEY', 'WECHAT_TASKS_PAGE', 'WECHAT_INVENTORY_PAGE',
  ]) assert.match(config, new RegExp(`'${key}'`));

  assert.ok(existsSync(new URL('backend/bin/send-reminders.php', root)));
  const client = read('backend/src/Notification/WeChatNotificationClient.php');
  assert.match(client, /cgi-bin\/token/);
  assert.match(client, /cgi-bin\/message\/subscribe\/send/);
  assert.match(client, /postJson\([^;]+, \$message\)/);
  assert.doesNotMatch(client, /\$message\[['"](?:secret|appsecret)['"]\]/i);
});
