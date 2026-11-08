<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

$user = require_roles(['gym_admin', 'system_admin']);

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action');
    $reservationId = (int) post('reservation_id');
    $reservation = $reservationId > 0 ? get_reservation($reservationId) : null;

    if (!$reservation) {
        flash('danger', 'The reservation could not be found.');
        redirect('admin/reservations.php');
    }

    if ($action === 'approve') {
        $result = approve_reservation($reservation, $user, 'Approved from the reservation queue.');
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
    }

    redirect('admin/reservations.php');
}

$statusFilter = (string) query('status', '');
$search = trim((string) query('search', ''));
$dateFrom = trim((string) query('date_from', ''));
$dateTo = trim((string) query('date_to', ''));
$page = max(1, (int) query('page', 1));
$perPage = 12;
$allowedStatuses = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];

if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}

$where = ['1 = 1'];
$params = [];

if ($statusFilter !== '') {
    $where[] = 'r.status = :status';
    $params['status'] = $statusFilter;
}

if ($search !== '') {
    $where[] = '(r.activity_name LIKE :search_activity OR r.reference_no LIKE :search_reference OR r.organization LIKE :search_organization OR CONCAT(u.first_name, " ", u.last_name) LIKE :search_requester)';
    $params['search_activity'] = '%' . $search . '%';
    $params['search_reference'] = '%' . $search . '%';
    $params['search_organization'] = '%' . $search . '%';
    $params['search_requester'] = '%' . $search . '%';
}

if ($dateFrom !== '') {
    $where[] = 'r.reservation_date >= :date_from';
    $params['date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'r.reservation_date <= :date_to';
    $params['date_to'] = $dateTo;
}

$whereSql = implode(' AND ', $where);

$countStatement = db()->prepare("SELECT COUNT(*) FROM reservations r JOIN users u ON u.id = r.user_id WHERE $whereSql");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$statement = db()->prepare(
    "SELECT r.*, u.first_name, u.last_name, u.email, u.role, u.organization AS user_organization
     FROM reservations r
     JOIN users u ON u.id = r.user_id
     WHERE $whereSql
     ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
              r.reservation_date ASC, r.start_time ASC
     LIMIT $perPage OFFSET $offset"
);
$statement->execute($params);
$reservations = $statement->fetchAll();

foreach ($reservations as &$reservation) {
    $reservation['conflicts'] = [];
    if ($reservation['status'] === 'pending') {
        $conflicts = check_conflicts(
            $reservation['reservation_date'],
            $reservation['start_time'],
            $reservation['end_time'],
            (int) $reservation['id']
        );
        $reservation['conflicts'] = $conflicts['blocking'];
    }
}
unset($reservation);

$statusCounts = [];
$countStatement = db()->query('SELECT status, COUNT(*) AS total FROM reservations GROUP BY status');
foreach ($countStatement->fetchAll() as $row) {
    $statusCounts[$row['status']] = (int) $row['total'];
}
$totalReservations = array_sum($statusCounts);

$pageTitle = 'Reservation Requests';
$activeNav = 'admin-reservations';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Reservation requests</h1>
        <p>Review requests, resolve competing bookings, and maintain the official gym schedule.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('admin/blocks.php')) ?>"><?= icon('wrench') ?> Manage blocked periods</a>
        <a class="btn btn-primary" href="<?= e(url('calendar.php')) ?>"><?= icon('calendar') ?> Open gym calendar</a>
    </div>
</div>

<div class="stats-grid">
    <a class="stat-card stat-amber" href="<?= e(url('admin/reservations.php?status=pending')) ?>">
        <div class="stat-top"><span class="stat-label">Pending review</span><span class="stat-icon"><?= icon('clock') ?></span></div>
        <p class="stat-value"><?= (int) ($statusCounts['pending'] ?? 0) ?></p>
        <div class="stat-meta"><?= icon('alert-triangle') ?> Requests awaiting a decision</div>
    </a>
    <a class="stat-card stat-green" href="<?= e(url('admin/reservations.php?status=approved')) ?>">
        <div class="stat-top"><span class="stat-label">Approved</span><span class="stat-icon"><?= icon('check-circle') ?></span></div>
        <p class="stat-value"><?= (int) ($statusCounts['approved'] ?? 0) ?></p>
        <div class="stat-meta"><?= icon('shield') ?> Protected on the calendar</div>
    </a>
    <a class="stat-card stat-red" href="<?= e(url('admin/reservations.php?status=rejected')) ?>">
        <div class="stat-top"><span class="stat-label">Rejected</span><span class="stat-icon"><?= icon('ban') ?></span></div>
        <p class="stat-value"><?= (int) ($statusCounts['rejected'] ?? 0) ?></p>
        <div class="stat-meta"><?= icon('history') ?> Declined reservation requests</div>
    </a>
    <a class="stat-card stat-teal" href="<?= e(url('admin/reservations.php')) ?>">
        <div class="stat-top"><span class="stat-label">All requests</span><span class="stat-icon"><?= icon('clipboard-list') ?></span></div>
        <p class="stat-value"><?= $totalReservations ?></p>
        <div class="stat-meta"><?= icon('chart') ?> Complete reservation record</div>
    </a>
