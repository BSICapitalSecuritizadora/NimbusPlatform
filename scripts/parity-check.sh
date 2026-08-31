#!/usr/bin/env bash
#
# Runs the `parity` and `mysql` test groups against MySQL.
#
# The suite normally runs on SQLite, which is fast but does not behave like
# production everywhere it matters: collation decides what a UNIQUE index
# considers equal, `UPPER()` is accent-aware in one engine and ASCII-only in the
# other, identifier length limits differ, and generated columns are not written
# by the same code path. A rule can therefore pass on SQLite and mean something
# else in production.
#
# So the tests that depend on the database *being* a particular database are
# tagged `parity` and run here a second time, on the real thing. Tag a new test
# by putting `pest()->group('parity');` at the top of its file.
#
# The `mysql` group runs here too, and is a different thing: those tests do not
# have a SQLite counterpart at all. They open real concurrent connections to
# observe locks, gap locks and deadlocks, which SQLite cannot show because it
# serializes writers. They skip themselves on any other driver, so this script
# is the only place they ever execute -- without it they were dead weight.
#
# The database is created fresh and dropped afterwards, so nothing is left behind
# and nothing pre-existing is touched: the name is namespaced and the script
# refuses to run against anything else.
#
# Usage:
#   ./scripts/parity-check.sh                 # inside the app container / CI
#   ./vendor/bin/sail exec laravel.test ./scripts/parity-check.sh
#   composer test:parity
#
# Creating and dropping a database needs more rights than running tests does, so
# the two use separate credentials: an admin account for the DDL (root by
# default, which is what Sail and the CI service container provide) and the
# ordinary application user for the tests themselves.
#
# Env overrides: PARITY_DB_HOST, PARITY_DB_PORT, PARITY_DB_USERNAME,
# PARITY_DB_PASSWORD, PARITY_DB_DATABASE, PARITY_ADMIN_USERNAME,
# PARITY_ADMIN_PASSWORD, PARITY_KEEP_DATABASE=1

set -euo pipefail

DB_HOST="${PARITY_DB_HOST:-${DB_HOST:-mysql}}"
DB_PORT="${PARITY_DB_PORT:-${DB_PORT:-3306}}"
DB_USERNAME="${PARITY_DB_USERNAME:-${DB_USERNAME:-sail}}"
DB_PASSWORD="${PARITY_DB_PASSWORD:-${DB_PASSWORD:-password}}"
DB_DATABASE="${PARITY_DB_DATABASE:-nimbus_parity_check}"
ADMIN_USERNAME="${PARITY_ADMIN_USERNAME:-root}"
ADMIN_PASSWORD="${PARITY_ADMIN_PASSWORD:-$DB_PASSWORD}"

# A guard, not decoration: this script drops the database it is pointed at, so it
# only ever accepts a name that cannot be a real one.
case "$DB_DATABASE" in
    nimbus_parity_check*) ;;
    *)
        echo "Recusando: PARITY_DB_DATABASE deve comecar com 'nimbus_parity_check' (recebido: '$DB_DATABASE')." >&2
        echo "O script apaga o banco ao final e nao deve poder apontar para um banco real." >&2
        exit 1
        ;;
esac

mysql_admin() {
    mysql --host="$DB_HOST" --port="$DB_PORT" --user="$ADMIN_USERNAME" --password="$ADMIN_PASSWORD" --execute="$1"
}

cleanup() {
    if [ "${PARITY_KEEP_DATABASE:-0}" = "1" ]; then
        echo "==> Banco '$DB_DATABASE' preservado (PARITY_KEEP_DATABASE=1)."
        return
    fi

    echo "==> Removendo o banco '$DB_DATABASE'."
    mysql_admin "DROP DATABASE IF EXISTS \`$DB_DATABASE\`;" || true
}
trap cleanup EXIT

echo "==> Criando o banco '$DB_DATABASE' em $DB_HOST:$DB_PORT."
# The default charset and collation mirror the application database on purpose:
# running the parity check under friendlier settings than production would defeat
# the point of running it at all.
mysql_admin "DROP DATABASE IF EXISTS \`$DB_DATABASE\`;
             CREATE DATABASE \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
             GRANT ALL PRIVILEGES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'%';
             FLUSH PRIVILEGES;"

echo "==> Rodando os grupos 'parity' e 'mysql' no MySQL."
DB_CONNECTION=mysql \
DB_HOST="$DB_HOST" \
DB_PORT="$DB_PORT" \
DB_DATABASE="$DB_DATABASE" \
DB_USERNAME="$DB_USERNAME" \
DB_PASSWORD="$DB_PASSWORD" \
    php artisan test --group=parity --group=mysql "$@"
