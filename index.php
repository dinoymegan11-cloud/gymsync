<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$user = require_login();
send_upcoming_reminders();

$isAdmin = is_admin($user);
$userId = (int) $user['id'];
$today = date('Y-m-d');
$hour = (int) date('G');

if ($hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour < 18) {
    $greeting = 'Good afternoon';
} else {
    $greeting = 'Good evening';
}

$stats = [
    'pending' => 0,
    'approved_upcoming' => 0,
    'total' => 0,
    'today_bookings' => 0,
    'month_approved' => 0,
    'conflicts' => 0,
];

if ($isAdmin) {
    $stats['pending'] = (int) db()->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
    $statement = db()->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'approved' AND reservation_date = :today");
    $statement->execute(['today' => $today]);
    $stats['today_bookings'] = (int) $statement->fetchColumn();
    $statement = db()->prepare("SELECT COUNT(*) FROM reservations WHERE status = 'approved' AND YEAR(reservation_date) = YEAR(CURDATE()) AND MONTH(reservation_date) = MONTH(CURDATE())");
    $statement->execute();
    $stats['month_approved'] = (int) $statement->fetchColumn();
    $stats['conflicts'] = (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('pending_conflict_detected', 'approval_blocked_by_conflict', 'reschedule_approval_blocked', 'pending_request_overlap') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
} else {
    $statement = db()->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = :user_id AND status = 'pending'");
    $statement->execute(['user_id' => $userId]);
    $stats['pending'] = (int) $statement->fetchColumn();

    $statement = db()->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = :user_id AND status = 'approved' AND reservation_date >= :today");
    $statement->execute(['user_id' => $userId, 'today' => $today]);
    $stats['approved_upcoming'] = (int) $statement->fetchColumn();

    $statement = db()->prepare('SELECT COUNT(*) FROM reservations WHERE user_id = :user_id');
    $statement->execute(['user_id' => $userId]);
    $stats['total'] = (int) $statement->fetchColumn();

    $statement = db()->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = :user_id AND status = 'approved' AND YEAR(reservation_date) = YEAR(CURDATE()) AND MONTH(reservation_date) = MONTH(CURDATE())");
    $statement->execute(['user_id' => $userId]);
    $stats['month_approved'] = (int) $statement->fetchColumn();
}

$unreadNotifications = unread_notification_count();

if ($isAdmin) {
    $upcomingStatement = db()->prepare(
        "SELECT r.*, u.first_name, u.last_name
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         WHERE r.status = 'approved' AND r.reservation_date >= :today
         ORDER BY r.reservation_date ASC, r.start_time ASC
         LIMIT 5"
    );
    $upcomingStatement->execute(['today' => $today]);
} else {
    $upcomingStatement = db()->prepare(
        "SELECT r.*, u.first_name, u.last_name
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         WHERE r.user_id = :user_id AND r.status IN ('approved', 'pending') AND r.reservation_date >= :today
         ORDER BY r.reservation_date ASC, r.start_time ASC
         LIMIT 5"
    );
    $upcomingStatement->execute(['user_id' => $userId, 'today' => $today]);
}
$upcoming = $upcomingStatement->fetchAll();

$nextApprovedStatement = db()->prepare(
    "SELECT * FROM reservations
     WHERE " . ($isAdmin ? '1 = 1' : 'user_id = :user_id') . "
       AND status = 'approved' AND reservation_date >= :today
     ORDER BY reservation_date ASC, start_time ASC LIMIT 1"
);
$nextApprovedParams = ['today' => $today];
if (!$isAdmin) {
    $nextApprovedParams['user_id'] = $userId;
}
$nextApprovedStatement->execute($nextApprovedParams);
$nextApproved = $nextApprovedStatement->fetch();

$notificationStatement = db()->prepare(
    'SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 4'
);
$notificationStatement->execute(['user_id' => $userId]);
$recentNotifications = $notificationStatement->fetchAll();

$pendingRequests = [];
$conflictWatch = [];

if ($isAdmin) {
    $pendingStatement = db()->query(
        "SELECT r.*, u.first_name, u.last_name, u.organization AS user_organization
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         WHERE r.status = 'pending'
         ORDER BY r.created_at ASC
         LIMIT 6"
    );
    $pendingRequests = $pendingStatement->fetchAll();

    foreach ($pendingRequests as $request) {
        $conflicts = check_conflicts($request['reservation_date'], $request['start_time'], $request['end_time'], (int) $request['id']);
        if ($conflicts['blocking'] !== []) {
            $request['_conflicts'] = $conflicts['blocking'];
            $conflictWatch[] = $request;
        }
    }
}

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';

$heroTitle = $greeting . ', ' . $user['first_name'] . '.';
if ($isAdmin) {
    $heroMessage = $stats['pending'] > 0
        ? 'The official gym calendar needs your attention. There ' . ($stats['pending'] === 1 ? 'is 1 request' : 'are ' . $stats['pending'] . ' requests') . ' waiting for review.'
        : 'Everything is up to date. No reservation requests are waiting for review right now.';
} else {
    $heroMessage = $nextApproved
        ? 'Your next approved reservation is ' . $nextApproved['activity_name'] . ' on ' . format_date($nextApproved['reservation_date']) . ' at ' . format_time($nextApproved['start_time']) . '.'
        : 'Check the gym calendar, find an open time, and submit a reservation request when you are ready.';
}
?>
<div class="dashboard-hero">
    <div class="hero-copy">
        <span class="hero-eyebrow"><span></span> <?= $isAdmin ? 'Gym administration overview' : 'Your reservation workspace' ?></span>
        <h1><?= e($heroTitle) ?></h1>
        <p><?= e($heroMessage) ?></p>
    </div>
    <div class="hero-availability">
        <span>Booked today</span>
        <strong><?= (int) $stats['today_bookings'] ?> session<?= (int) $stats['today_bookings'] === 1 ? '' : 's' ?></strong>
        <small>Gym hours: <?= e(format_time(setting('opening_time', '06:00'))) ?> – <?= e(format_time(setting('closing_time', '22:00'))) ?></small>
    </div>
    <div class="hero-actions">
        <?php if ($isAdmin): ?>
            <a class="btn btn-primary" href="<?= e(url('admin/reservations.php')) ?>"><?= icon('check-square') ?> Review requests</a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('reserve.php')) ?>"><?= icon('plus-circle') ?> Reserve the gym</a>
        <?php endif; ?>
        <a class="btn btn-secondary" href="<?= e(url('calendar.php')) ?>"><?= icon('calendar') ?> View calendar</a>
    </div>
