# Auth Web

Централизованная система авторизации (SSO-хаб) и общее хранилище данных экосистемы **nayanovaacademy.ru**.

## Назначение

Единый сервис аутентификации, классов, прогресса и активности для подключённых подпроектов:

- **contest** — контест-система
- **python** — Python-курс
- **ai**, **j**, **oge**, **office**, **inf**, **vpr**, **na** — остальные сервисы портала

После входа на `auth.nayanovaacademy.ru` ученик получает общую сессионную куку
`auth_session` на домен `.nayanovaacademy.ru`, которая автоматически распознаётся
всеми подключёнными проектами. Поддомены валидируют её сервер-к-серверу через
`GET /api/check.php` (канонический клиент — `AuthClient.php` в `na-web/shared/`,
кэш 5 минут в PHP-сессии).

## Возможности

- Вход / выход по логину и паролю, CSRF-защита форм
- Сессии с временем жизни 30 дней (`SESSION_LIFETIME` в `config.php`)
- Админ-панель:
  - Управление учениками (создание, редактирование, удаление)
  - Управление классами (создание, редактирование, удаление, состав) — единый источник классов для всех сервисов
  - Сброс пароля любому ученику
  - Массовый импорт учеников из текста или CSV/TXT-файла
  - Отчёт об активности учеников (входы/выходы, просмотренные страницы, время)
