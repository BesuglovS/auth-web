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
            <div class="password-field">
                <input type="password" id="password" name="password" required autocomplete="current-password">
                <button type="button" class="password-toggle" data-password-toggle aria-label="Показать пароль" aria-pressed="false" title="Показать пароль">
                    <svg class="icon-eye" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
                        <circle cx="12" cy="12" r="3"/>
                    </svg>
                    <svg class="icon-eye-off" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                        <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/>
                        <line x1="1" y1="1" x2="23" y2="23"/>
                    </svg>
                </button>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Войти</button>
        </div>
    </form>
</div>
