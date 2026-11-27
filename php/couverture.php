<?php
/** Sert l'image de couverture (colonne BLOB / BYTEA couverture_livre). */
require __DIR__ . '/config.php';
demarrer_session();
exiger_connexion();

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
    http_response_code(400);
    exit;
}
$st = bd()->prepare('SELECT couverture_livre FROM livre WHERE id_livre = :id');
$st->execute([':id' => $id]);
$st->bindColumn(1, $lob, PDO::PARAM_LOB);
if (!$st->fetch(PDO::FETCH_BOUND) || $lob === null) {
    http_response_code(404);
    exit;
}
$donnees = is_resource($lob) ? stream_get_contents($lob) : $lob;
if (strncmp($donnees, "\x89PNG", 4) === 0) {
    $type = 'image/png';
} elseif (strncmp($donnees, "\xFF\xD8\xFF", 3) === 0) {
    $type = 'image/jpeg';
} else {
    http_response_code(415);
    exit;
}
header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($donnees));
header('Cache-Control: private, max-age=3600');
echo $donnees;
