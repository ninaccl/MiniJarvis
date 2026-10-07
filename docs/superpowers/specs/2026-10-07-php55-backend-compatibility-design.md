# PHP 5.5.30 Backend Compatibility Design

## Goal and scope

Run the complete existing backend API and both scheduled CLI programs on PHP 5.5.30. Preserve the mini program's HTTP routes, request and response payloads, authentication behavior, tenant isolation, and existing business rules. PHP 8 compatibility is not a requirement. The user's change to `miniprogram/env.js` is outside this work.

This is a source port, not a generated PHP 5.5 deployment artifact. All production PHP files under `backend/src`, `backend/public`, and `backend/bin` must parse on PHP 5.5.30. PHP 5.5 verification must exercise the production code directly; the existing PHPUnit 10 suite is a reference for expected behavior, not a required runtime gate.

## Runtime and bootstrap

Replace the production entrypoints' dependency on Composer's generated autoloader and `vlucas/phpdotenv` with a small first-party PSR-4 loader and `.env` reader. The reader must retain current configuration semantics, avoid overwriting existing environment values, and report malformed configuration without exposing secrets. Composer and PHPUnit may remain development tools; production upload must not require `vendor/`.

Provide narrowly scoped compatibility helpers for functions absent in PHP 5.5. Cryptographic random generation must use a secure source available on the host and fail closed if none exists. JSON encode/decode must check `json_last_error()` in place of `JSON_THROW_ON_ERROR`. Replace high-resolution timing with `microtime(true)` while preserving timeout behavior. Do not silently weaken URL validation, authorization, or tenant checks.

## Source conversion

Convert PHP 7/8-only syntax throughout production files: scalar, nullable, union, return, and property types; constructor property promotion and `readonly`; arrow functions; null coalescing and null-safe access; newer destructuring and spread syntax; unsupported constant expressions; and unsupported catch forms. Retain class and interface type hints that PHP 5.5 supports. Declare object properties explicitly and preserve constructor behavior. Keep every repository interface and implementation signature mutually compatible after conversion.

Replace PHP 7/8 runtime APIs only where used. Handle `Exception` on PHP 5.5 without changing public error envelopes. Avoid broad polyfills that mask failures or alter standard functions globally.

## Data and deployment boundary

Remote verification found PHP 5.5.30 and MySQL 5.7.25-log. The host's older MySQL client cannot connect when `charset=utf8mb4` appears in the PDO DSN, and PHP 5.5 cannot read MySQL JSON column metadata directly. Connect first, run `SET NAMES utf8mb4`, and cast JSON fields to text in SELECT queries. Make the initialization script valid on MySQL 5.7, without running it against the existing production database. Remote verification confirmed `curl`, `fileinfo`, `json`, `mbstring`, `PDO`, and `pdo_mysql`, but `proc_open` is disabled. DNS resolution therefore needs an in-process fallback that keeps the same public-address validation; the host resolver controls DNS wait time.

The hosting account also serves an older site. Limit deployment changes to `/htdocs/backend`; do not change the host-wide PHP version or other site files. Preserve the user's existing `.env`, uploads, and live data. Production deployment requires a separate review after the source port passes local checks.

`www.sunhx.cn` is the public production domain. Verify public HTTP endpoints through it; use the separately supplied temporary FTP host only for file transfer.

## Verification and acceptance

1. Lint every production PHP file with PHP 5.5.30.
2. Add PHP 5.5 smoke coverage for bootstrap, health, login, household membership, recipe list/create/read, and representative inventory, meal plan, shopping, task, notification, upload, and link-preview paths.
3. Exercise scheduled CLI programs on PHP 5.5 against a disposable database and file store.
4. Verify the actual `GET /api/v1/health` and authenticated recipe list on the PHP 5.5 host after a reviewed deployment; check other API groups before declaring the complete port successful.

Success means the full API has no PHP version parse/runtime failures on 5.5.30 and retains its existing contract. A remaining database, PHP extension, or hosting restriction must be reported with its exact evidence rather than described as a completed port.
