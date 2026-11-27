<?php
/** MODIFICATION de la base (INSERT dans livre et ecrire) avec image de couverture stockée en BLOB (BYTEA). */
require __DIR__ . '/../config.php';
demarrer_session();
$u = exiger_connexion('bibliothecaire', '../');
$pdo = bd();

$erreurs = [];
$f = ['isbn' => '', 'titre' => '', 'langue' => 'Français', 'annee' => '', 'pages' => '', 'editeur' => 0, 'categorie' => 0, 'auteur' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $f['isbn'] = trim((string) ($_POST['isbn'] ?? ''));
    $f['titre'] = trim((string) ($_POST['titre'] ?? ''));
    $f['langue'] = trim((string) ($_POST['langue'] ?? ''));
    $f['annee'] = trim((string) ($_POST['annee'] ?? ''));
    $f['pages'] = trim((string) ($_POST['pages'] ?? ''));
    foreach (['editeur', 'categorie', 'auteur'] as $champ) {
        $v = filter_var($_POST[$champ] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $f[$champ] = $v === false ? 0 : $v;
    }
    // mêmes règles que les contraintes CHECK du schéma
    if (!preg_match('/^97[89][0-9]{10}$/', $f['isbn'])) {
        $erreurs[] = 'ISBN : 13 chiffres commençant par 978 ou 979.';
    }
    if ($f['titre'] === '' || strlen($f['titre']) > 200) {
        $erreurs[] = 'Titre : 1 à 200 caractères.';
    }
    if (strlen($f['langue']) < 2 || strlen($f['langue']) > 30 || preg_match('/[0-9]/', $f['langue'])) {
        $erreurs[] = 'Langue : 2 à 30 caractères, sans chiffre.';
    }
    $annee = filter_var($f['annee'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1450, 'max_range' => (int) date('Y')]]);
    if ($annee === false) {
        $erreurs[] = 'Année : entre 1450 et ' . date('Y') . '.';
    }
    $pages = filter_var($f['pages'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
    if ($pages === false) {
        $erreurs[] = 'Nombre de pages : entre 1 et 10 000.';
    }
    if ($f['editeur'] === 0 || $f['categorie'] === 0 || $f['auteur'] === 0) {
        $erreurs[] = 'Choisissez un éditeur, une catégorie et un auteur.';
    }
    $couvertureHex = null;
    if (isset($_FILES['couverture']) && $_FILES['couverture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $fic = $_FILES['couverture'];
        if ($fic['error'] !== UPLOAD_ERR_OK || $fic['size'] > 5 * 1024 * 1024) {
            $erreurs[] = 'Couverture : fichier invalide ou supérieur à 5 Mo.';
        } else {
            $contenu = file_get_contents($fic['tmp_name']);
            if (strncmp($contenu, "\x89PNG", 4) !== 0 && strncmp($contenu, "\xFF\xD8\xFF", 3) !== 0) {
                $erreurs[] = 'Couverture : seuls les formats PNG et JPEG sont acceptés.';
            } else {
                $couvertureHex = bin2hex($contenu);
            }
        }
    }
    if (!$erreurs) {
        try {
            $pdo->beginTransaction();
            // l'identifiant est généré par la base (colonne IDENTITY), comme dans le serveur Java
            $st = $pdo->prepare(
                "INSERT INTO livre (isbn, titre_livre, langue_livre, annee_publication, nombre_pages, couverture_livre, id_editeur, id_categorie)
                 VALUES (:isbn, :titre, :langue, :annee, :pages, decode(:couv, 'hex'), :ed, :cat)
                 RETURNING id_livre"
            );
            $st->bindValue(':isbn', $f['isbn']);
            $st->bindValue(':titre', $f['titre']);
            $st->bindValue(':langue', $f['langue']);
            $st->bindValue(':annee', $annee, PDO::PARAM_INT);
            $st->bindValue(':pages', $pages, PDO::PARAM_INT);
            $st->bindValue(':couv', $couvertureHex, $couvertureHex === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $st->bindValue(':ed', $f['editeur'], PDO::PARAM_INT);
            $st->bindValue(':cat', $f['categorie'], PDO::PARAM_INT);
            $st->execute();
            $id = (int) $st->fetchColumn();
            $st = $pdo->prepare('INSERT INTO ecrire (id_livre, id_auteur) VALUES (:l, :a)');
            $st->execute([':l' => $id, ':a' => $f['auteur']]);
            $pdo->commit();
            message('ok', 'Livre ajouté (n° ' . $id . ').');
            rediriger('../catalogue.php');
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // 23505 = violation d'unicité (ISBN déjà présent) ; 23503 = clé étrangère inexistante
            if ($e->getCode() === '23505') {
                $erreurs[] = 'Cet ISBN existe déjà dans le catalogue.';
            } elseif ($e->getCode() === '23503') {
                $erreurs[] = 'Éditeur, catégorie ou auteur inexistant.';
            } else {
                error_log('ajouter_livre.php : ' . $e->getMessage());
                $erreurs[] = 'Erreur interne, le livre n\'a pas été ajouté.';
            }
        }
    }
}

$editeurs = $pdo->query('SELECT id_editeur, nom_editeur FROM editeur ORDER BY nom_editeur')->fetchAll();
$categories = $pdo->query('SELECT id_categorie, libelle_categorie FROM categorie ORDER BY libelle_categorie')->fetchAll();
$auteurs = $pdo->query("SELECT id_auteur, trim(coalesce(prenom_auteur || ' ', '') || nom_auteur) AS nom FROM auteur ORDER BY nom_auteur")->fetchAll();

$titre = 'Ajouter un livre';
$prefixe = '../';
require __DIR__ . '/../inc/entete.php';
?>
<h2>Ajouter un livre</h2>
<?php foreach ($erreurs as $m): ?><p class="message erreur"><?= e($m) ?></p><?php endforeach; ?>
<form method="post" enctype="multipart/form-data">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <fieldset>
    <label for="isbn">ISBN (13 chiffres)</label><input id="isbn" name="isbn" value="<?= e($f['isbn']) ?>" maxlength="13" required>
    <label for="titre">Titre</label><input id="titre" name="titre" value="<?= e($f['titre']) ?>" maxlength="200" required size="50">
    <label for="langue">Langue</label><input id="langue" name="langue" value="<?= e($f['langue']) ?>" maxlength="30" required>
    <label for="annee">Année de publication</label><input id="annee" name="annee" type="number" value="<?= e($f['annee']) ?>" min="1450" max="<?= (int) date('Y') ?>" required>
    <label for="pages">Nombre de pages</label><input id="pages" name="pages" type="number" value="<?= e($f['pages']) ?>" min="1" max="10000" required>
    <label for="editeur">Éditeur</label>
    <select id="editeur" name="editeur" required><option value="">—</option>
      <?php foreach ($editeurs as $o): ?><option value="<?= (int) $o['id_editeur'] ?>" <?= $f['editeur'] === (int) $o['id_editeur'] ? 'selected' : '' ?>><?= e($o['nom_editeur']) ?></option><?php endforeach; ?>
    </select>
    <label for="categorie">Catégorie</label>
    <select id="categorie" name="categorie" required><option value="">—</option>
      <?php foreach ($categories as $o): ?><option value="<?= (int) $o['id_categorie'] ?>" <?= $f['categorie'] === (int) $o['id_categorie'] ? 'selected' : '' ?>><?= e($o['libelle_categorie']) ?></option><?php endforeach; ?>
    </select>
    <label for="auteur">Auteur</label>
    <select id="auteur" name="auteur" required><option value="">—</option>
      <?php foreach ($auteurs as $o): ?><option value="<?= (int) $o['id_auteur'] ?>" <?= $f['auteur'] === (int) $o['id_auteur'] ? 'selected' : '' ?>><?= e($o['nom']) ?></option><?php endforeach; ?>
    </select>
    <label for="couverture">Couverture (PNG ou JPEG, 5 Mo max, facultative)</label>
    <input id="couverture" name="couverture" type="file" accept="image/png,image/jpeg">
  </fieldset>
  <button type="submit">Ajouter</button>
</form>
<?php require __DIR__ . '/../inc/pied.php'; ?>
