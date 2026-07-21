<?php
/**
 * Скрипт миграции пользователей из contest-web в auth-web
 *
 * Использование:
 *   php scripts/migrate_users.php /path/to/contest.db
 *   php scripts/migrate_users.php                          (по умолчанию ищет рядом)
 */

$configPath = __DIR__ . '/../config.php';
if (!file_exists($configPath)) {
    echo "ОШИБКА: config.php не найден\n";
    exit(1);
}
require_once $configPath;

$contestDbPath = $argv[1] ?? '/var/www/contest.nayanovaacademy.ru/public/data/contest.db';
if (!file_exists($contestDbPath)) {
    echo "ОШИБКА: База данных contest-web не найдена: {$contestDbPath}\n";
    echo "Подсказка: укажите путь параметром: php scripts/migrate_users.php /path/to/contest.db\n";
    exit(1);
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

Database::initialize();

$contestDb = new PDO('sqlite:' . $contestDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$users = $contestDb->query("SELECT id, login, display_name, password_hash, is_admin, created_at FROM users ORDER BY id")->fetchAll();

echo "Найдено пользователей в contest-web: " . count($users) . "\n\n";

$imported = 0;
$skipped = 0;
$errors = 0;

foreach ($users as $user) {
    $db = Database::getInstance();
    $check = $db->prepare("SELECT id FROM users WHERE login = ?");
    $check->execute([$user['login']]);
    $existing = $check->fetch();

    if ($existing) {
        $stmt = $db->prepare("UPDATE users SET password_hash = ?, is_admin = ? WHERE id = ?");
        $stmt->execute([$user['password_hash'], $user['is_admin'], $existing['id']]);
        echo "  [ОБНОВЛЁН] {$user['login']} — пароль и права обновлены из contest-web\n";
        $skipped++;
        continue;
    }

    try {
        $stmt = $db->prepare("INSERT INTO users (login, display_name, password_hash, is_admin, created_at) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $user['login'],
            $user['display_name'],
            $user['password_hash'],
            $user['is_admin'],
            $user['created_at'],
        ]);
        echo "  [OK] {$user['login']} ({$user['display_name']})\n";
        $imported++;
    } catch (PDOException $e) {
        echo "  [ОШИБКА] {$user['login']}: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n--- Итого ---\n";
echo "Импортировано: {$imported}\n";
echo "Пропущено (уже существует): {$skipped}\n";
echo "Ошибок: {$errors}\n";
