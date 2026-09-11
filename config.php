<?php
define('SITE_NAME', 'Авторизация');
define('BASE_URL', 'https://auth.nayanovaacademy.ru');
define('BASE_PATH', __DIR__);

define('DB_PATH', BASE_PATH . '/data/auth.db');

define('SESSION_LIFETIME', 86400 * 30);

// Сервер-к-сервер уведомление журнала (j-web): после каждого изменения в
// списке родителей auth-web сигнализирует внутреннему эндпоинту зеркала —
// и журнал синхронизируется мгновенно, не дожидаясь своего TTL.
// На локальном dev-сервере — локальный инстанс журнала (порт 8090).
$authHostEarly = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
$notifyUrl = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $authHostEarly) || $authHostEarly === ''
    ? 'http://127.0.0.1:8090/api/internal/parents-sync'
    : 'https://j.nayanovaacademy.ru/api/internal/parents-sync';
define('PARENT_MIRROR_NOTIFY_URL', $notifyUrl);

$allowedOrigins = [
    'https://auth.nayanovaacademy.ru',
    'https://contest.nayanovaacademy.ru',
    'https://python.nayanovaacademy.ru',
    'https://j.nayanovaacademy.ru',
    'https://ai.nayanovaacademy.ru',
    'https://oge.nayanovaacademy.ru',
    'https://office.nayanovaacademy.ru',
    'https://inf.nayanovaacademy.ru',
    'https://vpr.nayanovaacademy.ru',
    'https://nayanovaacademy.ru',
    'https://www.nayanovaacademy.ru',
];

// Dev-origin по http разрешён только при локальном запуске auth-web.
// На проде HTTP_HOST — всегда *.nayanovaacademy.ru, и localhost в список не попадает.
$authHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
if ($authHost === '' || preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $authHost)) {
    $allowedOrigins[] = 'http://localhost:8080';
}

define('ALLOWED_ORIGINS', $allowedOrigins);

// IP-адреса серверов, которым разрешены серверные вызовы API без Origin
// (contest, python и т.д.). Значение берётся из .env (DEPLOY_SSH_HOST) —
// все три проекта деплоятся на общий сервер.
define('ALLOWED_IPS', array_merge(
    [
        '79.143.31.184', // contest.nayanovaacademy.ru / python.nayanovaacademy.ru (общий сервер)
    ],
    // Dev-исключение: локальный сервер-к-сервер (эндпоинты без Origin) —
    // на проде HTTP_HOST всегда *.nayanovaacademy.ru и в список не попадает.
    ($authHostEarly !== '' && preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $authHostEarly))
        ? ['127.0.0.1', '::1'] : []
));

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
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
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
    // Токен стабилен на всю сессию: без ротации, чтобы формы в нескольких
    // вкладках и повторные отправки не ломались.
    return hash_equals($_SESSION['csrf_token'], $token);
}

function sanitizeString(?string $value): string {
    if ($value === null) return '';
    $value = str_replace("\0", '', $value);
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    return $value;
}

function isSafeRedirect(string $url): bool {
    if ($url === '') return false;
    // Относительные пути внутри сайта (/index.php?page=...), кроме protocol-relative («//host»)
    if ($url[0] === '/') {
        return !str_starts_with($url, '//');
    }
    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || ($parts['scheme'] ?? '') !== 'https') {
        return false;
    }
    $host = $parts['host'];
    return $host === 'nayanovaacademy.ru' || str_ends_with($host, '.nayanovaacademy.ru');
}

function setCorsHeaders(): void {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array($origin, ALLOWED_ORIGINS)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
        // Кэшировать preflight: heartbeat'ы идут каждые 30 с — без кэша
        // браузер шлёт OPTIONS перед каждым из них.
        header('Access-Control-Max-Age: 86400');
    }
}

// Локальная зона отображения — Самара (UTC+4). Хранение остаётся в UTC.
function localTimezone(): DateTimeZone {
    static $tz = null;
    if ($tz === null) {
        try {
            $tz = new DateTimeZone('Europe/Samara');
        } catch (Exception $e) {
            $tz = new DateTimeZone('+04:00');
        }
    }
    return $tz;
}

/**
 * Перевести UTC-строку из БД в локальное время (UTC+4) для отображения.
 */
function displayDateTime(?string $utc): string {
    if ($utc === null || $utc === '') return '';
    try {
        $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $dt->setTimezone(localTimezone())->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $utc;
    }
}
