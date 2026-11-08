<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/reports.php';

require_roles(['gym_admin', 'system_admin']);

$filters = report_filters();
$reservations = report_reservations($filters);
$stats = report_stats($reservations);
$trend = report_monthly_trend();
$exportUrl = url('admin/export.php?' . http_build_query($filters));
$maxStatus = max(1, ...array_values($stats['statuses']));
$maxType = max(1, ...array_values($stats['types']));
$maxPeak = $stats['peak_hours'] === [] ? 1 : max(1, ...array_values($stats['peak_hours']));
$maxTrend = max(1, ...array_map(static fn (array $row) => $row['booked'], $trend));
$displayReservations = array_slice($reservations, 0, 75);
$hasFilters = $filters['status'] !== '' || $filters['event_type'] !== '' || $filters['search'] !== '';

$pageTitle = 'Reports & Analytics';
$activeNav = 'admin-reports';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Reports & analytics</h1>
        <p>Understand reservation demand, gym usage, and attendance for any period.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('admin/blocks.php')) ?>"><?= icon('wrench') ?> Blocked periods</a>
        <a class="btn btn-primary" href="<?= e($exportUrl) ?>"><?= icon('download') ?> Export CSV</a>
    </div>
</div>

<form method="get" class="filter-bar">
    <div class="search-field">
        <?= icon('search') ?>
        <input class="form-control" type="search" name="search" value="<?= e($filters['search']) ?>" placeholder="Search activity, requester, reference">
    </div>
    <div class="form-group">
        <label for="from">From</label>
        <input class="form-control" type="date" id="from" name="from" value="<?= e($filters['from']) ?>">
    </div>
    <div class="form-group">
        <label for="to">To</label>
        <input class="form-control" type="date" id="to" name="to" value="<?= e($filters['to']) ?>">
    </div>
    <div class="form-group">
        <label for="status">Status</label>
        <select class="form-control" id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending', 'approved', 'rejected', 'cancelled', 'completed'] as $status): ?>
                <option value="<?= e($status) ?>"<?= option_selected($filters['status'], $status) ?>><?= e(status_label($status)) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="event_type">Activity type</label>
        <select class="form-control" id="event_type" name="event_type">
            <option value="">All types</option>
            <?php foreach (event_type_label_map() as $value => $label): ?>
                <option value="<?= e($value) ?>"<?= option_selected($filters['event_type'], $value) ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Apply</button>
    <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('admin/reports.php')) ?>">Clear</a><?php endif; ?>
</form>

<p class="text-small text-muted mb-2">Showing reservations from <strong><?= e(format_date($filters['from'])) ?></strong> to <strong><?= e(format_date($filters['to'])) ?></strong>.</p>

