#!/usr/bin/env bash
# Installe PostgreSQL (si besoin), crée l'utilisateur + la base, puis charge sql/01_creation_base.sql
# Usage : ./scripts/install_db.sh        (depuis la racine du projet, .env déjà rempli)
set -euo pipefail
cd "$(dirname "$0")/.."

[ -f .env ] || { echo "Erreur : crée d'abord .env (cp .env.example .env) et mets un mot de passe."; exit 1; }
set -a; . ./.env; set +a
: "${DB_HOST:=localhost}" "${DB_PORT:=5432}" "${DB_NAME:=bibliotheque}" "${DB_USER:=bibli_user}"
: "${DB_PASS:?DB_PASS manquant dans .env}"
[ "$DB_PASS" != "change_moi" ] || { echo "Erreur : change DB_PASS dans .env."; exit 1; }

if ! command -v psql >/dev/null; then
  echo ">> Installation de PostgreSQL..."
  sudo apt-get update && sudo apt-get install -y postgresql postgresql-client
fi


echo ">> Création de l'utilisateur '$DB_USER' et de la base '$DB_NAME'..."
if [ "$(id -u)" = 0 ]; then
  su postgres -c "psql -v ON_ERROR_STOP=1 -q -v u='$DB_USER' -v d='$DB_NAME' -v mdp='$DB_PASS'" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN', :'u') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'u') \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'u', :'mdp') \gexec
SELECT format('CREATE DATABASE %I OWNER %I', :'d', :'u') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'d') \gexec
SQL
else
  sudo -u postgres psql -v ON_ERROR_STOP=1 -q -v u="$DB_USER" -v d="$DB_NAME" -v mdp="$DB_PASS" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN', :'u') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'u') \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'u', :'mdp') \gexec
SELECT format('CREATE DATABASE %I OWNER %I', :'d', :'u') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'d') \gexec
SQL
fi

./scripts/reset_db.sh
