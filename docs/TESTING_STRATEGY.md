# Testing strategy

Written 2026-11-03 as the Phase 5 deliverable per `PROMPT.md`.

## Approach

PHPUnit 13 (admin) / 13 (main) feature + unit tests, run against the real `admin_db`/`main_db` MySQL containers via `docker compose exec <app> php artisan test` — the same connection the dev stack itself uses. Each test uses `RefreshDatabase`, which wraps the test in a transaction and rolls it back afterwards, so nothing persists into the containers' data after a run.

This repo's `phpunit.xml` has a commented-out `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` pair in both apps, suggesting an in-memory SQLite suite was once intended. It was left commented out here rather than enabled, because neither app's Docker image installs `pdo_sqlite` (only `pdo_mysql` — see both `Dockerfile`s) — switching backends would mean changing the image, which is infrastructure work outside this phase's approved scope (`docs/IMPROVEMENT_ROADMAP.md`). Flagged as a future improvement, not done here.

**Mock only where it makes sense.** `Queue::fake()` is used in `admin`'s feature tests — not to avoid a slow dependency, but because the thing actually under test is "was the right job dispatched with the right payload," and a real RabbitMQ round-trip would test the broker, not `ProductController`. Nothing else is mocked: every test runs against the real Eloquent models, the real migrations, and the real job `handle()` methods.

## Coverage by layer

- **`admin` feature** (`admin/tests/Feature/ProductControllerTest.php`): all 5 `ProductController` actions (`index`, `show`, `store`, `update`, `destroy`) against the real `admin` database, plus two pre-existing-behavior gaps worth knowing about rather than assuming away:
  - `store()` has no request validation — a request missing `title` fails as an unhandled 500 (a DB `NOT NULL` violation), not a clean 422. Tested as the actual current behavior, not patched (see `docs/TECHNICAL_DEBT.md`).
  - `destroy()` on a non-existent id is a silent no-op (Laravel's `Model::destroy()` behavior) but still unconditionally dispatches `ProductDeleted` — `main` gets told to delete a product that was never there. Tested as-is.
- **`admin` jobs** (`admin/tests/Unit/Jobs/*Test.php`): `ProductCreated`/`ProductUpdated`/`ProductDeleted`'s `handle()` bodies are deliberately empty on this side (dispatch-only, by design — see `docs/ARCHITECTURE.md`). Each test asserts that calling `handle()` directly does *not* touch `admin`'s database, so a future accidental implementation here doesn't start double-writing.
- **`main` jobs** (`main/tests/Unit/Jobs/*Test.php`) — where the real replication logic lives:
  - `ProductCreatedTest`: `handle()` creates a row with the exact id/title/image/timestamps from the dispatched payload (main's `products.id` is a plain, non-auto-incrementing primary key — see `docs/ARCHITECTURE.md` — so this also implicitly covers that the explicit id round-trips correctly).
  - `ProductUpdatedTest`: `handle()` updates an existing row; a second test documents that `handle()` on a product that doesn't exist in `main`'s DB throws a fatal `Error` (`Product::find()` returns `null`, then `->update()` is called on it) — a real, pre-existing gap with no defensive check, not silently patched (see `docs/TECHNICAL_DEBT.md`).
  - `ProductDeletedTest`: `handle()` deletes an existing row, and is a no-op on a missing one (matching `admin`'s own `destroy()` behavior, so a delete racing ahead of a not-yet-replicated create doesn't crash the worker).

## What is deliberately not tested

- **RabbitMQ broker behavior itself** (connection handling, AMQP protocol, `vladimir-yuldashev/laravel-queue-rabbitmq`'s internals) — third-party infrastructure, already verified working end-to-end manually in Phase 3/4 (real `docker compose` runs, real create/update/delete against a running broker). Testing it here would mean testing the library, not this repo's code.
- **`main_worker`'s actual `queue:work` loop** — a long-running process by design; the thing worth unit-testing is what each job's `handle()` does once invoked, which is covered directly above.
- **Laravel's own `Route::apiResource` routing/model-binding machinery** — framework-tested, not this repo's logic.

## Running the suite

```bash
docker compose exec admin_app php artisan test
docker compose exec main_app php artisan test
docker compose exec admin_app composer audit --no-interaction --abandoned=report
docker compose exec main_app composer audit --no-interaction --abandoned=report
```

All four also run automatically in `.github/workflows/ci.yml` on every push/PR to `main`.