<div class="stats-grid">
    <div class="stat-card stat-blue">
        <div class="stat-top"><span class="stat-label">Requests in period</span><span class="stat-icon"><?= icon('clipboard-list') ?></span></div>
        <p class="stat-value"><?= number_format($stats['total']) ?></p>
        <div class="stat-meta"><?= icon('users') ?> <?= number_format($stats['unique_requesters']) ?> unique requester<?= $stats['unique_requesters'] === 1 ? '' : 's' ?></div>
    </div>
    <div class="stat-card stat-green">
        <div class="stat-top"><span class="stat-label">Confirmed bookings</span><span class="stat-icon"><?= icon('check-circle') ?></span></div>
        <p class="stat-value"><?= number_format($stats['statuses']['approved'] + $stats['statuses']['completed']) ?></p>
        <div class="stat-meta"><?= icon('shield') ?> Approved and completed</div>
    </div>
    <div class="stat-card stat-teal">
        <div class="stat-top"><span class="stat-label">Booked gym hours</span><span class="stat-icon"><?= icon('clock') ?></span></div>
        <p class="stat-value"><?= number_format($stats['booked_hours'], 1) ?></p>
        <div class="stat-meta"><?= icon('chart') ?> Total approved usage time</div>
    </div>
    <div class="stat-card stat-red">
        <div class="stat-top"><span class="stat-label">Attendance issues</span><span class="stat-icon"><?= icon('alert-triangle') ?></span></div>
        <p class="stat-value"><?= number_format($stats['no_shows']) ?></p>
        <div class="stat-meta"><?= icon('user-check') ?> <?= number_format($stats['checked_in']) ?> check-in<?= $stats['checked_in'] === 1 ? '' : 's' ?> recorded</div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <div><h2>Status breakdown</h2><p>Decision outcomes for the filtered period.</p></div>
        </div>
        <div class="card-body">
            <?php if ($stats['total'] === 0): ?>
                <p class="text-muted">No reservations match these filters.</p>
            <?php else: ?>
                <div class="bar-chart">
                    <?php foreach ($stats['statuses'] as $status => $count): ?>
                        <div class="bar-row">
                            <span class="bar-label"><?= e(status_label($status)) ?></span>
                            <div class="bar-track"><div class="bar-fill <?= in_array($status, ['rejected', 'cancelled'], true) ? 'alt' : '' ?>" style="width:<?= round(($count / $maxStatus) * 100) ?>%"></div></div>
                            <span class="bar-value"><?= number_format($count) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div><h2>Activity types</h2><p>Which kinds of activities drive gym demand.</p></div>
        </div>
        <div class="card-body">
            <?php if ($stats['total'] === 0): ?>
                <p class="text-muted">No reservations match these filters.</p>
            <?php else: ?>
                <div class="bar-chart">
                    <?php foreach ($stats['types'] as $type => $count): ?>
                        <div class="bar-row">
                            <span class="bar-label"><?= e(event_type_label($type)) ?></span>
                            <div class="bar-track"><div class="bar-fill" style="width:<?= round(($count / $maxType) * 100) ?>%"></div></div>
                            <span class="bar-value"><?= number_format($count) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-header">
            <div><h2>Peak start times</h2><p>Approved bookings grouped by start hour.</p></div>
        </div>
        <div class="card-body">
            <?php if ($stats['peak_hours'] === []): ?>
                <p class="text-muted">No approved bookings in this period.</p>
            <?php else: ?>
                <div class="bar-chart">
                    <?php foreach ($stats['peak_hours'] as $hour => $count): ?>
                        <div class="bar-row">
                            <span class="bar-label"><?= e(format_time($hour)) ?></span>
                            <div class="bar-track"><div class="bar-fill alt" style="width:<?= round(($count / $maxPeak) * 100) ?>%"></div></div>
                            <span class="bar-value"><?= number_format($count) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div><h2>Top requesters</h2><p>Groups with the most approved bookings.</p></div>
        </div>
        <div class="card-body">
            <?php if ($stats['top_requesters'] === []): ?>
                <p class="text-muted">No approved bookings in this period.</p>
            <?php else: ?>
                <div class="stack-list">
                    <?php foreach ($stats['top_requesters'] as $requester): ?>
                        <div class="flex-between" style="padding:10px 0;border-bottom:1px solid var(--border);">
                            <div class="user-cell">
                                <span class="avatar"><?= e(initials($requester['first_name'], $requester['last_name'])) ?></span>
                                <div><strong><?= e($requester['name']) ?></strong><small><?= e(role_short_label($requester['role'])) ?></small></div>
                            </div>
                            <div class="text-right">
                                <strong><?= number_format($requester['count']) ?></strong>
                                <div class="text-small text-muted"><?= number_format($requester['hours'], 1) ?> hrs</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div><h2>Six-month booking trend</h2><p>Approved and completed bookings over the recent months.</p></div>
    </div>
    <div class="card-body">
        <div class="bar-chart">
            <?php foreach ($trend as $month): ?>
                <div class="bar-row">
                    <span class="bar-label"><?= e($month['label']) ?></span>
                    <div class="bar-track"><div class="bar-fill" style="width:<?= round(($month['booked'] / $maxTrend) * 100) ?>%"></div></div>
                    <span class="bar-value"><?= number_format($month['booked']) ?> <span class="text-muted" style="font-weight:500;">/ <?= number_format($month['total']) ?></span></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div>
            <h2>Reservation records</h2>
            <p><?= count($reservations) ?> record<?= count($reservations) === 1 ? '' : 's' ?> match the filters<?= count($reservations) > count($displayReservations) ? '; showing the latest ' . count($displayReservations) : '' ?>.</p>
        </div>
        <a class="btn btn-secondary btn-sm" href="<?= e($exportUrl) ?>"><?= icon('download') ?> Export all</a>
    </div>
    <?php if ($displayReservations === []): ?>
        <div class="empty-state compact">
            <div class="empty-icon"><?= icon('chart') ?></div>
            <h3>No report data</h3>
            <p>No reservations match the selected filters.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr><th>Reference</th><th>Activity</th><th>Requester</th><th>Schedule</th><th>Hours</th><th>Status</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($displayReservations as $reservation): ?>
                    <?php $hours = max(0, (time_to_minutes($reservation['end_time']) - time_to_minutes($reservation['start_time'])) / 60); ?>
                    <tr>
                        <td class="nowrap"><span class="primary-cell"><?= e($reservation['reference_no']) ?></span><span class="subtext"><?= e(format_date($reservation['created_at'])) ?></span></td>
                        <td><span class="primary-cell"><?= e($reservation['activity_name']) ?></span><span class="subtext"><?= e(event_type_label($reservation['event_type'])) ?></span></td>
                        <td><span class="primary-cell"><?= e(trim($reservation['first_name'] . ' ' . $reservation['last_name'])) ?></span><span class="subtext"><?= e($reservation['organization'] ?: $reservation['user_organization'] ?: '—') ?></span></td>
                        <td><span class="nowrap"><?= e(format_date($reservation['reservation_date'])) ?></span><span class="subtext"><?= e(format_time_range($reservation['start_time'], $reservation['end_time'])) ?></span></td>
                        <td class="nowrap"><?= number_format($hours, 1) ?></td>
                        <td><?= status_badge($reservation['status']) ?></td>
                        <td><a class="table-icon-button" href="<?= e(url('admin/reservation_view.php?id=' . $reservation['id'])) ?>" title="Open reservation"><?= icon('eye') ?></a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
