<?php
declare(strict_types=1);

/**
 * Родители: профили (parents) и связи «родитель–ребёнок» (student_parents).
 *
 * Данные каноничны здесь (перенесены из j-web): каждый родитель —
 * учётная запись в users (см. миграцию v3), ФИО — в профиле parents,
 * связи ссылаются на users с обеих сторон (ученики в auth-web и есть
 * пользователи). Профиль и связи удаляются каскадом вместе с учётной
 * записью. Поддомены (j-web) держат read-only зеркало через
 * api/public_parents.php.
 */
class Parents
{
    /**
     * Список родителей с логином и колонкой «дети» (имена через запятую).
     * $groupId — фильтр «имеет ребёнка в указанном классе».
     */
    public static function getAll(?int $groupId = null): array
    {
        $db = Database::getInstance();
        $sql = "SELECT p.*, u.login, u.created_at,
                       (SELECT GROUP_CONCAT(s.display_name, ', ')
                          FROM student_parents sp
                          JOIN users s ON s.id = sp.student_id
                         WHERE sp.parent_id = p.id) AS children
                  FROM parents p
                  JOIN users u ON u.id = p.user_id";
        $params = [];
        if ($groupId !== null && $groupId > 0) {
            $sql .= " WHERE EXISTS (
                        SELECT 1 FROM student_parents sp2
                        JOIN user_groups ug2 ON ug2.user_id = sp2.student_id
                       WHERE sp2.parent_id = p.id AND ug2.group_id = ?)";
            $params[] = $groupId;
        }
        $sql .= " ORDER BY p.last_name, p.first_name, p.middle_name";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function getById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT p.*, u.login, u.created_at
               FROM parents p
               JOIN users u ON u.id = p.user_id
              WHERE p.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** id пользователей, у которых есть профиль родителя (для бейджа роли в admin/users.php). */
    public static function userIdsWithProfile(): array
    {
        $rows = Database::getInstance()->query("SELECT user_id FROM parents")->fetchAll();
        return array_map('intval', array_column($rows, 'user_id'));
    }

