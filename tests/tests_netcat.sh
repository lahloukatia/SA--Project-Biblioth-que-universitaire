#!/usr/bin/env bash
# Série de tests fonctionnels (T) et de robustesse (R) du serveur applicatif, avec netcat en mode CLIENT.
# Prérequis : serveur lancé (./scripts/run_serveur.sh dans un autre terminal).
# Usage : ./tests/tests_netcat.sh            (recharge la base, lance la série, affiche PASS/FAIL)
#         RUN_R8=1 ./tests/tests_netcat.sh   (ajoute le test d'inactivité : attend le délai du serveur)
# Les valeurs dépendant de la date du jour (retour prévu, jours de retard, amende) sont calculées ici.
cd "$(dirname "$0")/.."
set -a; . ./.env; set +a
HOST=${HOST_SERVEUR:-localhost}; PORT=${PORT_SERVEUR:-5000}
PSQL=(psql -h "${DB_HOST:-localhost}" -p "${DB_PORT:-5432}" -U "$DB_USER" -d "$DB_NAME" -tA)
export PGPASSWORD="$DB_PASS"

OK=0; KO=0
nc_envoi() { printf "$1" | nc -w 2 "$HOST" "$PORT"; }     # $1 = texte envoyé (avec \n)
verifie() {                                                # $1=nom  $2=attendu  $3=obtenu
  if [ "$2" == "$3" ]; then echo "PASS  $1"; OK=$((OK+1));
  else echo "FAIL  $1"; echo "   attendu : $(echo "$2" | tr '\n' '~')"; echo "   obtenu  : $(echo "$3" | tr '\n' '~')"; KO=$((KO+1)); fi
}
test_nc() { verifie "$1" "$3" "$(nc_envoi "$2")"; }       # $1=nom $2=envoi $3=attendu

AUJ=$(date +%F)
PREVU=$(date -d "$AUJ + 14 days" +%F)
JOURS=$(( ( $(date -d "$AUJ" +%s) - $(date -d 2026-10-19 +%s) ) / 86400 ))     # retard de l'emprunt 1
MONTANT=$(printf "%d.%02d" $(( JOURS*20/100 )) $(( JOURS*20%100 )))
TOTAL=$(printf "%d.%02d" $(( (100+JOURS*20)/100 )) $(( (100+JOURS*20)%100 )))   # 1.00 déjà dû + amende du retour T11

./scripts/reset_db.sh >/dev/null || exit 1
H='HELLO|2\n'; HO=$'HELLO_OK|Laurent|Thomas'
echo "=== Tests fonctionnels (jeu de données initial rechargé) ==="
test_nc "T1  identifier le poste + fermeture (T14)" "${H}QUIT\n"            "$HO"$'\nBYE'
test_nc "T2  identification refusée"                'HELLO|99\nQUIT\n'       $'HELLO_KO|E01\nBYE'
test_nc "T3  consulter un adhérent"                 "${H}ADH|1\nQUIT\n"      "$HO"$'\nADH_OK|Benali|Karim|etudiant|1|1.00\nBYE'
test_nc "T4  adhérent inconnu"                      "${H}ADH|999\nQUIT\n"    "$HO"$'\nADH_KO|E10\nBYE'
test_nc "T5  prêt refusé : amende impayée"          "${H}PRET|1|EX004\nQUIT\n" "$HO"$'\nPRET_KO|E12\nBYE'
test_nc "T6  prêt refusé : adhérent inactif"        "${H}PRET|3|EX004\nQUIT\n" "$HO"$'\nPRET_KO|E11\nBYE'
test_nc "T7  prêt refusé : exemplaire déjà emprunté" "${H}PRET|2|EX002\nQUIT\n" "$HO"$'\nPRET_KO|E14\nBYE'
test_nc "T8  prêt refusé : exemplaire abîmé"        "${H}PRET|2|EX003\nQUIT\n" "$HO"$'\nPRET_KO|E14\nBYE'
test_nc "T9  prêt refusé : exemplaire inconnu"      "${H}PRET|2|EX999\nQUIT\n" "$HO"$'\nPRET_KO|E13\nBYE'
test_nc "T10 prêt accepté (INSERT)"                 "${H}PRET|2|EX004\nQUIT\n" "$HO"$'\nPRET_OK|6|'"$PREVU"$'|0\nBYE'
test_nc "T11 retour en retard + réservation en attente (UPDATE)" "${H}RETOUR|EX001\nQUIT\n" "$HO"$'\nRETOUR_OK|1|'"$JOURS|$MONTANT"$'|1\nBYE'
test_nc "T12 retour refusé : aucun emprunt en cours" "${H}RETOUR|EX005\nQUIT\n" "$HO"$'\nRETOUR_KO|E15\nBYE'
test_nc "T13 retour refusé : exemplaire inconnu"    "${H}RETOUR|EX999\nQUIT\n" "$HO"$'\nRETOUR_KO|E13\nBYE'
test_nc "T15 prêt refusé : exemplaire réservé par un autre" "${H}PRET|2|EX001\nQUIT\n" "$HO"$'\nPRET_KO|E16\nBYE'
test_nc "T16 prêt avec réservation honorée"         "${H}PRET|4|EX001\nQUIT\n" "$HO"$'\nPRET_OK|7|'"$PREVU"$'|1\nBYE'
test_nc "T17 retour sans retard"                    "${H}RETOUR|EX004\nQUIT\n" "$HO"$'\nRETOUR_OK|6|0|0.00|0\nBYE'

