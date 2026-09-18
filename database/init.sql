CREATE DATABASE IF NOT EXISTS jarvis_family
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

USE jarvis_family;

CREATE TABLE IF NOT EXISTS jarvis_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  openid VARCHAR(128) NOT NULL,
  nickname VARCHAR(255) NULL,
  avatar_url VARCHAR(2048) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_openid (openid)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_api_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  expires_at TIMESTAMP(6) NOT NULL,
  revoked_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_sessions_token_hash (token_hash),
  KEY idx_api_sessions_user_expiry (user_id, expires_at),
  CONSTRAINT fk_api_sessions_user FOREIGN KEY (user_id) REFERENCES jarvis_users (id) ON DELETE CASCADE,
  CONSTRAINT chk_api_sessions_hash CHECK (CHAR_LENGTH(token_hash) = 64)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_households (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  owner_user_id BIGINT UNSIGNED NOT NULL,
  invite_code_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_households_invite_hash (invite_code_hash),
  KEY idx_households_owner (owner_user_id),
  CONSTRAINT fk_households_owner FOREIGN KEY (owner_user_id) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_households_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
  CONSTRAINT chk_households_invite_hash CHECK (CHAR_LENGTH(invite_code_hash) = 64)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_household_members (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'member',
  joined_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_household_members_user (user_id),
  UNIQUE KEY uq_household_members_household_user (household_id, user_id),
  KEY idx_household_members_tenant_role (household_id, role),
  CONSTRAINT fk_household_members_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_household_members_user FOREIGN KEY (user_id) REFERENCES jarvis_users (id) ON DELETE CASCADE,
  CONSTRAINT chk_household_members_role CHECK (role IN ('owner', 'member'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_recipe_categories (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(32) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_categories_name (name),
  UNIQUE KEY uq_recipe_categories_sort (sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_units (
  code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_name VARCHAR(32) NOT NULL,
  dimension VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  base_factor DECIMAL(18,6) NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (code),
  CONSTRAINT chk_units_dimension CHECK (dimension IN ('mass', 'volume', 'discrete')),
  CONSTRAINT chk_units_factor CHECK (base_factor > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_ingredients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  normalized_name VARCHAR(120) COLLATE utf8mb4_bin NOT NULL,
  default_unit_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_ingredients_tenant_id (household_id, id),
  UNIQUE KEY uq_ingredients_household_normalized (household_id, normalized_name),
  KEY idx_ingredients_tenant_updated (household_id, updated_at),
  KEY idx_ingredients_creator (created_by),
  CONSTRAINT fk_ingredients_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_ingredients_unit FOREIGN KEY (default_unit_code) REFERENCES jarvis_units (code) ON DELETE RESTRICT,
  CONSTRAINT fk_ingredients_creator FOREIGN KEY (created_by) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_ingredients_name CHECK (CHAR_LENGTH(TRIM(name)) > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_recipes (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  category_id SMALLINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  description TEXT NULL,
  instructions MEDIUMTEXT NULL,
  servings DECIMAL(10,2) NOT NULL DEFAULT 1,
  prep_minutes SMALLINT UNSIGNED NULL,
  cook_minutes SMALLINT UNSIGNED NULL,
  image_url VARCHAR(2048) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at TIMESTAMP(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipes_tenant_id (household_id, id),
  KEY idx_recipes_tenant_deleted_updated (household_id, deleted_at, updated_at),
  KEY idx_recipes_tenant_category (household_id, category_id),
  KEY idx_recipes_creator (created_by),
  CONSTRAINT fk_recipes_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_recipes_category FOREIGN KEY (category_id) REFERENCES jarvis_recipe_categories (id) ON DELETE SET NULL,
  CONSTRAINT fk_recipes_creator FOREIGN KEY (created_by) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_recipes_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
  CONSTRAINT chk_recipes_servings CHECK (servings > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_recipe_ingredients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  recipe_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(14,4) NULL,
  unit_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  note VARCHAR(255) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_ingredients_recipe_ingredient (household_id, recipe_id, ingredient_id),
  KEY idx_recipe_ingredients_tenant_ingredient (household_id, ingredient_id),
  CONSTRAINT fk_recipe_ingredients_recipe FOREIGN KEY (household_id, recipe_id) REFERENCES jarvis_recipes (household_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_ingredients_ingredient FOREIGN KEY (household_id, ingredient_id) REFERENCES jarvis_ingredients (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_recipe_ingredients_unit FOREIGN KEY (unit_code) REFERENCES jarvis_units (code) ON DELETE RESTRICT,
  CONSTRAINT chk_recipe_ingredients_quantity CHECK (
    (quantity IS NULL AND unit_code IS NULL) OR (quantity > 0 AND unit_code IS NOT NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_recipe_links (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  recipe_id BIGINT UNSIGNED NOT NULL,
  platform VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  url VARCHAR(2048) NOT NULL,
  miniapp_app_id VARCHAR(128) NULL,
  miniapp_path VARCHAR(1024) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipe_links_tenant_id (household_id, id),
  KEY idx_recipe_links_tenant_recipe (household_id, recipe_id),
  KEY idx_recipe_links_creator (created_by),
  CONSTRAINT fk_recipe_links_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_links_recipe FOREIGN KEY (household_id, recipe_id) REFERENCES jarvis_recipes (household_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_recipe_links_creator FOREIGN KEY (created_by) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_recipe_links_platform CHECK (platform IN ('douyin', 'bilibili', 'xiaohongshu', 'other'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_link_previews (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  recipe_link_id BIGINT UNSIGNED NULL,
  token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  platform VARCHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  normalized_url VARCHAR(2048) NOT NULL,
  title VARCHAR(512) NULL,
  description TEXT NULL,
  image_url VARCHAR(2048) NULL,
  temp_image_path VARCHAR(2048) NULL,
  image_mime_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  site_name VARCHAR(255) NULL,
  fetched_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  expires_at TIMESTAMP(6) NOT NULL,
  adopted_at TIMESTAMP(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_link_previews_token_hash (token_hash),
  UNIQUE KEY uq_link_previews_recipe_link (recipe_link_id),
  KEY idx_link_previews_tenant_link (household_id, recipe_link_id),
  KEY idx_link_previews_tenant_url (household_id, url_hash),
  CONSTRAINT fk_link_previews_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_link_previews_member FOREIGN KEY (household_id, user_id) REFERENCES jarvis_household_members (household_id, user_id) ON DELETE CASCADE,
  CONSTRAINT fk_link_previews_recipe_link FOREIGN KEY (household_id, recipe_link_id) REFERENCES jarvis_recipe_links (household_id, id) ON DELETE CASCADE,
  CONSTRAINT chk_link_previews_hash CHECK (CHAR_LENGTH(url_hash) = 64 AND CHAR_LENGTH(token_hash) = 64)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_inventory_batches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  quantity DECIMAL(18,4) NOT NULL,
  unit_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_quantity DECIMAL(14,4) NOT NULL,
  display_unit_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  purchased_at TIMESTAMP(6) NULL,
  opened_at TIMESTAMP(6) NULL,
  expires_on DATE NULL,
  note VARCHAR(255) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_inventory_batches_tenant_id (household_id, id),
  KEY idx_inventory_batches_tenant_expiry (household_id, expires_on),
  KEY idx_inventory_batches_tenant_ingredient (household_id, ingredient_id),
  CONSTRAINT fk_inventory_batches_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_batches_ingredient FOREIGN KEY (household_id, ingredient_id) REFERENCES jarvis_ingredients (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_batches_unit FOREIGN KEY (unit_code) REFERENCES jarvis_units (code) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_batches_display_unit FOREIGN KEY (display_unit_code) REFERENCES jarvis_units (code) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_batches_creator FOREIGN KEY (created_by) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_inventory_batches_quantity CHECK (quantity >= 0),
  CONSTRAINT chk_inventory_batches_display_quantity CHECK (display_quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_inventory_movements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  batch_id BIGINT UNSIGNED NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  movement_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  quantity DECIMAL(18,4) NOT NULL,
  unit_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  display_quantity DECIMAL(14,4) NOT NULL,
  display_unit_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_user_id BIGINT UNSIGNED NOT NULL,
  note VARCHAR(255) NULL,
  occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_inventory_movements_tenant_occurred (household_id, occurred_at),
  KEY idx_inventory_movements_tenant_ingredient (household_id, ingredient_id),
  KEY idx_inventory_movements_tenant_batch (household_id, batch_id),
  CONSTRAINT fk_inventory_movements_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_inventory_movements_batch FOREIGN KEY (household_id, batch_id) REFERENCES jarvis_inventory_batches (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_movements_ingredient FOREIGN KEY (household_id, ingredient_id) REFERENCES jarvis_ingredients (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_movements_unit FOREIGN KEY (unit_code) REFERENCES jarvis_units (code) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_movements_display_unit FOREIGN KEY (display_unit_code) REFERENCES jarvis_units (code) ON DELETE RESTRICT,
  CONSTRAINT fk_inventory_movements_actor FOREIGN KEY (actor_user_id) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_inventory_movements_type CHECK (movement_type IN ('add', 'consume', 'set')),
  CONSTRAINT chk_inventory_movements_display_quantity CHECK (display_quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_meal_plan_entries (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  recipe_id BIGINT UNSIGNED NOT NULL,
  meal_date DATE NOT NULL,
  meal_type VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  servings DECIMAL(10,2) NOT NULL DEFAULT 1,
  notes VARCHAR(500) NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_meal_plan_tenant_date (household_id, meal_date, meal_type),
  KEY idx_meal_plan_tenant_recipe (household_id, recipe_id),
  CONSTRAINT fk_meal_plan_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_meal_plan_recipe FOREIGN KEY (household_id, recipe_id) REFERENCES jarvis_recipes (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_meal_plan_creator FOREIGN KEY (created_by) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_meal_plan_type CHECK (meal_type IN ('breakfast', 'lunch', 'dinner')),
  CONSTRAINT chk_meal_plan_servings CHECK (servings > 0 AND servings <= 100)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_shopping_lists (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'active',
  selection_snapshot JSON NOT NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  completed_at TIMESTAMP(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_shopping_lists_tenant_id (household_id, id),
  KEY idx_shopping_lists_tenant_status (household_id, status, updated_at),
  CONSTRAINT fk_shopping_lists_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_shopping_lists_creator FOREIGN KEY (created_by) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_shopping_lists_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
  CONSTRAINT chk_shopping_lists_status CHECK (status IN ('active', 'completed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_shopping_list_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shopping_list_id BIGINT UNSIGNED NOT NULL,
  household_id BIGINT UNSIGNED NOT NULL,
  ingredient_id BIGINT UNSIGNED NOT NULL,
  source_recipe_id BIGINT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  required_quantity DECIMAL(18,4) NULL,
  inventory_offset DECIMAL(18,4) NULL,
  quantity DECIMAL(18,4) NULL,
  unit_code VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NULL,
  source_summary JSON NOT NULL,
  is_checked BOOLEAN NOT NULL DEFAULT FALSE,
  checked_household_id BIGINT UNSIGNED NULL,
  checked_by BIGINT UNSIGNED NULL,
  checked_at TIMESTAMP(6) NULL,
  stocked_household_id BIGINT UNSIGNED NULL,
  stocked_by BIGINT UNSIGNED NULL,
  stocked_at TIMESTAMP(6) NULL,
  stocked_batch_id BIGINT UNSIGNED NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_shopping_items_tenant_list (household_id, shopping_list_id, is_checked),
  KEY idx_shopping_items_tenant_ingredient (household_id, ingredient_id),
  KEY idx_shopping_items_tenant_recipe (household_id, source_recipe_id),
  KEY idx_shopping_items_checker_member (checked_household_id, checked_by),
  KEY idx_shopping_items_stocker_member (stocked_household_id, stocked_by),
  KEY idx_shopping_items_stocked_batch (household_id, stocked_batch_id),
  CONSTRAINT fk_shopping_items_list FOREIGN KEY (household_id, shopping_list_id) REFERENCES jarvis_shopping_lists (household_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_shopping_items_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_shopping_items_ingredient FOREIGN KEY (household_id, ingredient_id) REFERENCES jarvis_ingredients (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_shopping_items_recipe FOREIGN KEY (household_id, source_recipe_id) REFERENCES jarvis_recipes (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT fk_shopping_items_unit FOREIGN KEY (unit_code) REFERENCES jarvis_units (code) ON DELETE RESTRICT,
  CONSTRAINT fk_shopping_items_checker FOREIGN KEY (checked_household_id, checked_by) REFERENCES jarvis_household_members (household_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_shopping_items_stocker FOREIGN KEY (stocked_household_id, stocked_by) REFERENCES jarvis_household_members (household_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_shopping_items_stocked_batch FOREIGN KEY (household_id, stocked_batch_id) REFERENCES jarvis_inventory_batches (household_id, id) ON DELETE RESTRICT,
  CONSTRAINT chk_shopping_items_name CHECK (CHAR_LENGTH(TRIM(name)) > 0),
  CONSTRAINT chk_shopping_items_quantity CHECK (
    (required_quantity IS NULL AND inventory_offset IS NULL AND quantity IS NULL AND unit_code IS NULL)
    OR (required_quantity > 0 AND inventory_offset >= 0 AND quantity > 0 AND unit_code IS NOT NULL)
  ),
  CONSTRAINT chk_shopping_items_checker_tenant CHECK (
    (checked_by IS NULL AND checked_household_id IS NULL)
    OR (checked_by IS NOT NULL AND checked_household_id = household_id)
  ),
  CONSTRAINT chk_shopping_items_stocker_tenant CHECK (
    (stocked_by IS NULL AND stocked_household_id IS NULL AND stocked_at IS NULL AND stocked_batch_id IS NULL)
    OR (stocked_at IS NOT NULL AND stocked_batch_id IS NOT NULL AND (
      (stocked_by IS NULL AND stocked_household_id IS NULL)
      OR (stocked_by IS NOT NULL AND stocked_household_id = household_id)
    ))
  )
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_tasks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  due_at TIMESTAMP(6) NULL,
  parent_id BIGINT UNSIGNED NULL,
  assigned_household_id BIGINT UNSIGNED NULL,
  assigned_to BIGINT UNSIGNED NULL,
  created_by BIGINT UNSIGNED NOT NULL,
  completed_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_tasks_tenant_id (household_id, id),
  KEY idx_tasks_tenant_status_due (household_id, status, due_at),
  KEY idx_tasks_tenant_parent (household_id, parent_id),
  KEY idx_tasks_assignment_member (assigned_household_id, assigned_to),
  KEY idx_tasks_assignee_status (assigned_to, status),
  CONSTRAINT fk_tasks_household FOREIGN KEY (household_id) REFERENCES jarvis_households (id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_parent FOREIGN KEY (household_id, parent_id) REFERENCES jarvis_tasks (household_id, id) ON DELETE CASCADE,
  CONSTRAINT fk_tasks_assignee FOREIGN KEY (assigned_household_id, assigned_to) REFERENCES jarvis_household_members (household_id, user_id) ON DELETE RESTRICT,
  CONSTRAINT fk_tasks_creator FOREIGN KEY (created_by) REFERENCES jarvis_users (id) ON DELETE RESTRICT,
  CONSTRAINT chk_tasks_title CHECK (CHAR_LENGTH(TRIM(title)) > 0),
  CONSTRAINT chk_tasks_assignee_tenant CHECK (
    (assigned_to IS NULL AND assigned_household_id IS NULL)
    OR (assigned_to IS NOT NULL AND assigned_household_id = household_id)
  ),
  CONSTRAINT chk_tasks_status CHECK (status IN ('pending', 'completed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_notification_preferences (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  task_due BOOLEAN NOT NULL DEFAULT TRUE,
  inventory_expiry BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_preferences_household_user (household_id, user_id),
  KEY idx_notification_preferences_expiry (inventory_expiry, household_id),
  CONSTRAINT fk_notification_preferences_member FOREIGN KEY (household_id, user_id) REFERENCES jarvis_household_members (household_id, user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_notification_grants (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  template_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'available',
  claimed_by_job_id BIGINT UNSIGNED NULL,
  granted_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  consumed_at TIMESTAMP(6) NULL,
  PRIMARY KEY (id),
  KEY idx_notification_grants_available (household_id, user_id, template_type, status, id),
  CONSTRAINT fk_notification_grants_member FOREIGN KEY (household_id, user_id) REFERENCES jarvis_household_members (household_id, user_id) ON DELETE CASCADE,
  CONSTRAINT chk_notification_grants_template CHECK (template_type IN ('task_due', 'inventory_expiry')),
  CONSTRAINT chk_notification_grants_status CHECK (status IN ('available', 'claimed', 'consumed'))
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS jarvis_notification_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  household_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  event_key VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  job_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  status VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT 'pending',
  scheduled_at TIMESTAMP(6) NOT NULL,
  payload_snapshot JSON NOT NULL,
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(1000) NULL,
  sent_at TIMESTAMP(6) NULL,
  created_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_notification_jobs_event_key (event_key),
  KEY idx_notification_jobs_delivery (status, scheduled_at),
  KEY idx_notification_jobs_tenant_user (household_id, user_id, status),
  CONSTRAINT fk_notification_jobs_member FOREIGN KEY (household_id, user_id) REFERENCES jarvis_household_members (household_id, user_id) ON DELETE CASCADE,
  CONSTRAINT chk_notification_jobs_type CHECK (job_type IN ('task_due', 'inventory_expiry')),
  CONSTRAINT chk_notification_jobs_status CHECK (status IN ('pending', 'sending', 'sent', 'permanent_failed', 'cancelled')),
  CONSTRAINT chk_notification_jobs_attempts CHECK (attempts <= 3)
) ENGINE=InnoDB;

INSERT INTO jarvis_recipe_categories (name, sort_order) VALUES
  ('荤菜', 10),
  ('素菜', 20),
  ('汤', 30),
  ('甜品', 40),
  ('主食', 50),
  ('其他', 60)
ON DUPLICATE KEY UPDATE name = VALUES(name), sort_order = VALUES(sort_order);

INSERT INTO jarvis_units (code, display_name, dimension, base_factor) VALUES
  ('g', '克', 'mass', 1.000000),
  ('kg', '千克', 'mass', 1000.000000),
  ('ml', '毫升', 'volume', 1.000000),
  ('l', '升', 'volume', 1000.000000),
  ('piece', '个', 'discrete', 1.000000),
  ('pack', '包', 'discrete', 1.000000),
  ('box', '盒', 'discrete', 1.000000),
  ('bunch', '把', 'discrete', 1.000000),
  ('tbsp', '汤匙', 'discrete', 1.000000),
  ('tsp', '茶匙', 'discrete', 1.000000)
ON DUPLICATE KEY UPDATE
  display_name = VALUES(display_name),
  dimension = VALUES(dimension),
  base_factor = VALUES(base_factor);