</div>

<form method="get" class="filter-bar">
    <div class="search-field">
        <?= icon('search') ?>
        <input class="form-control" type="search" name="search" value="<?= e($search) ?>" placeholder="Search activity, requester, organization, reference">
    </div>
    <div class="form-group">
        <label for="status-filter">Status</label>
        <select class="form-control" id="status-filter" name="status">
            <option value="">All statuses</option>
            <?php foreach ($allowedStatuses as $status): ?>
                <option value="<?= e($status) ?>"<?= option_selected($statusFilter, $status) ?>><?= e(status_label($status)) ?></option>
            <?php endforeach; ?>
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
    <?php if ($search !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
        <a class="btn btn-ghost" href="<?= e(url('admin/reservations.php')) ?>">Clear</a>
    <?php endif; ?>
</form>

<div class="card">
    <?php if ($reservations === []): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= icon('clipboard-list') ?></div>
            <h3>No matching reservations</h3>
            <p>No reservation requests match the selected filters. Try adjusting the status, date range, or search term.</p>
            <a class="btn btn-secondary" href="<?= e(url('admin/reservations.php')) ?>">Reset filters</a>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Requester</th>
                        <th>Activity</th>
                        <th>Schedule</th>
                        <th>Submitted</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($reservations as $reservation): ?>
                    <tr>
                        <td>
                            <div class="user-cell">
                                <span class="avatar"><?= e(initials($reservation['first_name'], $reservation['last_name'])) ?></span>
                                <div>
                                    <strong><?= e(trim($reservation['first_name'] . ' ' . $reservation['last_name'])) ?></strong>
                                    <small><?= e(role_short_label($reservation['role'])) ?> · <?= e($reservation['organization'] ?: $reservation['user_organization'] ?: 'No organization') ?></small>
                                </div>
                            </div>
                        </td>
                        <td>
                            <span class="primary-cell"><?= e($reservation['activity_name']) ?></span>
                            <span class="subtext"><?= e($reservation['reference_no']) ?> · <?= e(event_type_label($reservation['event_type'])) ?></span>
                        </td>
                        <td>
                            <span class="nowrap"><?= e(format_date($reservation['reservation_date'])) ?></span>
                            <span class="subtext"><?= e(format_time_range($reservation['start_time'], $reservation['end_time'])) ?> · <?= number_format((int) $reservation['attendees']) ?> attendees</span>
                        </td>
                        <td class="nowrap text-muted"><?= e(time_ago($reservation['created_at'])) ?></td>
                        <td>
                            <?= status_badge($reservation['status']) ?>
                            <?php if ($reservation['status'] === 'pending' && $reservation['conflicts'] !== []): ?>
                                <span class="badge badge-rejected" style="margin-top:4px;"><?= icon('alert-triangle', 'icon-sm') ?> Conflict</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="table-actions">
                                <?php if ($reservation['status'] === 'pending' && $reservation['conflicts'] === [] && $reservation['reservation_date'] >= date('Y-m-d')): ?>
                                    <form method="post" data-confirm="Approve this reservation and add it to the official gym calendar?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                        <button type="submit" class="table-icon-button" title="Approve request" style="color:var(--success);"><?= icon('check') ?></button>
                                    </form>
                                <?php endif; ?>
                                <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/reservation_view.php?id=' . $reservation['id'])) ?>">Review</a>
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
                    <?php if ($page > 1): ?><a class="page-link" href="<?= e(pagination_url($page - 1)) ?>"><?= icon('chevron-left', 'icon-sm') ?></a><?php endif; ?>
                    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                        <a class="page-link <?= $i === $page ? 'active' : '' ?>" href="<?= e(pagination_url($i)) ?>"><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($page < $totalPages): ?><a class="page-link" href="<?= e(pagination_url($page + 1)) ?>"><?= icon('chevron-right', 'icon-sm') ?></a><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
