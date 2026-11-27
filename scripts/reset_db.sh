#!/usr/bin/env bash
# Recharge la base à l'état initial (DDL + jeu de données). À lancer avant chaque série de tests.
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a
: "${DB_HOST:=localhost}" "${DB_PORT:=5432}" "${DB_NAME:=bibliotheque}" "${DB_USER:=bibli_user}"
PGPASSWORD="$DB_PASS" psql -h "$DB_HOST" -p "$DB_PORT" -U "$DB_USER" -d "$DB_NAME" \
    -v ON_ERROR_STOP=1 -q -f sql/01_creation_base.sql
echo ">> Base '$DB_NAME' rechargée (état initial)."
