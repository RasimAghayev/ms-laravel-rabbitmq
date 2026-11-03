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

Docker Compose, 6 services:

| Service | Role |
|---|---|
| `admin_db`, `main_db` | Separate MySQL 8.4 instances — `admin`'s data and `main`'s replica are never in the same database. |
| `rabbitmq` | Message broker; management UI on `15672`. |
| `admin_app` | nginx + php-fpm under supervisord, entrypoint `docker-php-entrypoint-dev`, dev bind-mount `./admin:/var/www`. Serves the REST API on `8000`. |
| `main_app` | Same shape as `admin_app`, bind-mount `./main:/var/www`, port `8001`. No product routes — exists for migrations/tinker access and any future read-side API. |
| `main_worker` | Bare `php artisan queue:work rabbitmq ...`, bind-mount `./main:/var/www`, **no entrypoint override, no supervisord** — just the one worker process as PID 1. |

The dev bind-mounts are intentional for active development (edit on the host, see it live in the container) but they fully replace the image's own build output at `/var/www`, including its `vendor/`. See `REPOSITORY_AUDIT.md` for the consequence of that design on a fresh clone.
