<?php
declare(strict_types=1);

require_once __DIR__ . '/reservations.php';
require_once __DIR__ . '/ui.php';

$user = auth_user();
$authPage = $authPage ?? false;
$activeNav = $activeNav ?? '';
$pageTitle = $pageTitle ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$extraHead = $extraHead ?? '';
$gymName = setting('gym_name', 'CTU – Daanbantayan Campus Gymnasium');
$unreadCount = $user ? unread_notification_count() : 0;
$adminPendingCount = 0;

if ($user && is_admin($user)) {
    $pendingStatement = db()->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'");
    $adminPendingCount = (int) $pendingStatement->fetchColumn();
}

if (!$authPage && !$user) {
    flash('warning', 'Please sign in to continue.');
    redirect('login.php');
}

$navItem = static function (string $key, string $label, string $iconName, string $href, int $badge = 0, string $badgeClass = '') use ($activeNav): void {
    echo '<a class="nav-link' . ($activeNav === $key ? ' active' : '') . '" href="' . e(url($href)) . '">';
    echo '<span class="nav-icon">' . icon($iconName) . '</span><span>' . e($label) . '</span>';
    if ($badge > 0) {
        echo '<span class="nav-badge ' . e($badgeClass) . '">' . $badge . '</span>';
    }
    echo '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0f766e">
    <title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
    <link rel="icon" href="<?= e(url('assets/logo.png?v=3')) ?>" type="image/png">
    <link rel="preconnect" href="https://cdn.jsdelivr.net">
    <link rel="stylesheet" href="<?= e(url('assets/css/style.css')) ?>?v=4">
    <?= $extraHead ?>
</head>
<body class="<?= $authPage ? 'auth-body' : 'app-body' ?>">
<?php if ($authPage): ?>
<div class="auth-page">
    <section class="auth-visual">
        <div class="auth-visual-inner">
            <a class="brand brand-light" href="<?= e(url('login.php')) ?>">
                <span class="brand-mark brand-logo"><img src="<?= e(url('assets/logo.png?v=3')) ?>" alt="Cebu Technological University logo"></span>
                <span class="brand-copy"><strong>Gym<span>Sync</span></strong><small><?= e(APP_SUBTITLE) ?></small></span>
            </a>
            <div class="auth-hero">
                <span class="eyebrow"><span class="eyebrow-dot"></span> CTU Daanbantayan Campus</span>
                <h1>One gym.<br>One schedule.<br><span>Zero conflicts.</span></h1>
                <p>A centralized space for reserving the campus gymnasium, coordinating activities, and keeping every schedule visible to the people who need it.</p>
            </div>
            <div class="auth-features">
                <div class="auth-feature">
                    <span class="feature-icon"><?= icon('calendar') ?></span>
                    <div><strong>Live availability</strong><small>See approved, pending, and blocked schedules at a glance.</small></div>
                </div>
                <div class="auth-feature">
                    <span class="feature-icon"><?= icon('shield') ?></span>
                    <div><strong>Conflict prevention</strong><small>Automatic overlap checks protect every approved booking.</small></div>
                </div>
                <div class="auth-feature">
                    <span class="feature-icon"><?= icon('bell') ?></span>
                    <div><strong>Timely updates</strong><small>Know immediately when a request is approved or changed.</small></div>
                </div>
            </div>
            <div class="auth-mini-schedule">
                <div class="mini-schedule-head">
                    <span>Today at the gym</span>
                    <span class="live-pill"><span></span> Live</span>
                </div>
                <div class="mini-schedule-row">
                    <span class="mini-time">08:00 AM</span>
                    <span class="mini-bar approved-bar"></span>
                    <span class="mini-info"><strong>Sports Practice</strong><small>Approved · Main court</small></span>
                </div>
                <div class="mini-schedule-row">
                    <span class="mini-time">01:00 PM</span>
                    <span class="mini-bar pending-bar"></span>
                    <span class="mini-info"><strong>Student Assembly</strong><small>Pending review</small></span>
                </div>
                <div class="mini-schedule-row">
                    <span class="mini-time">04:30 PM</span>
                    <span class="mini-bar blocked-bar"></span>
                    <span class="mini-info"><strong>Maintenance</strong><small>Unavailable</small></span>
                </div>
            </div>
            <p class="auth-footnote">Cebu Technological University · Daanbantayan Campus</p>
        </div>
    </section>
    <section class="auth-form-side">
        <div class="auth-form-wrap">
            <div class="flash-stack auth-flashes">
                <?php foreach (get_flashes() as $flash): ?>
                    <div class="flash flash-<?= e($flash['type']) ?>" role="status">
                        <?= icon($flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'alert-triangle' : 'info')) ?>
                        <span><?= e($flash['message']) ?></span>
                        <button type="button" class="flash-close" data-dismiss-flash aria-label="Dismiss"><?= icon('x') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
