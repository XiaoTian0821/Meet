<?php
/**
 * ConnectMeet - application bootstrap.
 * Loads config, PDO, session, and common helpers. Include this at the top of
 * every entry point (pages + API files).
 */

declare(strict_types=1);

// ---------- load config ----------
$cmBaseDir = dirname(__DIR__);
$cmConfigFile = $cmBaseDir . '/config/database.php';
if (!is_file($cmConfigFile)) {
    // fall back to example (still functional, documents structure)
    $cmConfigFile = $cmBaseDir . '/config/example.php';
}
$CM_CONFIG = require $cmConfigFile;

// timezone
date_default_timezone_set($CM_CONFIG['app']['timezone'] ?? 'UTC');

// session (secure settings; Secure flag depends on config + scheme)
$cmSecureCookies = (bool)($CM_CONFIG['app']['cookie_secure'] ?? false);
$cmIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['SERVER_PORT'] ?? null) == 443);
$cmScheme = $cmIsHttps ? 'https' : 'http';
if ($cmIsHttps) {
    $cmSecureCookies = true;
}
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $cmSecureCookies,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('cm_sid');
    session_start();
}

// ---------- PDO ----------
$CM_PDO = null;
$CM_DB_ERROR = null;
try {
    $CM_PDO = new PDO(
        $CM_CONFIG['db']['dsn'],
        $CM_CONFIG['db']['username'],
        $CM_CONFIG['db']['password'],
        $CM_CONFIG['db']['options'] ?? []
    );
} catch (PDOException $e) {
    $CM_DB_ERROR = 'Database connection failed. Check config/database.php credentials and that the "connectmeet" database has been imported (see INSTALLATION.md). Details: ' . $e->getMessage();
    error_log('[ConnectMeet] DB connection error: ' . $e->getMessage());
}

// ---------- CSRF secret ----------
if (empty($_SESSION['cm_csrf_secret'])) {
    $_SESSION['cm_csrf_secret'] = bin2hex(random_bytes(32));
}

// ---------- app secret ----------
$CM_APP_SECRET = $CM_CONFIG['app']['app_secret'] ?? '';
if (strlen($CM_APP_SECRET) < 32) {
    // In production a weak secret is a security hole; warn but continue so
    // the local prototype still runs.
    error_log('[ConnectMeet] WARNING: app_secret too short (need 32+ chars). Set a strong secret for production.');
}

// ---------- include common helpers ----------
require_once $cmBaseDir . '/includes/functions.php';
require_once $cmBaseDir . '/includes/response.php';
require_once $cmBaseDir . '/includes/csrf.php';
require_once $cmBaseDir . '/includes/auth.php';

/**
 * Build an absolute URL for a path inside the app.
 */
function cm_url(string $path = ''): string
{
    global $CM_CONFIG;
    $base = rtrim($CM_CONFIG['app']['base_url'] ?? '', '/');
    if ($path === '') {
        return $base;
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Public WebRTC ICE configuration (safe to expose to the browser).
 */
function cm_ice_config(): array
{
    global $CM_CONFIG;
    return $CM_CONFIG['webrtc']['ice_servers'] ?? [];
}
