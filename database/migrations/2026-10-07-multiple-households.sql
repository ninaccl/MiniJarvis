USE jarvis_family;

ALTER TABLE jarvis_household_members
  DROP INDEX uq_household_members_user,
  ADD INDEX idx_household_members_user (user_id);
