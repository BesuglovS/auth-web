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
            // Ждать освобождения блокировки записи до 5 с вместо мгновенного
            // SQLITE_BUSY (нужно для BEGIN IMMEDIATE в rate-limit'е track.php).
            self::$instance->exec('PRAGMA busy_timeout=5000');
        }
        return self::$instance;
    }

    public static function initialize(): void
    {
        $db = self::getInstance();

        // Схема развивается пошагово: PRAGMA user_version как маркер миграции.
        // Существующая прод-БД на первом запросе после деплоя прогонит только
        // недостающие шаги (весь DDL — IF NOT EXISTS).
        $version = (int) $db->query('PRAGMA user_version')->fetchColumn();
        if ($version < 1) {
            self::migrate($db);
            $db->exec('PRAGMA user_version = 1');
        }
        if ($version < 2) {
            self::migrateV2($db);
            $db->exec('PRAGMA user_version = 2');
        }

        // Очистка протухших сессий — изредка, а не на каждом запросе.
        if (random_int(1, 100) === 1) {
            self::cleanupExpiredSessions($db);
        }
    }

    private static function migrate(PDO $db): void
    {
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

            CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                description TEXT DEFAULT ''
            );

            CREATE TABLE IF NOT EXISTS user_groups (
                user_id INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (user_id, group_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            );

            CREATE TABLE IF NOT EXISTS progress (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                course TEXT NOT NULL,
                module TEXT NOT NULL,
                completed INTEGER NOT NULL DEFAULT 0,
                score INTEGER,
                data TEXT,
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE (user_id, course, module),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_progress_user ON progress(user_id);
            CREATE INDEX IF NOT EXISTS idx_progress_user_course ON progress(user_id, course);

            CREATE INDEX IF NOT EXISTS idx_sessions_user_id ON sessions(user_id);
            CREATE INDEX IF NOT EXISTS idx_sessions_expires_at ON sessions(expires_at);
            CREATE INDEX IF NOT EXISTS idx_user_groups_group ON user_groups(group_id);

            CREATE TABLE IF NOT EXISTS activity_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                login_at DATETIME NOT NULL DEFAULT (datetime('now')),
                logout_at DATETIME,
                last_seen_at DATETIME,
                ip_address TEXT DEFAULT '',
                user_agent TEXT DEFAULT '',
                session_key TEXT DEFAULT '',
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_activity_sessions_user ON activity_sessions(user_id);
            CREATE INDEX IF NOT EXISTS idx_activity_sessions_login ON activity_sessions(login_at);

            CREATE TABLE IF NOT EXISTS page_views (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_key TEXT DEFAULT '',
                page_url TEXT NOT NULL,
                page_title TEXT DEFAULT '',
                referrer TEXT DEFAULT '',
                started_at DATETIME NOT NULL DEFAULT (datetime('now')),
                last_seen_at DATETIME,
                ended_at DATETIME,
                duration_seconds INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_page_views_user ON page_views(user_id);
            CREATE INDEX IF NOT EXISTS idx_page_views_user_started ON page_views(user_id, started_at);
            CREATE INDEX IF NOT EXISTS idx_page_views_started ON page_views(started_at);
        ");

        $count = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $hash = password_hash('admin', PASSWORD_BCRYPT);
            $stmt = $db->prepare("INSERT INTO users (login, display_name, password_hash, is_admin) VALUES (?, ?, ?, 1)");
            $stmt->execute(['admin', 'Администратор', $hash]);
        }

        self::cleanupExpiredSessions($db);
    }

    /**
     * Миграция v2: таблица rate-limit'ов (счётчик фиксированного окна
     * на ключ, например 'track:{user_id}'). Одна строка на ключ — таблица
     * не растёт и не требует очистки.
     */
    private static function migrateV2(PDO $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                name TEXT PRIMARY KEY,
                window_started_at INTEGER NOT NULL,
                hits INTEGER NOT NULL DEFAULT 0
            );
        ");
    }

    private static function cleanupExpiredSessions(PDO $db): void
    {
        $db->exec("DELETE FROM sessions WHERE expires_at < datetime('now')");
    }
}
