#!/usr/bin/env bash
# Lance le site PHP en local (serveur de développement intégré à PHP).
# Prérequis Ubuntu : sudo apt install php-cli php-pgsql
# Usage : ./scripts/run_web.sh [port]        puis ouvrir http://localhost:8080
set -euo pipefail
cd "$(dirname "$0")/.."
PORT="${1:-8080}"
if ! php -m | grep -qi '^pdo_pgsql$'; then
  echo "Extension PHP pdo_pgsql absente : sudo apt install php-pgsql" >&2; exit 1
fi
if [ ! -f .env ] && [ -z "${DB_PASS:-}" ]; then
  echo "Fichier .env introuvable (cp .env.example .env) ou variable DB_PASS non définie" >&2; exit 1
fi
echo "Site sur http://localhost:${PORT}  (Ctrl+C pour arrêter)"
exec php -S "0.0.0.0:${PORT}" -t php
