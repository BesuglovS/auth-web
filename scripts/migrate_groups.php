<?php
/**
 * Скрипт миграции групп (классов) из contest-web в auth-web.
 *
 * Переносит таблицы groups и user_groups с сохранением id,
 * чтобы ссылки contest_access.group_id в contest-web остались валидными.
 *
 * Использование:
 *   php scripts/migrate_groups.php /path/to/contest.db
 *   php scripts/migrate_groups.php                          (по умолчанию ищет contest-web на том же сервере)
 *
 * ВАЖНО: перед запуском нужно перенести учеников (scripts/migrate_users.php),
 * т.к. user_groups ссылается на id учеников auth-web.
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
    echo "Подсказка: укажите путь параметром: php scripts/migrate_groups.php /path/to/contest.db\n";
    exit(1);
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/Auth.php';

Database::initialize();
$db = Database::getInstance();

$contestDb = new PDO('sqlite:' . $contestDbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ---- Группы ----
$groups = $contestDb->query("SELECT id, name, description FROM groups ORDER BY id")->fetchAll();
echo "Найдено групп в contest-web: " . count($groups) . "\n\n";

$importedGroups = 0;
$updatedGroups = 0;
foreach ($groups as $group) {
    $existing = $db->prepare("SELECT id FROM groups WHERE id = ?");
    $existing->execute([$group['id']]);
    if ($existing->fetch()) {
        $db->prepare("UPDATE groups SET name = ?, description = ? WHERE id = ?")
            ->execute([$group['name'], $group['description'], $group['id']]);
        echo "  [ОБНОВЛЕНА] #{$group['id']} {$group['name']}\n";
        $updatedGroups++;
        continue;
    }

    try {
        $db->prepare("INSERT INTO groups (id, name, description) VALUES (?, ?, ?)")
            ->execute([$group['id'], $group['name'], $group['description']]);
        echo "  [OK] #{$group['id']} {$group['name']}\n";
        $importedGroups++;
    } catch (PDOException $e) {
        echo "  [ОШИБКА] #{$group['id']} {$group['name']}: " . $e->getMessage() . "\n";
    }
}

// ---- Принадлежность учеников к классам ----
$memberships = $contestDb->query("SELECT user_id, group_id FROM user_groups ORDER BY user_id")->fetchAll();
echo "\nНайдено связей user->group в contest-web: " . count($memberships) . "\n";

// Чистим старые связи, чтобы результат был консистентным
$db->exec("DELETE FROM user_groups");

$imported = 0;
$skipped = 0;
foreach ($memberships as $m) {
    $userExists = $db->prepare("SELECT id FROM users WHERE id = ?");
    $userExists->execute([$m['user_id']]);
    if (!$userExists->fetch()) {
        echo "  [ПРОПУЩЕНО] ученик #{$m['user_id']} не найден в auth-web\n";
        $skipped++;
        continue;
    }

    try {
        $db->prepare("INSERT OR IGNORE INTO user_groups (user_id, group_id) VALUES (?, ?)")
            ->execute([$m['user_id'], $m['group_id']]);
        $imported++;
    } catch (PDOException $e) {
        echo "  [ОШИБКА] user #{$m['user_id']} / group #{$m['group_id']}: " . $e->getMessage() . "\n";
    }
}

echo "\n--- Итого ---\n";
echo "Групп импортировано: {$importedGroups}\n";
echo "Групп обновлено: {$updatedGroups}\n";
echo "Связей user->group импортировано: {$imported}\n";
echo "Связей пропущено (нет ученика): {$skipped}\n";
