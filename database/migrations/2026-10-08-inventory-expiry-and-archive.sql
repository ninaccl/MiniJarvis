-- Apply once to an existing installation before deploying the inventory API changes.
ALTER TABLE jarvis_inventory_batches
  ADD COLUMN deleted_at TIMESTAMP(6) NULL AFTER note;

ALTER TABLE jarvis_notification_preferences
  ADD COLUMN expiry_days SMALLINT UNSIGNED NOT NULL DEFAULT 15 AFTER inventory_expiry;
