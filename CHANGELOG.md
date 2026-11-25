# Changelog

All notable changes to this project are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/); this project
does not use semantic version tags (see `SECURITY.md`), so entries are
grouped by date instead of version number.

## 2026-11-25

### Added

- Idempotent, order-tolerant replication: `main/app/Services/ProductSynchronizer.php`, used by both `ProductCreated::handle()` and `ProductUpdated::handle()` in place of the previous plain create/find-then-update.
- Drift detection between `admin` and `main`: `App\Services\ProductFingerprint` (both apps), `admin`'s internal-only `GET /api/internal/consistency/products` endpoint (guarded by `App\Http\Middleware\VerifyInternalToken`, a shared-secret header check), and `main`'s scheduled `sync:reconcile` artisan command (every 15 minutes, via `main`'s new `schedule:work` supervisord program).
- Liveness/readiness health endpoints in both apps: `GET /health/live`, `GET /health/ready` (checks DB connectivity). Both Dockerfiles' `HEALTHCHECK` now probes `/health/ready` instead of `/`.
- Request validation on `admin`'s `ProductController::store()`/`update()` (`title`/`image` required), and `findOrFail()` on `show()`/`update()` — see `docs/TECHNICAL_DEBT.md` #10.
- `renovate.json` at repo root, replacing the broken `.github/dependabot.yml` — see `docs/TECHNICAL_DEBT.md` #15.
- `CODEOWNERS`, `SUPPORT.md`, this `CHANGELOG.md`.

### Changed

- `laravel/framework` -> v13.44.1, `laravel/sanctum` -> v4.5.0, `laravel/tinker` -> v3.2.0, `phpunit/phpunit` -> 13.4.8, `nunomaduro/collision` -> v8.9.6 (both apps, verified against Packagist at run time).
- Docker base images refreshed to their current digests: `php:8.5-fpm-alpine`, `composer:2`, `mysql:26.10`; `rabbitmq` moved from `4.4.0-management-alpine` to `4.4.1-management-alpine`.
- `docker-compose.yaml`: removed hardcoded `container_name:` on every service so Compose's own project-name prefixing isolates concurrent stacks of this repo.
- `.github/workflows/ci.yml` triggers widened from `main`-only to `[preview, test, main]` to support the newly-adopted 3-branch model.
- `ACCESSIBILITY.md` rewritten to accurately describe a JSON-API-only project (the previous version claimed browser/screen-reader testing that never happened — see `docs/TECHNICAL_DEBT.md` #19).
- `README.md`'s stale "no LICENSE file" claim removed (`LICENSE.md`, MIT, already exists); `CONTRIBUTING.md`'s branch instructions updated from `main` to `preview`.

### Fixed

- `phpseclib/phpseclib` CVE (GHSA-w3p9-g4p2-h3rr) — the actual root cause of recent CI failures — fixed by updating to 3.0.59 in both apps' lockfiles.
- `main/app/Jobs/ProductUpdated.php::handle()`'s fatal error on a missing product (docs/TECHNICAL_DEBT.md #11), and the mirror-image duplicate-key crash in `ProductCreated::handle()` on message redelivery — both resolved by the new `ProductSynchronizer`.

### Removed

- Unused default `laravel new` frontend scaffolding in both apps: `package.json`, `vite.config.js`, `resources/{js,css}/*` — never built or referenced by either Dockerfile or blade view (`docs/TECHNICAL_DEBT.md` #16).
- Dead code: `admin/app/Jobs/TestJob.php`, `main/app/Jobs/TestJob.php`, `admin/app/Console/Commands/FireEvent.php` (the only thing that dispatched `TestJob`), `commond.txt`.
- `.github/dependabot.yml` (broken — pointed at the repo root, where no manifest exists; superseded by `renovate.json`).

### Considered, not applicable (standing decision 17)

- PHP 8.6 (latest stable minor) was pulled and its digest confirmed, but the upgrade from 8.5 is deliberately deferred to its own isolated, verified step rather than bundled here — see `docs/TECHNICAL_DEBT.md` #21.
