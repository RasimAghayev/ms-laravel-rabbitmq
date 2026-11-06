# Phase 5 baseline — container behavior contract

Written 2026-11-06. This is the behavior contract for `admin/` and `main/`
going forward: every later phase is compared against the results on this
page, not against memory or assumption.

A real, substantial Phase-5 draft already existed in the working tree when
this pass started (multi-stage Dockerfiles, `docker-compose.yaml`,
`Makefile`, both apps' `.dockerignore`) — carried over from a prior agent
session that was lost to a session boundary, not a crash. Every file was
re-read and verified line-by-line before being trusted; the issues found
during that review (below) were fixed here, not assumed away.

## What was inherited vs. fixed this pass

**Inherited and verified correct, unchanged:**
- Both Dockerfiles' stage structure (`base -> deps/test-deps -> build ->
  test -> runtime`), non-root `www-data` runtime, `/tmp`-based
  socket/pid/log paths (nginx + php-fpm + supervisord all previously
  assumed a writable `/var/run`, which a non-root user doesn't have),
  `HEALTHCHECK`, `STOPSIGNAL`, BuildKit composer cache mounts.
- `docker-compose.yaml`: `admin_test`/`main_test` as `profiles: ["test"]`
  services (never started by `make up`), runtime services with no dev
  bind-mount, correct `${VAR}` env-var interpolation (no hardcoded
  secrets).
- `Makefile`: full target set (`build`, `test`, `verify`, `up`/`run`,
  `down`, `logs`, `shell-admin`/`shell-main`, `migrate`, `seed`, `audit`,
  `lint`, `clean`), `clean`'s `CONFIRM=1` data-safety gate on the two named
  DB volumes.
- Both apps' `.dockerignore`.

**Fixed this pass:**
1. **New security-advisory CVEs surfaced by the test gate itself.**
   `composer audit` (run for real, inside the `test` stage) failed on
   both apps: `symfony/http-foundation` v8.1.7, `symfony/http-kernel`
   v8.1.7, and `symfony/routing` v8.1.6 were all inside the
   `>=8.1.0,<8.1.11` range flagged by advisories reported 2026-11-04 (two
   days before this phase ran) — `CVE-2026-118514`, `CVE-2026-118516`,
   and four no-CVE GHSA advisories. Not present in the 2026-11-03
   `docs/TEST_REPORT.md` baseline because they didn't exist yet. Fixed
   with a minimal, non-breaking, lockfile-only bump (`composer update
   symfony/http-foundation symfony/http-kernel symfony/routing
   --with-all-dependencies`, same pattern as the `symfony/mime`
   CVE-2026-45070 fix already recorded in `docs/TECHNICAL_DEBT.md`) — all
   three now resolve to `v8.1.11` (the fixed version) in both apps;
   `composer.json` untouched. `composer audit` is clean in both apps
   after the bump — see the gate output below.
2. **Base-image digests refreshed to what the registry serves today**
   (CLAUDE.md standing decision 1 — "latest" is read at run time, not
   memory). The inherited draft had already pinned every `FROM`/
   `COPY --from=` by tag *and* digest, but those digests were a few days
   stale by the time this pass actually ran `docker pull`:
   - `php:8.5-fpm-alpine`: `sha256:53db03966e2d19175531b782e8aa7d0e1457b0916b5a2bc361bbe067598d3371` → `sha256:64535609509e0fd5c8529359e6b599a68ce42089defd565f7881efdae7687cea` (same tag, newer build, `docker scout recommendations` showed it removes 25 vulnerabilities with zero new ones introduced).
   - `composer:2`: `sha256:5190d9b445add2fa26090108273cb1ef1e1af98175201c737b85b3c4f90fbf7c` → `sha256:3d1eb9437516b0ba88c46a0bb05826451a1ab178d29adacb5ded26dad0899ae4`.
   - `rabbitmq:4.4.0-management-alpine`: `sha256:b768d2e09a809b48458d6c3c32fc2894643a6bfafd76264c120373eff0ee6532` → `sha256:6cb708876a6163c93f1e5af03a649db13765027ff7a7d775c01a63b6a80545ee`.
   - `mysql:26.10` was already pinned to the current digest (`sha256:3819b2b23835c4a73c8b022fafc33c8095c0f81c929a8b7a84613d2fba6c52f5`) — no change.
3. **`.github/workflows/ci.yml` rewritten — the existing workflow was
   incompatible with this baseline, not just stale.** It exec'd
   `php artisan test`/`composer audit` into the dev-mode `admin_app`/
   `main_app` containers and polled for a `vendor/.install-complete`
   marker written by the dev entrypoint on first boot. This baseline's
   `runtime` services no longer bind-mount source or run a dev entrypoint
   at all — `vendor/` is baked into the image at build time — so that
   marker file would never appear and the old workflow would hang until
   its own timeout. Rewritten to call the same `make verify` / `make up`
   targets a developer runs locally (build + the `test` stage via the
   `admin_test`/`main_test` profile services, then the real runtime
   stack), plus an explicit health-wait and non-root check. Simulated
   locally end-to-end before trusting it (see "CI simulation" below) —
   this is a mechanical consequence of the Phase 5 topology change, not a
   Phase 10 CI/CD redesign; no new jobs, caching, or parallelization were
   added (that's still Phase 10's scope).
4. **Removed `admin/docker-compose.yaml` and `main/docker-compose.yaml`**
   — stale, superseded duplicates of the root `docker-compose.yaml`,
   dating from the repo's first commit (`1acae9e`, 2022-07-03), unused by
   the Makefile/CI/README (grepped first to confirm). **Security finding
   in these two files, reported rather than silently fixed:** both
   hardcoded the same plaintext `APP_KEY=base64:<REDACTED>` and
   `MYSQL_ROOT_PASSWORD=root` / `DB_PASSWORD=root` in every commit from
   `1acae9e` through the last commit that touched them, `3517eea`
   (2026-11-05) — i.e. still present in `main`'s history one day before
   this phase ran, even though `README.md`'s "What changed in this
   modernization pass" section already claimed this class of secret was
   "rotated... to environment-variable placeholders" (that rotation only
   touched the root-level `docker-compose.yaml`/`.env.example`; it missed
   these two per-service leftovers). Per CLAUDE.md's secrets standard, a
   secret found in code/history is treated as compromised: **reported
   here for a rotation decision — not rotated or history-rewritten by
   this phase** (out of Docker/Make-only scope; history rewrite is Gate
   1). Deleting the files from the current tree is normal forward-going
   cleanup, not a history rewrite, and does not by itself remove the
   value from git history.
5. **`apk upgrade --no-cache` added** to both Dockerfiles' `base` stage,
   ahead of `apk add`. Attempted fix for two `docker scout`-flagged OS
   packages (`zlib` CRITICAL, `musl` HIGH) that list a "Fixed version"
   number — **verified this is not yet actionable**: `apk list -a
   zlib musl` inside a fresh pull of the same base image shows Alpine
   3.24's stable repo currently serves only the already-installed
   versions (`zlib-1.3.2-r1`, `musl-1.2.6-r3`); the fixed builds scout
   lists haven't been backported to the 3.24 stable branch yet. Kept the
   `apk upgrade` line anyway (verified harmless, adds no measurable build
   time when there's nothing to upgrade) so a future Alpine security
   backport is picked up automatically on the next image build with no
   Dockerfile change needed.

## Build

```
$ make build
docker compose build admin_app main_app main_worker
 Image ms-laravel-rabbitmq-p5-admin_app   Built
 Image ms-laravel-rabbitmq-p5-main_app    Built
 Image ms-laravel-rabbitmq-p5-main_worker Built
$ echo $?
0
```

## Test (the Docker standard's `test` stage — the real gate)

```
$ make test
docker compose up -d admin_db main_db rabbitmq
docker compose --profile test build admin_test main_test
docker compose --profile test run --rm admin_test
...
Tests:    12 warnings, 1 passed (26 assertions)
Duration: 0.98s
No security vulnerability advisories found.
docker compose --profile test run --rm main_test
...
Tests:    6 warnings, 1 passed (10 assertions)
Duration: 0.78s
No security vulnerability advisories found.
$ echo $?
0
```

Exactly matches the counts already recorded in `docs/TEST_REPORT.md`
(admin: 13 tests / 26 assertions; main: 7 tests / 10 assertions; "1
passed" here is PHPUnit's top-level summary line counting the one
strictly-clean test, the other 12/6 are **warnings, not failures** — see
`docs/TEST_REPORT.md`'s explanation of the pre-existing, harmless
`file_get_contents(/var/www/.env)` warning on every test in both apps).
Zero regressions against the recorded baseline.

## `make verify` (build + test), clean slate

```
$ docker compose down -v        # drop dev volumes, force a real fresh-migration run
$ make verify
...
Tests:    12 warnings, 1 passed (26 assertions)
No security vulnerability advisories found.
...
Tests:    6 warnings, 1 passed (10 assertions)
No security vulnerability advisories found.
$ echo $?
0
```

## Sabotage test — proving the gate actually gates

`admin/tests/Unit/ExampleTest.php`'s trivial assertion was flipped
(`assertTrue(true)` → `assertTrue(false)`), the `admin_test` image was
rebuilt, and `docker compose --profile test run --rm admin_test` was run
again:

```
FAIL  Tests\Unit\ExampleTest
⨯ that true is true
  Failed asserting that false is true.
  at tests/Unit/ExampleTest.php:16
Tests:    1 failed, 12 warnings (26 assertions)
$ echo $?
1
```

Confirms the `test` stage genuinely fails the build on a real test
failure — it's not a no-op. The change was reverted immediately
(`git diff --stat` on the file is empty — zero net change), the image was
rebuilt, and `make test` was re-run to confirm a clean pass again (exit 0,
26 assertions, 1 passed — see above).

## Runtime stack — `make up`

```
$ make up
...
NAME          STATUS
admin_app     Up (health: starting) -> healthy
admin_db      Up (healthy)
main_app      Up (health: starting) -> healthy
main_db       Up (healthy)
main_worker   Up (health: starting) -> healthy
rabbitmq      Up (healthy)
```

```
$ docker inspect --format='{{.Name}}: {{json .State.Health.Status}}' admin_app main_app main_worker
/admin_app: "healthy"
/main_app: "healthy"
/main_worker: "healthy"

$ docker compose exec -T admin_app whoami && docker compose exec -T main_app whoami && docker compose exec -T main_worker whoami
www-data
www-data
www-data
```

Non-root confirmed for all three application containers.

## End-to-end API/sync output snapshot (the real behavior contract)

Real create against `admin`'s write API, confirmed replicated into
`main`'s database by the `main_worker` queue consumer — the core
documented behavior of this repo (`README.md`, "How the sync actually
works"):

```
$ curl -s -X POST http://localhost:8000/api/products \
    -H "Content-Type: application/json" \
    -d '{"title":"phase5-smoke-test","image":"smoke.png"}'
{"title":"phase5-smoke-test","image":"smoke.png","updated_at":"2026-11-06T03:45:18.000000Z","created_at":"2026-11-06T03:45:18.000000Z","id":10}

$ curl -s http://localhost:8000/api/products
[{"id":10,"title":"phase5-smoke-test","image":"smoke.png","likes":0,"created_at":"2026-11-06T03:45:18.000000Z","updated_at":"2026-11-06T03:45:18.000000Z"}]

$ docker compose exec -T main_db mysql -uroot -p$MYSQL_ROOT_PASSWORD main -e 'SELECT id,title,image FROM products;'
id	title	image
10	phase5-smoke-test	smoke.png
```

Also confirmed: `main_app` root (`http://localhost:8001/`) → `HTTP 200`;
RabbitMQ management UI (`http://localhost:15672/`) → `HTTP 200`.

(Note: a later, separate `docker compose down`/`up` cycle run without a
fresh product create logged one harmless RabbitMQ line, `operation
basic.get caused a channel exception not_found: no queue 'default' in
vhost '/'`  — the `default` queue is declared on first publish, not on
worker boot; it exists and consumes correctly as soon as a message is
actually published, as proven by the smoke test above. Not a regression,
not fixed here — informational only.)

## Image sizes

```
$ docker images --format '{{.Repository}}:{{.Tag}}\t{{.Size}}'
ms-laravel-rabbitmq-p5-admin_app:latest    297MB   (runtime, shipped)
ms-laravel-rabbitmq-p5-main_app:latest     297MB   (runtime, shipped)
ms-laravel-rabbitmq-p5-main_worker:latest  297MB   (same runtime image as main_app, different `command:`)
ms-laravel-rabbitmq-p5-admin_test:latest   422MB   (test stage, never shipped)
ms-laravel-rabbitmq-p5-main_test:latest    422MB   (test stage, never shipped)
```

## Image scan — `docker scout cves ms-laravel-rabbitmq-p5-admin_app:latest`

(`main_app`/`main_worker` share the identical base + package set — same
result, confirmed separately.)

| Package | Version | Critical | High | Medium | Low | Fixed version |
|---|---|---|---|---|---|---|
| zlib | 1.3.2-r1 | 1 | 0 | 0 | 0 | 1.4-r0 — **not yet in Alpine 3.24's stable repo** (verified via `apk list -a`, see fix #5 above) |
| libxml2 | 2.13.9-r2 | 0 | 1 | 5 | 5 | not fixed (upstream) |
| musl | 1.2.6-r3 | 0 | 1 | 0 | 0 | 1.2.6-r6 — **not yet in Alpine 3.24's stable repo** (same as zlib) |
| sqlite | 3.53.4-r0 | 0 | 1 | 0 | 0 | not fixed (upstream) |
| tar | 1.35-r5 | 0 | 0 | 3 | 0 | not fixed (upstream) |
| setuptools | 82.0.1 | 0 | 0 | 1 | 1 | 83.0.0 / 84.1.0 — **not present in the actual image**, see below |

**Totals: 19 vulnerabilities, 6 packages (1 Critical / 3 High / 9 Medium /
6 Low).** Down from an initial scan of **44 vulnerabilities in 8
packages** (9C/20H/9M/6L) before the base-image digest refresh (fix #2
above) — confirms the refresh was a real improvement, not just a digest
change for its own sake.

All six are OS-level Alpine packages pulled in transitively by
`php:8.5-fpm-alpine` (or its own base), none introduced by anything this
repo's `composer.json`/`composer.lock` declares — `composer audit` is
separately clean (see Test section above). `docker scout recommendations`
confirms the base image tag is already the most current build available
for `8.5-fpm-alpine` as of this scan.

**`setuptools` is not actually present in the image's filesystem** —
verified with `which python3 pip3` (both empty) and `find / -iname
'setuptools*'` (no matches) inside a container started from the base
image. This is a known class of `docker scout` false-attribution from
BuildKit's own build-provenance/SBOM attestation metadata (the Dockerfile
prints "resolving provenance for metadata file" during every build),
not a real package shipped in the deployed image. Not actionable, not a
real exposure; recorded rather than silently dropped from the table.

