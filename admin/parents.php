<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/Parents.php';

$pageTitle = 'Управление родителями';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!validateCsrf()) {
        $error = 'Недействительный CSRF-токен. Обновите страницу и повторите.';
    } elseif ($action === 'create') {
        $result = Parents::create(
            trim($_POST['login'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            trim($_POST['last_name'] ?? ''),
            trim($_POST['first_name'] ?? ''),
            trim($_POST['middle_name'] ?? '')
        );
        if ($result['success']) {
            $message = 'Родитель создан';
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'edit') {
        $result = Parents::update(
            (int) ($_POST['id'] ?? 0),
            trim($_POST['login'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            trim($_POST['last_name'] ?? ''),
            trim($_POST['first_name'] ?? ''),
            trim($_POST['middle_name'] ?? '')
        );
        if ($result['success']) {
            $message = 'Родитель обновлён';
            $editingParent = null;
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'delete') {
        $result = Parents::delete((int) ($_POST['id'] ?? 0));
        if ($result['success']) {
            $message = 'Родитель удалён';
        } else {
            $error = $result['error'];
        }
    } elseif ($action === 'attach') {
        if (Parents::attachChild((int) ($_POST['parent_id'] ?? 0), (int) ($_POST['student_id'] ?? 0))) {
            $message = 'Ребёнок привязан';
        } else {
            $error = 'Не удалось привязать ребёнка (возможно, связь уже есть)';
        }
    } elseif ($action === 'detach') {
        if (Parents::detachChild((int) ($_POST['parent_id'] ?? 0), (int) ($_POST['student_id'] ?? 0))) {
            $message = 'Ребёнок отвязан';
        } else {
            $error = 'Не удалось отвязать ребёнка';
        }
    }
}

$groups = Auth::getAllGroups();
$groupParam = $_GET['group_id'] ?? null;
$selectedGroupId = ($groupParam !== null && $groupParam !== '') ? (int) $groupParam : null;
$parents = Parents::getAll($selectedGroupId);

$editingParent = null;
if (isset($_GET['edit'])) {
    $editingParent = Parents::getById((int) $_GET['edit']);
}
$editingChildren = $editingParent ? Parents::getChildren((int) $editingParent['id']) : [];

// Ученики для привязки: все не-админы с классом в подписи,
// уже привязанные дети исключаются.
$attachedIds = array_map(fn (array $c): int => (int) $c['user_id'], $editingChildren);
$students = [];
foreach (Auth::getAllUsers() as $u) {
    if (!empty($u['is_admin']) || in_array((int) $u['id'], $attachedIds, true)) {
        continue;
    }
    $groupName = '';
    $gid = Auth::getUserGroupId((int) $u['id']);
    if ($gid !== null) {
        $group = Auth::getGroupById($gid);
        $groupName = $group['name'] ?? '';
    }
    $students[] = $u + ['group_name' => $groupName];
}
usort($students, fn (array $a, array $b): int => strcmp($a['display_name'], $b['display_name']));
?>
<div class="auth-card auth-card-wide">
    <h1 class="auth-title">Родители</h1>

    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="admin-section">
        <h2>Класс ребёнка</h2>
        <form method="get" class="auth-form auth-form-compact">
            <input type="hidden" name="page" value="admin-parents">
            <div class="form-row">
                <div class="form-group">
                    <label for="group_id">Показывать родителей детей класса</label>
                    <select name="group_id" id="group_id" onchange="this.form.submit()">
                        <option value="">Все классы</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?= (int) $g['id'] ?>" <?= $selectedGroupId === (int) $g['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($g['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
    </div>

    <div class="admin-section">
        <h2><?= $editingParent ? 'Редактировать родителя' : 'Создать родителя' ?></h2>
        <form method="POST" class="auth-form auth-form-compact">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="<?= $editingParent ? 'edit' : 'create' ?>">
            <?php if ($editingParent): ?>
                <input type="hidden" name="id" value="<?= (int) $editingParent['id'] ?>">
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="last_name">Фамилия</label>
                    <input type="text" id="last_name" name="last_name" required value="<?= htmlspecialchars($editingParent['last_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="first_name">Имя</label>
                    <input type="text" id="first_name" name="first_name" required value="<?= htmlspecialchars($editingParent['first_name'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="middle_name">Отчество</label>
                    <input type="text" id="middle_name" name="middle_name" value="<?= htmlspecialchars($editingParent['middle_name'] ?? '') ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="login">Логин</label>
                    <input type="text" id="login" name="login" required value="<?= htmlspecialchars($editingParent['login'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="password"><?= $editingParent ? 'Новый пароль (пусто — без изменений)' : 'Пароль' ?></label>
                    <input type="password" id="password" name="password" <?= $editingParent ? '' : 'required' ?>>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $editingParent ? 'Сохранить' : 'Создать' ?></button>
                    <?php if ($editingParent): ?>
                        <a href="<?= BASE_URL ?>/index.php?page=admin-parents" class="btn btn-secondary">Отмена</a>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>

    <?php if ($editingParent): ?>
    <div class="admin-section">
        <h2>Дети — <?= htmlspecialchars(trim(($editingParent['last_name'] ?? '') . ' ' . ($editingParent['first_name'] ?? ''))) ?></h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr><th>Ученик</th><th>Логин</th><th>Класс</th><th>Действия</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($editingChildren as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $c['display_name']) ?></td>
                        <td><?= htmlspecialchars((string) $c['login']) ?></td>
                        <td><?= htmlspecialchars((string) ($c['group_name'] ?? 'без класса')) ?></td>
                        <td>
                          <div class="actions">
                            <form method="POST" class="inline-form" onsubmit="return confirm('Отвязать <?= htmlspecialchars((string) $c['display_name']) ?>?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="detach">
                                <input type="hidden" name="parent_id" value="<?= (int) $editingParent['id'] ?>">
                                <input type="hidden" name="student_id" value="<?= (int) $c['user_id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Отвязать</button>
                            </form>
                          </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$editingChildren): ?>
                    <tr><td colspan="4">Детей нет.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <form method="POST" class="auth-form auth-form-compact">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="attach">
            <input type="hidden" name="parent_id" value="<?= (int) $editingParent['id'] ?>">
            <div class="form-row">
                <div class="form-group">
                    <label for="student_id">Привязать ребёнка</label>
                    <select name="student_id" id="student_id" required>
                        <option value="">— выберите ученика —</option>
                        <?php foreach ($students as $s): ?>
                            <option value="<?= (int) $s['id'] ?>">
                                <?= htmlspecialchars($s['display_name']) ?><?= $s['group_name'] !== '' ? ' (' . htmlspecialchars($s['group_name']) . ')' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary" <?= $students ? '' : 'disabled' ?>>Привязать</button>
                </div>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <div class="admin-section">
        <h2>Список родителей (<?= count($parents) ?>)</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ФИО</th>
                        <th>Логин</th>
                        <th>Дети</th>
                        <th>Создан</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($parents as $p): ?>
                    <tr>
                        <td><?= (int) $p['id'] ?></td>
                        <td><?= htmlspecialchars(trim($p['last_name'] . ' ' . $p['first_name'] . ' ' . ($p['middle_name'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars((string) $p['login']) ?></td>
                        <td><?= htmlspecialchars((string) ($p['children'] ?? '')) ?></td>
                        <td><?= htmlspecialchars(displayDateTime($p['created_at'])) ?></td>
                        <td>
                          <div class="actions">
                            <a href="<?= BASE_URL ?>/index.php?page=admin-parents&edit=<?= (int) $p['id'] ?><?= $selectedGroupId !== null ? '&group_id=' . (int) $selectedGroupId : '' ?>" class="btn btn-small btn-secondary">Ред.</a>
                            <form method="POST" class="inline-form" onsubmit="return confirm('Удалить родителя <?= htmlspecialchars((string) $p['login']) ?> вместе с учётной записью?')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn btn-small btn-danger">Удал.</button>
                            </form>
                          </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$parents): ?>
                    <tr><td colspan="6">Родителей нет.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
