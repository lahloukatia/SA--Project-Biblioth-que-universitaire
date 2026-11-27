<?php
/** MODIFICATION de la base (INSERT dans reservation) : un adhérent réserve un livre sans exemplaire disponible. */
require __DIR__ . '/config.php';
demarrer_session();
$u = exiger_connexion('adherent');
exiger_post();
verifier_csrf();

$idLivre = filter_var($_POST['id_livre'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($idLivre === false) {
    message('erreur', 'Livre invalide.');
    rediriger('catalogue.php');
}

$pdo = bd();
$pdo->beginTransaction();
try {
    $st = $pdo->prepare('SELECT COUNT(*) FROM adherent WHERE id_adherent = :a AND actif_adherent');
    $st->execute([':a' => $u['id_adherent']]);
    if ((int) $st->fetchColumn() === 0) {
        throw new RuntimeException('Votre compte est inactif : vous ne pouvez pas réserver.');
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM exemplaire WHERE id_livre = :l');
    $st->execute([':l' => $idLivre]);
    if ((int) $st->fetchColumn() === 0) {
        throw new RuntimeException('Livre introuvable ou sans exemplaire.');
    }

    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM exemplaire x
          WHERE x.id_livre = :l AND x.etat_exemplaire = 'bon'
            AND NOT EXISTS (SELECT 1 FROM emprunt e WHERE e.id_exemplaire = x.id_exemplaire AND e.date_retour_effective IS NULL)"
    );
    $st->execute([':l' => $idLivre]);
    if ((int) $st->fetchColumn() > 0) {
        throw new RuntimeException('Un exemplaire est disponible : empruntez-le directement au comptoir.');
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM reservation WHERE id_adherent = :a AND id_livre = :l AND statut_reservation = 'en_attente'");
    $st->execute([':a' => $u['id_adherent'], ':l' => $idLivre]);
    if ((int) $st->fetchColumn() > 0) {
        throw new RuntimeException('Vous avez déjà une réservation en attente pour ce livre.');
    }

    // identifiant généré par la base (IDENTITY) ; l'index unique ux_reservation_en_attente
    // bloque un doublon même si deux requêtes arrivent en même temps
    $st = $pdo->prepare(
        "INSERT INTO reservation (date_reservation, statut_reservation, id_adherent, id_livre)
         VALUES (CURRENT_DATE, 'en_attente', :a, :l)
         RETURNING id_reservation"
    );
    $st->execute([':a' => $u['id_adherent'], ':l' => $idLivre]);
    $id = (int) $st->fetchColumn();
    $pdo->commit();
    message('ok', 'Réservation enregistrée (n° ' . $id . ').');
} catch (PDOException $e) {
    // à placer AVANT RuntimeException : PDOException en hérite
    $pdo->rollBack();
    if ($e->getCode() === '23505') {
        message('erreur', 'Vous avez déjà une réservation en attente pour ce livre.');
    } else {
        error_log('reserver.php : ' . $e->getMessage());
        message('erreur', 'Erreur interne, la réservation n\'a pas été enregistrée.');
    }
} catch (RuntimeException $e) {
    $pdo->rollBack();
    message('erreur', $e->getMessage());
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('reserver.php : ' . $e->getMessage());
    message('erreur', 'Erreur interne, la réservation n\'a pas été enregistrée.');
}
rediriger('mes_emprunts.php');
