<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/Auth.php';
require_once __DIR__ . '/includes/Router.php';

Database::initialize();

$router = new Router();
$router->dispatch();
