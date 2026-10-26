<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

require_roles(['system_admin']);

$actionLabels = [
    'account_registered' => 'Account registered',
    'approval_blocked_by_conflict' => 'Approval blocked by conflict',
    'block_affected_reservations' => 'Block affected reservations',
    'block_created' => 'Blocked period created',
    'block_deleted' => 'Blocked period deleted',
    'block_updated' => 'Blocked period updated',
    'login' => 'Signed in',
    'login_blocked_inactive' => 'Inactive login blocked',
    'login_failed' => 'Failed sign-in',
    'logout' => 'Signed out',
    'notifications_marked_read' => 'Notifications marked read',
    'password_changed' => 'Password changed',
    'pending_conflict_detected' => 'Pending conflict detected',
    'pending_request_overlap' => 'Overlapping pending request',
    'profile_updated' => 'Profile updated',
    'reminder_scan_run' => 'Reminder scan run',
    'report_exported' => 'Report exported',
    'reschedule_approval_blocked' => 'Reschedule approval blocked',
    'reschedule_approved' => 'Reschedule approved',
    'reschedule_rejected' => 'Reschedule rejected',
    'reschedule_requested' => 'Reschedule requested',
    'reservation_approved' => 'Reservation approved',
    'reservation_cancelled' => 'Reservation cancelled',
    'reservation_checked_in' => 'Reservation check-in',
    'reservation_completed' => 'Reservation completed',
    'reservation_no_show' => 'No-show recorded',
    'reservation_rejected' => 'Reservation rejected',
    'reservation_submitted' => 'Reservation submitted',
    'settings_updated' => 'Settings updated',
    'user_created' => 'User created',
    'user_password_reset' => 'User password reset',
    'user_status_changed' => 'User status changed',
    'user_updated' => 'User updated',
];

$actions = db()->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);
$entityTypes = db()->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL AND entity_type != '' ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);
$actors = db()->query('SELECT id, first_name, last_name, role FROM users ORDER BY first_name ASC, last_name ASC')->fetchAll();

$actionFilter = (string) query('action', '');
if (!in_array($actionFilter, $actions, true)) {
    $actionFilter = '';
}
$entityFilter = (string) query('entity_type', '');
if (!in_array($entityFilter, $entityTypes, true)) {
    $entityFilter = '';
}
$actorFilter = (int) query('user_id', 0);
$dateFrom = trim((string) query('date_from', ''));
$dateTo = trim((string) query('date_to', ''));
$search = trim((string) query('search', ''));
$page = max(1, (int) query('page', 1));
$perPage = 25;

