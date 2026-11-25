# Architecture

## Overview

Two independently deployable Laravel apps:

- **`admin/`** — write side. Owns the `products` REST API (`GET`/`POST`/`PUT`/`DELETE` via `Route::apiResource('products', ProductController::class)`). Every mutation writes to `admin`'s own MySQL database, then dispatches a queue job describing the change.
- **`main/`** — read side. Runs a dedicated queue worker (`main_worker`) that consumes those jobs and replays the change into `main`'s own, separate MySQL database. `main` has no product-specific HTTP routes.

There is no synchronous call between the two apps. `main` only ever learns about a change by consuming it from RabbitMQ, via `vladimir-yuldashev/laravel-queue-rabbitmq`.

This is a **queue-based replication** pattern — one mutable `products` table on each side, no event log, no separate read/write schema, no reconciliation/retry-on-conflict logic. Calling the two apps "microservices" is accurate only in the narrow sense of "independently deployable" — both model the identical `Product` shape, so this is not CQRS or event sourcing.

## The same-class-name sync mechanism

`admin/app/Jobs/{ProductCreated,ProductUpdated,ProductDeleted}.php` and `main/app/Jobs/{ProductCreated,ProductUpdated,ProductDeleted}.php` declare **identical fully-qualified class names** (`App\Jobs\ProductCreated`, etc.) in each app's own codebase, but with different `handle()` bodies:

- `admin`'s copies: `handle()` is empty. These jobs are only ever dispatched from `ProductController`, never consumed locally.
- `main`'s copies: `handle()` contains the real `Product::create()` / `Product::update()` / `Product::destroy()` logic against `main`'s own database.

Laravel's queue serializes a job payload with the dispatching class's fully-qualified name as a string. When `main_worker` deserializes a message pulled from the shared RabbitMQ `default` queue, it resolves that string against **its own** `App\Jobs\ProductCreated` class — not `admin`'s. This is why the job class names (and constructor argument shape) must stay identical between the two apps if either side changes: there is no shared package, no shared namespace, just convention.

## Request flow

1. Client calls `admin`'s API (e.g. `POST /api/products` with `title`/`image`).
2. `ProductController::store()` writes the row to `admin`'s `products` table, then calls `ProductCreated::dispatch($product->toArray())`.
3. The job payload is published to the RabbitMQ `default` queue.
4. `main_worker` (bare `php artisan queue:work rabbitmq --queue=default --tries=3 --sleep=3`, no supervisord) picks up the message, resolves it against `main`'s `App\Jobs\ProductCreated`, and runs its `handle()` — writing the same row into `main`'s `products` table.
5. `admin` never sees `main`'s write; `main` never reads from `admin`'s database. The only coupling is the shared queue and the shared job-class contract.

Verified directly (2026-11-03): a full `POST`/`PUT`/`DELETE` cycle against `admin`'s API produced matching row creation, update, and deletion in `main`'s database, with `main_worker`'s log showing each job (`ProductCreated`/`ProductUpdated`/`ProductDeleted`) running and completing. See `REPOSITORY_AUDIT.md` for how this was verified and why it wasn't reproducible out of the box.

## Infrastructure

Docker Compose, 6 runtime services plus the Docker standard's `test` profile (`admin_test`/`main_test`, never started by `make up`):

| Service | Role |
|---|---|
| `admin_db`, `main_db` | Separate MySQL instances (latest stable, pinned by digest — see the Dockerfiles) — `admin`'s data and `main`'s replica are never in the same database. |
| `rabbitmq` | Message broker; management UI on `15672`. |
| `admin_app` | nginx + php-fpm under supervisord, running the built `runtime` image (no bind mount — what's deployed is exactly what `make verify`'s `test` stage already built and tested). Serves the REST API and `/health/{live,ready}` on `8000`. |
| `main_app` | Same shape as `admin_app`, port `8001`. No product routes beyond `/health/{live,ready}` — also runs the Laravel scheduler (`schedule:work`, see Drift detection below) as a third supervisord program. |
| `main_worker` | Runs the built `runtime` image's `php artisan queue:work rabbitmq --queue=default --tries=3 --sleep=3` via a compose `command:` override; healthchecked via a `pgrep` process check rather than the image's own HTTP healthcheck (nginx never starts in this service). |

