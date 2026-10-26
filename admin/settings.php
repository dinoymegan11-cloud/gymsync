<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

$admin = require_roles(['system_admin']);
$errors = [];
$values = settings_all();

$defaults = [
    'gym_name' => 'CTU - Daanbantayan Campus Gymnasium',
    'gym_short_name' => 'CTU Gymnasium',
    'opening_time' => '06:00',
    'closing_time' => '22:00',
    'buffer_minutes' => '15',
    'max_advance_days' => '90',
    'max_duration_hours' => '12',
    'max_attendees' => '1000',
    'auto_reminder_hours' => '24',
    'contact_email' => '',
    'contact_phone' => '',
    'reservation_policy' => '',
    'allow_registration' => '1',
];
$values = array_merge($defaults, $values);

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action', 'save');

    if ($action === 'run_reminders') {
        send_upcoming_reminders();
        audit_log('reminder_scan_run', 'system');
        flash('success', 'Reminder scan completed. Eligible approved reservations were notified.');
        redirect('admin/settings.php');
    }

    $values = [
        'gym_name' => trim((string) post('gym_name')),
        'gym_short_name' => trim((string) post('gym_short_name')),
        'opening_time' => normalize_time((string) post('opening_time')),
        'closing_time' => normalize_time((string) post('closing_time')),
        'buffer_minutes' => (string) (int) post('buffer_minutes', 0),
        'max_advance_days' => (string) (int) post('max_advance_days', 1),
        'max_duration_hours' => (string) (int) post('max_duration_hours', 1),
        'max_attendees' => (string) (int) post('max_attendees', 1),
        'auto_reminder_hours' => (string) (int) post('auto_reminder_hours', 0),
        'contact_email' => trim((string) post('contact_email')),
        'contact_phone' => trim((string) post('contact_phone')),
        'reservation_policy' => trim((string) post('reservation_policy')),
        'allow_registration' => post('allow_registration') ? '1' : '0',
    ];

    if (mb_strlen($values['gym_name']) < 3) {
        $errors[] = 'Please enter the official gym name.';
    }
    if (mb_strlen($values['gym_short_name']) < 2) {
        $errors[] = 'Please enter a short gym name.';
    }
    if ($values['opening_time'] === '' || $values['closing_time'] === '') {
        $errors[] = 'Please provide valid opening and closing times.';
    } elseif (time_to_minutes($values['closing_time']) <= time_to_minutes($values['opening_time'])) {
        $errors[] = 'The closing time must be later than the opening time.';
    }
    if ((int) $values['buffer_minutes'] < 0 || (int) $values['buffer_minutes'] > 120) {
        $errors[] = 'The buffer must be between 0 and 120 minutes.';
    }
    if ((int) $values['max_advance_days'] < 1 || (int) $values['max_advance_days'] > 365) {
        $errors[] = 'Advance booking days must be between 1 and 365.';
    }
    if ((int) $values['max_duration_hours'] < 1 || (int) $values['max_duration_hours'] > 12) {
        $errors[] = 'Maximum duration must be between 1 and 12 hours.';
    }
    if ((int) $values['max_attendees'] < 1 || (int) $values['max_attendees'] > 100000) {
        $errors[] = 'Maximum attendees must be between 1 and 100,000.';
    }
    if ((int) $values['auto_reminder_hours'] < 0 || (int) $values['auto_reminder_hours'] > 168) {
        $errors[] = 'Reminder lead time must be between 0 and 168 hours.';
    }
    if ($values['contact_email'] !== '' && !filter_var($values['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid gym contact email.';
    }

    if ($errors === []) {
        $changed = [];
        foreach ($values as $key => $value) {
            $previous = setting($key, null);
            save_setting($key, $value);
            if ((string) $previous !== (string) $value) {
                $changed[$key] = ['from' => $previous, 'to' => $value];
            }
        }
        audit_log('settings_updated', 'settings', null, ['changed' => $changed, 'by' => full_name($admin)]);
        flash('success', 'System settings were saved successfully.');
        redirect('admin/settings.php');
    }
}

$userStats = db()->query(
    "SELECT COUNT(*) AS total,
            SUM(role IN ('gym_admin', 'system_admin')) AS admins,
            SUM(status = 'active') AS active
     FROM users"
)->fetch();
$reservationStats = db()->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'pending') AS pending,
            SUM(status = 'approved') AS approved
     FROM reservations"
)->fetch();
$databaseVersion = (string) db()->getAttribute(PDO::ATTR_SERVER_VERSION);
$dbName = (string) db()->query('SELECT DATABASE()')->fetchColumn();

