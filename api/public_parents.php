<?php
declare(strict_types=1);
/**
 * Публичный эндпоинт для синхронизации родителей с поддоменами
 * (например, j-web): профили + связи «родитель–ребёнок», без паролей.
 * Доступен только с доверенных хостов (CORS + IP).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Parents.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setCorsHeaders();
    http_response_code(200);
    exit;
}

setCorsHeaders();

// Отклоняем запросы с неразрешённого Origin
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, ALLOWED_ORIGINS)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Запросы без Origin (серверные, curl) — только с доверенных IP
if ($origin === '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ALLOWED_IPS, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

Database::initialize();

echo json_encode(
    ['parents' => Parents::getForSync()],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);
