<?php
require __DIR__ . '/config.php';
demarrer_session();
if (utilisateur() !== null) {
    rediriger('catalogue.php');
}

// Hash bcrypt factice (mot de passe « password ») : on le vérifie même si le login n'existe pas,
// pour que le temps de réponse ne révèle pas l'existence d'un compte.
const HASH_FACTICE = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

$erreur = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_csrf();
    $maintenant = time();
    if (($_SESSION['bloque_jusqua'] ?? 0) > $maintenant) {
        $erreur = 'Trop de tentatives. Patientez ' . ((int) $_SESSION['bloque_jusqua'] - $maintenant) . ' s.';
    } else {
        $login = trim((string) ($_POST['login'] ?? ''));
        $mdp = (string) ($_POST['mot_de_passe'] ?? '');
        $p = false;
        if ($login !== '' && strlen($login) <= 50 && strlen($mdp) <= 200) {
            $st = bd()->prepare(
                'SELECT p.id_personne, p.nom, p.prenom, p.mot_de_passe_hache,
                        a.id_adherent, a.actif_adherent, b.id_bibliothecaire
                   FROM personne p
                   LEFT JOIN adherent a       ON a.id_personne = p.id_personne
                   LEFT JOIN bibliothecaire b ON b.id_personne = p.id_personne
                  WHERE p.identifiant_connexion = :login'
            );
            $st->execute([':login' => $login]);
            $p = $st->fetch();
        }
        $hash = $p ? $p['mot_de_passe_hache'] : HASH_FACTICE;
        $mdp_ok = password_verify($mdp, $hash);
        if ($p && $mdp_ok && ($p['id_adherent'] !== null || $p['id_bibliothecaire'] !== null)) {
            session_regenerate_id(true);
            $_SESSION['echecs'] = 0;
            $_SESSION['user'] = [
                'id_personne'       => (int) $p['id_personne'],
                'nom'               => $p['nom'],
                'prenom'            => $p['prenom'],
                'role'              => $p['id_bibliothecaire'] !== null ? 'bibliothecaire' : 'adherent',
                'id_adherent'       => $p['id_adherent'] !== null ? (int) $p['id_adherent'] : null,
                'id_bibliothecaire' => $p['id_bibliothecaire'] !== null ? (int) $p['id_bibliothecaire'] : null,
            ];
            rediriger('catalogue.php');
        }
        $_SESSION['echecs'] = (int) ($_SESSION['echecs'] ?? 0) + 1;
        if ($_SESSION['echecs'] >= NB_ECHECS_MAX) {
            $_SESSION['bloque_jusqua'] = $maintenant + BLOCAGE_S;
            $_SESSION['echecs'] = 0;
        }
        usleep(400000);
        $erreur = 'Identifiant ou mot de passe incorrect.';
    }
}
$titre = 'Connexion';
$prefixe = '';
require __DIR__ . '/inc/entete.php';
?>
<h2>Connexion</h2>
<?php if ($erreur !== ''): ?><p class="message erreur"><?= e($erreur) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <label for="login">Identifiant</label>
  <input id="login" name="login" maxlength="50" required autofocus>
  <label for="mdp">Mot de passe</label>
  <input id="mdp" name="mot_de_passe" type="password" maxlength="200" required>
  <p><button type="submit">Se connecter</button></p>
</form>
<?php require __DIR__ . '/inc/pied.php'; ?>
