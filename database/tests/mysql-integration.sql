-- Run after database/init.sql with a MySQL 8 account allowed to create routines.
-- All fixture rows are created in one transaction and rolled back.
USE jarvis_family;

DROP PROCEDURE IF EXISTS jarvis_run_schema_integration_tests;

DELIMITER //
CREATE PROCEDURE jarvis_run_schema_integration_tests()
BEGIN
  DECLARE table_count INT DEFAULT 0;
  DECLARE category_count INT DEFAULT 0;
  DECLARE unit_count INT DEFAULT 0;
  DECLARE duplicate_membership_rejected BOOLEAN DEFAULT FALSE;
  DECLARE cross_household_reference_rejected BOOLEAN DEFAULT FALSE;
  DECLARE user_one BIGINT UNSIGNED;
  DECLARE user_two BIGINT UNSIGNED;
  DECLARE openid_one VARCHAR(128);
  DECLARE openid_two VARCHAR(128);
  DECLARE household_one BIGINT UNSIGNED;
  DECLARE household_two BIGINT UNSIGNED;
  DECLARE recipe_one BIGINT UNSIGNED;
  DECLARE ingredient_two BIGINT UNSIGNED;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  SELECT COUNT(*) INTO table_count
    FROM information_schema.tables
    WHERE table_schema = 'jarvis_family'
      AND table_name IN (
        'users', 'api_sessions', 'households', 'household_members',
        'recipe_categories', 'units', 'ingredients', 'recipes',
        'recipe_ingredients', 'recipe_links', 'link_previews',
        'inventory_batches', 'inventory_movements', 'meal_plan_entries',
        'shopping_lists', 'shopping_list_items', 'tasks',
        'notification_preferences', 'notification_grants', 'notification_jobs'
      );
  IF table_count <> 20 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema integration: expected 20 application tables';
  END IF;

  SELECT COUNT(*) INTO category_count
    FROM recipe_categories
    WHERE (name, sort_order) IN (
      ('荤菜', 10), ('素菜', 20), ('汤', 30),
      ('甜品', 40), ('主食', 50), ('其他', 60)
    );
  IF category_count <> 6 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema integration: recipe category seeds are incomplete';
  END IF;

  SELECT COUNT(*) INTO unit_count
    FROM units
    WHERE code IN ('g', 'kg', 'ml', 'l', 'piece', 'pack', 'box', 'bunch', 'tbsp', 'tsp');
  IF unit_count <> 10 THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema integration: unit seeds are incomplete';
  END IF;

  START TRANSACTION;

  SET openid_one = CONCAT('jarvis_mysql_integration_', UUID());
  SET openid_two = CONCAT('jarvis_mysql_integration_', UUID());
  INSERT INTO users (openid) VALUES (openid_one);
  SET user_one = LAST_INSERT_ID();
  INSERT INTO users (openid) VALUES (openid_two);
  SET user_two = LAST_INSERT_ID();

  INSERT INTO households (name, owner_user_id, invite_code_hash)
    VALUES ('Integration Household One', user_one, SHA2(UUID(), 256));
  SET household_one = LAST_INSERT_ID();
  INSERT INTO households (name, owner_user_id, invite_code_hash)
    VALUES ('Integration Household Two', user_two, SHA2(UUID(), 256));
  SET household_two = LAST_INSERT_ID();

  INSERT INTO household_members (household_id, user_id, role)
    VALUES (household_one, user_one, 'owner');

  BEGIN
    DECLARE CONTINUE HANDLER FOR 1062 SET duplicate_membership_rejected = TRUE;
    INSERT INTO household_members (household_id, user_id, role)
      VALUES (household_two, user_one, 'member');
  END;
  IF duplicate_membership_rejected = FALSE THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema integration: one user joined two households';
  END IF;

  INSERT INTO household_members (household_id, user_id, role)
    VALUES (household_two, user_two, 'owner');
  INSERT INTO recipes (household_id, created_by, name, servings)
    VALUES (household_one, user_one, 'Integration Recipe', 1);
  SET recipe_one = LAST_INSERT_ID();
  INSERT INTO ingredients (household_id, name, normalized_name, default_unit_code, created_by)
    VALUES (household_two, 'Integration Ingredient', 'integration ingredient', 'g', user_two);
  SET ingredient_two = LAST_INSERT_ID();

  BEGIN
    DECLARE CONTINUE HANDLER FOR 1452 SET cross_household_reference_rejected = TRUE;
    INSERT INTO recipe_ingredients (household_id, recipe_id, ingredient_id, quantity, unit_code)
      VALUES (household_one, recipe_one, ingredient_two, 1, 'g');
  END;
  IF cross_household_reference_rejected = FALSE THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema integration: cross-household composite reference was accepted';
  END IF;

  ROLLBACK;

  IF EXISTS (
    SELECT 1 FROM users
    WHERE openid IN (openid_one, openid_two)
  ) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'schema integration: rollback left fixture users behind';
  END IF;
END//
DELIMITER ;

CALL jarvis_run_schema_integration_tests();
DROP PROCEDURE IF EXISTS jarvis_run_schema_integration_tests;

SELECT 'mysql integration tests passed' AS result;
