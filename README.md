# ms-laravel-rabbitmq

**What's on this branch (`master`) today is a generic Docker/Nginx
scaffold — no Laravel application code, no `composer.json`, no `app/`
directory.** The Laravel + RabbitMQ microservices system the repo name
promises does exist, but only on unmerged feature branches; see
[Architecture (not on this branch)](#architecture-not-on-this-branch)
below. This README documents both honestly, rather than describing the
unmerged work as if it were runnable from here.

## What's actually in `master`

| File | Purpose |
|---|---|
| `Dockerfile` | Builds a PHP 8.1-FPM + Nginx image from `fhsinchy/php-nginx-base` (a public Docker tutorial base image — its `APP_NAME="Question Board"` default is a leftover from that tutorial, unrelated to this project) |
| `docker-compose.yaml` | One `admin_app` service + one `admin_db` (MySQL) service |
| `docker/nginx.conf`, `docker/default.conf` | Nginx vhost — serves `/var/www/public`, routes `.php` to PHP-FPM over a Unix socket |
| `docker/supervisord.conf` | Runs `php-fpm` and `nginx` together under supervisord (the container's `CMD`) |
| `docker/docker-php-entrypoint`, `docker/docker-php-entrypoint-dev` | Writes PHP-FPM pool settings from env vars at container start; the `-dev` variant also fixes bind-mount permissions and runs `composer dump-autoload` |
| `commond.txt` | The author's own local shell-command notes from building the app (`composer create-project`, `php artisan make:*`) — not documentation, kept as-is |

**This branch has no application code to run.** `docker build .` on
`master` as committed fails, verified by actually running it:

```
> [4/13] COPY ./composer.json ./composer.lock* ./
ERROR: "/composer.json": not found
```

The Dockerfile's `COPY ./composer.json ...` step has nothing to copy — no
`composer.json`, no `app/`, no Laravel project exists anywhere in
`master`'s history. `docker-compose.yaml` also hardcodes a real-looking
Laravel `APP_KEY` and a trivial `root`/`root` MySQL password — see
[Known issues](#known-issues-flagged-not-fixed).

## Architecture (not on this branch)

The two-service, RabbitMQ-based design this repo's name refers to was
built, but on a **separate line of commits that was never merged into
`master`** — reachable today only via these branches:

| Branch | Commit | Contains |
|---|---|---|
| `ms-admin` | `cb5e2e5` | `admin/` — Laravel 9 app, Product REST API |
| `ms-main` | `54817a6` | `admin/` + `main/` — adds the read-replica app |
| `RabbitMQ` | `748dde7` | + RabbitMQ queue wiring, `.env` config |
| `Data_Consistency_Between_Microservices` / `Internal_Http_Requests` | `fc5d45c` (both branches point to the same commit) | Final state: full product-sync jobs on both sides |

`master` and this line share a common ancestor (`1acae9e "Project
start"`); after that point `master` only ever received one more real
change — an automated Renovate MySQL-image-tag bump, merged via PR #1 in
November 2023 — no human commit ever added the app code to `master`.
Two *further* Renovate bumps (`renovate/mysql-8.x` → 8.4,
`renovate/mysql-26.x` → v26) were pushed later but **never merged** —
they only exist as open branches. GitHub's repo-level "last pushed" date
(2026-07-28) reflects that unmerged `renovate/mysql-26.x` push, not an
actual change to `master`, which has been static since the Nov 2023
merge.

### How the two apps talk to each other

Read directly from `fc5d45c` (`admin/app/...`, `main/app/...`), not
inferred from the repo name:

- **`admin`** — `ProductController@store/update/destroy` writes to its own
  `products` table, then dispatches `App\Jobs\{ProductCreated,
  ProductUpdated,ProductDeleted}` onto the queue. Both apps set
  `QUEUE_CONNECTION=rabbitmq` by default (`vladimir-yuldashev/
  laravel-queue-rabbitmq`), publishing to the same `default` queue name.
- **`main`** — declares job classes with the **same three names** in its
  own `App\Jobs` namespace, but with real `handle()` bodies
  (`Product::create/update/destroy`) that replay the change into `main`'s
  own database.
- **`admin`'s own copies of those three job classes have empty `handle()`
  bodies** (verified by reading all six files) — they're only ever meant
  to be picked up by `main`'s queue worker, not admin's own. Same class
  name, two different implementations, one shared queue: an unconventional
  but real way to get eventual consistency between two Laravel apps
  without a separate event-schema library.

### Running the real system (unverified in this pass)

Not exercised end-to-end here — this task's scope was documenting
`master`, not standing up the unmerged branches' full stack. To try it:

```bash
git checkout Data_Consistency_Between_Microservices
# admin/ and main/ are each a full Laravel app with their own
# docker-compose.yaml — copy .env.example to .env in each,
# then `docker-compose up` per service, plus a RabbitMQ broker
# (not itself included in either compose file).
```

Both `admin/.env.example` and `main/.env.example` point
`RABBITMQ_HOST=127.0.0.1` with `guest`/`guest` credentials — a RabbitMQ
broker isn't declared in either app's own `docker-compose.yaml`, so one
has to be run separately (e.g. the official `rabbitmq:management` image)
for the queue to have anywhere to connect.

## Known issues (flagged, not fixed)

This task's scope is a README only — nothing below was changed:

- **Hardcoded secrets in `master`'s `docker-compose.yaml`**: a real-shaped
  Laravel `APP_KEY` and a `root`/`root` MySQL password, both in plaintext
  in a public repo. Lower real-world exposure than a live external
  credential (there's no application on this branch to have actually run
  with this key), but still worth rotating/removing if this branch is
  ever built on.
- **`master` cannot build as committed** (see above) — the Dockerfile
  assumes a `composer.json` that was never added here.
- **The unmerged branches are stale**: `laravel/framework: ^9.19` on
  PHP `^8.1`, dated July 2022 — would need a dependency-currency pass
  before real use, independent of the merge question.
- **No `.gitignore` on `master`** (the other branches have one) and no
  `LICENSE` file anywhere in the repository.

## Repo layout summary

```
master (this branch)          ms-admin / ms-main / RabbitMQ / ...
├── Dockerfile                ├── admin/            (Laravel 9 app)
├── docker-compose.yaml       ├── main/             (Laravel 9 app, later branches)
├── docker/                   ├── Dockerfile
├── commond.txt               ├── docker-compose.yaml
                               ├── docker/
                               └── commond.txt
```
