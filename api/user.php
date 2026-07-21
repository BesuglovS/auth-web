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

if (!Auth::isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Не авторизован']);
    exit;
}

echo json_encode([
    'user' => Auth::getUser(),
]);
