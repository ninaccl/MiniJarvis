# Jarvis Family Kitchen

Jarvis Family Kitchen is split into two independent applications:

- `backend/` is a framework-free PHP 8.2 JSON API.
- `miniprogram/` is the WeChat mini-program and manages its own dependencies and build configuration.
- `database/` contains the MySQL 8.0 schema shared by backend feature modules.

The backend does not build, serve, or import code from `miniprogram/`. See [backend/README.md](backend/README.md) for API setup and [database/README.md](database/README.md) for schema conventions.

## Quick start

1. Install MySQL 8.0 and PHP 8.2 with `curl`, `json`, `mbstring`, `PDO`, and `pdo_mysql`.
2. Apply `database/init.sql` with an account that can create databases.
3. Copy `backend/.env.example` to `backend/.env` and fill in secrets.
4. Run `composer install` in `backend/`.
5. For development, run `php -S 127.0.0.1:8080 -t public` from `backend/`.

All instants and deadlines cross the API boundary as UTC. Household calendar dates (meal dates and inventory expiry dates) use `Asia/Shanghai`. All `/api/v1` responses use a stable success or error envelope.