echo "=== Tests de robustesse ==="
test_nc "R1  commandes avant identification" 'ADH|1\nPRET|1|EX004\nRETOUR|EX001\nQUIT\n' $'ADH_KO|E02\nPRET_KO|E02\nRETOUR_KO|E02\nBYE'
test_nc "R2  messages mal formés" "${H}COUCOU\nADH|abc\nADH\nPRET|1\nHELLO|2|3\nQUIT\n" "$HO"$'\nERR|E03\nERR|E03\nERR|E03\nERR|E03\nERR|E03\nBYE'
AVANT=$("${PSQL[@]}" -c "SELECT count(*) FROM emprunt")
test_nc "R3  injection SQL refusée" "${H}PRET|1|EX004' OR '1'='1\nADH|1; DROP TABLE emprunt\nQUIT\n" "$HO"$'\nERR|E03\nERR|E03\nBYE'
APRES=$("${PSQL[@]}" -c "SELECT count(*) FROM emprunt" 2>&1)
verifie "R3b la table emprunt existe toujours, même nombre de lignes" "$AVANT" "$APRES"
LONG=$(printf '%9.0s' $(seq 1 300) | tr ' ' 'x')
test_nc "R4  ligne de 300 caractères refusée, session toujours utilisable" "${H}${LONG}\nADH|1\nQUIT\n" "$HO"$'\nERR|E03\nADH_OK|Benali|Karim|etudiant|0|'"$TOTAL"$'\nBYE'
test_nc "R5  trois identifications ratées : le serveur ferme" 'HELLO|91\nHELLO|92\nHELLO|93\nADH|1\n' $'HELLO_KO|E01\nHELLO_KO|E01\nHELLO_KO|E01'
# R6 : deux clients en même temps
( printf 'HELLO|2\nADH|1\nQUIT\n' | nc -w 2 "$HOST" "$PORT" > /tmp/c1.txt ) &
( printf 'HELLO|4\nADH|2\nQUIT\n' | nc -w 2 "$HOST" "$PORT" > /tmp/c2.txt ) & wait
verifie "R6  deux clients simultanés (client 1)" $'HELLO_OK|Laurent|Thomas\nADH_OK|Benali|Karim|etudiant|0|'"$TOTAL"$'\nBYE' "$(cat /tmp/c1.txt)"
verifie "R6  deux clients simultanés (client 2)" $'HELLO_OK|Michel|Nicolas\nADH_OK|Bernard|Emma|etudiant|1|0.00\nBYE' "$(cat /tmp/c2.txt)"
# R6b : deux prêts simultanés du même exemplaire : un seul doit réussir
( printf 'HELLO|2\nPRET|4|EX006\nQUIT\n' | nc -w 2 "$HOST" "$PORT" > /tmp/p1.txt ) &
( printf 'HELLO|2\nPRET|5|EX006\nQUIT\n' | nc -w 2 "$HOST" "$PORT" > /tmp/p2.txt ) & wait
NB_OK=$(cat /tmp/p1.txt /tmp/p2.txt | grep -c '^PRET_OK'); NB_KO=$(cat /tmp/p1.txt /tmp/p2.txt | grep -c '^PRET_KO|E14')
verifie "R6b prêt simultané du même exemplaire : 1 accepté + 1 refusé (E14)" "1/1" "$NB_OK/$NB_KO"
# R7 : coupure brutale (pas de QUIT) puis nouvelle connexion
printf 'HELLO|2\n' | nc -w 1 "$HOST" "$PORT" >/dev/null
test_nc "R7  nouvelle connexion après coupure brutale" "${H}QUIT\n" "$HO"$'\nBYE'
if [ "${RUN_R8:-0}" = "1" ]; then
  ATTENTE=$(( ${DELAI_INACTIVITE_S:-60} + 5 ))
  verifie "R8  inactivité : le serveur envoie TIMEOUT et ferme" "TIMEOUT" "$( (sleep $((ATTENTE)) ) | nc -w $ATTENTE "$HOST" "$PORT")"
else echo "SKIP  R8  inactivité (lancer avec RUN_R8=1, attend ${DELAI_INACTIVITE_S:-60} s)"; fi
echo "SKIP  R9 (client Python seul avec 'nc -l') et R10 (serveur arrêté) : tests manuels, voir README"
echo; echo "Résultat : $OK réussis, $KO échoués"; [ "$KO" -eq 0 ]
