# Repository audit

Audited 2026-11-03 as repo #5 in the `RasimAghayev` personal-repo modernization pass. See `docs/ARCHITECTURE.md` for how the system works and `docs/TECHNICAL_DEBT.md` for the itemized backlog.

## Scope of this audit

Application code (Laravel 9→13, PHP 8.1→8.5, Docker rewrite, secret rotation) was already modernized on `master` before this audit began — confirmed with the repo owner as prior, already-approved work, not something this audit re-litigates or re-verifies for the upgrade decision itself. This audit instead independently verified the **current, as-committed state actually works**, per this project's standing rule to never report something as working without personally verifying it.

## Finding: the README's "verified end-to-end" claim did not hold on a fresh clone

The README claims the admin→RabbitMQ→main sync flow "was verified end-to-end in Docker for this task" and gives a 4-step quickstart (`cp .env.example .env`, fill secrets, `docker compose up -d --build`, `migrate --force` on both apps).

**Reproduction attempt on a genuinely fresh clone (this audit, 2026-11-03):** `admin_app`, `main_app`, and `main_worker` all crash-looped:

```
Fatal error: Uncaught Error: Class "Illuminate\Foundation\Application" not found
```
```
Fatal error: Uncaught Error: Failed opening required '/var/www/vendor/autoload.php'
```

**Root cause:** `vendor/` is correctly gitignored in both `admin/` and `main/`. The dev Compose setup bind-mounts the host directory over the image's build output (`./admin:/var/www`, `./main:/var/www`) — on a fresh clone there is no host `vendor/` to mount, so the bind mount replaces the image's correctly-built `vendor/` with an empty directory. The dev entrypoint (`*/docker/docker-php-entrypoint-dev`) only runs `composer dump-autoload` (regenerate the autoloader from an existing `vendor/`), never `composer install` (fetch the packages). It assumes a developer already ran `composer install` locally before ever touching `docker compose up` — a prerequisite the README's documented steps never state. `main_worker` has no entrypoint override at all, so it has neither step and fails the same way.

**This is specifically a reproducibility/documentation bug, not a correctness bug in the sync logic.** After installing `vendor/` for both apps as a one-off local diagnostic (via a throwaway `composer:2` container against each app directory — not committed, since `vendor/` is gitignored by design), the stack came up cleanly and the actual claimed behavior was verified directly:

| Step | Evidence |
|---|---|
| `POST /api/products` on `admin` (8000) | `201`, row in `admin`'s DB |
| `main_worker` log | `App\Jobs\ProductCreated ... DONE` |
| `main`'s DB | matching row appeared |
| `PUT /api/products/1` on `admin` | `202`, row updated in `admin`'s DB |
| `main_worker` log | `App\Jobs\ProductUpdated ... DONE` |
| `main`'s DB | row updated to match |
| `DELETE /api/products/1` on `admin` | `204`, row gone from `admin`'s DB |
| `main_worker` log | `App\Jobs\ProductDeleted ... DONE` |
| `main`'s DB | row gone |

So the core thing this repo exists to demonstrate — queue-based eventual-consistency replication between two independent Laravel apps — **genuinely works**, confirmed by direct API/DB/log evidence rather than by trusting the README. What was false was the *reproducibility* claim: "verified end-to-end" implied anyone following the documented steps would get a working stack, and that wasn't true for a fresh clone. The likely origin: whoever ran this verification already had a locally-populated `vendor/` from prior manual development before ever running `docker compose up`.

**Remediation is proposed, not yet executed** — see the Phase 3 plan in `IMPROVEMENT_ROADMAP.md`, pending approval.

## Other gates checked

| Gate | Result |
|---|---|
| CI/CD pipeline | **None.** No `.github/workflows/`. `.styleci.yml` exists per-app but is a hosted linter, not a pipeline — no test run, no build check gates any PR. |
| Automated tests | **None of substance.** Both apps have only Laravel's stock `ExampleTest.php` (Feature + Unit) — zero coverage of `ProductController`, the three job classes, or the sync behavior. |
| Secrets in git history | None found. `.env` is gitignored (`git check-ignore -v .env` confirmed); `.env.example` holds placeholder guidance only. |
| Default branch | `master`, not `main` — same pattern flagged in other repos in this audit (Gate #4 candidate). |
| Open dependency PRs | 7 open (Renovate ×6, Dependabot ×1) — see `TECHNICAL_DEBT.md` for the per-PR breakdown. |

## Key observation

This repo's Phase 2 gap is the inverse of `be-chatty`'s (repo #3): there, business logic was missing entirely. Here, the business logic is real, correct, and already verified by this audit — but the documented path to actually run it was broken, and the README overclaimed having verified that path.
