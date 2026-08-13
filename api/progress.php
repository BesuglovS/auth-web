<?php
/**
 * Единый API прогресса ученика.
 *
 * Один источник данных о прогрессе по всем курсам экосистемы
 * (oge, office, vpr, inf, python, ai и др.). Клиент — shared/js/progress-client.js
 * и сводная карточка ученика на портале na-web.
 *
 * Правила доступа (аналогично public_users.php / user_groups.php):
 *  - браузерный запрос (с Origin): нужен вход по куке auth_session,
 *    Origin должен быть в ALLOWED_ORIGINS;
 *  - серверный запрос (без Origin, curl с сервера): разрешён с ALLOWED_IPS
 *    и может передавать user_id явно (для отчёта курса о прогрессе ученика).
 *
 * GET  ?course=X            → {progress:{module:row,...}, stats:{...}}
 * GET  (без course)         → {courses:{course:{total,completed,percentage,updated_at}}}
 * POST {course,module,...}  → upsert одного модуля
 * PUT  {course,updates:[...]} → пакетный upsert (до 200 модулей)
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
$isServer = ($origin === '');

if ($isServer) {
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

Database::initialize();

// ─── Определяем пользователя, чей прогресс обрабатываем ───
$userId = null;
if (Auth::isLoggedIn()) {
    $userId = Auth::getUserId();
} elseif ($isServer) {
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    $userId = isset($_GET['user_id']) ? (int) $_GET['user_id']
        : (isset($body['user_id']) ? (int) $body['user_id'] : null);
}

if ($userId === null) {
    http_response_code(401);
    echo json_encode(['authenticated' => false, 'error' => 'Требуется авторизация']);
    exit;
}

$db = Database::getInstance();

function validCourse(string $course): bool {
    return preg_match('/^[a-z0-9_-]{1,32}$/', $course) === 1;
}

function validModule(string $module): bool {
    return preg_match('/^[a-zA-Z0-9_.-]{1,64}$/', $module) === 1;
}

function progressRow(array $row): array {
    $data = $row['data'] ?? null;
    if (is_string($data)) {
        $decoded = json_decode($data, true);
        $data = ($decoded === null) ? $data : $decoded;
    }
    return [
        'completed'  => (int) $row['completed'],
        'score'      => $row['score'] !== null ? (int) $row['score'] : null,
        'data'       => $data,
        'updated_at' => $row['updated_at'],
    ];
}

function courseStats(array $rows): array {
    $summary = null;
    $completed = 0;
    $total = 0;
    foreach ($rows as $module => $row) {
        if ($module === '__summary__') {
            $s = $row['data'];
            if (is_array($s)) {
                $summary = [
                    'completed' => (int) ($s['completed'] ?? 0),
                    'total'     => (int) ($s['total'] ?? 0),
                ];
            }
            continue;
        }
        $total++;
        if (!empty($row['completed'])) {
            $completed++;
        }
    }
    if ($summary !== null && $summary['total'] > 0) {
        $total = $summary['total'];
        $completed = max($completed, $summary['completed']);
    }
    return [
        'total'      => $total,
        'completed'  => $completed,
        'percentage' => $total > 0 ? (int) round($completed / $total * 100) : 0,
    ];
}

// ─── GET ───
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $course = isset($_GET['course']) ? (string) $_GET['course'] : '';

    if ($course !== '') {
        if (!validCourse($course)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid course']);
            exit;
        }
        $stmt = $db->prepare("SELECT module, completed, score, data, updated_at
                              FROM progress WHERE user_id = ? AND course = ?");
        $stmt->execute([$userId, $course]);
        $rows = [];
        $raw = $stmt->fetchAll();
        foreach ($raw as $r) {
            $rows[$r['module']] = progressRow($r);
        }
        echo json_encode([
            'progress' => $rows,
            'stats'    => courseStats($rows),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Все курсы — сводка для портала
    $stmt = $db->prepare("SELECT course, module, completed, score, data, updated_at
                          FROM progress WHERE user_id = ? ORDER BY updated_at DESC");
    $stmt->execute([$userId]);
    $courses = [];
    foreach ($stmt->fetchAll() as $r) {
        if (!isset($courses[$r['course']])) {
            $courses[$r['course']] = ['rows' => []];
        }
        $courses[$r['course']]['rows'][$r['module']] = progressRow($r);
    }
    $out = [];
    foreach ($courses as $c => $bag) {
        $stats = courseStats($bag['rows']);
        $out[$c] = [
            'total'      => $stats['total'],
            'completed'  => $stats['completed'],
            'percentage' => $stats['percentage'],
            'updated_at' => $bag['rows']['__summary__']['updated_at'] ?? end($bag['rows'])['updated_at'],
        ];
    }
    echo json_encode(['courses' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Запись (POST / PUT) ───
$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

$course = isset($body['course']) ? (string) $body['course'] : '';
if (!validCourse($course)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing course']);
    exit;
}

$items = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($body['module']) || !validModule((string) $body['module'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid or missing module']);
        exit;
    }
    $items[] = $body;
} elseif ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $updates = $body['updates'] ?? [];
    if (!is_array($updates) || count($updates) > 200) {
        http_response_code(400);
        echo json_encode(['error' => 'updates must be an array of up to 200 items']);
        exit;
    }
    foreach ($updates as $u) {
        if (!is_array($u) || !isset($u['module']) || !validModule((string) $u['module'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Each update needs a valid module']);
            exit;
        }
        $items[] = $u;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$stmt = $db->prepare(
    "INSERT INTO progress (user_id, course, module, completed, score, data, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
     ON CONFLICT(user_id, course, module) DO UPDATE SET
       completed = excluded.completed,
       score = CASE
         WHEN excluded.score IS NOT NULL THEN excluded.score
         ELSE progress.score
       END,
       data = CASE
         WHEN excluded.data IS NOT NULL THEN excluded.data
         ELSE progress.data
       END,
       updated_at = datetime('now')"
);

foreach ($items as $item) {
    $completed = !empty($item['completed']) ? 1 : 0;
    $score = isset($item['score']) && is_numeric($item['score']) ? (int) $item['score'] : null;
    $data = isset($item['data']) ? json_encode($item['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmt->execute([$userId, $course, (string) $item['module'], $completed, $score, $data]);
}

echo json_encode(['success' => true, 'saved' => count($items)]);
