#!/usr/bin/env bash
# Tests du site web avec curl. Le site doit tourner (./scripts/run_web.sh) sur une base fraîche.
# Ces tests MODIFIENT la base (réservation, encaissement, ajout d'un livre) : ./scripts/reset_db.sh avant chaque série.
# Usage : BASE=http://localhost:8080 ./tests/tests_web.sh
BASE="${BASE:-http://localhost:8080}"
JAR_A="$(mktemp)"; JAR_B="$(mktemp)"
trap 'rm -f "$JAR_A" "$JAR_B" /tmp/web_body.$$' EXIT
OK=0; KO=0
pass() { echo "PASS  $1"; OK=$((OK+1)); }
fail() { echo "FAIL  $1  ($2)"; KO=$((KO+1)); }
# get JAR URL -> corps dans /tmp/web_body.$$, code HTTP dans $CODE
get()  { CODE=$(curl -s -o /tmp/web_body.$$ -w '%{http_code}' -b "$1" -c "$1" "$BASE/$2"); }
# post JAR URL "champs curl..." 
post() { local jar="$1" url="$2"; shift 2; CODE=$(curl -s -o /tmp/web_body.$$ -w '%{http_code}' -b "$jar" -c "$jar" -L "$@" "$BASE/$url"); }
contient() { grep -q -- "$1" /tmp/web_body.$$; }
csrf() { get "$1" "${2:-login.php}"; grep -o 'name="csrf" value="[^"]*"' /tmp/web_body.$$ | head -1 | sed 's/.*value="//;s/"$//'; }

echo "== Accès sans connexion"
CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/catalogue.php")
[ "$CODE" = "302" ] && pass "W1 catalogue sans session -> redirection vers login" || fail "W1" "code $CODE"

echo "== Connexion"
T=$(csrf "$JAR_A")
post "$JAR_A" login.php -d "csrf=$T" -d "login=karim.benali" -d "mot_de_passe=mauvais"
contient "incorrect" && pass "W2 mauvais mot de passe refusé" || fail "W2" "message absent"
post "$JAR_A" login.php -d "csrf=jeton_bidon" -d "login=karim.benali" -d "mot_de_passe=Biblio2026!"
[ "$CODE" = "403" ] && pass "W3 jeton CSRF invalide -> 403" || fail "W3" "code $CODE"
T=$(csrf "$JAR_A")
post "$JAR_A" login.php -d "csrf=$T" -d "login=karim.benali" -d "mot_de_passe=Biblio2026!"
contient "Catalogue" && pass "W4 connexion adhérent (karim.benali)" || fail "W4" "page catalogue absente"

echo "== Catalogue (SELECT)"
get "$JAR_A" catalogue.php
contient "Les Mis" && pass "W5 catalogue affiché" || fail "W5" "titre absent"
get "$JAR_A" "catalogue.php?q=orwell"
{ contient "1984" && ! contient "Les Mis"; } && pass "W6 recherche par auteur (orwell)" || fail "W6" "résultat inattendu"
get "$JAR_A" "catalogue.php?q=%25%27%3B--"
[ "$CODE" = "200" ] && pass "W7 saisie hostile dans la recherche : pas d'erreur serveur" || fail "W7" "code $CODE"

echo "== Réservation (INSERT) et annulation (UPDATE)"
T=$(csrf "$JAR_A" catalogue.php)
post "$JAR_A" reserver.php -d "id_livre=2"
[ "$CODE" = "403" ] && pass "W8 réservation sans jeton CSRF refusée" || fail "W8" "code $CODE"
post "$JAR_A" reserver.php -d "csrf=$T" -d "id_livre=2"
contient "Réservation enregistrée" && pass "W9 réservation du livre 2 (aucun exemplaire disponible)" || fail "W9" "message absent"
post "$JAR_A" reserver.php -d "csrf=$T" -d "id_livre=2"
contient "déjà une réservation" && pass "W10 doublon refusé" || fail "W10" "message absent"
post "$JAR_A" reserver.php -d "csrf=$T" -d "id_livre=4"
contient "disponible" && pass "W11 livre disponible : réservation refusée" || fail "W11" "message absent"
get "$JAR_A" mes_emprunts.php
ID_RES=$(grep -B3 'name="id_reservation"' /tmp/web_body.$$ | grep -o 'name="id_reservation" value="[0-9]*"' | tail -1 | grep -o '[0-9]*')
post "$JAR_A" annuler_reservation.php -d "csrf=$T" -d "id_reservation=${ID_RES:-0}"
contient "Réservation annulée" && pass "W12 annulation de la réservation n°${ID_RES}" || fail "W12" "message absent"
get "$JAR_A" biblio/retards.php
[ "$CODE" = "403" ] && pass "W13 un adhérent n'accède pas à l'espace bibliothécaire" || fail "W13" "code $CODE"
post "$JAR_A" logout.php -d "csrf=$T"
get "$JAR_A" catalogue.php
[ "$CODE" = "302" ] && pass "W14 déconnexion effective" || fail "W14" "code $CODE"

echo "== Espace bibliothécaire"
T=$(csrf "$JAR_B")
post "$JAR_B" login.php -d "csrf=$T" -d "login=julie.moreau" -d "mot_de_passe=Biblio2026!"
get "$JAR_B" biblio/retards.php
contient "Emprunts en retard" && pass "W15 liste des retards (bibliothécaire)" || fail "W15" "page absente (code $CODE)"
ID_EMP=$(grep -o 'name="id_emprunt" value="[0-9]*"' /tmp/web_body.$$ | head -1 | grep -o '[0-9]*')
T=$(csrf "$JAR_B" catalogue.php)
post "$JAR_B" biblio/retards.php -d "csrf=$T" -d "id_emprunt=${ID_EMP:-0}"
contient "encaissée" && pass "W16 encaissement de l'amende de l'emprunt n°${ID_EMP}" || fail "W16" "message absent"
post "$JAR_B" biblio/ajouter_livre.php -d "csrf=$T" -d "isbn=123" -d "titre=Test" -d "langue=Français" -d "annee=2024" -d "pages=100" -d "editeur=1" -d "categorie=2" -d "auteur=1"
contient "ISBN" && pass "W17 ISBN invalide refusé" || fail "W17" "message absent"
post "$JAR_B" biblio/ajouter_livre.php -d "csrf=$T" -d "isbn=9780000000071" -d "titre=Livre de test" -d "langue=Français" -d "annee=2024" -d "pages=100" -d "editeur=1" -d "categorie=2" -d "auteur=1"
contient "Livre ajouté" && pass "W18 ajout d'un livre (INSERT)" || fail "W18" "message absent"
post "$JAR_B" biblio/ajouter_livre.php -d "csrf=$T" -d "isbn=9780000000071" -d "titre=Doublon" -d "langue=Français" -d "annee=2024" -d "pages=100" -d "editeur=1" -d "categorie=2" -d "auteur=1"
contient "existe déjà" && pass "W19 ISBN en double refusé par la base" || fail "W19" "message absent"

echo
echo "Résultat : $OK réussi(s), $KO échec(s)"
[ "$KO" -eq 0 ]
