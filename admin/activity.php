<?php
$pageTitle = 'Активность учеников';
$db = Database::getInstance();

$users = Auth::getAllUsers();

$selectedUserId = isset($_GET['user_id']) && $_GET['user_id'] !== '' ? (int) $_GET['user_id'] : null;
$dateFrom = isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']) ? $_GET['from'] : '';
$dateTo = isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']) ? $_GET['to'] : '';
if ($dateFrom === '') $dateFrom = date('Y-m-d', strtotime('-30 days'));
if ($dateTo === '') $dateTo = date('Y-m-d');

$userCond = '';
$userParams = [];
if ($selectedUserId !== null) {
    $userCond = ' AND a.user_id = ?';
    $userParams[] = $selectedUserId;
}

$tzUtc = new DateTimeZone('UTC');
$dayFrom = (new DateTimeImmutable($dateFrom . ' 00:00:00', localTimezone()))->setTimezone($tzUtc)->format('Y-m-d H:i:s');
$dayTo = (new DateTimeImmutable($dateTo . ' 23:59:59', localTimezone()))->setTimezone($tzUtc)->format('Y-m-d H:i:s');

function fmtDuration(int $seconds): string {
    if ($seconds < 0) $seconds = 0;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    if ($h > 0) return "{$h} ч {$m} мин";
    if ($m > 0) return "{$m} мин {$s} с";
    return "{$s} с";
}

// Правильная форма множественного числа для русского языка:
// pluralRu(3, 'сессия', 'сессии', 'сессий') → 'сессии'
function pluralRu(int $n, string $one, string $few, string $many): string {
    $n10 = $n % 10;
    $n100 = $n % 100;
    if ($n10 == 1 && $n100 != 11) return $one;
    if ($n10 >= 2 && $n10 <= 4 && ($n100 < 12 || $n100 > 14)) return $few;
    return $many;
}

// ─── Сводка ───
$stmt = $db->prepare(
    "SELECT COUNT(*) AS sessions,
            COALESCE(SUM(
                CASE
                    WHEN a.logout_at IS NOT NULL THEN strftime('%s', a.logout_at) - strftime('%s', a.login_at)
                    ELSE COALESCE(strftime('%s', a.last_seen_at), strftime('%s', 'now')) - strftime('%s', a.login_at)
                END
            ), 0) AS session_seconds,
            COUNT(DISTINCT a.user_id) AS users
     FROM activity_sessions a
     WHERE a.login_at BETWEEN ? AND ?" . $userCond
);
$stmt->execute(array_merge([$dayFrom, $dayTo], $userParams));
$summary = $stmt->fetch();

$stmt = $db->prepare(
    "SELECT COALESCE(SUM(pv.duration_seconds), 0) AS active_seconds
     FROM page_views pv
     WHERE pv.started_at BETWEEN ? AND ?" . str_replace('a.', '', $userCond)
);
$stmt->execute(array_merge([$dayFrom, $dayTo], $userParams));
$activeSeconds = (int) $stmt->fetch()['active_seconds'];

// ─── Рабочие сессии ───
$stmt = $db->prepare(
    "SELECT a.*, u.login, u.display_name
     FROM activity_sessions a
     INNER JOIN users u ON u.id = a.user_id
     WHERE a.login_at BETWEEN ? AND ?" . $userCond . "
     ORDER BY a.login_at DESC"
);
$stmt->execute(array_merge([$dayFrom, $dayTo], $userParams));
$sessions = $stmt->fetchAll();

// ─── Просмотренные страницы (с пагинацией) ───
$perPage = 200;
$page = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
$offset = ($page - 1) * $perPage;

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM page_views pv
     WHERE pv.started_at BETWEEN ? AND ?" . str_replace('a.', '', $userCond)
);
$stmt->execute(array_merge([$dayFrom, $dayTo], $userParams));
$totalViews = (int) $stmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalViews / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