Container baseline (Phase 5) replaced the original dev bind-mounts (`./admin:/var/www`, `./main:/var/www`) with the multi-stage `base -> deps/test-deps -> build -> test -> runtime` pattern — the running container is the tested image, not a live host checkout reinstalled at container start. `docker-compose.yaml` relies on Compose's own `name:`-based project-name prefixing for container naming (no `container_name:` overrides), so the same repo can run multiple isolated stacks (e.g. two phase agents, or a dev stack alongside a test run) without name collisions.

## Idempotent replication (`ProductSynchronizer`)

RabbitMQ gives **at-least-once** delivery, not exactly-once: a message can be redelivered after an ack is lost, a worker crash mid-job, or a `--tries` retry following a transient failure. The original `main`-side job handlers did not account for this — `ProductCreated::handle()` called plain `Product::create()`, which throws on a redelivered message's duplicate primary key, and `ProductUpdated::handle()` called `Product::find($id)->update(...)` with no null check, which fatally errors if the update arrives before its own create (a real possibility once a message is requeued past a worker that's already picked up a later one). Both failure modes permanently broke replication for the affected product once retries were exhausted into `failed_jobs` (see `docs/TECHNICAL_DEBT.md` #10/#11, now resolved).

`main/app/Services/ProductSynchronizer.php` replaces both handlers with one idempotent routine, `syncFromPayload()`:

1. `DB::transaction()` + `lockForUpdate()` on the target row serializes concurrent writes to the *same* product across multiple `main_worker` replicas (the horizontal-scaling case).
2. The incoming payload's `updated_at` (set once, by `admin`, the single writer) is compared against what's already stored — last-write-wins. No vector clocks or sequence numbers are needed because there is exactly one authority for "when did this change." A stale/duplicate/out-of-order redelivery is recognized and skipped rather than clobbering fresher data.
3. `Product::upsert()` (`INSERT ... ON DUPLICATE KEY UPDATE`) applies the write as a single atomic statement.

**Known, accepted limitation:** `ProductDeleted` is still a plain `Product::destroy()` with no tombstone. If a `Created`/`Updated` for the same product is redelivered *after* its `Deleted` has already run, the synchronizer has no record the product was ever deleted and will resurrect it. A tombstone table would close this window but was judged disproportionate to this repo's scope (KISS/YAGNI) — documented as a real, intentional gap rather than silently left unexamined.

## Drift detection (reconciliation)

At-least-once delivery plus the limitation above means `admin` and `main` can, in principle, quietly diverge with nothing in either app's logs to show it. Rather than trying to make delivery exactly-once (disproportionate to this repo's scope), both apps independently expose the same cheap drift check:

- `App\Services\ProductFingerprint::compute()` (identical implementation in both apps, same convention as the job classes — no shared package between two independently deployable apps) computes `count` plus an order-independent SHA-256 checksum of every `id:updated_at` pair in its own `products` table.
- `admin` exposes this at `GET /api/internal/consistency/products`, guarded by `App\Http\Middleware\VerifyInternalToken` — a shared-secret (`X-Internal-Token` header, compared with `hash_equals()`) rather than full auth, proportionate to this being service-to-service traffic under one operator's control (the public products API itself has no auth either; see `docs/TECHNICAL_DEBT.md`).
- `main`'s `App\Console\Commands\ReconcileProductsCommand` (`sync:reconcile`, scheduled every 15 minutes via `main`'s own `schedule:work` supervisord program) computes its own fingerprint, calls `admin`'s endpoint, and compares. A mismatch is logged as a warning (`Log::warning`, with both sides' count/checksum) and the command exits non-zero — it deliberately does **not** attempt to resolve the drift itself (that would mean either replaying history this architecture never stores, or a full resync, a product-level decision), only raise the alarm.

## Health checks

Both apps expose liveness (`/health/live`, always 200 — "is the process up") separately from readiness (`/health/ready`, 200 only if `DB::connection()->getPdo()` succeeds, else 503 — "can it actually serve a request"). Each Dockerfile's own `HEALTHCHECK` probes `/health/ready`, so a container that's running but can't reach its database is correctly reported unhealthy by `docker inspect`/Compose, not just "it answers HTTP so it must be fine."
