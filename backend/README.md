# Backend API

Native PHP 8.2, PDO, and a small front controller provide the API without a framework or ORM. Composer supplies PSR-4 autoloading and loads `vlucas/phpdotenv`; PHPUnit is development-only.

Complete frontend-facing endpoint/payload contracts are in [API.md](API.md). Production TLS/FPM permissions, cron, local mock login and WeChat setup are in [deploy/README.md](deploy/README.md), with [nginx.conf.example](deploy/nginx.conf.example).

## Requirements

- PHP 8.2+
- PHP extensions: `curl`, `fileinfo`, `json`, `mbstring`, `PDO`, `pdo_mysql`
- Composer 2
- MySQL 8.0 using `utf8mb4`
- Nginx and PHP-FPM for production
- An executable PHP CLI binary and enabled `proc_open` for deadline-bounded DNS resolution

## Setup

```sh
cp .env.example .env
composer install
mysql -u root -p < ../database/init.sql
php -S 127.0.0.1:8080 -t public
```

The application sets every PDO connection to UTC. Configure the database host, credentials, calendar timezone, and WeChat application credentials in `.env`; never commit that file. A deployment example is in `deploy/nginx.conf`.

## Time convention

`CALENDAR_TIMEZONE` defaults to and should remain `Asia/Shanghai` for this product. Date-only values such as `meal_plan_entries.meal_date` and `inventory_batches.expires_on` are household calendar dates interpreted in Asia/Shanghai. Instants and deadlines—including `due_at`, `scheduled_for`, session expiry, creation/update times, and notification send times—are normalized and stored in UTC. Later services must convert a local calendar boundary or local 09:00 schedule to a UTC instant before persistence.

`mbstring` supplies character-aware nickname and household-name limits for Chinese text. `App\Support\Text` retains a Unicode regex fallback for defensive portability, although `ext-mbstring` is a declared runtime requirement.

## Authentication

`POST /api/v1/auth/wechat` accepts:

```json
{"code":"WeChat login code","nickname":"optional","avatar_url":"https://optional.example/avatar.png"}
```

In production and other non-local environments, the code is exchanged with WeChat's `jscode2session` endpoint. Its `session_key` is deliberately discarded and is never stored or returned. The API issues a cryptographically random 256-bit Bearer token, stores only its SHA-256 hash, and expires the session after 30 days.

Only when `APP_ENV=local`, a stable code such as `dev:alice` is accepted without contacting WeChat. The stable-id portion is limited to 124 ASCII characters so the complete local openid fits the 128-byte database column. Any `dev:` code is rejected outside local mode.

Pass the API token as `Authorization: Bearer <token>`. The backend intentionally sends no CORS headers; browser cross-origin policy should be handled only if a future browser client is explicitly introduced.

## Routes

| Method | Path | Access | Purpose |
| --- | --- | --- | --- |
| GET | `/api/v1/health` | Public | Process/database bootstrap health |
| POST | `/api/v1/auth/wechat` | Public | Exchange login code and issue session |
| POST | `/api/v1/households` | Bearer | Create a household and receive its invite once |
| POST | `/api/v1/households/join` | Bearer | Join using `{invite_code}` |
| GET | `/api/v1/households/current` | Member | Get the current household and members |
| POST | `/api/v1/households/invite/reset` | Owner | Rotate and receive the invite once |
| DELETE | `/api/v1/households/members/{userId}` | Owner | Remove a non-owner member |
| GET | `/api/v1/categories` | Member | List seeded recipe categories |
| GET, POST | `/api/v1/recipes` | Member | Search/list or create recipes |
| GET, PATCH, DELETE | `/api/v1/recipes/{id}` | Member | Read, atomically replace, or soft-delete a recipe |
| POST | `/api/v1/uploads/images` | Member | Upload a MIME-sniffed JPEG/PNG/WebP cover up to 5 MiB |
| POST | `/api/v1/link-previews` | Member | Fetch a guarded best-effort external-link preview |
| GET | `/api/v1/link-previews/{token}/image` | Creating member | Read an unexpired temporary preview image |
| POST | `/api/v1/link-previews/{token}/adopt` | Creating member | Adopt a temporary preview image once |
| GET | `/api/v1/inventory?q=&status=all\|active\|expiring\|expired` | Member | List household inventory batches |
| POST | `/api/v1/inventory/batches` | Member | Create a batch and its initial movement |
| PATCH | `/api/v1/inventory/batches/{id}` | Member | Update expiry date and/or note |
| POST | `/api/v1/inventory/batches/{id}/movements` | Member | Add, consume, or set batch stock |
| GET | `/api/v1/inventory/movements?page=&page_size=` | Member | Read immutable movement history |
| GET | `/api/v1/recipes/matches?count=` | Member | Greedily match 1–10 recipes (default 2) |
| GET, POST | `/api/v1/tasks` | Member | Filter household tasks or create a task/subtask |
| GET, PATCH, DELETE | `/api/v1/tasks/{id}` | Member | Read, update/link completion, or cascade-delete a task |
| GET, PATCH | `/api/v1/notifications/preferences` | Member | Read or update task/expiry notification preferences |
| POST | `/api/v1/notifications/subscription-grants` | Member | Record one WeChat subscription prompt result |

