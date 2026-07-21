<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    setCorsHeaders();
    http_response_code(200);
    exit;
}

setCorsHeaders();

Auth::logout();

$redirect = $_GET['redirect'] ?? '';
if ($redirect !== '') {
    header('Location: ' . $redirect);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
