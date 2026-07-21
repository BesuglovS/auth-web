<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#6366f1">
    <title><?= htmlspecialchars($pageTitle ?? 'Авторизация') ?> — <?= SITE_NAME ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
    <div class="auth-wrapper">
        <header class="auth-header">
            <a href="<?= BASE_URL ?>/index.php" class="auth-logo"><?= SITE_NAME ?></a>
            <?php if (Auth::isLoggedIn()): ?>
            <nav class="auth-nav">
                <a href="<?= BASE_URL ?>/index.php" class="nav-link">Главная</a>
                <?php if (Auth::isAdmin()): ?>
                <a href="<?= BASE_URL ?>/index.php?page=admin-users" class="nav-link">Пользователи</a>
                <a href="<?= BASE_URL ?>/index.php?page=admin-change-password" class="nav-link">Сброс пароля</a>
                <?php endif; ?>
                <span class="nav-user"><?= htmlspecialchars(Auth::getUserName()) ?></span>
                <a href="<?= BASE_URL ?>/index.php?page=logout" class="nav-link nav-logout">Выйти</a>
            </nav>
            <?php endif; ?>
        </header>
        <main class="auth-main">
            <?= $content ?? '' ?>
        </main>
        <footer class="auth-footer">
            <p>&copy; <?= date('Y') ?> <?= SITE_NAME ?></p>
        </footer>
    </div>
    <script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
