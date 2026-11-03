# Improvement roadmap

## Phase 3 plan — fix the fresh-clone reproducibility bug (awaiting approval)

**Problem:** see `docs/REPOSITORY_AUDIT.md` and `docs/TECHNICAL_DEBT.md` #1. The documented quickstart doesn't produce a working stack from a fresh clone; the underlying sync logic is correct, but nobody following the README gets there.

**Proposed fix:**
1. `admin/docker/docker-php-entrypoint-dev` and `main/docker/docker-php-entrypoint-dev`: run a real `composer install` (not just `dump-autoload`) whenever `vendor/autoload.php` is missing, so a fresh clone self-heals on first `docker compose up` instead of requiring an undocumented manual step. Keep the existing cheap `dump-autoload` path when `vendor/` already exists, so normal dev-loop restarts stay fast.
2. Add the same missing-`vendor/` guard to `main_worker`'s startup — it currently has no entrypoint override at all and inherits the gap with no recovery step.
3. Update the README's "Running it" section so the documented steps match what's actually required — either because the entrypoint fix above makes the manual step unnecessary, or, if you'd rather not change the entrypoint's behavior, document the missing prerequisite explicitly instead.
4. Re-verify the full `docker compose up -d --build` → `migrate --force` ×2 → create/update/delete smoke test on a genuinely fresh clone (delete the containers/volumes/vendor, not just restart) before calling this done.

**What this does not do:** it does not touch the sync logic itself (`ProductController`, the job classes) — that was independently verified correct already. It does not address the 5 other debt items below; those are separate phases.

**Risk:** low — this only changes container bootstrap behavior and documentation, not application logic. No Approval Gate from CLAUDE.md's numbered list applies directly (not a history rewrite, not a rename/delete/merge, not a default-branch change, not a major-version bump, not a production system). Flagged here for Gate #10 (per-repo Phase 2 plan review) rather than any specific numbered gate.

## Phase 4 plan — CI/CD and dependency triage (after Phase 3)

- Add a GitHub Actions workflow: Docker-only pattern matching `photo-gallery-api`'s (compose config validation, image builds, test run per app, lint) rather than requiring a PHP toolchain on the runner.
- Triage the 7 open dependency PRs individually (never batch-merge) — likely safe: `#19` laravel/framework lockfile, `#18` phpunit lockfile. Needs review: `#20` rabbitmq Docker tag, `#17` npm group, `#16` vite major, `#13` phpunit major, `#6` axios security fix.
- Default branch rename `master` → `main` (Gate #4 — separate approval, same pattern as other repos in this audit).

## Phase 5 plan — tests (after Phase 4)

- Real feature tests for `admin/app/Http/Controllers/ProductController.php` (create/update/delete against `admin`'s own DB and job dispatch).
- Real tests for all 6 job classes (3 per app) — at minimum, that `main`'s copies correctly create/update/destroy against `main`'s DB given a representative payload array.
- A `TESTING_STRATEGY.md`/`TEST_REPORT.md` pair per `PROMPT.md`'s template, covering what's tested, what's deliberately not (e.g. RabbitMQ broker behavior itself, which is third-party infrastructure), and real run numbers.

## Deliberately not planned

- No attempt to make this a "real" CQRS/event-sourced system — the current queue-replication pattern is a legitimate, working design choice for this repo's scope (see `docs/ARCHITECTURE.md`), not a defect to re-architect.
- No observability infrastructure (Sentry/Prometheus/tracing) — no production deployment or incident history to justify it, same reasoning as `photo-gallery-api`'s Phase 4.

---

**This Phase 3-5 plan needs your approval (Gate #10) before Phase 3 execution begins**, per the established per-repo pattern.
