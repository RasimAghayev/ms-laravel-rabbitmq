.PHONY: build test verify up run down logs shell-admin shell-main audit lint migrate seed clean

COMPOSE = docker compose

# ---------------------------------------------------------------------------
# build - the default (runtime) target for admin_app/main_app/main_worker.
# ---------------------------------------------------------------------------
build:
	$(COMPOSE) build admin_app main_app main_worker

# ---------------------------------------------------------------------------
# test - the Docker standard's `test` stage. Builds+runs admin_test and
# main_test (profile "test", never started by `up`) against the real,
# already-healthy admin_db/main_db/rabbitmq services - the same
# real-MySQL contract docs/TESTING_STRATEGY.md deliberately tests
# against. Needs the db/rabbitmq services already up (see `verify`).
# ---------------------------------------------------------------------------
test:
	$(COMPOSE) up -d admin_db main_db rabbitmq
	$(COMPOSE) --profile test build admin_test main_test
	$(COMPOSE) --profile test run --rm admin_test
	$(COMPOSE) --profile test run --rm main_test

# ---------------------------------------------------------------------------
# verify = build + test. `up` depends on this - nothing is brought up
# until the image builds and its test stage passes (CLAUDE.md standing
# decision 2). See docs/BASELINE.md for the one documented pre-existing
# exception (a composer-audit CVE finding unrelated to this phase).
# ---------------------------------------------------------------------------
verify: build test

up: verify
	$(COMPOSE) up -d
	$(COMPOSE) ps

run: up

down:
	$(COMPOSE) down

logs:
	$(COMPOSE) logs -f

shell-admin:
	$(COMPOSE) exec admin_app sh

shell-main:
	$(COMPOSE) exec main_app sh

migrate:
	$(COMPOSE) exec -T admin_app php artisan migrate --force
	$(COMPOSE) exec -T main_app php artisan migrate --force

seed:
	$(COMPOSE) exec -T admin_app php artisan db:seed
	$(COMPOSE) exec -T main_app php artisan db:seed

audit:
	$(COMPOSE) --profile test run --rm --entrypoint "composer audit --no-interaction --abandoned=report" admin_test
	$(COMPOSE) --profile test run --rm --entrypoint "composer audit --no-interaction --abandoned=report" main_test

lint:
	@echo "No formatter/static-analysis dev dependency installed in either app yet (see docs/TEST_REPORT.md) - nothing to run."

# clean never deletes the named DB volumes (admin_db_data/main_db_data)
# without an explicit confirmation - CLAUDE.md's Docker standard forbids
# silent data loss. Pass CONFIRM=1 to also drop them.
clean:
	$(COMPOSE) down --rmi local
ifeq ($(CONFIRM),1)
	$(COMPOSE) down -v
else
	@echo "Volumes (admin_db_data, main_db_data) kept. Re-run as 'make clean CONFIRM=1' to also delete them."
endif
