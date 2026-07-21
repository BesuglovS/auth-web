<?php
class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $dbDir = dirname(DB_PATH);
            if (!is_dir($dbDir)) {
                if (!mkdir($dbDir, 0755, true)) {
                    throw new RuntimeException(
                        "Не удалось создать директорию для базы данных: {$dbDir}"
                    );
                }
            }

            if (!is_writable($dbDir)) {
                throw new RuntimeException(
                    "Директория базы данных недоступна для записи: {$dbDir}"
                );
            }

            self::$instance = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            self::$instance->exec('PRAGMA journal_mode=WAL');
            self::$instance->exec('PRAGMA foreign_keys=ON');
        }
        return self::$instance;
    }

    public static function initialize(): void
    {
        $db = self::getInstance();

        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                login TEXT UNIQUE NOT NULL,
                display_name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                is_admin INTEGER NOT NULL DEFAULT 0,
                created_at DATETIME NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                user_id INTEGER NOT NULL,
                created_at DATETIME NOT NULL DEFAULT (datetime('now')),
                expires_at DATETIME NOT NULL,
                ip_address TEXT DEFAULT '',
                user_agent TEXT DEFAULT '',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
            CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);
        ");

        $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $hash = password_hash('admin', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (login, display_name, password_hash, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute(['admin', 'Администратор', $hash]);
        }

        self::cleanupExpiredSessions($db);
    }

    private static function cleanupExpiredSessions(PDO $db): void
    {
        $db->exec("DELETE FROM sessions WHERE expires_at < datetime('now')");
    }
}