</div>

<div class="stats-grid">
    <?php if ($isAdmin): ?>
        <div class="stat-card stat-amber">
            <div class="stat-top"><span class="stat-label">Pending requests</span><span class="stat-icon"><?= icon('clock') ?></span></div>
            <p class="stat-value"><?= (int) $stats['pending'] ?></p>
            <div class="stat-meta"><?= icon('arrow-right') ?> Awaiting administrator review</div>
        </div>
        <div class="stat-card stat-teal">
            <div class="stat-top"><span class="stat-label">Today's bookings</span><span class="stat-icon"><?= icon('calendar') ?></span></div>
            <p class="stat-value"><?= (int) $stats['today_bookings'] ?></p>
            <div class="stat-meta"><?= icon('clock') ?> Approved sessions today</div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-top"><span class="stat-label">Approved this month</span><span class="stat-icon"><?= icon('check-circle') ?></span></div>
            <p class="stat-value"><?= (int) $stats['month_approved'] ?></p>
            <div class="stat-meta"><?= icon('chart') ?> <?= e(date('F Y')) ?></div>
        </div>
        <div class="stat-card stat-red">
            <div class="stat-top"><span class="stat-label">Conflicts flagged</span><span class="stat-icon"><?= icon('alert-triangle') ?></span></div>
            <p class="stat-value"><?= (int) $stats['conflicts'] ?></p>
            <div class="stat-meta"><?= icon('history') ?> In the last 30 days</div>
        </div>
    <?php else: ?>
        <div class="stat-card stat-teal">
            <div class="stat-top"><span class="stat-label">Upcoming approved</span><span class="stat-icon"><?= icon('check-circle') ?></span></div>
            <p class="stat-value"><?= (int) $stats['approved_upcoming'] ?></p>
            <div class="stat-meta"><?= icon('calendar') ?> From today onward</div>
        </div>
        <div class="stat-card stat-amber">
            <div class="stat-top"><span class="stat-label">Awaiting review</span><span class="stat-icon"><?= icon('clock') ?></span></div>
            <p class="stat-value"><?= (int) $stats['pending'] ?></p>
            <div class="stat-meta"><?= icon('clipboard-list') ?> Pending reservation requests</div>
        </div>
        <div class="stat-card stat-green">
            <div class="stat-top"><span class="stat-label">Approved this month</span><span class="stat-icon"><?= icon('ticket') ?></span></div>
            <p class="stat-value"><?= (int) $stats['month_approved'] ?></p>
            <div class="stat-meta"><?= icon('chart') ?> <?= e(date('F Y')) ?></div>
        </div>
        <div class="stat-card stat-blue">
            <div class="stat-top"><span class="stat-label">Unread notifications</span><span class="stat-icon"><?= icon('bell') ?></span></div>
            <p class="stat-value"><?= (int) $unreadNotifications ?></p>
            <div class="stat-meta"><?= icon('info') ?> Updates from gym administration</div>
        </div>
    <?php endif; ?>
