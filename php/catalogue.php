<?php
/** Catalogue : EXTRACTION (SELECT) avec jointures, sous-requêtes et agrégats ; accessible à tout utilisateur connecté. */
require __DIR__ . '/config.php';
demarrer_session();
$u = exiger_connexion();

$q = trim((string) ($_GET['q'] ?? ''));
if (strlen($q) > 100) {
    $q = substr($q, 0, 100);
}
$cat = filter_var($_GET['cat'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$cat = $cat === false ? 0 : $cat;
// on neutralise les jokers LIKE saisis par l'utilisateur
$motif = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';

$pdo = bd();
$categories = $pdo->query('SELECT id_categorie, libelle_categorie FROM categorie ORDER BY libelle_categorie')->fetchAll();

$sql = "SELECT l.id_livre, l.titre_livre, l.annee_publication, l.langue_livre, c.libelle_categorie,
               (CASE WHEN l.couverture_livre IS NULL THEN 0 ELSE 1 END) AS a_couverture,
               (SELECT string_agg(trim(coalesce(a.prenom_auteur || ' ', '') || a.nom_auteur), ', ' ORDER BY a.nom_auteur)
                  FROM ecrire w JOIN auteur a ON a.id_auteur = w.id_auteur
                 WHERE w.id_livre = l.id_livre) AS auteurs,
               (SELECT COUNT(*) FROM exemplaire x WHERE x.id_livre = l.id_livre) AS nb_exemplaires,
               (SELECT COUNT(*) FROM exemplaire x
                 WHERE x.id_livre = l.id_livre AND x.etat_exemplaire = 'bon'
                   AND NOT EXISTS (SELECT 1 FROM emprunt e
                                    WHERE e.id_exemplaire = x.id_exemplaire AND e.date_retour_effective IS NULL)) AS nb_disponibles
          FROM livre l
          JOIN categorie c ON c.id_categorie = l.id_categorie
         WHERE (l.titre_livre ILIKE :m1
                OR EXISTS (SELECT 1 FROM ecrire w2 JOIN auteur a2 ON a2.id_auteur = w2.id_auteur
                            WHERE w2.id_livre = l.id_livre AND a2.nom_auteur ILIKE :m2))
           AND (:c1 = 0 OR l.id_categorie = :c2)
         ORDER BY l.titre_livre
         LIMIT 100";
$st = $pdo->prepare($sql);
$st->bindValue(':m1', $motif);
$st->bindValue(':m2', $motif);
$st->bindValue(':c1', $cat, PDO::PARAM_INT);
$st->bindValue(':c2', $cat, PDO::PARAM_INT);
$st->execute();
$livres = $st->fetchAll();

$peut_reserver = $u['role'] === 'adherent';
$titre = 'Catalogue';
$prefixe = '';
require __DIR__ . '/inc/entete.php';
?>
<h2>Catalogue</h2>
<form method="get">
  <input name="q" value="<?= e($q) ?>" placeholder="Titre ou nom d'auteur" maxlength="100">
  <select name="cat">
    <option value="0">Toutes les catégories</option>
    <?php foreach ($categories as $c): ?>
      <option value="<?= (int) $c['id_categorie'] ?>" <?= $cat === (int) $c['id_categorie'] ? 'selected' : '' ?>><?= e($c['libelle_categorie']) ?></option>
    <?php endforeach; ?>
  </select>
  <button type="submit">Rechercher</button>
</form>
<p class="petit"><?= count($livres) ?> livre(s) trouvé(s).</p>
<table>
  <tr><th></th><th>Titre</th><th>Auteur(s)</th><th>Catégorie</th><th>Année</th><th>Langue</th><th>Disponibilité</th><?php if ($peut_reserver): ?><th></th><?php endif; ?></tr>
  <?php foreach ($livres as $l): ?>
  <tr>
    <td><?php if ((int) $l['a_couverture'] === 1): ?><img class="couverture" src="couverture.php?id=<?= (int) $l['id_livre'] ?>" alt="Couverture"><?php endif; ?></td>
    <td><?= e($l['titre_livre']) ?></td>
    <td><?= e($l['auteurs'] ?? '—') ?></td>
    <td><?= e($l['libelle_categorie']) ?></td>
    <td><?= (int) $l['annee_publication'] ?></td>
    <td><?= e($l['langue_livre']) ?></td>
    <td>
      <?php if ((int) $l['nb_disponibles'] > 0): ?>
        <span class="dispo"><?= (int) $l['nb_disponibles'] ?> / <?= (int) $l['nb_exemplaires'] ?> disponible(s)</span>
      <?php else: ?>
        <span class="indispo">Aucun exemplaire disponible</span> <span class="petit">(<?= (int) $l['nb_exemplaires'] ?> au total)</span>
      <?php endif; ?>
    </td>
    <?php if ($peut_reserver): ?>
    <td>
      <?php if ((int) $l['nb_disponibles'] === 0 && (int) $l['nb_exemplaires'] > 0): ?>
        <form method="post" action="reserver.php" class="inline">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="id_livre" value="<?= (int) $l['id_livre'] ?>">
          <button type="submit">Réserver</button>
        </form>
      <?php endif; ?>
    </td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
</table>
<?php require __DIR__ . '/inc/pied.php'; ?>
