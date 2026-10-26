<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$user = require_login();
$userId = (int) $user['id'];

$statusFilter = (string) query('status', '');
$search = trim((string) query('search', ''));
$page = max(1, (int) query('page', 1));
$perPage = 10;

$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$where = ['r.user_id = :user_id'];
$params = ['user_id' => $userId];

if ($statusFilter !== '') {
    $where[] = 'r.status = :status';
    $params['status'] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(r.activity_name LIKE :search_activity OR r.reference_no LIKE :search_reference OR r.organization LIKE :search_organization)';
    $params['search_activity'] = '%' . $search . '%';
    $params['search_reference'] = '%' . $search . '%';
    $params['search_organization'] = '%' . $search . '%';
}

$whereSql = implode(' AND ', $where);
$countStatement = db()->prepare("SELECT COUNT(*) FROM reservations r WHERE $whereSql");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$statement = db()->prepare(
    "SELECT r.*,
            (SELECT COUNT(*) FROM reschedule_requests rr WHERE rr.reservation_id = r.id AND rr.status = 'pending') AS pending_changes
     FROM reservations r
     WHERE $whereSql
     ORDER BY r.created_at DESC
     LIMIT $perPage OFFSET $offset"
);
$statement->execute($params);
$reservations = $statement->fetchAll();

$statusCounts = [];
$countStatement = db()->prepare('SELECT status, COUNT(*) AS total FROM reservations WHERE user_id = :user_id GROUP BY status');
$countStatement->execute(['user_id' => $userId]);
foreach ($countStatement->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$allCount = array_sum($statusCounts);

$pageTitle = 'My Reservations';
$activeNav = 'my-reservations';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>My reservations</h1>
        <p>Track every request, review administrator decisions, and manage your bookings.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e(url('reserve.php')) ?>"><?= icon('plus-circle') ?> New reservation</a>
    </div>
</div>

<div class="stats-grid">
    <a class="stat-card stat-teal" href="<?= e(url('my_reservations.php')) ?>">
        <div class="stat-top"><span class="stat-label">All requests</span><span class="stat-icon"><?= icon('clipboard-list') ?></span></div>
        <p class="stat-value"><?= $allCount ?></p>
        <div class="stat-meta"><?= icon('history') ?> Lifetime reservation requests</div>
    </a>
    <a class="stat-card stat-amber" href="<?= e(url('my_reservations.php?status=pending')) ?>">
        <div class="stat-top"><span class="stat-label">Pending</span><span class="stat-icon"><?= icon('clock') ?></span></div>
        <p class="stat-value"><?= (int) ($statusCounts['pending'] ?? 0) ?></p>
        <div class="stat-meta"><?= icon('info') ?> Awaiting gym administrator review</div>
    </a>
    <a class="stat-card stat-green" href="<?= e(url('my_reservations.php?status=approved')) ?>">
        <div class="stat-top"><span class="stat-label">Approved</span><span class="stat-icon"><?= icon('check-circle') ?></span></div>
        <p class="stat-value"><?= (int) ($statusCounts['approved'] ?? 0) ?></p>
        <div class="stat-meta"><?= icon('calendar') ?> Confirmed on the gym calendar</div>
    </a>
    <a class="stat-card stat-red" href="<?= e(url('my_reservations.php?status=rejected')) ?>">
        <div class="stat-top"><span class="stat-label">Rejected / cancelled</span><span class="stat-icon"><?= icon('ban') ?></span></div>
        <p class="stat-value"><?= (int) ($statusCounts['rejected'] ?? 0) + (int) ($statusCounts['cancelled'] ?? 0) ?></p>
        <div class="stat-meta"><?= icon('history') ?> Closed or declined requests</div>
    </a>
</div>

<form method="get" class="filter-bar">
    <div class="search-field">
        <?= icon('search') ?>
        <input class="form-control" type="search" name="search" value="<?= e($search) ?>" placeholder="Search activity, reference, or organization">
    </div>
    <div class="form-group">
        <label for="status-filter">Status</label>
        <select class="form-control" id="status-filter" name="status" style="min-width:150px;">
            <option value="">All statuses</option>
            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= e($status) ?>"<?= option_selected($statusFilter, $status) ?>><?= e(status_label($status)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Apply filters</button>
    <?php if ($statusFilter !== '' || $search !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('my_reservations.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<div class="card">
    <?php if ($reservations === []): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= icon('calendar') ?></div>
            <h3>No reservations found</h3>
            <p><?= $search !== '' || $statusFilter !== '' ? 'No requests match your current filters. Try clearing the search or choosing a different status.' : 'You have not submitted any reservation requests yet. Check the gym calendar and reserve an available time.' ?></p>
            <a class="btn btn-primary" href="<?= e(url('reserve.php')) ?>"><?= icon('plus-circle') ?> Reserve the gym</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Activity / reference</th>
                        <th>Schedule</th>
                        <th>Attendees</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reservations as $reservation): ?>
                    <tr>
                        <td>
                            <span class="primary-cell"><?= e($reservation['activity_name']) ?></span>
                            <span class="subtext"><?= e($reservation['reference_no']) ?> · <?= e($reservation['organization'] ?: event_type_label($reservation['event_type'])) ?></span>
                            <?php if ((int) $reservation['pending_changes'] > 0): ?>
                                <span class="badge badge-pending" style="margin-top:5px;">Schedule change pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="nowrap"><?= e(format_date($reservation['reservation_date'])) ?></span>
                            <span class="subtext"><?= e(format_time_range($reservation['start_time'], $reservation['end_time'])) ?></span>
                        </td>
                        <td><?= number_format((int) $reservation['attendees']) ?></td>
                        <td><?= status_badge($reservation['status']) ?></td>
                        <td>
                            <div class="table-actions">
                                <a class="table-icon-button" href="<?= e(url('reservation_detail.php?id=' . $reservation['id'])) ?>" title="View details"><?= icon('eye') ?></a>
                                <?php if (in_array($reservation['status'], ['pending', 'approved'], true)): ?>
                                    <a class="table-icon-button" href="<?= e(url('reservation_detail.php?id=' . $reservation['id'] . '#manage')) ?>" title="Manage reservation"><?= icon('pencil') ?></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="pagination">
            <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?> request<?= $total === 1 ? '' : 's' ?></span>
            <?php if ($totalPages > 1): ?>
                <div class="pagination-links">
                    <?php if ($page > 1): ?><a class="page-link" href="<?= e(pagination_url($page - 1)) ?>"><?= icon('chevron-right', 'icon-sm') ?></a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="<?= e(pagination_url($i)) ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a class="page-link" href="<?= e(pagination_url($page + 1)) ?>"><?= icon('chevron-right', 'icon-sm') ?></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
