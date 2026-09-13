const SHANGHAI_OFFSET_MS = 8 * 60 * 60 * 1000;

function localDate(value) {
  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) throw new TypeError('A valid date is required.');
  const shifted = new Date(date.getTime() + SHANGHAI_OFFSET_MS);
  return `${shifted.getUTCFullYear()}-${String(shifted.getUTCMonth() + 1).padStart(2, '0')}-${String(shifted.getUTCDate()).padStart(2, '0')}`;
}

function todayAndTomorrow(now = new Date()) {
  const today = localDate(now);
  const tomorrow = localDate(new Date(now.getTime() + 24 * 60 * 60 * 1000));
  return [today, tomorrow];
}

function localDateTime(value) {
  const date = value instanceof Date ? value : new Date(value);
  const shifted = new Date(date.getTime() + SHANGHAI_OFFSET_MS);
  return `${localDate(date)} ${String(shifted.getUTCHours()).padStart(2, '0')}:${String(shifted.getUTCMinutes()).padStart(2, '0')}`;
}

module.exports = { localDate, localDateTime, todayAndTomorrow };