</div>

<div class="grid grid-2-1">
    <div class="card">
        <div class="card-header">
            <div>
                <h2><?= $isAdmin ? 'Upcoming gym schedule' : 'Your upcoming reservations' ?></h2>
                <p><?= $isAdmin ? 'Approved bookings on the official calendar.' : 'Approved and pending requests from today onward.' ?></p>
            </div>
            <a class="link-button" href="<?= e(url($isAdmin ? 'calendar.php' : 'my_reservations.php')) ?>">View all <?= icon('arrow-right') ?></a>
        </div>
        <div class="card-body">
            <?php if ($upcoming === []): ?>
                <div class="empty-state compact">
                    <div class="empty-icon"><?= icon('calendar') ?></div>
                    <h3>No upcoming reservations</h3>
                    <p><?= $isAdmin ? 'Once reservations are approved, they will appear on this schedule.' : 'Find an open time and submit your first reservation request.' ?></p>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('reserve.php')) ?>"><?= icon('plus-circle') ?> Reserve the gym</a>
                </div>
            <?php else: ?>
                <div class="schedule-list">
                    <?php foreach ($upcoming as $reservation): ?>
                        <a class="schedule-item" href="<?= e(url(($isAdmin ? 'admin/reservation_view.php' : 'reservation_detail.php') . '?id=' . $reservation['id'])) ?>">
                            <span class="schedule-date">
                                <span><?= e(date('M', strtotime($reservation['reservation_date']))) ?></span>
                                <strong><?= e(date('j', strtotime($reservation['reservation_date']))) ?></strong>
                            </span>
                            <span class="schedule-body">
                                <strong><?= e($reservation['activity_name']) ?></strong>
                                <small><?= icon('clock') ?> <?= e(format_time_range($reservation['start_time'], $reservation['end_time'])) ?> · <?= e($reservation['organization'] ?: event_type_label($reservation['event_type'])) ?></small>
                                <?php if ($isAdmin): ?><small><?= icon('user') ?> <?= e(trim($reservation['first_name'] . ' ' . $reservation['last_name'])) ?></small><?php endif; ?>
                            </span>
                            <span class="schedule-end">
                                <?= status_badge($reservation['status']) ?>
                                <small><?= e(format_date($reservation['reservation_date'], 'D')) ?></small>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($upcoming !== []): ?>
            <div class="card-footer">
                <span class="text-small text-muted">Showing the next <?= count($upcoming) ?> schedule<?= count($upcoming) === 1 ? '' : 's' ?>.</span>
                <a class="link-button" href="<?= e(url('calendar.php')) ?>">Open full calendar <?= icon('arrow-right') ?></a>
            </div>
        <?php endif; ?>
    </div>

    <div class="card card-pad">
        <div class="flex-between mb-2">
            <div>
                <h2 class="mb-0" style="font-size:15px;">Quick actions</h2>
                <p class="text-small text-muted" style="margin:4px 0 0;">Common tasks for your account.</p>
            </div>
        </div>
        <div class="quick-actions">
            <a class="quick-action" href="<?= e(url('reserve.php')) ?>">
                <span class="quick-action-icon"><?= icon('plus-circle') ?></span>
                <div><strong>Request a reservation</strong><small>Check a schedule and submit details</small></div>
                <?= icon('chevron-right') ?>
            </a>
            <a class="quick-action" href="<?= e(url('calendar.php')) ?>">
                <span class="quick-action-icon"><?= icon('calendar') ?></span>
                <div><strong>Browse the calendar</strong><small>See every approved and blocked time</small></div>
                <?= icon('chevron-right') ?>
            </a>
            <a class="quick-action" href="<?= e(url('my_reservations.php')) ?>">
                <span class="quick-action-icon"><?= icon('clipboard-list') ?></span>
                <div><strong>Manage my reservations</strong><small>Track status, cancel, or request changes</small></div>
                <?= icon('chevron-right') ?>
            </a>
            <?php if ($isAdmin): ?>
                <a class="quick-action" href="<?= e(url('admin/reports.php')) ?>">
                    <span class="quick-action-icon"><?= icon('chart') ?></span>
                    <div><strong>View usage reports</strong><small>Analyze bookings and gym utilization</small></div>
                    <?= icon('chevron-right') ?>
                </a>
            <?php else: ?>
                <a class="quick-action" href="<?= e(url('notifications.php')) ?>">
                    <span class="quick-action-icon"><?= icon('bell') ?></span>
                    <div><strong>Check notifications</strong><small>Approval updates and schedule changes</small></div>
                    <?= icon('chevron-right') ?>
                </a>
            <?php endif; ?>
        </div>
        <?php if ($isAdmin): ?>
            <div class="notice <?= $stats['pending'] > 0 ? 'notice-warning' : 'notice-success' ?> mt-3">
                <?= icon($stats['pending'] > 0 ? 'alert-triangle' : 'check-circle') ?>
                <div><strong><?= $stats['pending'] > 0 ? 'Action needed' : 'Queue cleared' ?></strong><br><?= $stats['pending'] > 0 ? 'Review pending requests before competing bookings accumulate.' : 'There are no pending reservation requests at the moment.' ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin): ?>
    <div class="grid grid-2-1 mt-3">
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Requests waiting for review</h2>
                    <p>Oldest requests appear first so nothing is overlooked.</p>
                </div>
                <a class="link-button" href="<?= e(url('admin/reservations.php')) ?>">Manage all <?= icon('arrow-right') ?></a>
            </div>
            <?php if ($pendingRequests === []): ?>
                <div class="card-body">
                    <div class="empty-state compact">
                        <div class="empty-icon"><?= icon('check-circle') ?></div>
                        <h3>All caught up</h3>
                        <p>No reservation requests are waiting for review.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Request</th><th>Schedule</th><th>Submitted</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($pendingRequests as $request): ?>
                            <tr>
                                <td>
                                    <span class="primary-cell"><?= e($request['activity_name']) ?></span>
                                    <span class="subtext"><?= e(trim($request['first_name'] . ' ' . $request['last_name'])) ?> · <?= e($request['organization'] ?: 'No organization') ?></span>
                                </td>
                                <td>
                                    <span class="nowrap"><?= e(format_date($request['reservation_date'])) ?></span>
                                    <span class="subtext"><?= e(format_time_range($request['start_time'], $request['end_time'])) ?></span>
                                </td>
                                <td class="nowrap text-muted"><?= e(time_ago($request['created_at'])) ?></td>
                                <td class="text-right"><a class="btn btn-secondary btn-sm" href="<?= e(url('admin/reservation_view.php?id=' . $request['id'])) ?>">Review</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div class="card card-pad">
            <div class="flex-between mb-2">
                <div>
                    <h2 class="mb-0" style="font-size:15px;">Conflict watch</h2>
                    <p class="text-small text-muted" style="margin:4px 0 0;">Pending requests that overlap another booking.</p>
                </div>
                <span class="badge <?= $conflictWatch === [] ? 'badge-approved' : 'badge-pending' ?>"><?= count($conflictWatch) ?> found</span>
            </div>
            <?php if ($conflictWatch === []): ?>
                <div class="notice notice-success">
                    <?= icon('check-circle') ?>
                    <div><strong>No overlapping requests.</strong><br>The pending queue does not conflict with approved reservations or blocked periods.</div>
                </div>
            <?php else: ?>
                <div class="schedule-list">
                    <?php foreach ($conflictWatch as $index => $request): ?>
                        <?php if ($index >= 4) break; ?>
                        <a class="schedule-item" href="<?= e(url('admin/reservation_view.php?id=' . $request['id'])) ?>">
                            <span class="schedule-date">
                                <span><?= e(date('M', strtotime($request['reservation_date']))) ?></span>
                                <strong><?= e(date('j', strtotime($request['reservation_date']))) ?></strong>
                            </span>
                            <span class="schedule-body">
                                <strong><?= e($request['activity_name']) ?></strong>
                                <small><?= icon('alert-triangle') ?> Overlaps <?= e($request['_conflicts'][0]['title']) ?></small>
                                <small><?= e(format_time_range($request['start_time'], $request['end_time'])) ?></small>
                            </span>
                            <span class="schedule-end"><span class="badge badge-rejected">Conflict</span></span>
                        </a>
                    <?php endforeach; ?>
                </div>
                <a class="btn btn-secondary btn-sm btn-block mt-2" href="<?= e(url('admin/reservations.php?status=pending')) ?>">Review pending requests</a>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<div class="grid grid-2 mt-3">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Recent notifications</h2>
                <p>Updates related to your account and reservations.</p>
            </div>
            <a class="link-button" href="<?= e(url('notifications.php')) ?>">View all <?= icon('arrow-right') ?></a>
        </div>
        <div class="notification-list">
            <?php if ($recentNotifications === []): ?>
                <div class="empty-state compact">
                    <div class="empty-icon"><?= icon('bell') ?></div>
                    <h3>No notifications yet</h3>
                    <p>Approval decisions and schedule changes will appear here.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentNotifications as $notification): ?>
                    <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
                        <span class="notification-icon <?= e($notification['type']) ?>">
                            <?= icon($notification['type'] === 'success' ? 'check-circle' : ($notification['type'] === 'warning' ? 'alert-triangle' : ($notification['type'] === 'danger' ? 'ban' : 'info'))) ?>
                        </span>
                        <div class="notification-body">
                            <div class="notification-title-row">
                                <strong><?= e($notification['title']) ?></strong>
                                <small><?= e(time_ago($notification['created_at'])) ?></small>
                            </div>
                            <p><?= e(truncate_text($notification['message'], 130)) ?></p>
                        </div>
                        <?php if (!$notification['is_read']): ?><span class="unread-dot"></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="card card-pad">
        <div class="flex-between mb-2">
            <div>
                <h2 class="mb-0" style="font-size:15px;">Gym policies & contact</h2>
                <p class="text-small text-muted" style="margin:4px 0 0;">Keep these guidelines in mind when reserving.</p>
            </div>
            <span class="stat-icon"><?= icon('shield') ?></span>
        </div>
        <p class="text-small" style="color:var(--text-soft);line-height:1.7;"><?= e(setting('reservation_policy', 'Reservations are subject to gym administrator review and university policies.')) ?></p>
        <div class="info-row"><span>Operating hours</span><strong><?= e(format_time(setting('opening_time', '06:00'))) ?> – <?= e(format_time(setting('closing_time', '22:00'))) ?></strong></div>
        <div class="info-row"><span>Buffer between bookings</span><strong><?= (int) setting_int('buffer_minutes', 0) ?> minutes</strong></div>
        <div class="info-row"><span>Booking window</span><strong>Up to <?= (int) setting_int('max_advance_days', 90) ?> days ahead</strong></div>
        <div class="info-row"><span>Gym office</span><strong><?= e(setting('contact_email', 'gymnasium@ctu.edu.ph')) ?></strong></div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
