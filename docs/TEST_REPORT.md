# Test report

Last run 2026-11-03, via `docker compose exec <app> php artisan test`, against the real `admin_db`/`main_db` MySQL containers (each test wrapped in a rolled-back transaction via `RefreshDatabase`). See `docs/TESTING_STRATEGY.md` for what each layer covers and why.

## Result — admin

```
13 tests, 26 assertions
0 failures, 0 errors, 0 skipped
```

12 of the 13 report as PHPUnit "warning" rather than a clean pass — every test in both apps does, including the two pre-existing stock `ExampleTest`s, because of a harmless pre-existing condition unrelated to this phase's work (see `docs/TECHNICAL_DEBT.md` #8: `file_get_contents(/var/www/.env): Failed to open stream`, since the app reads its config from compose-injected env vars, not a file). The actual pass/fail signal is the "0 failures, 0 errors" line, not the warning count.

## Result — main

```
7 tests, 10 assertions
0 failures, 0 errors, 0 skipped
```

## By class

| App | Class | Tests | What it covers |
|---|---|---|---|
| admin | `Tests\Feature\ProductControllerTest` | 8 | All 5 `ProductController` actions against the real `admin` DB: index/show (including the no-not-found-handling behavior on a missing id), store (success + job dispatch, and the no-validation 500 gap), update (success + job dispatch), destroy (success + job dispatch, and the dispatch-on-a-missing-id gap). |
| admin | `Tests\Unit\Jobs\ProductCreatedTest` | 1 | `handle()` is a no-op on admin's side (dispatch-only by design). |
| admin | `Tests\Unit\Jobs\ProductUpdatedTest` | 1 | Same, for `ProductUpdated`. |
| admin | `Tests\Unit\Jobs\ProductDeletedTest` | 1 | Same, for `ProductDeleted`. |
| admin | `Tests\Unit\ExampleTest` + `Tests\Feature\ExampleTest` | 2 | Pre-existing Laravel boilerplate, left as-is. |
| main | `Tests\Unit\Jobs\ProductCreatedTest` | 1 | `handle()` creates a row with the exact id/title/image/timestamps from the payload. |
| main | `Tests\Unit\Jobs\ProductUpdatedTest` | 2 | `handle()` updates an existing row; throws a fatal `Error` on a missing one (real, pre-existing gap — documented, not patched). |
| main | `Tests\Unit\Jobs\ProductDeletedTest` | 2 | `handle()` deletes an existing row; is a no-op on a missing one. |
| main | `Tests\Unit\ExampleTest` + `Tests\Feature\ExampleTest` | 2 | Pre-existing Laravel boilerplate, left as-is. |

**2 pre-existing (per app) + 11 added this Phase 5 pass in admin, 5 added in main** — 0 tests existed for `ProductController` or any of the 6 job classes before this phase; the only coverage previously in the repo was the stock Laravel `ExampleTest` scaffolding.

## Other quality gates, same run

| Gate | admin | main |
|---|---|---|
| `composer audit` | ✅ 0 vulnerabilities | ✅ 0 vulnerabilities |

No linter or static analysis tool (Pint, Larastan/PHPStan) is installed as a dev dependency in either app, so none is reported here — not run, not silently assumed clean.

## Known limitations

- Both suites run against real MySQL containers, not an isolated in-memory database — see `docs/TESTING_STRATEGY.md` for why, and the flagged future improvement.
- Coverage percentage is not measured — no Xdebug/PCOV build step in either Docker image, and adding one solely to produce a number wasn't judged worth the added image complexity. Every `ProductController` action and every job class's `handle()` method has at least one test, verified by the table above, not by a coverage tool.
- The two real, pre-existing gaps surfaced while writing these tests (`store()`'s missing validation, `ProductUpdated::handle()`'s missing null-check) are documented as tests of the *current* behavior and in `docs/TECHNICAL_DEBT.md` — neither was fixed here, since fixing either changes observable API/worker behavior and wasn't part of the approved Phase 5 scope (tests, not behavior changes).
