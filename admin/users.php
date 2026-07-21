<?php
$pageTitle = 'Управление пользователями';
$db = Database::getInstance();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $login = trim($_POST['login'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

        if ($login && $displayName && $password) {
            $result = Auth::createUser($login, $displayName, $password, (bool) $isAdmin);
            if ($result['success']) {
                $message = 'Пользователь создан';
            } else {
                $error = $result['error'];
            }
        } else {
            $error = 'Заполните все поля';
        }
    }

    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $login = trim($_POST['login'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $password = $_POST['password'] ?? '';
        $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

        if ($id && $login && $displayName) {
            $result = Auth::updateUser($id, $login, $displayName, (bool) $isAdmin, $password ?: null);
            if ($result['success']) {
                $message = 'Пользователь обновлён';
            } else {
                $error = $result['error'];
            }
        } else {
            $error = 'Заполните обязательные поля';
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id == 1) {
            $error = 'Нельзя удалить первого администратора';
        } elseif (Auth::deleteUser($id)) {
            $message = 'Пользователь удалён';
        } else {
            $error = 'Не удалось удалить пользователя';
        }
    }

    if ($action === 'bulk_import') {
        $bulkResults = ['success' => [], 'failed' => []];
        $rawText = '';

        if (!empty($_FILES['bulk_file']['tmp_name'])) {
            $rawText = file_get_contents($_FILES['bulk_file']['tmp_name']);
        } elseif (!empty($_POST['bulk_text'])) {
            $rawText = trim($_POST['bulk_text']);
        }

        if ($rawText !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $rawText);
            foreach ($lines as $lineNum => $line) {
                $line = trim($line);
                if ($line === '') continue;

                $parts = preg_split('/\t+/', $line);
                if (count($parts) < 3) {
                    $parts = preg_split('/\s*,\s*/', $line);
                }
                if (count($parts) < 3) {
                    $parts = preg_split('/\s{2,}/', $line);
                }
                if (count($parts) < 3) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 3) {
                        $displayName = implode(' ', array_slice($parts, 0, -2));
                        $login = $parts[count($parts) - 2];
                        $password = $parts[count($parts) - 1];
                    } else {
                        $bulkResults['failed'][] = "Строка " . ($lineNum + 1) . ": недостаточно полей";
                        continue;
                    }
                } else {
                    $displayName = trim($parts[0]);
                    $login = trim($parts[1]);
                    $password = trim($parts[2]);
                }

                if ($displayName === '' || $login === '' || $password === '') {
                    $bulkResults['failed'][] = "Строка " . ($lineNum + 1) . ": пустое поле";
                    continue;
                }

                $result = Auth::createUser($login, $displayName, $password);
                if ($result['success']) {
                    $bulkResults['success'][] = $login;
                } else {
                    $bulkResults['failed'][] = "Строка " . ($lineNum + 1) . ": " . $result['error'];
                }
            }

            $imported = count($bulkResults['success']);
            $failed = count($bulkResults['failed']);
            $message = "Импорт завершён: {$imported} создано, {$failed} ошибок";
        } else {
            $error = 'Нет данных для импорта';
        }
    }
}

$users = Auth::getAllUsers();
$editingUser = null;
if (isset($_GET['edit'])) {
    $editingUser = Auth::getUserById((int) $_GET['edit']);
}
?>
<div class="auth-card auth-card-wide">
    <h1 class="auth-title">Пользователи</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="admin-section">
        <h2><?= $editingUser ? 'Редактировать пользователя' : 'Создать пользователя' ?></h2>
        <form method="POST" class="auth-form auth-form-compact">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editingUser ? 'edit' : 'create' ?>">
            <?php if ($editingUser): ?>
                <input type="hidden" name="id" value="<?= $editingUser['id'] ?>">
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="login">Логин</label>
                    <input type="text" id="login" name="login" required value="<?= htmlspecialchars($editingUser['login'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="display_name">Отображаемое имя</label>
                    <input type="text" id="display_name" name="display_name" required value="<?= htmlspecialchars($editingUser['display_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password"><?= $editingUser ? 'Новый пароль (пусто — без изменений)' : 'Пароль' ?></label>
                    <input type="password" id="password" name="password" <?= $editingUser ? '' : 'required' ?>>
                </div>
                <div class="form-group form-group-checkbox">
                    <label>
                        <input type="checkbox" name="is_admin" <?= ($editingUser && $editingUser['is_admin']) ? 'checked' : '' ?>>
                        Админ
                    </label>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $editingUser ? 'Сохранить' : 'Создать' ?></button>
                    <?php if ($editingUser): ?>
                        <a href="<?= BASE_URL ?>/index.php?page=admin-users" class="btn btn-secondary">Отмена</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <div class="admin-section">
        <h2>Массовый импорт</h2>
        <form method="POST" enctype="multipart/form-data" class="auth-form auth-form-compact">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_import">
            <div class="form-group">
                <label for="bulk_text">Текст (Имя, Логин, Пароль — по строкам)</label>
                <textarea id="bulk_text" name="bulk_text" rows="6" placeholder="Иван Иванов, ivanov, password123&#10;Петр Петров, petrov, password456"></textarea>
            </div>
            <div class="form-group">
                <label for="bulk_file">Или файл (.txt, .csv)</label>
                <input type="file" id="bulk_file" name="bulk_file" accept=".txt,.csv">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Импортировать</button>
            </div>
        </form>
    </div>

    <div class="admin-section">
        <h2>Список пользователей (<?= count($users) ?>)</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Логин</th>
                        <th>Имя</th>
                        <th>Роль</th>
                        <th>Создан</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= $u['id'] ?></td>
                        <td><?= htmlspecialchars($u['login']) ?></td>
                        <td><?= htmlspecialchars($u['display_name']) ?></td>
                        <td><?= $u['is_admin'] ? 'Админ' : 'Пользователь' ?></td>
                        <td><?= htmlspecialchars($u['created_at']) ?></td>
                        <td class="actions">
                            <a href="<?= BASE_URL ?>/index.php?page=admin-users&edit=<?= $u['id'] ?>" class="btn btn-small btn-secondary">Ред.</a>
                            <a href="<?= BASE_URL ?>/index.php?page=admin-change-password&user_id=<?= $u['id'] ?>" class="btn btn-small btn-secondary">Пароль</a>
                            <?php if ($u['id'] != 1): ?>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Удалить пользователя <?= htmlspecialchars($u['login']) ?>?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Удал.</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
