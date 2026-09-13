# Database schema

`init.sql` is idempotent and targets MySQL 8.0. It creates `jarvis_family` with `utf8mb4_0900_ai_ci`, creates all tables required by product Tasks 1–5, and upserts reference seeds.

Apply it with an account allowed to create databases:

```sh
mysql -u root -p < init.sql
```

After applying `init.sql`, run the transactional MySQL 8 integration probe. It verifies application tables and seeds, the single-household membership constraint, tenant-aware composite foreign keys, and rollback cleanup:

```sh
mysql -u root -p < tests/mysql-integration.sql
```

## Conventions

- Integer primary keys are unsigned `BIGINT` except small reference identifiers.
- Tenant-owned rows carry `household_id` and a leading tenant index. Every query in later services must scope by the authenticated household; use `TenantGuard` before repository access.
- MySQL `TIMESTAMP(6)` instants and deadlines and the application PDO session use UTC. Date-only `meal_plan_entries.meal_date` and `inventory_batches.expires_on` values are calendar dates interpreted in `Asia/Shanghai`; they must not be shifted as UTC instants.
- Persisted quantities are `DECIMAL`, never floating point. `recipe_ingredients.quantity` is nullable and represents `适量` when its unit is also null. Inventory batch `quantity` is the current canonical base amount and may be zero; the batch remains as inactive history. Inventory movement `quantity` is a signed canonical base delta, while both tables retain the positive submitted display quantity/unit. Meal and shopping quantities are positive and non-null.
- Recipes use `deleted_at` for soft deletion. Queries should default to `deleted_at IS NULL`.
- Task self-parenting and hierarchy depth are rejected transactionally by `TaskService`. MySQL retains the tenant-aware parent foreign key, but does not use a `CHECK` against the auto-increment task id because MySQL 8 rejects that table definition.
- Ingredient deletion is restricted while an ingredient is referenced by recipe usage, inventory batches, or immutable inventory movements. A physical recipe purge cascades only its composition/link children; normal recipe removal remains a soft delete.
- JSON is intentionally limited to `shopping_lists.selection_snapshot` and `notification_jobs.payload_snapshot`. Relational fields remain queryable columns.
- User membership is globally unique in `household_members`, enforcing at most one household per user. Application transactions translate the named membership duplicate constraint to a friendly 409; database constraints remain the final concurrency guard.
- Tenant parent/child relationships use composite foreign keys that include `household_id`. Parent tables expose matching `(household_id, id)` unique keys. Notification rows reference `(household_id, user_id)` membership pairs. Nullable task assignments and shopping-item checker/stocker identities use restrictive composite member foreign keys; the household repository clears both identity columns in the same transaction before deleting a member, without changing task, check, or stock history.
- API session tokens and household invite codes are stored only as SHA-256 hashes.

## Units

Mass units use grams as base (`g=1`, `kg=1000`) and volume units use millilitres (`ml=1`, `l=1000`). The discrete codes `piece`, `pack`, `box`, `bunch`, `tbsp`, and `tsp` all have factor 1, but are not mutually convertible: discrete quantities compare only when their unit codes are identical.

`inventory_batches.quantity/unit_code` and `inventory_movements.quantity/unit_code` are canonical base values. Their `display_quantity/display_unit_code` pairs preserve what the member entered; for `set`, the stored movement quantity is the signed delta from the locked prior value, not the submitted absolute target.

The seeded recipe categories, in order, are: `荤菜`, `素菜`, `汤`, `甜品`, `主食`, `其他`.

## Tenant table map

Tenant-owned tables are `household_members`, `ingredients`, `recipes`, `recipe_ingredients`, `recipe_links`, `link_previews`, `inventory_batches`, `inventory_movements`, `meal_plan_entries`, `shopping_lists`, `shopping_list_items`, `tasks`, `notification_preferences`, `notification_grants`, and `notification_jobs`. Child rows repeat `household_id` only where it participates in tenant-aware composite foreign keys and tenant-leading indexes. `recipe_categories` and `units` are global seeded reference data.
