<div class="auth-card">
    <h1 class="auth-title">Добро пожаловать</h1>
    <p class="auth-subtitle">Вы авторизованы как <strong><?= htmlspecialchars(Auth::getUserName()) ?></strong></p>

    <div class="home-links">
        <a href="https://contest.nayanovaacademy.ru" class="home-link">Контест</a>
        <a href="https://python.nayanovaacademy.ru" class="home-link">Python курс</a>
    </div>

    <?php if (Auth::isAdmin()): ?>
    <div class="admin-section">
        <h2>Управление</h2>
        <div class="home-links">
            <a href="<?= BASE_URL ?>/index.php?page=admin-users" class="home-link home-link-admin">Пользователи</a>
            <a href="<?= BASE_URL ?>/index.php?page=admin-change-password" class="home-link home-link-admin">Сбросить пароль</a>
        </div>
    </div>
    <?php endif; ?>
</div>
