<?php
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

if (!Auth::isLoggedIn() || !Auth::isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Доступ запрещён']);
    exit;
}

$db = Database::getInstance();
$users = $db->query("SELECT id, login, display_name, is_admin, created_at FROM users ORDER BY login")->fetchAll();

echo json_encode(['users' => $users]);
