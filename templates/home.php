<div class="auth-card">
    <h1 class="auth-title">Добро пожаловать</h1>
    <p class="auth-subtitle">Вы авторизованы как <strong><?= htmlspecialchars(Auth::getUserName()) ?></strong></p>

    <div class="home-links">
        <a href="https://contest.nayanovaacademy.ru" class="home-link">Контест</a>
        <a href="https://python.nayanovaacademy.ru" class="home-link">Python курс</a>
        <a href="https://j.nayanovaacademy.ru" class="home-link">Журнал</a>
    </div>

    <?php if (Auth::isAdmin()): ?>
    <div class="admin-section">
        <h2>Управление</h2>
        <div class="home-links">
            <a href="<?= BASE_URL ?>/index.php?page=admin-users" class="home-link home-link-admin">Ученики</a>
            <a href="<?= BASE_URL ?>/index.php?page=admin-groups" class="home-link home-link-admin">Классы</a>
            <a href="<?= BASE_URL ?>/index.php?page=admin-change-password" class="home-link home-link-admin">Сбросить пароль</a>
            <a href="<?= BASE_URL ?>/index.php?page=admin-activity" class="home-link home-link-admin">Активность</a>
        </div>
    </div>
    <?php endif; ?>
</div>
