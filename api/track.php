<?php
/**
 * Единый API учёта активности ученика.
 *
 * Клиент — tracking-client.js (распространяется по экосистеме, канонический
 * источник auth-web/assets/js/tracking-client.js). Отправляет heartbeat'ы
 * вида { url, title, referrer, duration } для авторизованных пользователей
 * (кука auth_session).
 *
 * Правила доступа (аналогично progress.php):
 *  - браузерный запрос (с Origin): нужен вход по куке auth_session,
 *    Origin должен быть в ALLOWED_ORIGINS;
 *  - серверный запрос (без Origin, curl с сервера): разрешён с ALLOWED_IPS.
 *
 * Rate-limit: не более TRACK_RATE_LIMIT heartbeat'ов за TRACK_RATE_WINDOW
 * секунд на ученика, иначе 429.
 *
 * POST { url, title, referrer, duration, tab } → { success: true }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setCorsHeaders();
    http_response_code(200);
    exit;
}

setCorsHeaders();

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($origin === '') {
    if (!in_array($ip, ALLOWED_IPS, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
} elseif (!in_array($origin, ALLOWED_ORIGINS, true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

Database::initialize();

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['authenticated' => false, 'error' => 'Требуется авторизация']);
    exit;
}

// ─── Rate-limit heartbeat'ов ───
const TRACK_RATE_WINDOW = 60;  // размер окна, сек
const TRACK_RATE_LIMIT = 12;   // максимум запросов на окно на ученика

/**
 * Фиксированное окно на user_id: не более TRACK_RATE_LIMIT heartbeat'ов
 * за TRACK_RATE_WINDOW секунд. Легитимный клиент тикает раз в 30 с на вкладку,
 * так что лимит покрывает несколько вкладок и beacon'ы при сворачивании.
 * Отклонённые запросы не трогают activity_sessions/page_views.
 */
function trackRateLimit(PDO $db, int $userId): bool {
    $name = 'track:' . $userId;
    $now = time();

    // BEGIN IMMEDIATE сериализует конкурентные вкладки одного ученика.
    // Нюанс версий PHP: на старых pdo_sqlite (8.1 Ubuntu) exec('BEGIN') не
    // регистрирует транзакцию в драйвере — commit()/rollBack() бросают
    // "There is no active transaction", а SQLite-транзакция остаётся висеть.
    // Поэтому определяем режим по inTransaction() сразу после BEGIN
    // (логика общая с Auth::immediateTxn).
    $db->exec('BEGIN IMMEDIATE');
    $driverTxn = $db->inTransaction();
    try {
        $stmt = $db->prepare("SELECT window_started_at, hits FROM rate_limits WHERE name = ?");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        $allowed = true;

        if (!$row || ($now - (int) $row['window_started_at']) >= TRACK_RATE_WINDOW) {
            $stmt = $db->prepare(
                "INSERT INTO rate_limits (name, window_started_at, hits) VALUES (?, ?, 1)
                 ON CONFLICT(name) DO UPDATE SET
                   window_started_at = excluded.window_started_at,
                   hits = 1"
            );
            $stmt->execute([$name, $now]);
        } elseif ((int) $row['hits'] >= TRACK_RATE_LIMIT) {
            $allowed = false;
        } else {
            $stmt = $db->prepare("UPDATE rate_limits SET hits = hits + 1 WHERE name = ?");
            $stmt->execute([$name]);
        }

        if ($driverTxn) { $db->commit(); } else { $db->exec('COMMIT'); }
        return $allowed;
    } catch (Throwable $e) {
        try {
            if ($driverTxn) {
                if ($db->inTransaction()) { $db->rollBack(); }
            } else {
                $db->exec('ROLLBACK');
            }
        } catch (Throwable $ignored) {}
        throw $e;
    }
}

$db = Database::getInstance();
if (!trackRateLimit($db, (int) Auth::getUserId())) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests']);
    exit;
}

$userId = Auth::getUserId();
$sessionKey = session_id();
$now = date('Y-m-d H:i:s');

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$tab = isset($body['tab']) ? (string) $body['tab'] : '';
if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $tab)) {
    $tab = '';
}
$viewKey = $tab !== '' ? $sessionKey . ':' . $tab : $sessionKey;

$url = isset($body['url']) ? (string) $body['url'] : '';
if ($url === '' || strlen($url) > 2048) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing url']);
    exit;
}

$title = isset($body['title']) ? mb_substr((string) $body['title'], 0, 255) : '';
$referrer = isset($body['referrer']) ? mb_substr((string) $body['referrer'], 0, 2048) : '';
$duration = isset($body['duration']) && is_numeric($body['duration']) ? max(0, min(3600, (int) $body['duration'])) : 0;

// ─── Активная рабочая сессия ───
$stmt = $db->prepare("SELECT id FROM activity_sessions WHERE user_id = ? AND logout_at IS NULL ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId]);
$activeSession = $stmt->fetch();

if ($activeSession) {
    $stmt = $db->prepare("UPDATE activity_sessions SET last_seen_at = ? WHERE id = ?");
    $stmt->execute([$now, (int) $activeSession['id']]);
} else {
    $ipAddr = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $stmt = $db->prepare("INSERT INTO activity_sessions (user_id, login_at, last_seen_at, ip_address, user_agent, session_key) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $now, $now, $ipAddr, $ua, $sessionKey]);
}

// ─── Просмотр страницы ───
$stmt = $db->prepare("SELECT id, page_url FROM page_views WHERE user_id = ? AND session_key = ? AND ended_at IS NULL ORDER BY id DESC LIMIT 1");
$stmt->execute([$userId, $viewKey]);
$open = $stmt->fetch();

if ($open && $open['page_url'] === $url) {
    $stmt = $db->prepare("UPDATE page_views SET duration_seconds = duration_seconds + ?, page_title = CASE WHEN ? = '' THEN page_title ELSE ? END, last_seen_at = ? WHERE id = ?");
    $stmt->execute([$duration, $title, $title, $now, (int) $open['id']]);
} elseif ($open) {
    $stmt = $db->prepare("UPDATE page_views SET ended_at = ? WHERE id = ?");
    $stmt->execute([$now, (int) $open['id']]);
    $stmt = $db->prepare("INSERT INTO page_views (user_id, session_key, page_url, page_title, referrer, started_at, last_seen_at, duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $viewKey, $url, $title, $referrer, $now, $now, $duration]);
} else {
    $stmt = $db->prepare("INSERT INTO page_views (user_id, session_key, page_url, page_title, referrer, started_at, last_seen_at, duration_seconds) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $viewKey, $url, $title, $referrer, $now, $now, $duration]);
}

echo json_encode(['success' => true]);