$where = ['1 = 1'];
$params = [];
if ($actionFilter !== '') {
    $where[] = 'a.action = :action';
    $params['action'] = $actionFilter;
}
if ($entityFilter !== '') {
    $where[] = 'a.entity_type = :entity_type';
    $params['entity_type'] = $entityFilter;
}
if ($actorFilter > 0) {
    $where[] = 'a.user_id = :user_id';
    $params['user_id'] = $actorFilter;
}
if ($dateFrom !== '') {
    $where[] = 'a.created_at >= :date_from';
    $params['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== '') {
    $where[] = 'a.created_at <= :date_to';
    $params['date_to'] = $dateTo . ' 23:59:59';
}
if ($search !== '') {
    $where[] = '(a.action LIKE :search_action OR a.details LIKE :search_details OR a.ip_address LIKE :search_ip OR CONCAT(u.first_name, " ", u.last_name) LIKE :search_actor OR a.entity_id LIKE :search_entity)';
    $params['search_action'] = '%' . $search . '%';
    $params['search_details'] = '%' . $search . '%';
    $params['search_ip'] = '%' . $search . '%';
    $params['search_actor'] = '%' . $search . '%';
    $params['search_entity'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$countStatement = db()->prepare("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $whereSql");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logsStatement = db()->prepare(
    "SELECT a.*, u.first_name, u.last_name, u.role, u.email
     FROM audit_logs a
     LEFT JOIN users u ON u.id = a.user_id
     WHERE $whereSql
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT $perPage OFFSET $offset"
);
$logsStatement->execute($params);
$logs = $logsStatement->fetchAll();

$auditStats = db()->query(
    "SELECT COUNT(*) AS total,
            SUM(DATE(created_at) = CURDATE()) AS today,
            COUNT(DISTINCT user_id) AS actors
     FROM audit_logs"
)->fetch();

$hasFilters = $actionFilter !== '' || $entityFilter !== '' || $actorFilter > 0 || $dateFrom !== '' || $dateTo !== '' || $search !== '';
$pageTitle = 'Audit Logs';
$activeNav = 'admin-audit';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Audit logs</h1>
        <p>A chronological record of sign-ins, reservations, account changes, settings updates, and administrative actions.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('admin/users.php')) ?>"><?= icon('users') ?> Users & roles</a>
        <a class="btn btn-secondary" href="<?= e(url('admin/settings.php')) ?>"><?= icon('settings') ?> System settings</a>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card stat-blue"><div class="stat-top"><span class="stat-label">Total events</span><span class="stat-icon"><?= icon('history') ?></span></div><p class="stat-value"><?= number_format((int) ($auditStats['total'] ?? 0)) ?></p><div class="stat-meta"><?= icon('shield') ?> Recorded system activity</div></div>
    <div class="stat-card stat-green"><div class="stat-top"><span class="stat-label">Events today</span><span class="stat-icon"><?= icon('calendar') ?></span></div><p class="stat-value"><?= number_format((int) ($auditStats['today'] ?? 0)) ?></p><div class="stat-meta"><?= icon('clock') ?> Actions since midnight</div></div>
    <div class="stat-card stat-teal"><div class="stat-top"><span class="stat-label">Unique actors</span><span class="stat-icon"><?= icon('users') ?></span></div><p class="stat-value"><?= number_format((int) ($auditStats['actors'] ?? 0)) ?></p><div class="stat-meta"><?= icon('user') ?> Accounts with recorded activity</div></div>
</div>

<form method="get" class="filter-bar">
    <div class="search-field"><?= icon('search') ?><input class="form-control" type="search" name="search" value="<?= e($search) ?>" placeholder="Search action, details, actor, IP"></div>
    <div class="form-group">
        <label for="action-filter">Action</label>
        <select class="form-control" id="action-filter" name="action">
            <option value="">All actions</option>
            <?php foreach ($actions as $action): ?><option value="<?= e($action) ?>"<?= option_selected($actionFilter, $action) ?>><?= e($actionLabels[$action] ?? ucwords(str_replace('_', ' ', $action))) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="actor-filter">Actor</label>
        <select class="form-control" id="actor-filter" name="user_id">
            <option value="0">All users</option>
            <?php foreach ($actors as $actor): ?><option value="<?= (int) $actor['id'] ?>"<?= option_selected($actorFilter, $actor['id']) ?>><?= e(full_name($actor)) ?> (<?= e(role_short_label($actor['role'])) ?>)</option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="entity-filter">Entity</label>
        <select class="form-control" id="entity-filter" name="entity_type">
            <option value="">All entities</option>
            <?php foreach ($entityTypes as $entityType): ?><option value="<?= e($entityType) ?>"<?= option_selected($entityFilter, $entityType) ?>><?= e(ucfirst($entityType)) ?></option><?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="date_from">From</label>
        <input class="form-control" type="date" id="date_from" name="date_from" value="<?= e($dateFrom) ?>">
    </div>
    <div class="form-group">
        <label for="date_to">To</label>
        <input class="form-control" type="date" id="date_to" name="date_to" value="<?= e($dateTo) ?>">
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Apply</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('admin/audit.php')) ?>">Clear</a><?php endif; ?>
</form>

<div class="card">
    <div class="card-header"><div><h2>Activity log</h2><p><?= number_format($total) ?> matching event<?= $total === 1 ? '' : 's' ?>.</p></div></div>
    <?php if ($logs === []): ?>
        <div class="empty-state compact">
            <div class="empty-icon"><?= icon('history') ?></div>
            <h3>No audit events found</h3>
            <p>No activity matches the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Details</th><th>IP address</th></tr></thead>
                <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="nowrap">
                            <span class="primary-cell"><?= e(format_datetime($log['created_at'], 'M j, Y')) ?></span>
                            <span class="subtext"><?= e(format_datetime($log['created_at'], 'g:i:s A')) ?> · <?= e(time_ago($log['created_at'])) ?></span>
                        </td>
                        <td>
                            <?php if ($log['first_name']): ?>
                                <div class="user-cell">
                                    <span class="avatar"><?= e(initials($log['first_name'], $log['last_name'])) ?></span>
                                    <div><strong><?= e(trim($log['first_name'] . ' ' . $log['last_name'])) ?></strong><small><?= e(role_short_label($log['role'])) ?></small></div>
                                </div>
                            <?php else: ?>
                                <span class="text-muted">System</span>
                            <?php endif; ?>
                        </td>
                        <td><span class="primary-cell"><?= e($actionLabels[$log['action']] ?? ucwords(str_replace('_', ' ', $log['action']))) ?></span><span class="subtext"><?= e($log['action']) ?></span></td>
                        <td>
                            <?php if ($log['entity_type']): ?>
                                <span class="badge badge-neutral"><?= e(ucfirst($log['entity_type'])) ?></span>
                                <?php if ($log['entity_id']): ?>
                                    <?php if ($log['entity_type'] === 'reservation'): ?>
                                        <a class="subtext" href="<?= e(url('admin/reservation_view.php?id=' . $log['entity_id'])) ?>">#<?= (int) $log['entity_id'] ?></a>
                                    <?php else: ?>
                                        <span class="subtext">#<?= (int) $log['entity_id'] ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($log['details']): ?>
                                <?php
                                $decoded = json_decode((string) $log['details'], true);
                                $pretty = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $log['details'];
                                ?>
                                <details class="audit-details">
                                    <summary><?= e(truncate_text($pretty, 70)) ?></summary>
                                    <pre><?= e(truncate_text($pretty, 2000)) ?></pre>
                                </details>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap text-muted text-small"><?= e($log['ip_address'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <?php if ($totalPages > 1): ?>
        <div class="card-footer">
            <div class="pagination">
                <span>Page <?= $page ?> of <?= $totalPages ?></span>
                <div class="pagination-links">
                    <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e(pagination_url($page - 1)) ?>"><?= icon('chevron-left') ?> Previous</a><?php endif; ?>
                    <?php if ($page < $totalPages): ?><a class="btn btn-secondary btn-sm" href="<?= e(pagination_url($page + 1)) ?>">Next <?= icon('chevron-right') ?></a><?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