$stmt = $db->prepare(
    "SELECT pv.*, u.login, u.display_name
     FROM page_views pv
     INNER JOIN users u ON u.id = pv.user_id
     WHERE pv.started_at BETWEEN ? AND ?" . str_replace('a.', '', $userCond) . "
     ORDER BY pv.started_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute(array_merge([$dayFrom, $dayTo], $userParams));
$views = $stmt->fetchAll();

function filterUrl(array $extra): string {
    $params = array_merge([
        'page' => 'admin-activity',
        'user_id' => isset($_GET['user_id']) ? $_GET['user_id'] : '',
        'from' => $_GET['from'] ?? '',
        'to' => $_GET['to'] ?? '',
        'p' => $_GET['p'] ?? '',
    ], $extra);
    foreach ($params as $k => $v) {
        if ($v === '') unset($params[$k]);
    }
    return BASE_URL . '/index.php?' . http_build_query($params);
}
?>
<div class="auth-card auth-card-extra-wide">
    <h1 class="auth-title">Активность учеников</h1>
    <p class="auth-subtitle">Входы/выходы и просмотренные страницы по всей экосистеме (contest, python, j, ai, oge, office, inf, vpr, na). Время указано в UTC+4 (Самара).</p>

    <form method="get" class="auth-form auth-form-compact">
        <input type="hidden" name="page" value="admin-activity">
        <div class="form-row">
            <div class="form-group">
                <label for="user_id">Ученик</label>
                <select name="user_id" id="user_id">
                    <option value="">Все ученики</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= (int) $u['id'] ?>" <?= $selectedUserId === (int) $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['login']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="from">С</label>
                <input type="date" id="from" name="from" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="form-group">
                <label for="to">По</label>
                <input type="date" id="to" name="to" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Показать</button>
            </div>
        </div>
    </form>

    <div class="admin-section">
        <h2>Сводка за период</h2>
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-value"><?= (int) $summary['sessions'] ?></div>
                <div class="stat-label"><?= pluralRu((int) $summary['sessions'], 'рабочая сессия', 'рабочие сессии', 'рабочих сессий') ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= (int) $summary['users'] ?></div>
                <div class="stat-label"><?= pluralRu((int) $summary['users'], 'ученик', 'ученика', 'учеников') ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= fmtDuration((int) $summary['session_seconds']) ?></div>
                <div class="stat-label">время в системе</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= fmtDuration($activeSeconds) ?></div>
                <div class="stat-label">активное время на страницах</div>
            </div>
        </div>
    </div>

    <div class="admin-section">
        <h2>Рабочие сессии (<?= count($sessions) ?>)</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ученик</th>
                        <th>Вход</th>
                        <th>Выход</th>
                        <th>Длительность</th>
                        <th>IP</th>
                        <th>Браузер</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sessions): ?>
                        <?php foreach ($sessions as $s): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($s['display_name']) ?><br>
                                <small class="muted"><?= htmlspecialchars($s['login']) ?></small>
                            </td>
                            <td><?= htmlspecialchars(displayDateTime($s['login_at'])) ?></td>
                            <td>
                                <?= $s['logout_at'] !== null ? htmlspecialchars(displayDateTime($s['logout_at'])) : '<span class="muted">открыта</span>' ?>
                                <?php if ($s['last_seen_at'] !== null): ?><br><small class="muted">посл. активность: <?= htmlspecialchars(displayDateTime($s['last_seen_at'])) ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $end = $s['logout_at'] ?? $s['last_seen_at'] ?? date('Y-m-d H:i:s');
                                $dur = strtotime($end) - strtotime($s['login_at']);
                                echo fmtDuration($dur);
                                ?>
                            </td>
                            <td><?= htmlspecialchars($s['ip_address']) ?></td>
                            <td class="muted" title="<?= htmlspecialchars($s['user_agent']) ?>"><?= htmlspecialchars(mb_substr($s['user_agent'], 0, 40)) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="muted">За период нет данных о сессиях</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="admin-section">
        <h2>Просмотренные страницы (<?= $totalViews ?>)</h2>
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ученик</th>
                        <th>Страница</th>
                        <th>Начало просмотра</th>
                        <th>Длительность</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($views): ?>
                        <?php foreach ($views as $v): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($v['display_name']) ?><br>
                                <small class="muted"><?= htmlspecialchars($v['login']) ?></small>
                            </td>
                            <td>
                                <?php if (preg_match('#^https?://#', $v['page_url'])): ?>
                                    <a href="<?= htmlspecialchars($v['page_url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($v['page_url']) ?></a>
                                <?php else: ?>
                                    <?= htmlspecialchars($v['page_url']) ?>
                                <?php endif; ?>
                                <?php if ($v['page_title'] !== ''): ?><br><small class="muted"><?= htmlspecialchars($v['page_title']) ?></small><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(displayDateTime($v['started_at'])) ?></td>
                            <td><?= fmtDuration((int) $v['duration_seconds']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="4" class="muted">За период нет данных о просмотрах</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a class="btn btn-small btn-secondary" href="<?= filterUrl(['p' => $page - 1]) ?>">← Назад</a>
            <?php endif; ?>
            <span class="muted">Страница <?= $page ?> из <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?>
                <a class="btn btn-small btn-secondary" href="<?= filterUrl(['p' => $page + 1]) ?>">Вперёд →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>