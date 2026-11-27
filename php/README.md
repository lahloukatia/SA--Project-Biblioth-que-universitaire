# Site web PHP – Bibliothèque universitaire

Site pour les **adhérents** et les **bibliothécaires**, branché sur la même base PostgreSQL que le serveur Java.

## Lancer
```bash
sudo apt install php-cli php-pgsql        # PHP >= 7.4 et l'extension pdo_pgsql
cp .env.example .env                      # le site lit le même .env que le serveur Java (DB_USER, DB_PASS, DB_URL…)
./scripts/run_web.sh                      # http://localhost:8080
./tests/tests_web.sh                      # tests curl (modifient la base : ./scripts/reset_db.sh avant)
```
Variables lues : `DB_HOST` (localhost), `DB_PORT` (5432), `DB_NAME` (bibliotheque), `DB_USER`, `DB_PASS`,
ou `DB_URL` au format JDBC (`jdbc:postgresql://hote:port/base`).
Sur le serveur intranet : copier le dossier `php/` dans `public_html/`, puis créer `php/config.local.php`
(ignoré par git) : `<?php return ['DB_HOST'=>'…','DB_NAME'=>'…','DB_USER'=>'…','DB_PASS'=>'…'];`

Comptes de démonstration (mot de passe `Biblio2026!`) : adhérents `karim.benali`, `emma.bernard`, `amadou.diallo` (inactif),
`sarah.petit`, `hugo.robert` ; bibliothécaires `julie.moreau`, `thomas.laurent`, `linh.nguyen`, `nicolas.michel`, `ines.lefebvre`.

## Pages et exigences du sujet
| Page | Rôle | Exigence |
|---|---|---|
| `login.php`, `logout.php` | Connexion login / mot de passe, sessions | table des utilisateurs, mots de passe hachés (`password_verify` sur le hash bcrypt de `personne`), sessions |
| `catalogue.php` | Recherche titre / auteur / catégorie, disponibilité | **extraction (SELECT)** : jointures, sous-requêtes, agrégats |
| `reserver.php` | Réservation d'un livre sans exemplaire disponible | **modification (INSERT)** dans `reservation` |
| `annuler_reservation.php` | Annulation d'une réservation en attente | **modification (UPDATE)** |
| `mes_emprunts.php` | Emprunts, amendes, réservations de l'adhérent | SELECT |
| `biblio/retards.php` | Retards, amendes par adhérent (GROUP BY), encaissement | SELECT + UPDATE (`statut_amende`, `date_paiement`) |
| `biblio/ajouter_livre.php` + `couverture.php` | Ajout d'un livre avec image de couverture | INSERT dans `livre` / `ecrire`, **BLOB** (`couverture_livre`) |

## Sécurité mise en place
- requêtes **préparées** partout (PDO, `ATTR_EMULATE_PREPARES = false`) : pas d'injection SQL ; jokers `LIKE` neutralisés ;
- mots de passe jamais en clair : `password_verify` (bcrypt) ; réponse à temps constant si le login n'existe pas ;
- `session_regenerate_id` à la connexion, cookie `HttpOnly` + `SameSite=Lax` (+ `Secure` en HTTPS), expiration après 30 min d'inactivité ;
- **CSRF** : jeton sur tous les formulaires POST ; blocage 60 s après 5 échecs de connexion ;
- sortie HTML échappée (`e()`), en-têtes `Content-Security-Policy`, `X-Frame-Options`, `nosniff` ;
- contrôle des rôles (un adhérent n'accède pas à `biblio/`) et propriété des données (un adhérent n'annule que SES réservations) ;
- les règles métier du serveur Java sont reprises (adhérent actif, une réservation en attente par livre) ;
  les nouveaux identifiants sont générés par la base (colonnes `IDENTITY`, `INSERT ... RETURNING`), comme dans le serveur Java.
