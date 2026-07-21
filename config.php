<?php
define('SITE_NAME', 'Авторизация');
define('BASE_URL', 'https://auth.nayanovaacademy.ru');
define('BASE_PATH', __DIR__);

define('DB_PATH', BASE_PATH . '/data/auth.db');

define('SESSION_LIFETIME', 86400 * 30);

define('ALLOWED_ORIGINS', [
    'https://contest.nayanovaacademy.ru',
    'https://python.nayanovaacademy.ru',
]);

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

date_default_timezone_set('UTC');

if (session_status() === PHP_SESSION_NONE) {
    session_name('auth_session');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'domain' => '.nayanovaacademy.ru',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_only_cookies', 1);
    session_start();
}

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function validateCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    $valid = hash_equals($_SESSION['csrf_token'], $token);
    if ($valid) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $valid;
}

function sanitizeString(?string $value): string {
    if ($value === null) return '';
    $value = str_replace("\0", '', $value);
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    return $value;
}

function setCorsHeaders(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, ALLOWED_ORIGINS)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    }
}