    /** Дети родителя: пользователи auth-web + класс. */
    public static function getChildren(int $parentId): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare(
            "SELECT s.id AS user_id, s.login, s.display_name,
                    (SELECT g.name FROM user_groups ug
                      JOIN groups g ON g.id = ug.group_id
                     WHERE ug.user_id = s.id LIMIT 1) AS group_name
               FROM student_parents sp
               JOIN users s ON s.id = sp.student_id
              WHERE sp.parent_id = ?
              ORDER BY s.display_name"
        );
        $stmt->execute([$parentId]);
        return $stmt->fetchAll();
    }

    /**
     * Создать родителя: учётная запись + профиль одной транзакцией.
     * display_name выводится из ФИО.
     */
    public static function create(string $login, string $password, string $lastName, string $firstName, string $middleName): array
    {
        $login = trim($login);
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $middleName = trim($middleName);

        if ($login === '' || $lastName === '' || $firstName === '') {
            return ['success' => false, 'error' => 'Заполните фамилию, имя и логин'];
        }
        if ($password === '') {
            return ['success' => false, 'error' => 'Укажите пароль'];
        }

        $db = Database::getInstance();
        try {
            $db->beginTransaction();
            $stmt = $db->prepare(
                "INSERT INTO users (login, display_name, password_hash, is_admin) VALUES (?, ?, ?, 0)"
            );
            $stmt->execute([$login, self::fullName($lastName, $firstName, $middleName), password_hash($password, PASSWORD_BCRYPT)]);
            $userId = (int) $db->lastInsertId();

            $stmt = $db->prepare(
                "INSERT INTO parents (user_id, last_name, first_name, middle_name) VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([$userId, $lastName, $firstName, $middleName !== '' ? $middleName : null]);
            $parentId = (int) $db->lastInsertId();

            $db->commit();
            self::notifyMirror();
            return ['success' => true, 'id' => $parentId];
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['success' => false, 'error' => 'Родитель с таким логином уже существует'];
            }
            error_log('[auth] Parents::create failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Ошибка базы данных'];
        }
    }

    /**
     * Обновить родителя: ФИО (профиль + display_name учётки), логин,
     * пароль (пусто — без изменений).
     */
    public static function update(int $id, string $login, string $password, string $lastName, string $firstName, string $middleName): array
    {
        $login = trim($login);
        $lastName = trim($lastName);
        $firstName = trim($firstName);
        $middleName = trim($middleName);

        if ($login === '' || $lastName === '' || $firstName === '') {
            return ['success' => false, 'error' => 'Заполните фамилию, имя и логин'];
        }

        $parent = self::getById($id);
        if (!$parent) {
            return ['success' => false, 'error' => 'Родитель не найден'];
        }

        $db = Database::getInstance();
        try {
            $db->beginTransaction();
            if ($password !== '') {
                $stmt = $db->prepare(
                    "UPDATE users SET login = ?, display_name = ?, password_hash = ? WHERE id = ?"
                );
                $stmt->execute([$login, self::fullName($lastName, $firstName, $middleName), password_hash($password, PASSWORD_BCRYPT), (int) $parent['user_id']]);
            } else {
                $stmt = $db->prepare(
                    "UPDATE users SET login = ?, display_name = ? WHERE id = ?"
                );
                $stmt->execute([$login, self::fullName($lastName, $firstName, $middleName), (int) $parent['user_id']]);
            }

            $stmt = $db->prepare(
                "UPDATE parents SET last_name = ?, first_name = ?, middle_name = ? WHERE id = ?"
            );
            $stmt->execute([$lastName, $firstName, $middleName !== '' ? $middleName : null, $id]);

            $db->commit();
            self::notifyMirror();
            return ['success' => true];
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['success' => false, 'error' => 'Родитель с таким логином уже существует'];
            }
            error_log('[auth] Parents::update failed: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Ошибка базы данных'];
        }
    }

    /**
     * Удалить родителя вместе с учётной записью (профиль и связи
     * родитель–ребёнок снимаются каскадом).
     */
    public static function delete(int $id): array
    {
        $parent = self::getById($id);
        if (!$parent) {
            return ['success' => false, 'error' => 'Родитель не найден'];
        }
        if (!Auth::deleteUser((int) $parent['user_id'])) {
            return ['success' => false, 'error' => 'Не удалось удалить учётную запись родителя'];
        }
        self::notifyMirror();
        return ['success' => true];
    }

    public static function attachChild(int $parentId, int $studentUserId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT OR IGNORE INTO student_parents (student_id, parent_id) VALUES (?, ?)");
        $stmt->execute([$studentUserId, $parentId]);
        if ($stmt->rowCount() > 0) {
            self::notifyMirror();
            return true;
        }
        return false;
    }

    public static function detachChild(int $parentId, int $studentUserId): bool
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM student_parents WHERE student_id = ? AND parent_id = ?");
        $stmt->execute([$studentUserId, $parentId]);
        if ($stmt->rowCount() > 0) {
            self::notifyMirror();
            return true;
        }
        return false;
    }

    /**
     * Данные для синхронизации поддоменов (api/public_parents.php):
     * профили + массив id детей (users auth-web). Без паролей.
     */
    public static function getForSync(): array
    {
        $db = Database::getInstance();
        $parents = $db->query(
            "SELECT p.id, p.user_id, u.login, p.last_name, p.first_name, p.middle_name
               FROM parents p
               JOIN users u ON u.id = p.user_id
              ORDER BY p.id"
        )->fetchAll();

        $linksByParent = [];
        $links = $db->query("SELECT parent_id, student_id FROM student_parents")->fetchAll();
        foreach ($links as $l) {
            $linksByParent[(int) $l['parent_id']][] = (int) $l['student_id'];
        }

        foreach ($parents as &$p) {
            $p['id'] = (int) $p['id'];
            $p['user_id'] = (int) $p['user_id'];
            $p['middle_name'] = $p['middle_name'] ?? '';
            $p['children'] = $linksByParent[(int) $p['id']] ?? [];
        }
        unset($p);

        return $parents;
    }

    private static function fullName(string $lastName, string $firstName, string $middleName): string
    {
        return trim(implode(' ', array_filter([$lastName, $firstName, $middleName], fn (string $v): bool => $v !== '')));
    }

    /**
     * Массовый импорт родителей (текст, по строке на родителя):
     * «Фамилия Имя Отчество;логин;пароль». Разделитель — `;` (или `,`,
     * либо таб). Пароль можно опустить — будет сгенерирован (возвращается
     * в result['success']). Импортированные никуда не привязываются —
     * привязка к ученикам отдельным действием после импорта.
     */
    public static function bulkCreate(string $rawText): array
    {
        $result = ['success' => [], 'failed' => [], 'created' => []];
        $lines = preg_split('/\r\n|\r|\n/', $rawText);
        foreach ($lines as $lineNum => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // разделитель: ; или , или табуляция
            $parts = str_contains($line, ';') ? preg_split('/\s*;\s*/', $line)
                : (str_contains($line, ',') ? preg_split('/\s*,\s*/', $line)
                    : preg_split('/\t+/', $line));

            $fio = trim((string)($parts[0] ?? ''));
            $login = trim((string)($parts[1] ?? ''));
            $password = trim((string)($parts[2] ?? ''));
            $label = 'Строка ' . ($lineNum + 1);

            $nameParts = preg_split('/\s+/u', $fio) ?: [];
            $lastName = $nameParts[0] ?? '';
            $firstName = $nameParts[1] ?? '';
            $middleName = implode(' ', array_slice($nameParts, 2));

            if ($login === '' || $lastName === '' || $firstName === '') {
                $result['failed'][] = "$label: нужно «Фамилия Имя Отчество;логин;пароль»";
                continue;
            }

            if ($password === '') {
                $password = bin2hex(random_bytes(4));
            }

            $res = self::create($login, $password, $lastName, $firstName, $middleName);
            if ($res['success']) {
                $result['success'][] = htmlspecialchars($fio) . ' (' . htmlspecialchars($login) . ') — пароль: '
                    . htmlspecialchars($password);
                $result['created'][] = [
                    'id' => (int) $res['id'],
                    'name' => trim($fio),
                ];
            } else {
                $result['failed'][] = "$label: " . htmlspecialchars($res['error']) . ' — ' . htmlspecialchars($login);
            }
        }
        return $result;
    }

    /**
     * Уведомить зеркала поддоменов (сейчас — журнал j-web) об изменении
     * родителей: вызывается после каждого успешного create/update/delete
     * и привязки/отвязки ребёнка. Best-effort: сбой уведомления не
     * откатывает изменение (зеркало догонит по своему TTL).
     */
    private static function notifyMirror(): void
    {
        $url = defined('PARENT_MIRROR_NOTIFY_URL') ? PARENT_MIRROR_NOTIFY_URL : '';
        if ($url === '') {
            return;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code !== 200) {
            error_log('[auth] parents mirror notify failed: http=' . $code);
        }
    }
}
