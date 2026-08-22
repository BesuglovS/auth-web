# AGENTS.md — Инструкции для ИИ-ассистентов

SSO-хаб и общее хранилище данных (пользователи, классы, прогресс, активность) экосистемы
Nayanova Academy. Чистый **PHP 8 + SQLite** без зависимостей и фреймворков. Прод: `https://auth.nayanovaacademy.ru`.
От этого проекта зависит авторизация **всех** поддоменов (contest, python, ai, j, oge, office, inf, vpr, na).

## ⚠️ Критические правила

1. **Это keystone всей экосистемы.** Изменение имени/параметров сессионной куки, `ALLOWED_ORIGINS`,
   `ALLOWED_IPS` или CORS-логики в `config.php` ломает вход и SSO для всех поддоменов.
   Проверяйте последствия для всех потребителей перед правкой.
2. **SSO-модель**: общий сервер + домен-широкая кука `auth_session` на `.nayanovaacademy.ru`.
   Потребители валидируют её сервер-к-серверу через `/api/check.php` (канонический `AuthClient.php`,
   кэш 5 мин в PHP-сессии). **Не вводите** локальные JWT/таблицы сессий — таблица `sessions` в SQLite
   это запись, а не авторитет; реальная сессия — встроенная PHP-сессия.
3. **Белые списки**: CORS-origins и серверные IP (`ALLOWED_IPS`, жёстко `79.143.31.184`) —
   доверенные хосты отвергают всё остальное. `ALLOWED_IPS` держать вручную в синхроне с реальным хостом.
4. **`api/progress.php`** поддерживает server-to-server переопределение `user_id` (запросы без `Origin`
   с `ALLOWED_IPS`) — курсы (`python-web`, `ai-web` через `ProgressReporter.php`) отчитываются за ученика.
   Браузерные запросы (с `Origin`) обязаны проходить по куке `auth_session` — никогда не пускайте их
   через override.
5. **CSRF**: все form-POST требуют сменный токен. **Rate-limit и блокировки по паролю НЕТ** —
   не презентуйте систему как защищённую от брутфорса.
6. **`.env` читается только `deploy.ps1`**; PHP его не читает. Никогда не печатайте значения
   `.env` и дефолтный пароль админа `admin` (авто-сид в пустую таблицу `users` при `Database::initialize()`).
7. **Часовые пояса**: в БД все метки UTC; пользовательское время — через `displayDateTime()`/`localTimezone()` (UTC+4, Самара).
8. **Локальная `data/auth.db` — устаревший снапшот схемы** (только `users`+`sessions`). Не считайте её
   продакшен-авторитетом; живёт БД на сервере и исключена из git/деплоя.
9. **Синхронизация канонических файлов**: `assets/js/tracking-client.js` (здесь каноничный) и файлы
   из `na-web/shared/` (`php/auth-client/AuthClient.php`, `js/progress-client.js`) копируются на поддомены
   через `na-web/shared/sync.ps1`. После правки здесь — запустите синк, иначе экосистема разойдётся.

## 🔧 Команды

```bash
php -S 127.0.0.1:8080        # локальный dev-сервер (требуется PHP 8 + SQLite3)
.\deploy.ps1 -DryRun         # сухой прогон деплоя
.\deploy.ps1                 # деплой
```

Тестов/CI/линтера нет — проверка вручную через браузер и curl к `/api/*`.

## 🏗 Структура

```
config.php               # КЛЮЧЕВОЙ: параметры сессии, ALLOWED_ORIGINS, ALLOWED_IPS, пути, CORS
index.php                # фронт-контроллер/роутер
api/                     # JSON-эндпоинты: check.php, login.php, logout.php, progress.php, track.php, groups.php, public_users.php, user_groups.php, admin_*.php ...
includes/                # PHP-классы: Database, Auth, AuthClient, User, Group и др. (без namespace)
admin/                   # админка: пользователи, классы, группы, активность, импорт
assets/js/tracking-client.js   # КАНОНИЧНЫЙ heartbeat-клиент (копируется на поддомены)
templates/               # PHP-шаблоны (layout.php + партиалы)
data/auth.db             # SQLite (на сервере; локально — устаревший снапшот)
scripts/                 # вспомогательные скрипты
deploy.ps1               # деплой по SSH (сохраняет БД)
auth.nayanovaacademy.ru  # nginx-конфиг
```

## 💻 Конвенции кода

- **PHP 8**: `declare(strict_types=1)` в новых файлах, типизация возвратов/аргументов, PDO с подготовленными statements, `require_once` для зависимостей, русские docblock'и.
- **JSON API**: `Content-Type: application/json; charset=utf-8`, `JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE`, ошибки `{"error": "..."}` с корректным HTTP-кодом (400/401/403/404/405/429/500).
- **Шаблоны**: `$pageTitle` + буфер `ob_start()`, `$content = ob_get_clean(); require templates/layout.php`.
- **Формы**: CSRF-токен обязательно (сгенерировать, проверить на POST).

## 🚀 Деплой (`deploy.ps1`)

1. Читает `.env` → SSH-переменные; `icacls` для ключа.
2. `tar` репозитория (без `.git`, БД, логов, `.env`, `deploy.ps1`, nginx-конфига) → SSH.
3. Удалённо: бэкап `data/auth.db*` в `/tmp` → **полное `rm -rf` webroot** → распаковка → восстановление БД → chown.
4. Деплой nginx-конфига + `nginx -t && systemctl reload nginx`.

⚠️ Деплой уничтожает всё на сервере, кроме сохранённой БД. Не добавляйте файлы на сервер вручную — их переживёт только БД.

## 🔒 Безопасность (не ломать)

- Файл `G:\WebSites\na\ssh-private.key` (незашифрованный ключ вне репозитория) — никогда не читать, не печатать, не коммитить.
- Не коммитить `.env`, `data/*.db*`, логи (защищены `.gitignore`).
- Известная XSS-дыра: `admin/groups.php` выводит `$bulkErrors` без экранирования — безопасно ТОЛЬКО потому,
  что `Auth::bulkAddUsersToGroup()` экранирует каждую строку на входе. Сохраняйте это экранирование на источнике.
- `admin/activity.php`: переиспользует SQL-фрагмент через `str_replace('a.', '', $userCond)` и
  интерполирует `LIMIT/OFFSET` (безопасно только по построению) — рефакторить осторожно.
- README частично устарел (`.htaccess` удалён, новые эндпоинты не описаны). Источник истины — код.