<?php
/** En-tête HTML commun. Variables attendues : $titre (string), $prefixe ('' ou '../'). */
$prefixe = $prefixe ?? '';
$u = utilisateur();
?><!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titre ?? 'Bibliothèque') ?> – Bibliothèque universitaire</title>
<link rel="stylesheet" href="<?= e($prefixe) ?>style.css">
</head>
<body>
<header>
  <h1>Bibliothèque universitaire</h1>
  <?php if ($u): ?>
  <nav>
    <a href="<?= e($prefixe) ?>catalogue.php">Catalogue</a>
    <?php if ($u['role'] === 'adherent'): ?>
      <a href="<?= e($prefixe) ?>mes_emprunts.php">Mes emprunts et réservations</a>
    <?php else: ?>
      <a href="<?= e($prefixe) ?>biblio/retards.php">Retards et amendes</a>
      <a href="<?= e($prefixe) ?>biblio/ajouter_livre.php">Ajouter un livre</a>
    <?php endif; ?>
    <span class="utilisateur"><?= e($u['prenom'] . ' ' . $u['nom']) ?> (<?= e($u['role']) ?>)</span>
    <form method="post" action="<?= e($prefixe) ?>logout.php" class="inline">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button type="submit">Déconnexion</button>
    </form>
  </nav>
  <?php endif; ?>
</header>
<main>
<?php foreach (($_SESSION['messages'] ?? []) as [$type, $texte]): ?>
  <p class="message <?= $type === 'ok' ? 'ok' : 'erreur' ?>"><?= e($texte) ?></p>
<?php endforeach; unset($_SESSION['messages']); ?>
