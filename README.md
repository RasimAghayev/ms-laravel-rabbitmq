# ms-laravel-rabbitmq

![Views](https://hits.sh/github.com/RasimAghayev/ms-laravel-rabbitmq.svg?label=views&color=0e75b6)
[![CI](https://github.com/RasimAghayev/ms-laravel-rabbitmq/actions/workflows/ci.yml/badge.svg)](https://github.com/RasimAghayev/ms-laravel-rabbitmq/actions/workflows/ci.yml)

Two independent Laravel apps — `admin/` (write side) and `main/` (read
side) — kept in sync via RabbitMQ. `admin` owns the `products` REST API;
every create/update/delete both writes to `admin`'s own database and
publishes a queue job describing the change. `main` runs a queue worker
that consumes those jobs and replays the same change into its own,
separate database. There is no synchronous call between the two apps —
`main` only ever learns about a change by consuming it from the queue.

This is a plain **queue-based replication** pattern, not an event-sourced
or CQRS system — there's a single mutable `products` table on each side,
no event log, no separate read/write schema. Calling it "microservices"
is accurate in the narrow sense of "two independently deployable Laravel
apps," not in the sense of bounded contexts or independent domain models
— both sides model the same `Product` shape.

## How the sync actually works

`admin/app/Http/Controllers/ProductController.php` writes to `admin`'s
own `products` table, then dispatches one of `App\Jobs\{ProductCreated,
ProductUpdated,ProductDeleted}` onto the shared `default` RabbitMQ queue
(`vladimir-yuldashev/laravel-queue-rabbitmq`). `main` declares job
classes with the **same three fully-qualified names** in its own
`App\Jobs` namespace — Laravel's queue payload carries the class name as
a string, so when `main`'s worker deserializes a message, it resolves
against its *own* copy of that class, not admin's. `admin`'s own copies
of those three classes have deliberately empty `handle()` bodies (they're
only ever dispatched, never consumed locally); `main`'s copies contain
the real `Product::create/update/destroy` logic. Same class name, two
different implementations, one shared queue — this is how eventual
consistency happens here, and it's why the job class names must stay
identical between the two apps if either is changed.

This was verified end-to-end in Docker (`POST`/`PUT`/`DELETE` against
`admin`'s API, confirmed via `main`'s own database state and the
worker's log output after each step). An earlier pass had already run
this once, but only from a workstation with a `vendor/` already
installed locally — on a genuinely fresh clone, the containers
crash-looped on a missing autoloader (see the fix below). That gap is
now closed and this was re-verified from a truly fresh clone, following
exactly the steps below with no manual step outside them.

## Running it

```bash
cp .env.example .env
# fill in MYSQL_ROOT_PASSWORD, ADMIN_DB_PASSWORD, MAIN_DB_PASSWORD,
# ADMIN_APP_KEY, MAIN_APP_KEY, INTERNAL_SYNC_TOKEN
# (.env.example explains how to generate the keys/token)

make verify   # build + Docker test stage for both apps - must be green first
make up       # docker compose up -d --build
make migrate  # runs `artisan migrate --force` in both admin_app and main_app
```

| Service | URL | Purpose |
|---|---|---|
| `admin_app` | http://localhost:8000/api/products | Write-side REST API (`GET`/`POST`/`PUT`/`DELETE`) |
| `main_app` | http://localhost:8001 | Read-side app (no product-specific routes beyond the Laravel default) |
| `rabbitmq` management UI | http://localhost:15672 | Queue inspection (same user/pass as `RABBITMQ_USER`/`RABBITMQ_PASSWORD`) |
| `main_worker` | — (no port) | Runs `php artisan queue:work rabbitmq`, consumes `admin`'s jobs into `main`'s database |

`admin_db` and `main_db` are separate MySQL instances (`admin`'s
data and `main`'s replica are never in the same database) — reachable
from the host on `33063` and `33064` respectively if you need to inspect
them directly.

Both apps also expose:

- `GET /health/live` — always `200`, "is the process up."
- `GET /health/ready` — `200` if the app can reach its own database, else
  `503`. This is what each container's own Docker `HEALTHCHECK` probes.
- `admin` only: `GET /api/internal/consistency/products`, guarded by a
  shared-secret `X-Internal-Token` header (`INTERNAL_SYNC_TOKEN`) — polled
  every 15 minutes by `main`'s `sync:reconcile` artisan command to detect
  replication drift between the two databases. See `docs/ARCHITECTURE.md`
  ("Drift detection") for why this exists and what it does (and does not)
  do about a mismatch it finds.

## What changed in this modernization pass

The application code (`admin/`, `main/`) existed only on unmerged
feature branches (`ms-admin`, `ms-main`, `RabbitMQ`,
`Data_Consistency_Between_Microservices`) for its entire history —
`master` had nothing but a Docker/Nginx scaffold with no `composer.json`
and no way to build. This pass:

- Merged the fully-consolidated feature line
  (`Data_Consistency_Between_Microservices`, itself a strict superset of
  the other three branches) into `master`.
- Bumped both apps from Laravel 9.19 / PHP 8.1 (end-of-life) to
  **Laravel 13.32 / PHP 8.5** — verified against live Packagist data,
  not assumed: as of this writing, Laravel 12.x's bug-fix window has
  already closed and PHP 8.5 is the only line with a full
  (non-security-only) support window past 2026. Every third-party
  dependency (`laravel/sanctum`, `vladimir-yuldashev/laravel-queue-rabbitmq`,
  `barryvdh/laravel-ide-helper`, etc.) was confirmed to have a
  Laravel-13-compatible release before the jump.
- Replaced the third-party `fhsinchy/php-nginx-base` tutorial image
  (pinned to PHP 8.1.3) with official `php:8.5-fpm-alpine` in both
  apps' Dockerfiles.
- Replaced the root-level Dockerfile/`docker-compose.yaml` — which
  never built, since no `composer.json` existed at the repo root — with
  a real 5-service `docker-compose.yaml` (`admin_db`, `main_db`,
  `rabbitmq`, `admin_app`, `main_app`, `main_worker`). Previously,
  nothing anywhere in this repo ever ran a queue worker, so `main`'s
  side of the sync had never actually executed.
- Rotated the hardcoded `APP_KEY` and `root`/`root` MySQL credentials
  that were committed in plaintext (both in `docker-compose.yaml` and
  in each app's `.env.example`) to environment-variable placeholders —
  see `.env.example`.
- Fixed `RABBITMQ_VHOST=guest` → `RABBITMQ_VHOST=/` in both apps'
  `.env.example` (`guest` is RabbitMQ's default *username*, not a
  vhost; a fresh broker has no vhost named `guest`, so the AMQP
  connection would have failed for anyone following the example file).
- Fixed a fresh-clone bootstrap bug: the dev containers bind-mount
  `./admin:/var/www` and `./main:/var/www`, which replaces each
  image's own built `vendor/` with whatever is on the host — nothing,
  on a fresh clone, since `vendor/` is gitignored. The dev entrypoints
  only ran `composer dump-autoload` (regenerate from an *existing*
  `vendor/`), never `composer install`, so the steps above previously
  crash-looped all three app containers unless you'd already run
  `composer install` locally first, outside the documented flow. The
  entrypoints now install automatically when `vendor/` is missing.
  `main_app` and `main_worker` share the same mount; only `main_app`
  installs, `main_worker` just waits for it rather than racing a
  second install against the same files.
- **Container baseline (Phase 5):** both Dockerfiles rebuilt as
  explicit multi-stage `base -> deps/test-deps -> build -> test ->
  runtime` images, non-root at runtime, with a real Docker `test`
  stage that actually gates (`make verify` = build + test; `make up`
  depends on it). Removed `admin/docker-compose.yaml` and
  `main/docker-compose.yaml` — stale duplicates of this file from the
  repo's first commit that this modernization pass's "rotated the
  hardcoded `APP_KEY`/`root` credentials" bullet above had missed;
  both still carried the original plaintext values. See
  `docs/BASELINE.md` for full evidence and the secrets finding.

## Distributed-systems hardening pass

On top of the container baseline (Phase 5) and the dependency/test work
above, this pass addressed the gaps a replicated, horizontally-scalable
system actually needs to not silently corrupt data or go dark without
anyone noticing:

- **Idempotent, order-tolerant replication** — `main`'s job handlers now
  go through `App\Services\ProductSynchronizer`, which upserts under a
  row lock with last-write-wins semantics instead of a plain
  create/find-then-update that crashed on a redelivered or out-of-order
  RabbitMQ message. See `docs/ARCHITECTURE.md`.
- **Drift detection** — `admin`'s internal consistency endpoint plus
  `main`'s scheduled `sync:reconcile` command (above).
- **Health/readiness probes** wired into both Dockerfiles'
  `HEALTHCHECK` (above), so an app that's up but can't reach its own
  database is reported unhealthy instead of "fine" because nginx
  answers.
- **Latest stable, verified against the real registries at run time**:
  `laravel/framework` v13.44.1, `laravel/sanctum` v4.5.0,
  `laravel/tinker` v3.2.0, `phpunit/phpunit` 13.4.8,
  `nunomaduro/collision` v8.9.6, and a `phpseclib/phpseclib` CVE fix
  (3.0.59) that was the actual root cause of recent CI failures.
- **Dependabot/Renovate cleanup** — the committed `dependabot.yml`
  pointed at the repo root, where neither app's manifest lives, and
  duplicated an already-working Renovate installation; replaced with a
  single policy-compliant `renovate.json`. See `docs/TECHNICAL_DEBT.md`
  #15.
- **Removed unused frontend scaffolding** (`package.json`,
  `vite.config.js`, `resources/{js,css}`) in both apps — default
  `laravel new` output never built or referenced by either Dockerfile or
  blade view. See `docs/TECHNICAL_DEBT.md` #16.
- **Dead code removed**: `TestJob.php` (both apps), the `fire` artisan
  command that only existed to dispatch it, and `commond.txt` (the
  author's own shell-history scratch file).
- **Multi-agent-safe Compose file** — removed hardcoded `container_name:`
  overrides so Compose's own project-name prefixing (already set up via
  `name: ms-laravel-rabbitmq-p5`) actually isolates concurrent stacks of
  this repo instead of colliding on fixed container names.
- **3-branch model adopted** (`preview -> test -> main`) — see
  `CONTRIBUTING.md`.

## Known issues (flagged, not fixed)

- Neither app has integration tests covering the actual queue-sync
  flow end-to-end through a real RabbitMQ broker — the unit/feature
  tests exercise `ProductSynchronizer`/the job handlers directly, not a
  real publish-then-consume round trip. The flow was verified manually
  in Docker for the original modernization pass (see above), not via an
  automated integration test.
- `ProductDeleted` has no tombstone — see `docs/ARCHITECTURE.md`'s
  "Idempotent replication" section for the specific, accepted gap this
  leaves.
- PHP 8.6 (latest stable minor) is not yet adopted — see
  `docs/TECHNICAL_DEBT.md` #21.
