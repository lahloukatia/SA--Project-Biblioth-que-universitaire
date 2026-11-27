<?php
/**
 * Espace bibliothécaire : emprunts en retard (SELECT avec sous-requête et calcul de dates),
 * amendes impayées par adhérent (SELECT avec GROUP BY / SUM) et encaissement d'une amende (UPDATE).
 */
require __DIR__ . '/../config.php';
demarrer_session();
$u = exiger_connexion('bibliothecaire', '../');
$pdo = bd();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $id = filter_var($_POST['id_emprunt'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        message('erreur', 'Emprunt invalide.');
    } else {
        try {
            $st = $pdo->prepare(
                "UPDATE emprunt SET statut_amende = 'payee', date_paiement = CURRENT_DATE
                  WHERE id_emprunt = :id AND statut_amende = 'impayee'"
            );
            $st->execute([':id' => $id]);
            if ($st->rowCount() === 1) {
                message('ok', 'Amende de l\'emprunt n° ' . $id . ' encaissée.');
            } else {
                message('erreur', 'Aucune amende impayée pour cet emprunt.');
            }
        } catch (Throwable $e) {
            error_log('retards.php : ' . $e->getMessage());
            message('erreur', 'Erreur interne, paiement non enregistré.');
        }
    }
    rediriger('retards.php');
}

$retards = $pdo->query(
    "SELECT e.id_emprunt, l.titre_livre, x.code_inventaire, p.nom, p.prenom, e.date_emprunt, e.date_retour_prevue,
            CURRENT_DATE - e.date_retour_prevue AS jours_retard
       FROM emprunt e
       JOIN exemplaire x ON x.id_exemplaire = e.id_exemplaire
       JOIN livre l      ON l.id_livre = x.id_livre
       JOIN adherent a   ON a.id_adherent = e.id_adherent
       JOIN personne p   ON p.id_personne = a.id_personne
      WHERE e.date_retour_effective IS NULL AND e.date_retour_prevue < CURRENT_DATE
      ORDER BY jours_retard DESC, e.id_emprunt"
)->fetchAll();

$totaux = $pdo->query(
    "SELECT a.id_adherent, p.nom, p.prenom, COUNT(*) AS nb_amendes, SUM(e.montant_amende) AS total
       FROM emprunt e
       JOIN adherent a ON a.id_adherent = e.id_adherent
       JOIN personne p ON p.id_personne = a.id_personne
      WHERE e.statut_amende = 'impayee'
      GROUP BY a.id_adherent, p.nom, p.prenom
      ORDER BY total DESC"
)->fetchAll();

$impayees = $pdo->query(
    "SELECT e.id_emprunt, p.nom, p.prenom, l.titre_livre, e.montant_amende, e.date_emission
       FROM emprunt e
       JOIN adherent a   ON a.id_adherent = e.id_adherent
       JOIN personne p   ON p.id_personne = a.id_personne
       JOIN exemplaire x ON x.id_exemplaire = e.id_exemplaire
       JOIN livre l      ON l.id_livre = x.id_livre
      WHERE e.statut_amende = 'impayee'
      ORDER BY e.date_emission, e.id_emprunt"
)->fetchAll();

$titre = 'Retards et amendes';
$prefixe = '../';
require __DIR__ . '/../inc/entete.php';
?>
<h2>Emprunts en retard</h2>
<table>
  <tr><th>N°</th><th>Adhérent</th><th>Livre</th><th>Exemplaire</th><th>Emprunté le</th><th>Retour prévu</th><th>Retard</th></tr>
  <?php foreach ($retards as $r): ?>
  <tr>
    <td><?= (int) $r['id_emprunt'] ?></td>
    <td><?= e($r['prenom'] . ' ' . $r['nom']) ?></td>
    <td><?= e($r['titre_livre']) ?></td>
    <td><?= e($r['code_inventaire']) ?></td>
    <td><?= e($r['date_emprunt']) ?></td>
    <td><?= e($r['date_retour_prevue']) ?></td>
    <td><?= (int) $r['jours_retard'] ?> jour(s)</td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$retards): ?><tr><td colspan="7">Aucun retard.</td></tr><?php endif; ?>
</table>

<h2>Amendes impayées par adhérent</h2>
<table>
  <tr><th>Adhérent</th><th>Nombre d'amendes</th><th>Total</th></tr>
  <?php foreach ($totaux as $t): ?>
  <tr><td><?= e($t['prenom'] . ' ' . $t['nom']) ?></td><td><?= (int) $t['nb_amendes'] ?></td><td><?= e(number_format((float) $t['total'], 2, ',', ' ')) ?> €</td></tr>
  <?php endforeach; ?>
  <?php if (!$totaux): ?><tr><td colspan="3">Aucune amende impayée.</td></tr><?php endif; ?>
</table>

<h2>Encaisser une amende</h2>
<table>
  <tr><th>Emprunt</th><th>Adhérent</th><th>Livre</th><th>Montant</th><th>Émise le</th><th></th></tr>
  <?php foreach ($impayees as $m): ?>
  <tr>
    <td><?= (int) $m['id_emprunt'] ?></td>
    <td><?= e($m['prenom'] . ' ' . $m['nom']) ?></td>
    <td><?= e($m['titre_livre']) ?></td>
    <td><?= e(number_format((float) $m['montant_amende'], 2, ',', ' ')) ?> €</td>
    <td><?= e($m['date_emission']) ?></td>
    <td>
      <form method="post" class="inline">
        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="id_emprunt" value="<?= (int) $m['id_emprunt'] ?>">
        <button type="submit">Encaisser</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  <?php if (!$impayees): ?><tr><td colspan="6">Rien à encaisser.</td></tr><?php endif; ?>
</table>
<?php require __DIR__ . '/../inc/pied.php'; ?>
