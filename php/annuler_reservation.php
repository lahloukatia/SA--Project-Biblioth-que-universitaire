<?php
/** MODIFICATION de la base (UPDATE de reservation) : l'adhérent annule SA réservation en attente. */
require __DIR__ . '/config.php';
demarrer_session();
$u = exiger_connexion('adherent');
exiger_post();
verifier_csrf();

$id = filter_var($_POST['id_reservation'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    message('erreur', 'Réservation invalide.');
    rediriger('mes_emprunts.php');
}
try {
    $st = bd()->prepare(
        "UPDATE reservation SET statut_reservation = 'annule'
          WHERE id_reservation = :r AND id_adherent = :a AND statut_reservation = 'en_attente'"
    );
    $st->execute([':r' => $id, ':a' => $u['id_adherent']]);
    if ($st->rowCount() === 1) {
        message('ok', 'Réservation annulée.');
    } else {
        message('erreur', 'Cette réservation ne peut pas être annulée.');
    }
} catch (Throwable $e) {
    error_log('annuler_reservation.php : ' . $e->getMessage());
    message('erreur', 'Erreur interne.');
}
rediriger('mes_emprunts.php');
