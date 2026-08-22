<?php
$pageTitle = 'Управление классами';
$db = Database::getInstance();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrf()) {
        $error = 'Недействительный CSRF-токен. Обновите страницу и повторите.';
    } elseif ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($name === '') {
            $error = 'Введите название класса';
        } else {
            $result = Auth::createGroup($name, $description);
            if ($result['success']) {
                $message = 'Класс создан';
            } else {
                $error = $result['error'];
            }
        }
    } elseif ($action === 'update') {
        $id = (int) ($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($name === '') {
            $error = 'Введите название класса';
        } else {
            $result = Auth::updateGroup($id, $name, $description);
            if ($result['success']) {
                $message = 'Класс обновлён';
            } else {
                $error = $result['error'];
            }
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (Auth::deleteGroup($id)) {
            $message = 'Класс удалён';
        } else {
            $error = 'Не удалось удалить класс';
        }
    } elseif ($action === 'add_user') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        if (Auth::addUserToGroup($userId, $groupId)) {
            $message = 'Ученик добавлен в класс';
        } else {
            $error = 'Ученик не добавлен';
        }
    } elseif ($action === 'remove_user') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $userId = (int) ($_POST['user_id'] ?? 0);
        Auth::removeUserFromGroup($userId, $groupId);
        $message = 'Ученик удалён из класса';
    } elseif ($action === 'bulk_add_users') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $rawText = trim($_POST['bulk_logins'] ?? '');
        if ($rawText === '') {
            $error = 'Пустой список логинов';
        } else {
            $bulkResult = Auth::bulkAddUsersToGroup($groupId, $rawText);
            $message = 'В класс добавлено учеников: ' . count($bulkResult['success']);
            $bulkErrors = implode('<br>', $bulkResult['failed']);
        }
    }
}

$groups = Auth::getAllGroups();
$allUsers = Auth::getAllUsers();

$editGroup = null;
$groupUsers = [];
if (isset($_GET['edit'])) {
    $editGroup = Auth::getGroupById((int) $_GET['edit']);
    if ($editGroup) {
        $groupUsers = Auth::getGroupUsers((int) $editGroup['id']);
    }
}
?>
<div class="auth-card auth-card-wide">
    <h1 class="auth-title">Классы</h1>
    <p class="auth-subtitle">Классы — единый источник для всех сервисов. Здесь они хранятся и редактируются.</p>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="admin-section">
        <h2><?= $editGroup ? 'Редактировать класс' : 'Создать класс' ?></h2>
        <form method="POST" class="auth-form auth-form-compact">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editGroup ? 'update' : 'create' ?>">
            <?php if ($editGroup): ?>
                <input type="hidden" name="id" value="<?= $editGroup['id'] ?>">
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Название</label>
                    <input type="text" id="name" name="name" required value="<?= htmlspecialchars($editGroup['name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="description">Описание</label>
                    <input type="text" id="description" name="description" value="<?= htmlspecialchars($editGroup['description'] ?? '') ?>">
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $editGroup ? 'Сохранить' : 'Создать' ?></button>
                    <?php if ($editGroup): ?>
                        <a href="<?= BASE_URL ?>/index.php?page=admin-groups" class="btn btn-secondary">Отмена</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php if ($editGroup): ?>
    <div class="admin-section">
        <h2>Ученики в классе «<?= htmlspecialchars($editGroup['name']) ?>» (<?= count($groupUsers) ?>)</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Логин</th>
                        <th>Имя</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($groupUsers): ?>
                        <?php foreach ($groupUsers as $gu): ?>
                        <tr>
                            <td><?= htmlspecialchars($gu['login']) ?></td>
                            <td><?= htmlspecialchars($gu['display_name']) ?></td>
                            <td class="actions">
                                <form method="POST" class="inline-form" onsubmit="return confirm('Убрать ученика <?= htmlspecialchars($gu['display_name']) ?> из класса?')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="remove_user">
                                    <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
                                    <input type="hidden" name="user_id" value="<?= $gu['id'] ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Убрать</button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="3" style="color: var(--text-muted);">В классе нет учеников</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <h2 style="margin-top: 24px;">Добавить ученика</h2>
        <form method="POST" class="auth-form auth-form-compact">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_user">
            <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="user_id">Ученик</label>
                    <select name="user_id" id="user_id">
                        <?php
                        $groupUserIds = array_map(fn($gu) => (int) $gu['id'], $groupUsers);
                        foreach ($allUsers as $u):
                            if (in_array((int) $u['id'], $groupUserIds)) continue;
                        ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['login']) ?> (<?= htmlspecialchars($u['display_name']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </div>
            </div>
        </form>

        <h2 style="margin-top: 24px;">Добавить учеников списком</h2>
        <p style="color: var(--text-muted); font-size: 13px;">По одному логину на строку</p>
        <?php if (isset($bulkErrors)): ?>
            <div class="alert alert-error" style="margin-top: 8px;">Ошибки:<br><?= $bulkErrors ?></div>
        <?php endif; ?>
        <form method="POST" class="auth-form auth-form-compact">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="bulk_add_users">
            <input type="hidden" name="group_id" value="<?= $editGroup['id'] ?>">
            <div class="form-group">
                <label for="bulk_logins">Логины</label>
                <textarea id="bulk_logins" name="bulk_logins" rows="6" style="font-family: monospace;" placeholder="ivanov&#10;petrov&#10;sidorov"></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Добавить списком</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="admin-section">
        <h2>Список классов (<?= count($groups) ?>)</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Описание</th>
                        <th>Учеников</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><?= $g['id'] ?></td>
                        <td><?= htmlspecialchars($g['name']) ?></td>
                        <td><?= htmlspecialchars($g['description']) ?></td>
                        <td><?= (int) $g['user_count'] ?></td>
                        <td class="actions">
                            <a href="<?= BASE_URL ?>/index.php?page=admin-groups&edit=<?= $g['id'] ?>" class="btn btn-small btn-secondary">Ред.</a>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Удалить класс <?= htmlspecialchars($g['name']) ?>?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $g['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Удал.</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>