$pageTitle = 'System Settings';
$activeNav = 'admin-settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>System settings</h1>
        <p>Configure reservation rules, gym hours, contact information, and registration access.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('admin/audit.php')) ?>"><?= icon('history') ?> Audit logs</a>
    </div>
</div>

<?php if ($errors !== []): ?>
    <div class="error-summary">
        <strong><?= icon('alert-triangle') ?> Settings were not saved</strong>
        <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="grid grid-2-1">
        <div>
            <div class="card">
                <div class="card-header"><div><h2>Gym identity</h2><p>How the gymnasium is presented throughout GymSync.</p></div></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label for="gym_name">Official gym name <span class="required">*</span></label>
                            <input class="form-control" type="text" id="gym_name" name="gym_name" value="<?= e($values['gym_name']) ?>" maxlength="180" required>
                        </div>
                        <div class="form-group full">
                            <label for="gym_short_name">Short name</label>
                            <input class="form-control" type="text" id="gym_short_name" name="gym_short_name" value="<?= e($values['gym_short_name']) ?>" maxlength="80">
                        </div>
                        <div class="form-group">
                            <label for="contact_email">Contact email</label>
                            <input class="form-control" type="email" id="contact_email" name="contact_email" value="<?= e($values['contact_email']) ?>" maxlength="160">
                        </div>
                        <div class="form-group">
                            <label for="contact_phone">Contact phone</label>
                            <input class="form-control" type="text" id="contact_phone" name="contact_phone" value="<?= e($values['contact_phone']) ?>" maxlength="30">
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><div><h2>Reservation rules</h2><p>These limits are enforced when users submit and edit requests.</p></div></div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="opening_time">Gym opens <span class="required">*</span></label>
                            <input class="form-control" type="time" id="opening_time" name="opening_time" value="<?= e(substr($values['opening_time'], 0, 5)) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="closing_time">Gym closes <span class="required">*</span></label>
                            <input class="form-control" type="time" id="closing_time" name="closing_time" value="<?= e(substr($values['closing_time'], 0, 5)) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="buffer_minutes">Buffer between bookings</label>
                            <input class="form-control" type="number" id="buffer_minutes" name="buffer_minutes" value="<?= (int) $values['buffer_minutes'] ?>" min="0" max="120" required>
                            <span class="form-hint">Minutes reserved for cleanup and turnover between activities.</span>
                        </div>
                        <div class="form-group">
                            <label for="max_advance_days">Maximum advance booking</label>
                            <div class="input-suffix"><input class="form-control" type="number" id="max_advance_days" name="max_advance_days" value="<?= (int) $values['max_advance_days'] ?>" min="1" max="365" required><span>days</span></div>
                        </div>
                        <div class="form-group">
                            <label for="max_duration_hours">Maximum reservation duration</label>
                            <div class="input-suffix"><input class="form-control" type="number" id="max_duration_hours" name="max_duration_hours" value="<?= (int) $values['max_duration_hours'] ?>" min="1" max="12" required><span>hours</span></div>
                        </div>
                        <div class="form-group">
                            <label for="max_attendees">Maximum attendees</label>
                            <input class="form-control" type="number" id="max_attendees" name="max_attendees" value="<?= (int) $values['max_attendees'] ?>" min="1" max="100000" required>
                        </div>
                        <div class="form-group">
                            <label for="auto_reminder_hours">Upcoming reminder lead time</label>
                            <div class="input-suffix"><input class="form-control" type="number" id="auto_reminder_hours" name="auto_reminder_hours" value="<?= (int) $values['auto_reminder_hours'] ?>" min="0" max="168" required><span>hours</span></div>
                            <span class="form-hint">Set to 0 to disable automatic reminders.</span>
                        </div>
                        <div class="form-group">
                            <label>Public registration</label>
                            <label class="checkbox"><input type="checkbox" name="allow_registration" value="1"<?= checkbox_checked($values['allow_registration']) ?>><span>Allow new students and faculty to register</span></label>
                            <span class="form-hint">When disabled, only system administrators can create accounts.</span>
                        </div>
                        <div class="form-group full">
                            <label for="reservation_policy">Reservation policy</label>
                            <textarea class="form-control" id="reservation_policy" name="reservation_policy" style="min-height:120px;" maxlength="3000"><?= e($values['reservation_policy']) ?></textarea>
                            <span class="form-hint">Displayed to users on the reservation form and confirmation pages.</span>
                        </div>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn btn-primary"><?= icon('check') ?> Save settings</button></div>
                </div>
            </div>
        </div>

        <div class="side-sticky">
            <div class="card">
                <div class="card-header"><div><h2>System information</h2><p>Read-only environment details.</p></div></div>
                <div class="card-body">
                    <div class="detail-stat"><span>Application</span><strong><?= e(APP_NAME) ?> <?= e(APP_VERSION) ?></strong></div>
                    <div class="detail-stat"><span>Environment</span><strong>PHP <?= e(PHP_VERSION) ?></strong></div>
                    <div class="detail-stat"><span>Database</span><strong>MySQL <?= e($databaseVersion) ?></strong></div>
                    <div class="detail-stat"><span>Database name</span><strong><?= e($dbName) ?></strong></div>
                    <div class="detail-stat"><span>Timezone</span><strong><?= e(date_default_timezone_get()) ?></strong></div>
                    <div class="detail-stat"><span>App base path</span><strong style="word-break:break-all;"><?= e(APP_BASE) ?></strong></div>
                    <div class="detail-stat"><span>Accounts</span><strong><?= (int) ($userStats['total'] ?? 0) ?> (<?= (int) ($userStats['active'] ?? 0) ?> active)</strong></div>
                    <div class="detail-stat"><span>Administrators</span><strong><?= (int) ($userStats['admins'] ?? 0) ?></strong></div>
                    <div class="detail-stat"><span>Reservations</span><strong><?= (int) ($reservationStats['total'] ?? 0) ?></strong></div>
                    <div class="detail-stat"><span>Pending review</span><strong><?= (int) ($reservationStats['pending'] ?? 0) ?></strong></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><div><h2>Reminder scan</h2><p>Send reminders for upcoming approved reservations.</p></div></div>
                <div class="card-body">
                    <div class="notice notice-info mb-2"><?= icon('bell') ?><div>Reminders are normally triggered by a scheduled task. You can also run a manual scan after changing the reminder lead time.</div></div>
                    <button type="submit" form="reminder-form" class="btn btn-secondary btn-block"><?= icon('bell') ?> Run reminder scan now</button>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><div><h2>Security reminder</h2><p>Keep administrator accounts protected.</p></div></div>
                <div class="card-body">
                    <p class="text-small text-muted mb-0">Use a unique password for administrator accounts, deactivate unused accounts promptly, and review the audit log regularly. GymSync records account, reservation, block, and settings changes.</p>
                </div>
            </div>
        </div>
    </div>
</form>

<form method="post" id="reminder-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="run_reminders">
</form>
<?php require __DIR__ . '/../includes/footer.php'; ?>
