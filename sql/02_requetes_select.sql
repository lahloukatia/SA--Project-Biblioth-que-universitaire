-- =====================================================================
--  SAÉ BD / Réseau - Bibliothèque universitaire
--  Les 10 questions à la base de données (requêtes SELECT, DML)
--  Usage : psql -d bibliotheque -f sql/02_requetes_select.sql
-- =====================================================================

-- Q1 : Quels exemplaires sont disponibles et où sont-ils rangés ?
SELECT exemplaire.code_inventaire,
       livre.titre_livre,
       emplacement.salle,
       emplacement.rayon
FROM exemplaire
JOIN livre        ON exemplaire.id_livre = livre.id_livre
JOIN emplacement  ON exemplaire.id_emplacement = emplacement.id_emplacement
WHERE exemplaire.etat_exemplaire = 'bon'
  AND NOT EXISTS (
        SELECT *
        FROM emprunt
        WHERE emprunt.id_exemplaire = exemplaire.id_exemplaire
          AND emprunt.date_retour_effective IS NULL
  )
ORDER BY exemplaire.code_inventaire;

-- Q2 : Quelles réservations sont en attente, pour quels livres et quels adhérents ?
SELECT reservation.id_reservation,
       reservation.date_reservation,
       livre.titre_livre,
       personne.nom,
       personne.prenom
FROM reservation
JOIN livre     ON reservation.id_livre = livre.id_livre
JOIN adherent  ON reservation.id_adherent = adherent.id_adherent
JOIN personne  ON adherent.id_personne = personne.id_personne
WHERE reservation.statut_reservation = 'en_attente'
ORDER BY reservation.id_reservation;

-- Q3 : Qui sont les auteurs de chaque livre ?
SELECT livre.titre_livre,
       auteur.nom_auteur,
       auteur.prenom_auteur
FROM livre
JOIN ecrire ON livre.id_livre = ecrire.id_livre
JOIN auteur ON ecrire.id_auteur = auteur.id_auteur
ORDER BY livre.titre_livre;

-- Q4 : Dans quelle salle et quel rayon se trouve chaque exemplaire ?
SELECT exemplaire.code_inventaire,
       livre.titre_livre,
       emplacement.salle,
       emplacement.rayon
FROM exemplaire
JOIN livre        ON exemplaire.id_livre = livre.id_livre
JOIN emplacement  ON exemplaire.id_emplacement = emplacement.id_emplacement
ORDER BY exemplaire.code_inventaire;

-- Q5 : Quels livres sont actuellement empruntés et par qui ?
SELECT livre.titre_livre,
       exemplaire.code_inventaire,
       personne.nom,
       personne.prenom,
       emprunt.date_emprunt,
       emprunt.date_retour_prevue
FROM emprunt
JOIN exemplaire ON emprunt.id_exemplaire = exemplaire.id_exemplaire
JOIN livre      ON exemplaire.id_livre = livre.id_livre
JOIN adherent   ON emprunt.id_adherent = adherent.id_adherent
JOIN personne   ON adherent.id_personne = personne.id_personne
WHERE emprunt.date_retour_effective IS NULL
ORDER BY emprunt.id_emprunt;

-- Q6 : Quels livres ont été rendus en retard et avec combien de jours de retard ?
SELECT livre.titre_livre,
       emprunt.date_retour_prevue,
       emprunt.date_retour_effective,
       emprunt.date_retour_effective - emprunt.date_retour_prevue AS jours_retard
FROM emprunt
JOIN exemplaire ON emprunt.id_exemplaire = exemplaire.id_exemplaire
JOIN livre      ON exemplaire.id_livre = livre.id_livre
WHERE emprunt.date_retour_effective > emprunt.date_retour_prevue
ORDER BY emprunt.id_emprunt;

-- Q7 : Combien d'emprunts chaque adhérent a-t-il effectués ?   (agrégat + GROUP BY)
SELECT adherent.id_adherent,
       personne.nom,
       personne.prenom,
       COUNT(emprunt.id_emprunt) AS nombre_emprunts
FROM adherent
JOIN personne        ON adherent.id_personne = personne.id_personne
LEFT JOIN emprunt    ON adherent.id_adherent = emprunt.id_adherent
GROUP BY adherent.id_adherent, personne.nom, personne.prenom
ORDER BY adherent.id_adherent;

-- Q8 : Quel est le montant total des amendes impayées de chaque adhérent ?   (agrégat + GROUP BY)
SELECT adherent.id_adherent,
       personne.nom,
       personne.prenom,
       COALESCE(SUM(emprunt.montant_amende), 0) AS total_amendes_impayees
FROM adherent
JOIN personne        ON adherent.id_personne = personne.id_personne
LEFT JOIN emprunt    ON adherent.id_adherent = emprunt.id_adherent
                    AND emprunt.statut_amende = 'impayee'
GROUP BY adherent.id_adherent, personne.nom, personne.prenom
ORDER BY adherent.id_adherent;

-- Q9 : Quels livres n'ont jamais été empruntés ?   (requête imbriquée NOT EXISTS)
SELECT livre.id_livre,
       livre.titre_livre
FROM livre
WHERE NOT EXISTS (
        SELECT *
        FROM exemplaire
        JOIN emprunt ON exemplaire.id_exemplaire = emprunt.id_exemplaire
        WHERE exemplaire.id_livre = livre.id_livre
)
ORDER BY livre.id_livre;

-- Q10 : Quels adhérents ont effectué plus d'emprunts que la moyenne des adhérents ?
--       (requête imbriquée + agrégats + GROUP BY / HAVING)
SELECT adherent.id_adherent,
       personne.nom,
       personne.prenom,
       COUNT(emprunt.id_emprunt) AS nombre_emprunts
FROM adherent
JOIN personne        ON adherent.id_personne = personne.id_personne
LEFT JOIN emprunt    ON adherent.id_adherent = emprunt.id_adherent
GROUP BY adherent.id_adherent, personne.nom, personne.prenom
HAVING COUNT(emprunt.id_emprunt) > (
        SELECT COUNT(*) * 1.0
               / NULLIF((SELECT COUNT(*) FROM adherent), 0)
        FROM emprunt
)
ORDER BY adherent.id_adherent;
