# Technical debt

Tracked 2026-11-03. See `docs/REPOSITORY_AUDIT.md` for how each finding was verified.

| # | Severity | Item | Where | Status |
|---|---|---|---|---|
| 1 | **P1** | Fresh-clone reproducibility bug: documented "Running it" steps crash-loop `admin_app`/`main_app`/`main_worker`. Root cause: dev bind-mount shadows the image's built `vendor/`, and the dev entrypoint only runs `composer dump-autoload`, never `composer install`; `main_worker` has no entrypoint step at all. The underlying sync logic itself is correct (verified directly — see audit). | `admin/docker/docker-php-entrypoint-dev`, `main/docker/docker-php-entrypoint-dev`, `docker-compose.yaml` (`main_worker` service), `README.md` | Diagnosed, locally verified fixable, not yet remediated in-repo. **Phase 3 candidate.** |
| 2 | **P2** | No GitHub Actions CI/CD. `.styleci.yml` exists per-app but is a hosted linter, not a pipeline — nothing runs tests or builds on a PR. | repo root, `admin/`, `main/` | Not done. **Phase 4 candidate.** |
| 3 | **P2** | Zero real test coverage. Both apps have only Laravel's stock `ExampleTest.php`; nothing exercises `ProductController`, the three job classes per app, or the actual replication behavior. | `admin/tests/`, `main/tests/` | Not done. **Phase 5 candidate.** |
| 4 | **P3** | 7 open dependency-bot PRs, untriaged: `#20` rabbitmq Docker tag → 4.4.0, `#19` laravel/framework → 13.39.0 (lockfile-only), `#18` phpunit → 12.5.43 (lockfile-only), `#17` npm_and_yarn group bump, `#16` vite → v8 (major), `#13` phpunit → v13 (major), `#6` axios security fix. | GitHub PRs | Not done — awaiting individual review per PROMPT.md's PR-triage step. |
| 5 | **P3** | Default branch is `master`, not `main`. | repo settings | Not done — Gate #4 candidate, raised separately per the pattern used on other repos. |
| 6 | **P3** | `admin`'s and `main`'s `TestJob` job classes are leftover scaffolding from early development (per `commond.txt`'s command history) — `admin`'s is an empty stub, `main`'s just echoes a string. Neither is dispatched anywhere in application code. | `admin/app/Jobs/TestJob.php`, `main/app/Jobs/TestJob.php` | Not done — cleanup candidate, zero functional impact. |
| 7 | **P3** | `commond.txt` at repo root is a raw shell-history scratch file (artisan commands run during early development) checked into the repo, not meaningful documentation. | `commond.txt` | Not done — your call, likely safe to delete or move into `docs/` as historical context. |

## Not treated as debt

- The same-class-name, two-implementations sync pattern (`App\Jobs\ProductCreated` etc.) is a deliberate, working design, not an accident — see `docs/ARCHITECTURE.md`. It's fragile to independent refactors (the two apps must keep the class names and constructor shapes in sync by convention, with nothing enforcing it), but that's an inherent property of the chosen pattern, not a bug to fix.
- `main_app`'s lack of product-specific HTTP routes is intentional per the README (`main` is the read side; this audit found no indication a read API was ever planned to land here).
