<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$user = require_login();
$userId = (int) $user['id'];
send_upcoming_reminders();

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'mark_all') {
        $statement = db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE user_id = :user_id AND is_read = 0');
        $statement->execute(['user_id' => $userId]);
        audit_log('notifications_marked_read', 'notification', null, ['scope' => 'all']);
        flash('success', 'All notifications have been marked as read.');
    }

    if ($action === 'mark_read') {
        $notificationId = (int) post('notification_id');
        $statement = db()->prepare('UPDATE notifications SET is_read = 1, read_at = NOW() WHERE id = :id AND user_id = :user_id');
        $statement->execute(['id' => $notificationId, 'user_id' => $userId]);
        flash('success', 'Notification marked as read.');
    }

    redirect('notifications.php' . (query('filter') === 'unread' ? '?filter=unread' : ''));
}

$filter = (string) query('filter', 'all');
$page = max(1, (int) query('page', 1));
$perPage = 15;

$where = 'user_id = :user_id';
$params = ['user_id' => $userId];
if ($filter === 'unread') {
    $where .= ' AND is_read = 0';
}

$countStatement = db()->prepare("SELECT COUNT(*) FROM notifications WHERE $where");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$statement = db()->prepare("SELECT * FROM notifications WHERE $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$statement->execute($params);
$notifications = $statement->fetchAll();

$unreadCount = unread_notification_count();

$pageTitle = 'Notifications';
$activeNav = 'notifications';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Notifications</h1>
        <p>Approval decisions, schedule changes, reminders, and important gym announcements.</p>
    </div>
    <div class="page-actions">
        <?php if ($unreadCount > 0): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all">
                <button type="submit" class="btn btn-secondary"><?= icon('check-square') ?> Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="flex-between mb-2 flex-wrap">
    <div class="tabs">
        <a class="tab <?= $filter !== 'unread' ? 'active' : '' ?>" href="<?= e(url('notifications.php')) ?>">All notifications</a>
        <a class="tab <?= $filter === 'unread' ? 'active' : '' ?>" href="<?= e(url('notifications.php?filter=unread')) ?>">Unread <?= $unreadCount > 0 ? '(' . $unreadCount . ')' : '' ?></a>
    </div>
    <span class="text-small text-muted"><?= $total ?> notification<?= $total === 1 ? '' : 's' ?><?= $filter === 'unread' ? ' unread' : '' ?></span>
</div>

<div class="card">
    <?php if ($notifications === []): ?>
        <div class="empty-state">
            <div class="empty-icon"><?= icon('bell') ?></div>
            <h3><?= $filter === 'unread' ? 'No unread notifications' : 'No notifications yet' ?></h3>
            <p><?= $filter === 'unread' ? 'You have read all your notifications. New updates will appear here.' : 'When your reservation is submitted, approved, rejected, rescheduled, or approaching, you will see updates here.' ?></p>
            <a class="btn btn-secondary" href="<?= e(url('notifications.php')) ?>">View all notifications</a>
        </div>
    <?php else: ?>
        <div class="notification-list">
            <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
                    <span class="notification-icon <?= e($notification['type']) ?>">
                        <?= icon($notification['type'] === 'success' ? 'check-circle' : ($notification['type'] === 'warning' ? 'alert-triangle' : ($notification['type'] === 'danger' ? 'ban' : 'info'))) ?>
                    </span>
                    <div class="notification-body">
                        <div class="notification-title-row">
                            <strong><?= e($notification['title']) ?></strong>
                            <small><?= e(format_datetime($notification['created_at'], 'M j, Y g:i A')) ?></small>
                        </div>
                        <p><?= e($notification['message']) ?></p>
                        <div class="flex flex-wrap mt-1" style="gap:10px;">
                            <?php if ($notification['link']): ?>
                                <a href="<?= e($notification['link']) ?>">View related record <?= icon('arrow-right') ?></a>
                            <?php endif; ?>
                            <?php if (!$notification['is_read']): ?>
                                <form method="post" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="mark_read">
                                    <input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>">
                                    <button type="submit" class="link-button"><?= icon('check') ?> Mark as read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!$notification['is_read']): ?><span class="unread-dot"></span><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="pagination">
            <span>Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?> notification<?= $total === 1 ? '' : 's' ?></span>
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
<?php require __DIR__ . '/includes/footer.php'; ?>