Success responses are `{"success":true,"data":...,"meta":...}` (with optional `meta`). Failures are `{"success":false,"error":{"code":"...","message":"...","fields":...}}` (with optional `fields`). Expected client failures use 401, 403, 404, 409, or 422; unhandled failures use 500 without exposing internals.

Invite codes contain exactly eight characters from `ABCDEFGHJKLMNPQRSTUVWXYZ23456789`. Only their SHA-256 hashes are stored, and plaintext is returned only when a household is created or its invite is reset.

## Extension points

- `App\Http\Router`, `Request`, `Response`, `ApiKernel`, and `ApiException` define the HTTP boundary.
- `App\Database\Connection` exposes PDO and a rollback-safe transaction callback.
- `App\Auth\AuthService`, `AuthMiddleware`, and `AuthContext` provide user, optional household, and role context.
- `App\Household\HouseholdService` owns household rules.
- `App\Household\TenantGuard::requireMembership`, `requireOwner`, and `requireTenant` must gate later household resources. A tenant mismatch deliberately returns 404 to avoid leaking another household's records.

Controllers validate transport types; services enforce domain rules; PDO stores are the persistence boundary. Later modules should preserve this separation.

## Recipe media and link-preview safety

Recipe writes replace ingredients and links in the same transaction as the recipe row. Ingredient identity is the household plus a normalized name (trimmed, Unicode whitespace collapsed, ASCII letters lowercased); Chinese synonyms remain distinct. Deleted recipes keep their row and historical foreign-key identity but are hidden from normal operations.

Uploads ignore original filenames, sniff content with `fileinfo`, and use random names below `public/uploads/YYYY/MM`. The HTTP mover verifies `is_uploaded_file`; tests replace only that movement boundary.

Link previews accept only HTTPS destinations on exact configured hosts or true subdomains. Every page, image, and redirect hop is resolved and all A/AAAA answers must be public; cURL follows no redirects itself and pins each request to the validated answers. `PHP_CLI_BINARY` selects an executable CLI (default `PHP_BINDIR/php`); a no-shell child resolver plus `proc_open` enforces the remaining shared deadline around otherwise-blocking system DNS. Page bodies are capped at 1 MiB, images at 5 MiB, and requests use 3-second connection/8-second total timeouts without cookies or authorization headers. Temporary files are never web-addressable: an authenticated opaque-token API serves them with private caching, creator/household checks, and 24-hour expiry. Run this at least hourly:

```sh
php bin/cleanup-previews.php
```

## Inventory and recipe matching

Inventory stores canonical grams or millilitres for convertible dimensions and preserves exact discrete unit codes. Each batch also retains its creation display quantity/unit. Every stock change locks the household-qualified batch, updates its non-negative canonical quantity, and inserts a signed movement in one transaction. Expiry dates use Shanghai calendar days; zero and expired batches are excluded from availability.

Recipe matching is read-only. It aggregates compatible non-expired batches, scores all remaining active recipes by full match, satisfied ratio, missing base amount, then recipe ID, and virtually consumes stock after each greedy pick. An `适量` ingredient needs positive presence but has no invented numeric depletion.

## Tests

```sh
composer test
```

Tests run real HTTP envelope and service behavior with in-memory stores at the PDO boundary and a fake at the WeChat network boundary.

## Reminder runner

Configure the two WeChat subscription template IDs, their field keys, mini-program target pages, and a non-public token-cache path via the `WECHAT_*` values in `.env`. Run this every five minutes; it is a bounded process, not a daemon:

```sh
*/5 * * * * cd /path/to/backend && /usr/bin/php bin/send-reminders.php
```

The runner groups positive inventory batches expiring today through three days ahead by the Asia/Shanghai calendar and schedules that day's summary for 09:00 local time. Task and job instants remain UTC. A missing one-time grant cancels that job as an observable `skipped_no_grant` outcome without treating it as an operational error.
