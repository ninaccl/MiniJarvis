const { todayAndTomorrow } = require('./date');

function defaultPurchaseSelections(now) {
  return todayAndTomorrow(now).map((date) => ({ date, meals: ['breakfast', 'lunch', 'dinner'] }));
}

module.exports = { defaultPurchaseSelections };
