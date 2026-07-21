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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$login = trim($input['login'] ?? '');
$password = $input['password'] ?? '';

if ($login === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Заполните логин и пароль']);
    exit;
}

$result = Auth::login($login, $password);

if ($result['success']) {
    echo json_encode([
        'success' => true,
        'user' => Auth::getUser(),
    ]);
} else {
    http_response_code(401);
    echo json_encode($result);
}
