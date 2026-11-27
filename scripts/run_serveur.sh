#!/usr/bin/env bash
# Compile et lance le serveur applicatif Java (port 5000 par défaut).
# Prérequis Ubuntu : sudo apt install default-jdk libpostgresql-jdbc-java
set -euo pipefail
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a
JAR=/usr/share/java/postgresql.jar
[ -f "$JAR" ] || JAR=$(ls lib/postgresql*.jar 2>/dev/null | head -1)
[ -n "${JAR:-}" ] || { echo "Driver JDBC introuvable : sudo apt install libpostgresql-jdbc-java"; exit 1; }
mkdir -p java/out
javac -d java/out java/Serveur.java
exec java -Dfile.encoding=UTF-8 -Dstdout.encoding=UTF-8 -cp "java/out:$JAR" Serveur
