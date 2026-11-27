<?php
/** Espace adhérent : emprunts, amendes et réservations (SELECT). */
require __DIR__ . '/config.php';
demarrer_session();
$u = exiger_connexion('adherent');
$pdo = bd();

$st = $pdo->prepare(
    "SELECT e.id_emprunt, l.titre_livre, x.code_inventaire, e.date_emprunt, e.date_retour_prevue, e.date_retour_effective,
            e.montant_amende, e.statut_amende::text AS statut_amende,
            CASE WHEN e.date_retour_effective IS NULL AND e.date_retour_prevue < CURRENT_DATE
                 THEN CURRENT_DATE - e.date_retour_prevue ELSE 0 END AS jours_retard
       FROM emprunt e
       JOIN exemplaire x ON x.id_exemplaire = e.id_exemplaire
       JOIN livre l      ON l.id_livre = x.id_livre
      WHERE e.id_adherent = :a
      ORDER BY e.date_emprunt DESC, e.id_emprunt DESC"
);
$st->execute([':a' => $u['id_adherent']]);
$emprunts = $st->fetchAll();

$st = $pdo->prepare("SELECT COALESCE(SUM(montant_amende), 0) FROM emprunt WHERE id_adherent = :a AND statut_amende = 'impayee'");
$st->execute([':a' => $u['id_adherent']]);
$amendes = $st->fetchColumn();

$st = $pdo->prepare(
    "SELECT r.id_reservation, l.titre_livre, r.date_reservation, r.statut_reservation::text AS statut
       FROM reservation r JOIN livre l ON l.id_livre = r.id_livre
      WHERE r.id_adherent = :a
      ORDER BY r.date_reservation DESC, r.id_reservation DESC"
);
$st->execute([':a' => $u['id_adherent']]);
$reservations = $st->fetchAll();

$titre = 'Mes emprunts';
$prefixe = '';
require __DIR__ . '/inc/entete.php';
?>
<h2>Mes emprunts</h2>
<p>Amendes impayées : <strong><?= e(number_format((float) $amendes, 2, ',', ' ')) ?> €</strong>
  <?php if ((float) $amendes > 0): ?><span class="petit">(un nouveau prêt est refusé tant qu'une amende reste impayée ; réglez-la au comptoir)</span><?php endif; ?></p>
<table>
  <tr><th>Livre</th><th>Exemplaire</th><th>Emprunté le</th><th>Retour prévu</th><th>Rendu le</th><th>Amende</th></tr>
  <?php foreach ($emprunts as $m): ?>
  <tr>
    <td><?= e($m['titre_livre']) ?></td>
    <td><?= e($m['code_inventaire']) ?></td>
    <td><?= e($m['date_emprunt']) ?></td>
    <td><?= e($m['date_retour_prevue']) ?><?php if ((int) $m['jours_retard'] > 0): ?> <span class="indispo">(<?= (int) $m['jours_retard'] ?> j de retard)</span><?php endif; ?></td>
    <td><?= $m['date_retour_effective'] === null ? 'en cours' : e($m['date_retour_effective']) ?></td>
    <td><?= $m['statut_amende'] === 'aucune' ? '—' : e(number_format((float) $m['montant_amende'], 2, ',', ' ')) . ' € (' . e($m['statut_amende']) . ')' ?></td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$emprunts): ?><tr><td colspan="6">Aucun emprunt.</td></tr><?php endif; ?>
</table>

<h2>Mes réservations</h2>
<table>
  <tr><th>Livre</th><th>Date</th><th>Statut</th><th></th></tr>
  <?php foreach ($reservations as $r): ?>
  <tr>
    <td><?= e($r['titre_livre']) ?></td>
    <td><?= e($r['date_reservation']) ?></td>
    <td><?= e($r['statut']) ?></td>
    <td>
      <?php if ($r['statut'] === 'en_attente'): ?>
      <form method="post" action="annuler_reservation.php" class="inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id_reservation" value="<?= (int) $r['id_reservation'] ?>">
        <button type="submit">Annuler</button>
      </form>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$reservations): ?><tr><td colspan="4">Aucune réservation.</td></tr><?php endif; ?>
</table>
<?php require __DIR__ . '/inc/pied.php'; ?>