Not a Phase 5 build gate (per the already-established
`container-baseline-php` pattern) — OS-base-image CVEs with no available
fix are not blocking; recorded fully, acted on where an actual fix
existed (fix #5 attempted, confirmed not yet possible), left for a future
Phase 9/10 periodic re-scan otherwise.

## Secrets

- No secret is baked into any image layer, `ARG`, or `ENV` — verified by
  reading every `Dockerfile` instruction above. The only credentials
  involved are injected at container-start time via `docker-compose.yaml`
  `${VAR}` interpolation from a gitignored `.env` (never committed; not
  read by `.dockerignore`'s build context either).
- `.env.example` (root) carries placeholders only, with a comment
  explaining each is Compose-only and how to generate the two Laravel
  `APP_KEY`s. `admin/.env.example` and `main/.env.example` (each app's own
  Laravel defaults) likewise carry no real values.
- **Historical secret finding** (see fix #4 above): plaintext `APP_KEY`
  and MySQL `root`/`root` credentials existed in `admin/docker-compose.yaml`
  and `main/docker-compose.yaml` from this repo's first commit until this
  phase deleted the files. Reported for a rotation decision, not rotated
  here — see fix #4 for exact commit range and CLAUDE.md's secrets
  standard for why no history rewrite was attempted in this phase.
- `.env` itself is gitignored at the root and inside both `admin/` and
  `main/` (`.gitignore` already covers `.env`/`.env.backup`); this phase
  added nothing new here, both were already correct.

## Ports / `COMPOSE_PROJECT_NAME`

`docker-compose.yaml` top-level `name: ms-laravel-rabbitmq-p5` (R4
isolation). Existing port scheme preserved exactly, not renumbered:
`admin_app` 8000, `main_app` 8001, `admin_db` 33063, `main_db` 33064,
`rabbitmq` 5672 (AMQP) / 15672 (management UI). The fresh
`20090-20099` block reserved in `projects/info/state/ports.md` was not
needed this phase — no new port was required.

## Dependent services

`admin_app`/`main_app`/`main_worker` all depend on `admin_db`/`main_db`
(their own, not shared) and `rabbitmq`, each via `depends_on: condition:
service_healthy`. No external dependency beyond these three containers;
`MODE=standalone` / dual-mode independence (stub adapters for a missing
dependency) is Phase 9's concern, not addressed here.

## Characterization tests

None added this phase. `docs/TEST_REPORT.md` (written 2026-11-03, in an
earlier session) already documents that every `ProductController` action
and every job class's `handle()` method has at least one direct test —
re-confirmed true by re-reading `admin/tests/` and `main/tests/` in this
pass (8 feature + 5 unit tests in `admin`, 5 unit tests in `main`, plus
the stock `ExampleTest` in each). No critical path found with zero
coverage, so no new characterization tests were written here, per the
"only where critical paths have none" rule.

## CI simulation (before trusting the rewritten `.github/workflows/ci.yml`)

The exact sequence the workflow now runs was reproduced locally first,
with a throwaway `.env` generated the same way the workflow generates
one (`cp .env.example .env` + random secrets via `openssl rand`, never
the real local `.env`):

```
$ docker compose config --quiet            # exit 0
$ make verify                              # exit 0 (see above)
$ make up                                  # exit 0
$ for svc in admin_app main_app main_worker; do
    until [ "$(docker inspect -f '{{.State.Health.Status}}' $svc)" = healthy ]; do sleep 2; done
  done                                     # all three reach healthy within ~25s
$ for svc in admin_app main_app main_worker; do
    docker compose exec -T "$svc" whoami   # www-data, all three
  done
```

All steps passed locally before the workflow was trusted in a real PR.
