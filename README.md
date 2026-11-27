# SAÉ BD / Réseau — Bibliothèque universitaire (L3-I, CY Cergy Paris Université)

Application de gestion d'une bibliothèque universitaire :
- **Base de données** PostgreSQL (`sql/`)
- **Partie réseau** : client Python ↔ serveur applicatif Java (TCP, protocole texte `COMMANDE|champ|champ`) (`java/`, `python/`)
- **Site web** PHP pour les adhérents et les bibliothécaires (`php/`)

> README en cours de rédaction — complété à chaque étape.

## 1. Installer la base (Ubuntu)

```bash
cp .env.example .env        # puis édite .env : choisis un mot de passe pour DB_PASS
./scripts/install_db.sh     # installe PostgreSQL si besoin, crée l'utilisateur + la base, charge les données
```

Recharger la base à l'état initial (avant chaque série de tests) : `./scripts/reset_db.sh`

Comptes de test (site web) : identifiant `prenom.nom` (ex. `karim.benali`), mot de passe `Biblio2026!`
- `karim.benali`, `emma.bernard`, `amadou.diallo` (inactif), `sarah.petit`, `hugo.robert` = **adhérents**
- `julie.moreau`, `thomas.laurent`, `linh.nguyen`, `nicolas.michel`, `ines.lefebvre` = **bibliothécaires**

## 2. Partie réseau (serveur Java + client Python)

Prérequis Ubuntu : `sudo apt install default-jdk libpostgresql-jdbc-java netcat-openbsd python3`

```bash
# Terminal 1 : le serveur applicatif (port 5000)
./scripts/run_serveur.sh

# Terminal 2 : le client du comptoir
python3 python/client_bibliotheque.py            # bibliothécaire de test : 2 (Laurent Thomas)

# Terminal 3 : la série de tests automatique avec netcat (recharge la base, affiche PASS/FAIL)
./tests/tests_netcat.sh
RUN_R8=1 ./tests/tests_netcat.sh                 # ajoute le test d'inactivité (attend 60 s)
```

Tests manuels :
- **R9 (client seul, netcat en serveur)** : terminal A `nc -l 5001`, terminal B `python3 python/client_bibliotheque.py localhost 5001`, saisir 2 ; dans A taper `HELLO_OK|Laurent|Thomas` puis, après le choix 4 du client, `BYE`.
- **R10 (serveur arrêté)** : lancer le client, se connecter, arrêter le serveur (Ctrl+C), faire « 1 » : le client tente 3 reconnexions puis affiche « serveur injoignable ».

Protocole (voir le rapport) : `HELLO|id` · `ADH|id_adh` · `PRET|id_adh|code` · `RETOUR|code` · `QUIT`.
Réponses : `HELLO_OK|nom|prenom`, `ADH_OK|nom|prenom|type|nb_en_cours|amendes`, `PRET_OK|id|retour_prevu|reservation_honoree(0/1)`,
`RETOUR_OK|id|jours_retard|montant|reservation_en_attente(0/1)`, `BYE`, `TIMEOUT`, `XXX_KO|Exx`, `ERR|Exx`.

## 3. Contenu

| Fichier | Rôle |
|---|---|
| `sql/01_creation_base.sql` | DDL + jeu de données initial |
| `sql/02_requetes_select.sql` | Les 10 requêtes SELECT (questions à la base) |
| `tests/test_contraintes.sql` | Preuve que cardinalités et règles métier sont garanties par la base |
| `java/Serveur.java` | Serveur applicatif TCP (JDBC → PostgreSQL) |
| `python/client_bibliotheque.py` | Client réseau du comptoir |
| `tests/tests_netcat.sh` | Tests fonctionnels (T) et de robustesse (R) avec netcat |
| `scripts/run_serveur.sh` | Compile et lance le serveur (lit `.env`) |