<?php else: ?>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="<?= e(url('index.php')) ?>">
                <span class="brand-mark brand-logo"><img src="<?= e(url('assets/logo.png?v=3')) ?>" alt="Cebu Technological University logo"></span>
                <span class="brand-copy"><strong>Gym<span>Sync</span></strong><small>Campus Gym Portal</small></span>
            </a>
            <button type="button" class="icon-button sidebar-close" data-sidebar-close aria-label="Close navigation"><?= icon('x') ?></button>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            <div class="nav-section">
                <span class="nav-label">Workspace</span>
                <?php
                $navItem('dashboard', 'Dashboard', 'dashboard', 'index.php');
                $navItem('calendar', 'Gym Calendar', 'calendar', 'calendar.php');
                $navItem('reserve', 'Reserve the Gym', 'plus-circle', 'reserve.php');
                $navItem('my-reservations', 'My Reservations', 'clipboard-list', 'my_reservations.php');
                $navItem('notifications', 'Notifications', 'bell', 'notifications.php', $unreadCount, 'nav-badge-alert');
                ?>
            </div>

            <?php if (is_admin($user)): ?>
            <div class="nav-section">
                <span class="nav-label">Gym administration</span>
                <?php
                $navItem('admin-reservations', 'Reservation Requests', 'check-square', 'admin/reservations.php', $adminPendingCount, 'nav-badge-warn');
                $navItem('admin-blocks', 'Maintenance & Blocks', 'wrench', 'admin/blocks.php');
                $navItem('admin-reports', 'Reports & Analytics', 'chart', 'admin/reports.php');
                $navItem('reservation-qr', 'Reservation QR Code', 'calendar', 'reservation_qr.php');
                ?>
            </div>
            <?php endif; ?>

            <?php if (is_system_admin($user)): ?>
            <div class="nav-section">
                <span class="nav-label">System administration</span>
                <?php
                $navItem('admin-users', 'Users & Roles', 'users', 'admin/users.php');
                $navItem('admin-settings', 'System Settings', 'settings', 'admin/settings.php');
                $navItem('admin-audit', 'Audit Logs', 'history', 'admin/audit.php');
                ?>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="gym-status">
                <span class="status-dot"></span>
                <div><strong>Gym open today</strong><small><?= e(format_time(setting('opening_time', '06:00'))) ?> – <?= e(format_time(setting('closing_time', '22:00'))) ?></small></div>
            </div>
            <a class="sidebar-profile" href="<?= e(url('profile.php')) ?>">
                <span class="avatar"><?= e(initials($user['first_name'], $user['last_name'])) ?></span>
                <span class="profile-copy"><strong><?= e(full_name($user)) ?></strong><small><?= e(role_short_label($user['role'])) ?></small></span>
                <?= icon('chevron-right') ?>
            </a>
            <form method="post" action="<?= e(url('logout.php')) ?>" class="logout-form">
                <?= csrf_field() ?>
                <button type="submit" class="sidebar-logout"><?= icon('log-out') ?> Sign out</button>
            </form>
        </div>
    </aside>

    <button type="button" class="sidebar-overlay" data-sidebar-close aria-label="Close navigation"></button>

    <div class="main-shell">
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="icon-button menu-toggle" data-sidebar-open aria-label="Open navigation"><?= icon('menu') ?></button>
                <div class="topbar-context">
                    <span>GymSync workspace</span>
                    <strong><?= e(date('l, F j, Y')) ?></strong>
                </div>
            </div>
            <div class="topbar-actions">
                <a class="icon-button notification-button" href="<?= e(url('notifications.php')) ?>" aria-label="Notifications">
                    <?= icon('bell') ?>
                    <?php if ($unreadCount > 0): ?><span class="notification-dot"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span><?php endif; ?>
                </a>
                <a class="topbar-profile" href="<?= e(url('profile.php')) ?>">
                    <span class="avatar avatar-sm"><?= e(initials($user['first_name'], $user['last_name'])) ?></span>
                    <span class="topbar-profile-copy"><strong><?= e(full_name($user)) ?></strong><small><?= e(role_short_label($user['role'])) ?></small></span>
                </a>
            </div>
        </header>

        <main class="main-content">
            <div class="flash-stack">
                <?php foreach (get_flashes() as $flash): ?>
                    <div class="flash flash-<?= e($flash['type']) ?>" role="status">
                        <?= icon($flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'danger' ? 'alert-triangle' : 'info')) ?>
                        <span><?= e($flash['message']) ?></span>
                        <button type="button" class="flash-close" data-dismiss-flash aria-label="Dismiss"><?= icon('x') ?></button>
                    </div>
                <?php endforeach; ?>
            </div>
<?php endif; ?>
