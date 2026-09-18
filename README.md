# ms-laravel-rabbitmq

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

This was verified end-to-end in Docker for this task (`POST`/`PUT`
/`DELETE` against `admin`'s API, confirmed via `main`'s own database
state and the worker's log output after each step) — the first time
this flow has ever actually been run, going by this repo's git history.
It previously existed only as application code on unmerged branches with
no working Docker path to stand it up.

## Running it

```bash
cp .env.example .env
# fill in MYSQL_ROOT_PASSWORD, ADMIN_DB_PASSWORD, MAIN_DB_PASSWORD,
# ADMIN_APP_KEY, MAIN_APP_KEY (.env.example explains how to generate the keys)

docker compose up -d --build
docker compose exec admin_app php artisan migrate --force
docker compose exec main_app php artisan migrate --force
```

| Service | URL | Purpose |
|---|---|---|
| `admin_app` | http://localhost:8000/api/products | Write-side REST API (`GET`/`POST`/`PUT`/`DELETE`) |
| `main_app` | http://localhost:8001 | Read-side app (no product-specific routes beyond the Laravel default) |
| `rabbitmq` management UI | http://localhost:15672 | Queue inspection (same user/pass as `RABBITMQ_USER`/`RABBITMQ_PASSWORD`) |
| `main_worker` | — (no port) | Runs `php artisan queue:work rabbitmq`, consumes `admin`'s jobs into `main`'s database |

`admin_db` and `main_db` are separate MySQL 8.4 instances (`admin`'s
data and `main`'s replica are never in the same database) — reachable
from the host on `33063` and `33064` respectively if you need to inspect
them directly.

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

## Known issues (flagged, not fixed)

- **No `LICENSE` file** anywhere in the repository — outside this
  task's scope; a licensing decision for the repo owner.
- **`commond.txt`** — the author's own local shell-command scratch
  notes from originally building the app (`composer create-project`,
  `artisan make:*`). Not documentation, left as-is; harmless but not
  cleaned up here.
- Neither app has integration tests covering the actual queue-sync
  flow — `tests/Feature/ExampleTest.php` is still the Laravel
  boilerplate default in both. The flow was verified manually in
  Docker for this task (see above), not via an automated test added to
  the suite.
