<?php
class Auth
{
    public static function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }

    public static function isAdmin(): bool
    {
        return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] == 1;
    }

    public static function getUserId(): ?int
    {
        return $_SESSION['user_id'] ?? null;
    }

    public static function getUserName(): ?string
    {
        return $_SESSION['display_name'] ?? null;
    }

    public static function getLogin(): ?string
    {
        return $_SESSION['login'] ?? null;
    }

    public static function getUser(): ?array
    {
        if (!self::isLoggedIn()) return null;
        return [
            'id' => self::getUserId(),
            'login' => self::getLogin(),
            'display_name' => self::getUserName(),
            'is_admin' => self::isAdmin(),
        ];
    }

    public static function login(string $login, string $password): array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM users WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь не найден'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['success' => false, 'error' => 'Неверный пароль'];
        }

        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['login'] = $user['login'];
        $_SESSION['display_name'] = $user['display_name'];
        $_SESSION['is_admin'] = (int) $user['is_admin'];

        $sessionId = session_id();
        $expiresAt = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $stmt = $db->prepare("INSERT OR REPLACE INTO sessions (id, user_id, expires_at, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$sessionId, $user['id'], $expiresAt, $ip, $ua]);

        return ['success' => true];
    }

    public static function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionId = session_id();
            $db = Database::getInstance();
            $stmt = $db->prepare("DELETE FROM sessions WHERE id = ?");
            $stmt->execute([$sessionId]);
            session_destroy();
        }

        setcookie('auth_session', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '.nayanovaacademy.ru',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
            header('Location: ' . BASE_URL . '/index.php?page=login');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            header('Location: ' . BASE_URL . '/index.php');
            exit;
        }
    }

    public static function getAllUsers(): array
    {
        $db = Database::getInstance();
        return $db->query("SELECT id, login, display_name, is_admin, created_at FROM users ORDER BY login")->fetchAll();
    }

    public static function getUserById(int $id): ?array
    {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT id, login, display_name, is_admin, created_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function createUser(string $login, string $displayName, string $password, bool $isAdmin = false): array
    {
        $db = Database::getInstance();
        try {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (login, display_name, password_hash, is_admin) VALUES (?, ?, ?, ?)");
            $stmt->execute([$login, $displayName, $hash, (int) $isAdmin]);
            return ['success' => true, 'id' => $db->lastInsertId()];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['success' => false, 'error' => 'Пользователь с таким логином уже существует'];
            }
            return ['success' => false, 'error' => 'Ошибка базы данных'];
        }
    }

    public static function updateUser(int $id, string $login, string $displayName, bool $isAdmin, ?string $password = null): array
    {
        $db = Database::getInstance();
        try {
            if ($password !== null && $password !== '') {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET login = ?, display_name = ?, is_admin = ?, password_hash = ? WHERE id = ?");
                $stmt->execute([$login, $displayName, (int) $isAdmin, $hash, $id]);
            } else {
                $stmt = $db->prepare("UPDATE users SET login = ?, display_name = ?, is_admin = ? WHERE id = ?");
                $stmt->execute([$login, $displayName, (int) $isAdmin, $id]);
            }
            return ['success' => true];
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'UNIQUE')) {
                return ['success' => false, 'error' => 'Пользователь с таким логином уже существует'];
            }
            return ['success' => false, 'error' => 'Ошибка базы данных'];
        }
    }

    /**
     * Сбросить пароль пользователя (только для администратора)
     */
    public static function resetPassword(int $userId, string $newPassword): array
    {
        if (!self::isAdmin()) {
            return ['success' => false, 'error' => 'Нет прав'];
        }

        if (strlen($newPassword) < 6) {
            return ['success' => false, 'error' => 'Пароль должен быть не менее 6 символов'];
        }

        $db = Database::getInstance();
        $user = self::getUserById($userId);
        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь не найден'];
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$hash, $userId]);

        return ['success' => true];
    }

    public static function deleteUser(int $id): bool
    {
        if ($id == 1) {
            return false;
        }
        $db = Database::getInstance();
        $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }
}
