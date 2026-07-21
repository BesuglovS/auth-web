<?php
/**
 * Публичный эндпоинт для синхронизации пользователей.
 * Отдаёт список всех пользователей (без паролей).
 * Доступен только с доверенных хостов (CORS + IP).
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setCorsHeaders();
    http_response_code(200);
    exit;
}

setCorsHeaders();

// Дополнительная защита: отклоняем запросы, если origin не пустой и не из белого списка
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && !in_array($origin, ALLOWED_ORIGINS)) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

Database::initialize();
$db = Database::getInstance();
$users = $db->query(
    "SELECT id, login, display_name, is_admin, created_at FROM users ORDER BY login"
)->fetchAll();

echo json_encode(['users' => $users]);
