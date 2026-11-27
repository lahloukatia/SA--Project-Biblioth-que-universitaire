-- =====================================================================
--  Preuve que les cardinalités et règles métier sont garanties PAR LA BASE
--  (utile pour répondre à la question du prof sur les "1,1" du MCD).
--  Chaque test DOIT afficher une ERREUR ; tout est annulé (ROLLBACK).
--  Usage : psql -d bibliotheque -f tests/test_contraintes.sql
-- =====================================================================
\set ON_ERROR_STOP off
\echo '--- C1 (1,1) : un livre sans éditeur est refusé (NOT NULL)'
BEGIN; INSERT INTO livre (isbn,titre_livre,langue_livre,annee_publication,nombre_pages,id_categorie)
       VALUES ('9780000000071','Test','Français',2020,100,1); ROLLBACK;

\echo '--- C2 (1,1) : un exemplaire sans livre est refusé'
BEGIN; INSERT INTO exemplaire (code_inventaire,id_emplacement) VALUES ('EX900',1); ROLLBACK;

\echo '--- C3 (1,1) : un emprunt sans bibliothécaire est refusé'
BEGIN; INSERT INTO emprunt (date_retour_prevue,id_adherent,id_exemplaire)
       VALUES (CURRENT_DATE+14,1,6); ROLLBACK;

\echo '--- C4 (1,1) : une réservation vers un livre inexistant est refusée (clé étrangère)'
BEGIN; INSERT INTO reservation (id_adherent,id_livre) VALUES (1,999); ROLLBACK;

\echo '--- C5 (1,N) : un livre sans auteur est refusé à la fin de la transaction'
BEGIN; INSERT INTO livre (isbn,titre_livre,langue_livre,annee_publication,nombre_pages,id_editeur,id_categorie)
       VALUES ('9780000000071','Sans auteur','Français',2020,100,1,1); COMMIT;

\echo '--- C6 (héritage exclusif) : une personne ne peut pas être adhérent ET bibliothécaire'
BEGIN; INSERT INTO adherent (id_personne,type_adherent) VALUES (6,'etudiant'); ROLLBACK;

\echo '--- C7 (héritage total) : une personne sans rôle est refusée à la fin de la transaction'
BEGIN; INSERT INTO personne (nom,prenom,email,date_naissance,identifiant_connexion,mot_de_passe_hache)
       VALUES ('Dupont','Jean','jean.dupont@etu.cyu.fr','2000-01-01','jean.dupont', repeat('x',60)); COMMIT;

\echo '--- C8 : deux emprunts en cours sur le même exemplaire sont refusés'
BEGIN; INSERT INTO emprunt (date_retour_prevue,id_adherent,id_bibliothecaire,id_exemplaire)
       VALUES (CURRENT_DATE+14,2,2,1); ROLLBACK;

\echo '--- C9 : deux réservations en attente du même adhérent pour le même livre sont refusées'
BEGIN; INSERT INTO reservation (id_adherent,id_livre) VALUES (4,1); ROLLBACK;

\echo '--- C10 : amende "payee" sans date de paiement est refusée'
BEGIN; UPDATE emprunt SET statut_amende='payee' WHERE id_emprunt=5; ROLLBACK;

\echo '--- C11 : date de retour prévue différente de emprunt + 14 jours refusée'
BEGIN; INSERT INTO emprunt (date_retour_prevue,id_adherent,id_bibliothecaire,id_exemplaire)
       VALUES (CURRENT_DATE+20,2,2,6); ROLLBACK;

\echo '--- OK attendu : personne + rôle dans la même transaction est ACCEPTÉ'
BEGIN;
INSERT INTO personne (nom,prenom,email,date_naissance,identifiant_connexion,mot_de_passe_hache)
       VALUES ('Dupont','Jean','jean.dupont@etu.cyu.fr','2000-01-01','jean.dupont', repeat('x',60));
INSERT INTO adherent (id_personne,type_adherent)
       VALUES (currval(pg_get_serial_sequence('personne','id_personne')),'etudiant');
COMMIT;
DELETE FROM adherent WHERE id_personne = (SELECT id_personne FROM personne WHERE identifiant_connexion='jean.dupont');
DELETE FROM personne WHERE identifiant_connexion='jean.dupont';
