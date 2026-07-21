<?php
$pageTitle = 'Сброс пароля';
$message = '';
$error = '';
$users = Auth::getAllUsers();
$selectedUserId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrf()) {
        $error = 'Недействительный CSRF-токен';
    } else {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (!$userId) {
            $error = 'Выберите пользователя';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Пароли не совпадают';
        } else {
            $result = Auth::resetPassword($userId, $newPassword);
            if ($result['success']) {
                $message = 'Пароль успешно сброшен';
            } else {
                $error = $result['error'];
            }
        }
        $selectedUserId = $userId;
    }
}
?>
<div class="auth-card">
    <h1 class="auth-title">Сброс пароля</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
        <?= csrfField() ?>
        <div class="form-group">
            <label for="user_id">Пользователь</label>
            <select id="user_id" name="user_id" required>
                <option value="">— Выберите —</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= $selectedUserId == $u['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['login']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="new_password">Новый пароль</label>
            <input type="password" id="new_password" name="new_password" required minlength="6">
        </div>
        <div class="form-group">
            <label for="confirm_password">Подтвердите пароль</label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="6">
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Сбросить пароль</button>
            <a href="<?= BASE_URL ?>/index.php?page=admin-users" class="btn btn-secondary">Назад</a>
        </div>
    </form>
</div>
