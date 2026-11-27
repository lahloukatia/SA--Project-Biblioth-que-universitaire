<?php
require __DIR__ . '/config.php';
demarrer_session();
exiger_post();
verifier_csrf();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
}
session_destroy();
rediriger('login.php');
