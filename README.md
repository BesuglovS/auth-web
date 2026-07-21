# Auth Web

Централизованная система авторизации для проектов портала **nayanovaacademy.ru**.

## Назначение

Предоставляет единый сервис аутентификации для подключённых подпроектов:
- **contest.nayanovaacademy.ru** — контест-система
- **python.nayanovaacademy.ru** — Python-курс

После входа на auth.nayanovaacademy.ru пользователь получает общий сессионный cookie (`.nayanovaacademy.ru`), который автоматически распознаётся всеми подключёнными проектами.

## Возможности

- Вход / выход по логину и паролю
- Сессии с настраиваемым времени жизни (по умолчанию 30 дней)
- CSRF-защита форм
- Админ-панель:
  - Управление пользователями (создание, редактирование, удаление)
  - Сброс пароля любому пользователю
  - Массовый импорт пользователей из текста или CSV/TXT-файла
- REST API (`/api/`) для программного входа и проверки сессии
- CORS для разрешённых поддоменов

## Стек

- **PHP 8+** ( vanilla, без фреймворков)
- **SQLite** (PDO) — файл базы в `data/auth.db`
- **HTML/CSS/JS** — минималистичный фронтенд

## Структура проекта

```
auth-web/
├── index.php              # Точка входа
├── config.php             # Конфигурация (БД, сессии, CORS)
├── .htaccess              # URL-rewriting (Apache)
├── includes/
│   ├── Auth.php           # Логика аутентификации и управления пользователями
│   ├── Database.php       # Синглтон-обёртка над PDO
│   └── Router.php         # Простой роутер по страницам
├── templates/             # Шаблоны страниц (login, home, layout)
├── admin/                 # Админ-панель (пользователи, сброс пароля)
├── api/                   # REST API (login, logout, check, user)
├── assets/                # CSS, JS
├── scripts/               # Утилиты (миграция пользователей)
├── data/                  # Файл SQLite (не в git)
├── deploy.ps1             # Скрипт деплоя на сервер по SSH
└── .env                   # Переменные для деплоя (не в git)
```

## Установка

1. Убедитесь, что PHP 8+ установлен и работает с расширением `pdo_sqlite`.
2. Скопируйте проект на веб-сервер (Apache с `mod_rewrite`).
3. Убедитесь, что папка `data/` доступна для записи веб-сервером.
4. Первоначальная миграция пользователей (если нужно):
   ```bash
   php scripts/migrate_users.php
   ```

## Деплой

Скрипт `deploy.ps1` деплоит проект на удалённый сервер через SSH.

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

## API

### POST /api/login

Вход. Тело запроса — JSON:
```json
{ "login": "user", "password": "pass" }
```

Ответ при успехе (200):
```json
{ "success": true, "user": { "id": 1, "login": "user", "display_name": "...", "is_admin": false } }
```

### POST /api/logout

Выход. Удаляет сессию.

### GET /api/check

Проверка текущей сессии. Возвращает данные пользователя или ошибку 401.

### GET /api/user

Информация о текущем авторизованном пользователе.

Все API-эндпоинты поддерживают CORS для разрешённых origin-доменов.
