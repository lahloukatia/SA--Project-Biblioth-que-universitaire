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
    $id = prochain_id($pdo, 'reservation', 'id_reservation');   // verrouille la table pendant la transaction

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

    $st = $pdo->prepare(
        "INSERT INTO reservation (id_reservation, date_reservation, statut_reservation, id_adherent, id_livre)
         VALUES (:id, CURRENT_DATE, 'en_attente', :a, :l)"
    );
    $st->execute([':id' => $id, ':a' => $u['id_adherent'], ':l' => $idLivre]);
    $pdo->commit();
    message('ok', 'Réservation enregistrée (n° ' . $id . ').');
} catch (RuntimeException $e) {
    $pdo->rollBack();
    message('erreur', $e->getMessage());
} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('reserver.php : ' . $e->getMessage());
    message('erreur', 'Erreur interne, la réservation n\'a pas été enregistrée.');
}
rediriger('mes_emprunts.php');
