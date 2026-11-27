<?php
/** En-tête HTML commun. Variables attendues : $titre (string), $prefixe ('' ou '../'). */
$prefixe = $prefixe ?? '';
$u = utilisateur();
$pageCourante = basename($_SERVER['SCRIPT_NAME'] ?? '');
$versionStyle = filemtime(__DIR__ . '/../style.css');
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titre ?? 'Bibliothèque') ?> – Ma BU</title>
<link rel="icon" type="image/png" sizes="32x32" href="<?= e($prefixe) ?>assets/ma-bu-favicon.png">
<link rel="stylesheet" href="<?= e($prefixe) ?>style.css?v=<?= (int) $versionStyle ?>">
</head>
<body>
<header class="site-entete<?= $pageCourante === 'login.php' ? ' site-entete-connexion' : '' ?>">
  <div class="site-entete-contenu">
  <h1 class="site-brand">
    <img src="<?= e($prefixe) ?>assets/ma-bu-logo.png" alt="Ma BU – Bibliothèque universitaire" width="2172" height="724">
  </h1>
  <?php if ($u): ?>
  <nav aria-label="Navigation principale">
    <a href="<?= e($prefixe) ?>catalogue.php"<?= $pageCourante === 'catalogue.php' ? ' aria-current="page"' : '' ?>>Catalogue</a>
    <?php if ($u['role'] === 'adherent'): ?>
      <a href="<?= e($prefixe) ?>mes_emprunts.php"<?= $pageCourante === 'mes_emprunts.php' ? ' aria-current="page"' : '' ?>>Mes emprunts et réservations</a>
    <?php else: ?>
      <a href="<?= e($prefixe) ?>biblio/retards.php"<?= $pageCourante === 'retards.php' ? ' aria-current="page"' : '' ?>>Retards et amendes</a>
      <a href="<?= e($prefixe) ?>biblio/ajouter_livre.php"<?= $pageCourante === 'ajouter_livre.php' ? ' aria-current="page"' : '' ?>>Ajouter un livre</a>
    <?php endif; ?>
    <span class="utilisateur"><?= e($u['prenom'] . ' ' . $u['nom']) ?> (<?= e($u['role']) ?>)</span>
    <form method="post" action="<?= e($prefixe) ?>logout.php" class="inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button type="submit">Déconnexion</button>
    </form>
  </nav>
  <?php endif; ?>
  </div>
</header>
<main>
<?php foreach (($_SESSION['messages'] ?? []) as [$type, $texte]): ?>
  <p class="message <?= $type === 'ok' ? 'ok' : 'erreur' ?>"><?= e($texte) ?></p>
<?php endforeach; unset($_SESSION['messages']); ?>
