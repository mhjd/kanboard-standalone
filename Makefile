DOCKER ?= docker
DOCKER_IMAGE := docker.io/kanboard/kanboard
DOCKER_TAG := main
PHP_IMAGE ?= php:8.2-cli
VERSION := $(shell git rev-parse --short HEAD)

.PHONY: archive fixtures test test-sqlite test-mysql test-postgres sql \
	build dev format lint ci ci-docker docker-image docker-images docker-run docker-sh

archive:
	@ echo "Build archive: version=$(VERSION)"
	@ git archive --format=zip --prefix=kanboard/ $(VERSION) -o kanboard-$(VERSION).zip

# Build + validate the minimal Kanboard SQLite fixture for tests
fixtures:
	@ $(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) php scripts/fixtures/create_minimal_fixture.php
	@ $(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) php scripts/fixtures/verify_minimal_fixture.php
	@ $(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) php scripts/fixtures/round_trip_minimal_fixture.php

# Run unit/integration tests in a PHP container (SQLite config)
# Falls back to fixture round-trip if phpunit is not installed.
test:
	@ if [ -x ./vendor/bin/phpunit ]; then \
		$(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) ./vendor/bin/phpunit -c tests/units.sqlite.xml; \
	else \
		echo "phpunit not installed; running fixture round-trip tests instead."; \
		$(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) php scripts/fixtures/create_minimal_fixture.php; \
		$(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) php scripts/fixtures/verify_minimal_fixture.php; \
		$(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) php scripts/fixtures/round_trip_minimal_fixture.php; \
	fi

test-sqlite:
	@ ./vendor/bin/phpunit -c tests/units.sqlite.xml

test-mysql:
	@ ./vendor/bin/phpunit -c tests/units.mysql.xml

test-postgres:
	@ ./vendor/bin/phpunit -c tests/units.postgres.xml

# Start a local Kanboard container (SQLite) for manual dev
dev:
	@ $(DOCKER) compose -f docker-compose.sqlite.yml up

# Build a release archive artifact (placeholder for future binary packaging)
build: archive

# Run the formatter (placeholder until a formatter is configured)
format:
	@ echo "No formatter configured yet."

# Run a basic syntax lint on a representative entrypoint
lint:
	@ $(DOCKER) run --rm -v "$(CURDIR)":/app -w /app $(PHP_IMAGE) php -l index.php >/dev/null

# CI: format + lint + test + build + fixtures
ci: format lint test build fixtures

# Docker-friendly CI alias for headless environments
ci-docker: ci

sql:
	@ pg_dump --schema-only --no-owner --no-privileges --quote-all-identifiers -n public --file app/Schema/Sql/postgres.sql kanboard
	@ pg_dump -d kanboard --column-inserts --data-only --table settings >> app/Schema/Sql/postgres.sql
	@ pg_dump -d kanboard --column-inserts --data-only --table links >> app/Schema/Sql/postgres.sql

	@ mysqldump -uroot --quote-names --no-create-db --skip-comments --no-data --single-transaction kanboard | sed 's/ AUTO_INCREMENT=[0-9]*//g' > app/Schema/Sql/mysql.sql
	@ mysqldump -uroot --quote-names --no-create-info --skip-comments --no-set-names kanboard settings >> app/Schema/Sql/mysql.sql
	@ mysqldump -uroot --quote-names --no-create-info --skip-comments --no-set-names kanboard links >> app/Schema/Sql/mysql.sql

	@ let password_hash=`php -r "echo password_hash('admin', PASSWORD_DEFAULT);"` ;\
	echo "INSERT INTO users (username, password, role) VALUES ('admin', '$$password_hash', 'app-admin');" >> app/Schema/Sql/mysql.sql ;\
	echo "INSERT INTO public.users (username, password, role) VALUES ('admin', '$$password_hash', 'app-admin');" >> app/Schema/Sql/postgres.sql

	@ let mysql_version=`echo 'select version from schema_version;' | mysql -N -uroot kanboard` ;\
	echo "INSERT INTO schema_version VALUES ('$$mysql_version');" >> app/Schema/Sql/mysql.sql

	@ let pg_version=`psql -U postgres -A -c 'copy(select version from schema_version) to stdout;' kanboard` ;\
	echo "INSERT INTO public.schema_version VALUES ('$$pg_version');" >> app/Schema/Sql/postgres.sql

	@ grep -v "SET idle_in_transaction_session_timeout = 0;" app/Schema/Sql/postgres.sql > temp && mv temp app/Schema/Sql/postgres.sql

docker-image:
	@ $(DOCKER) buildx build --load --build-arg VERSION=main.$(VERSION) -t $(DOCKER_IMAGE):$(DOCKER_TAG) .

docker-images:
	$(DOCKER) buildx build \
		--platform linux/amd64,linux/arm64,linux/arm/v7,linux/arm/v6 \
		--file Dockerfile \
		--build-arg VERSION=main.$(VERSION) \
		--tag $(DOCKER_IMAGE):$(VERSION) \
		.

docker-run:
	@ $(DOCKER) run --rm --name=kanboard -p 80:80 -p 443:443 $(DOCKER_IMAGE):$(DOCKER_TAG)

docker-sh:
	@ $(DOCKER) exec -ti kanboard bash