- REST API (`/api/`) для программного входа, проверки сессии, списков учеников/классов
- Единый API прогресса по всем курсам (`/api/progress.php`)
- Учёт активности учеников (heartbeat'и `/api/track.php`)
- CORS для разрешённых поддоменов (`ALLOWED_ORIGINS`) и серверные вызовы с доверенных IP (`ALLOWED_IPS`)

## Стек

- **PHP 8+** (vanilla, без фреймворков и зависимостей)
- **SQLite** (PDO) — файл базы `data/auth.db`
- **HTML/CSS/JS** — минималистичный фронтенд
- **nginx + PHP-FPM** на проде

## Структура проекта

```
auth-web/
├── index.php               # Точка входа / фронт-контроллер
├── config.php              # Конфигурация: сессии, CSRF, CORS, ALLOWED_ORIGINS/IP
├── includes/
│   ├── Auth.php            # Аутентификация, управление учениками и классами
│   ├── Database.php        # Синглтон PDO SQLite + миграции схемы (PRAGMA user_version)
│   └── Router.php          # Простой роутер по ?page=
├── templates/              # Шаблоны страниц (layout, login, home)
├── admin/                  # Админ-панель (users, groups, change_password, activity)
├── api/                    # JSON API (см. раздел «API»)
├── assets/
│   └── js/tracking-client.js  # Канонический heartbeat-клиент активности
├── scripts/                # Миграции учеников/классов из contest-web
├── data/                   # Файл SQLite (на сервере; в git не входит)
├── auth.nayanovaacademy.ru # nginx-конфиг сайта (деплоится deploy.ps1)
├── deploy.ps1              # Деплой на сервер по SSH
└── .env                    # Переменные деплоя (не в git)
```

## Учёт активности

Система записывает, кто и когда входил/выходил, какие страницы экосистемы
просматривал и сколько времени на них провёл. Данные хранятся в `data/auth.db`:

- **`activity_sessions`** — рабочие сессии: кто, когда вошёл (`login_at`),
  когда вышел (`logout_at`) или последняя активность (`last_seen_at`), IP и браузер.
- **`page_views`** — просмотренные страницы: URL, заголовок, начало просмотра
  и накопленное время в секундах (`duration_seconds`).

Механика:

- JS-клиент **`assets/js/tracking-client.js`** (`window.NayanovaTrack`) на
  страницах экосистемы каждые 30 с отправляет heartbeat с URL и временем на
  странице на `POST /api/track.php` (авторизация — по общей куке `auth_session`).
  Канонический источник — в этом проекте; копии в проектах-поддоменах
  синхронизируются скриптом `na-web/shared/sync.ps1`. После правки клиента
  здесь обязательно запустите синк, иначе поддомены разойдутся.
- Вход фиксируется в `Auth::login()`, выход — в `Auth::logout()`.
- Отчёт — в админ-панели: **Активность** (`?page=admin-activity`): фильтры по
  ученику и периоду, сводка, таблицы сессий и просмотренных страниц.
  Время в БД хранится в UTC; отображение — в UTC+4 (Самара).

Трекинг работает только для авторизованных пользователей.

## Прогресс по курсам

Таблица `progress` и эндпоинт `/api/progress.php` — единое хранилище прогресса
по всем курсам (python, ai, oge, office, inf, vpr…). Клиенты —
`shared/js/progress-client.js` на поддоменах и сводная карточка ученика на
портале na-web. Курсы могут отчитываться за ученика сервер-к-серверу
(без Origin с доверенного IP, с явным `user_id`); браузерные запросы всегда
идут по куке `auth_session`.

## Установка

1. Убедитесь, что PHP 8+ работает с расширениями `pdo_sqlite` и `sqlite3`.
2. Прод разворачивается под nginx + PHP-FPM; корень сайта — `public`
   (роутинг: `try_files ... /index.php$is_args$args`, см. `auth.nayanovaacademy.ru`).
   Доступ к `data/` извне закрыт в nginx.
3. Убедитесь, что папка `data/` доступна на запись пользователю PHP-FPM.
4. Первоначальная миграция учеников (если нужно):
   ```bash
   php scripts/migrate_users.php
   ```
5. Миграция классов из contest-web (переносит `groups` и `user_groups` с сохранением id):
   ```bash
   php scripts/migrate_groups.php /path/to/contest.db
   ```
   > Выполняется один раз после переноса учеников. Классы — единый источник для contest-web; удаление локальных таблиц классов происходит автоматически при обновлении contest-web.

Схема БД создаётся автоматически при первом запросе (`Database::initialize()`,
маркер миграции — `PRAGMA user_version`). При пустой таблице `users`
создаётся стартовый администратор.

## Деплой

Скрипт `deploy.ps1` деплоит проект на удалённый сервер через SSH и
устанавливает nginx-конфиг (`nginx -t && systemctl reload nginx`).
Деплой пересоздаёт webroot, сохраняя только БД (`data/auth.db*`).

Создайте файл `.env`:
```
DEPLOY_SSH_HOST=your-server
DEPLOY_SSH_PORT=22
DEPLOY_SSH_USER=deployer
DEPLOY_REMOTE_PATH=/var/www/auth.nayanovaacademy.ru
DEPLOY_WEB_USER=www-data
DEPLOY_SSH_KEY=/path/to/key
```

Запуск:
```powershell
.\deploy.ps1
```

Для предпросмотра команд без выполнения:
```powershell
.\deploy.ps1 -DryRun
```

⚠️ Не добавляйте файлы на сервер вручную — при следующем деплое их удалит
(переживёт только БД). Правьте код в репозитории и деплойте.

## API

Все эндпоинты отдают `Content-Type: application/json; charset=utf-8`;
ошибки — `{"error": "..."}` с соответствующим HTTP-кодом.

**Правила доступа:**

- *браузерный запрос* (есть заголовок `Origin`): Origin должен быть в
  `ALLOWED_ORIGINS`; авторизация — по куке `auth_session`;
- *серверный запрос* (без `Origin`): REMOTE_ADDR должен быть в `ALLOWED_IPS`;
  там, где это предусмотрено, допускает явный `user_id`.

### POST /api/login.php

Вход. Тело запроса — JSON:
```json
{ "login": "user", "password": "pass" }
```

Ответ при успехе (200):
```json
{ "success": true, "user": { "id": 1, "login": "user", "display_name": "...", "is_admin": false } }
```

### POST|GET /api/logout.php

Выход, удаление сессии. Опционально `?redirect=https://...` — безопасный
редирект (только `*.nayanovaacademy.ru`).

### GET /api/check.php

Проверка текущей сессии (основной механизм SSO для поддоменов):
```json
{ "authenticated": true, "user": { "id", "login", "display_name", "is_admin" } }
```

### GET /api/user.php

Информация о текущем авторизованном ученике (401, если не вошёл).

### GET /api/groups.php

Все классы: `{ "groups": [{ "id", "name", "description", "user_count" }] }`.

### GET /api/user_groups.php

Принадлежность учеников к классам: `{ "memberships": [{ "user_id", "group_id" }] }`.

### GET /api/public_users.php

Список всех учеников (без паролей) для синхронизации поддоменов:
`{ "users": [{ "id", "login", "display_name", "is_admin", "created_at" }] }`.
Только доверенным хостам.

### GET /api/admin_users.php

Список учеников для администратора (авторизация по куке, требуется `is_admin`).

### GET/POST/PUT /api/progress.php

Прогресс ученика по курсам.

- `GET ?course=X` → `{ "progress": { module: {...} }, "stats": {...} }`
- `GET` (без course) → сводка по всем курсам
- `POST` `{ course, module, completed?, score?, data?, user_id? }` — upsert модуля
- `PUT` `{ course, updates: [...] }` — пакетный upsert (до 200 модулей)

Браузерный запрос — от имени вошедшего ученика; серверный (доверенный IP)
может указывать `user_id` явно.

### POST /api/track.php

Heartbeat учёта активности (используется `tracking-client.js`). Тело — JSON:
`{ "url", "title", "referrer", "duration", "tab" }`, где `duration` — секунды на
текущей странице с прошлого тика (ограничены сверху часом). Авторизация по
куке `auth_session`. Rate-limit: не более 12 запросов в минуту на ученика,
иначе `429 {"error": "Too many requests"}`.
