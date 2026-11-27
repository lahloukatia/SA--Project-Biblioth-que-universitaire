<?php
/**
 * Configuration commune du site : connexion PostgreSQL (PDO), session, sécurité.
 * Les paramètres sont lus (par priorité) : variables d'environnement > php/config.local.php > ../.env > php/.env
 * Variables : DB_HOST (localhost), DB_PORT (5432), DB_NAME (bibliotheque), DB_USER, DB_PASS
 *             ou DB_URL au format JDBC (jdbc:postgresql://hote:port/base) déjà utilisé par le serveur Java.
 */
declare(strict_types=1);

const DUREE_SESSION_S = 1800;   // déconnexion après 30 min d'inactivité
const NB_ECHECS_MAX   = 5;      // tentatives de connexion avant blocage temporaire
const BLOCAGE_S       = 60;

function charger_env(string $fichier): array
{
    $vars = [];
    if (!is_readable($fichier)) {
        return $vars;
    }
    foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
        $ligne = trim($ligne);
        if ($ligne === '' || $ligne[0] === '#' || strpos($ligne, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $ligne, 2);
        $vars[trim($k)] = trim(trim($v), "\"'");
    }
    return $vars;
}

function parametre(string $nom, ?string $defaut = null): ?string
{
    static $env = null;
    if ($env === null) {
        $env = charger_env(__DIR__ . '/../.env') + charger_env(__DIR__ . '/.env');
        $local = __DIR__ . '/config.local.php';
        if (is_readable($local)) {
            $env = array_merge($env, (array) require $local);
        }
    }
    $v = getenv($nom);
    if ($v !== false && $v !== '') {
        return $v;
    }
    return $env[$nom] ?? $defaut;
}

/** Connexion PDO unique (requêtes préparées partout : pas d'injection SQL). */
function bd(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $hote = parametre('DB_HOST', 'localhost');
    $port = parametre('DB_PORT', '5432');
    $base = parametre('DB_NAME', 'bibliotheque');
    $url = parametre('DB_URL');
    if ($url !== null && preg_match('#^jdbc:postgresql://([^:/]+)(?::(\d+))?/([^?]+)#', $url, $m)) {
        $hote = $m[1];
        $port = $m[2] !== '' ? $m[2] : '5432';
        $base = $m[3];
    }
    $user = parametre('DB_USER', 'bibli_user');
    $pass = parametre('DB_PASS');
    if ($pass === null) {
        http_response_code(500);
        exit('Configuration incomplète : DB_PASS est absent (voir .env.example).');
    }
    try {
        $pdo = new PDO("pgsql:host=$hote;port=$port;dbname=$base;sslmode=require", $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('Connexion BD impossible : ' . $e->getMessage());
        http_response_code(503);
        exit('Base de données indisponible. Réessayez plus tard.');
    }
    return $pdo;
}

function e($valeur): string
{
    return htmlspecialchars((string) $valeur, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function entetes_securite(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; form-action 'self'; frame-ancestors 'none'");
}

function demarrer_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    entetes_securite();
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('BIBSESSID');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();
    if (isset($_SESSION['derniere_activite']) && time() - (int) $_SESSION['derniere_activite'] > DUREE_SESSION_S) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['derniere_activite'] = time();
}

function rediriger(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verifier_csrf(): void
{
    $envoye = $_POST['csrf'] ?? '';
    if (!is_string($envoye) || !hash_equals($_SESSION['csrf'] ?? '', $envoye)) {
        http_response_code(403);
        exit('Requête refusée (jeton CSRF invalide).');
    }
}

function utilisateur(): ?array
{
    return $_SESSION['user'] ?? null;
}

/** Exige une connexion (et éventuellement un rôle : 'adherent' ou 'bibliothecaire'). */
function exiger_connexion(string $role = '', string $prefixe = ''): array
{
    $u = utilisateur();
    if ($u === null) {
        rediriger($prefixe . 'login.php');
    }
    if ($role !== '' && $u['role'] !== $role) {
        http_response_code(403);
        exit('Accès réservé aux ' . ($role === 'adherent' ? 'adhérents' : 'bibliothécaires') . '.');
    }
    return $u;
}

function message(string $type, string $texte): void
{
    $_SESSION['messages'][] = [$type, $texte];
}

function exiger_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Méthode non autorisée.');
    }
}
