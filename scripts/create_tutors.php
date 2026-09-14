<?php

declare(strict_types=1);

/**
 * Разовое идемпотентное создание учётных записей тьюторов (классных
 * руководителей) на портале auth-web. Обычные пользователи (is_admin=0),
 * не состоят ни в каких группах — журнал j-web резолвит по ним роль
 * «тьютор» через зеркальную таблицу tutors (см. scripts/seed_tutors.php j-web).
 *
 * Данные читаются из CSV (кодировка UTF-8, разделитель «;»):
 *
 *     login;ФИО;пароль
 *     ЗагуменскаяВА;Загуменская Виктория Алексеевна;Fkz3FBl5KX
 *
 * Строка-заголовок (первая колонка = "login") пропускается. Пароль —
 * открытый текст ТОЛЬКО в CSV: храните файл вне репозитория и удалите
 * после выполнения. Скрипт пароли в выход не печатает, хранит bcrypt.
 *
 * Запуск (на сервере):  php create_tutors.php /tmp/tutors.csv [путь-к-auth.db]
 * Идемпотентно: повторный запуск обновит ФИО и пароль существующих учёток.
 * Учётки, состоящие в группах (ученики), не редактируются — только отчёт.
 */

$csvPath = $argv[1] ?? '';
$dbPath = $argv[2] ?? (dirname(__DIR__) . '/data/auth.db');
if ($csvPath === '' || !is_file($csvPath)) {
    fwrite(STDERR, "Использование: php create_tutors.php <tutors.csv> [db-path]\n");
    exit(1);
}
if (!is_file($dbPath)) {
    fwrite(STDERR, "БД не найдена: $dbPath\n");
    exit(1);
}
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

/** Нижний регистр без mbstring: ASCII + кириллица UTF-8 */
function lower(string $s): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($s);
    }
    $upper = 'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower = 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяabcdefghijklmnopqrstuvwxyz';
    $map = array_combine(
        preg_split('//u', $upper, -1, PREG_SPLIT_NO_EMPTY),
        preg_split('//u', $lower, -1, PREG_SPLIT_NO_EMPTY)
    );
    return strtr($s, $map);
}

$hasMembership = $pdo->prepare('SELECT 1 FROM user_groups WHERE user_id=? LIMIT 1');
$byLogin = $pdo->prepare('SELECT id, display_name FROM users WHERE login = ? LIMIT 2');
$ins = $pdo->prepare('INSERT INTO users (login, display_name, password_hash, is_admin) VALUES (?, ?, ?, 0)');
$upd = $pdo->prepare('UPDATE users SET display_name=?, password_hash=? WHERE id=?');

$report = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'dup_csv' => 0, 'errors' => []];
$seen = [];

$rows = file($csvPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
if ($rows === false) {
    fwrite(STDERR, "Не удалось прочитать CSV: $csvPath\n");
    exit(1);
}

foreach ($rows as $i => $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') {
        continue;
    }
    $parts = array_map('trim', explode(';', $line));
    if (count($parts) < 3) {
        $report['errors'][] = 'строка ' . ($i + 1) . ': ожидается login;ФИО;пароль';
        continue;
    }
    [$login, $displayName, $password] = [$parts[0], $parts[1], implode(';', array_slice($parts, 2))];
    if (lower($login) === 'login') {
        continue; // заголовок
    }
    if ($login === '' || $password === '') {
        $report['errors'][] = 'строка ' . ($i + 1) . ': пустой логин или пароль';
        continue;
    }
    if (isset($seen[lower($login)])) {
        $report['dup_csv']++;
        continue; // дубль в CSV (несколько классов одного тьютора)
    }
    $seen[lower($login)] = true;

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $byLogin->execute([$login]);
    $existing = $byLogin->fetch();

    if ($existing === false) {
        $ins->execute([$login, $displayName, $hash]);
        $report['added']++;
        continue;
    }

    // Существующая учётка: не трогаем учеников (состоит в группах)
    $hasMembership->execute([(int)$existing['id']]);
    if ($hasMembership->fetch()) {
        $report['skipped']++;
        $report['errors'][] = 'логин ' . $login . ' уже занят пользователем-учеником — не тронут';
        continue;
    }
    $upd->execute([$displayName, $hash, (int)$existing['id']]);
    $report['updated']++;
}

echo "Учёток: +{$report['added']}, обновлено {$report['updated']}, пропущено {$report['skipped']}, дублей в CSV {$report['dup_csv']}\n";
foreach ($report['errors'] as $err) {
    echo "  ВНИМАНИЕ: $err\n";
}
echo $report['errors'] ? "Не все строки обработаны\n" : "OK\n";
exit($report['errors'] ? 2 : 0);
