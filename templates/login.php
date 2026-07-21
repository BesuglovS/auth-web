<div class="auth-card">
    <h1 class="auth-title">Вход в систему</h1>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="login">Логин</label>
            <input type="text" id="login" name="login" required autocomplete="username" autofocus>
        </div>
        <div class="form-group">
            <label for="password">Пароль</label>
            <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Войти</button>
        </div>
    </form>
</div>
