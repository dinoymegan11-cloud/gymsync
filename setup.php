<?php
declare(strict_types=1);

require_once __DIR__ . '/config/config.php';

require_once __DIR__ . '/includes/db.php';

$errors = [];
$success = false;
$databaseReady = false;
$installed = false;
$userCount = 0;
$credentials = [];
$schemaPath = __DIR__ . '/database/schema.sql';

function setup_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function setup_icon(string $name): string
{
    return '<svg class="icon" aria-hidden="true"><use href="' . setup_escape(APP_BASE . '/assets/icons.svg') . '#icon-' . setup_escape($name) . '"></use></svg>';
}

try {
    $server = db_server();
    $databaseReady = true;
    $check = $server->prepare('SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = :name');
    $check->execute(['name' => DB_NAME]);

    if ($check->fetchColumn()) {
        $database = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
        $tableCheck = $database->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if ($tableCheck) {
            $userCount = (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $installed = $userCount > 0;
        }
    }
} catch (Throwable $exception) {
    $errors[] = 'Database connection failed: ' . $exception->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$installed) {
    $token = $_POST['_csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['_setup_csrf'] ?? '', $token)) {
        $errors[] = 'Your session expired. Please refresh the page and try again.';
    }

    if (!is_file($schemaPath)) {
        $errors[] = 'The database schema file could not be found.';
    }

    if ($errors === []) {
        try {
            $server = db_server();
            $databaseName = str_replace('`', '', DB_NAME);
            $server->exec("CREATE DATABASE IF NOT EXISTS `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

            $database = new PDO(
                'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );

            $schema = file_get_contents($schemaPath);
            if ($schema === false) {
                throw new RuntimeException('The database schema could not be read.');
            }

            foreach (preg_split('/;\s*(?:\r?\n|$)/', $schema) as $statement) {
                $statement = trim($statement);
                if ($statement !== '') {
                    $database->exec($statement);
                }
            }

            $defaultSettings = [
                'gym_name' => 'CTU – Daanbantayan Campus Gymnasium',
                'gym_short_name' => 'CTU Gymnasium',
                'opening_time' => '06:00',
                'closing_time' => '22:00',
                'buffer_minutes' => '15',
                'max_advance_days' => '90',
                'max_duration_hours' => '12',
                'max_attendees' => '1000',
                'auto_reminder_hours' => '24',
                'allow_registration' => '1',
                'contact_email' => 'gymnasium@ctu.edu.ph',
                'contact_phone' => '(032) 000-0000',
                'reservation_policy' => 'Reservations are reviewed by the gym administrator. Approved school events and regular PE classes may be given priority in accordance with university policies. Requests must be submitted at least one day before the activity whenever possible.',
            ];

            $settingStatement = $database->prepare(
                'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($defaultSettings as $key => $value) {
                $settingStatement->execute(['key' => $key, 'value' => $value]);
            }

            $existingUsers = (int) $database->query('SELECT COUNT(*) FROM users')->fetchColumn();

            if ($existingUsers === 0) {
                $accounts = [
                    [
                        'first_name' => 'Maria',
                        'last_name' => 'Santos',
                        'email' => 'sysadmin@gymsync.local',
                        'password' => 'Admin@123',
                        'role' => 'system_admin',
                        'department' => 'Information Technology Office',
                        'organization' => null,
                        'student_id' => null,
                    ],
                    [
                        'first_name' => 'Alex',
                        'last_name' => 'Villanueva',
                        'email' => 'gymadmin@gymsync.local',
                        'password' => 'Gym@123',
                        'role' => 'gym_admin',
                        'department' => 'Physical Education Department',
                        'organization' => 'Gymnasium Office',
                        'student_id' => null,
                    ],
                    [
                        'first_name' => 'Juan',
                        'last_name' => 'Dela Cruz',
                        'email' => 'student@gymsync.local',
                        'password' => 'Student@123',
                        'role' => 'student',
                        'department' => 'College of Education',
                        'organization' => 'Supreme Student Council',
                        'student_id' => '2023-00123',
                    ],
                    [
                        'first_name' => 'Ana',
                        'last_name' => 'Reyes',
                        'email' => 'teacher@gymsync.local',
                        'password' => 'Teacher@123',
                        'role' => 'teacher',
                        'department' => 'College of Engineering',
                        'organization' => 'Faculty Association',
                        'student_id' => null,
                    ],
                ];

                $insertUser = $database->prepare(
                    'INSERT INTO users (first_name, last_name, email, student_id, password_hash, role, department, organization, status, created_at, updated_at)
                     VALUES (:first_name, :last_name, :email, :student_id, :password_hash, :role, :department, :organization, :status, NOW(), NOW())'
                );

                foreach ($accounts as $account) {
                    $insertUser->execute([
                        'first_name' => $account['first_name'],
                        'last_name' => $account['last_name'],
                        'email' => $account['email'],
                        'student_id' => $account['student_id'],
                        'password_hash' => password_hash($account['password'], PASSWORD_DEFAULT),
                        'role' => $account['role'],
                        'department' => $account['department'],
                        'organization' => $account['organization'],
                        'status' => 'active',
                    ]);
                    $credentials[] = $account;
                }

                $gymAdminId = (int) $database->query("SELECT id FROM users WHERE email = 'gymadmin@gymsync.local'")->fetchColumn();
                $studentId = (int) $database->query("SELECT id FROM users WHERE email = 'student@gymsync.local'")->fetchColumn();
                $teacherId = (int) $database->query("SELECT id FROM users WHERE email = 'teacher@gymsync.local'")->fetchColumn();

                $notify = $database->prepare(
                    'INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                     VALUES (:user_id, :title, :message, :type, :link, 0, NOW())'
                );

                foreach ([$gymAdminId, $studentId, $teacherId] as $userId) {
                    $notify->execute([
                        'user_id' => $userId,
                        'title' => 'Welcome to GymSync',
                        'message' => 'Your GymSync account is ready. You can view the gym calendar and submit reservation requests from your dashboard.',
                        'type' => 'info',
                        'link' => APP_BASE . '/index.php',
                    ]);
                }

                if (!empty($_POST['sample_data'])) {
                    $insertReservation = $database->prepare(
                        'INSERT INTO reservations
                            (reference_no, user_id, activity_name, organization, event_type, reservation_date, start_time, end_time,
                             purpose, attendees, equipment, setup_notes, status, admin_note, approved_by, approved_at, created_at, updated_at)
                         VALUES
                            (:reference_no, :user_id, :activity_name, :organization, :event_type, :reservation_date, :start_time, :end_time,
                             :purpose, :attendees, :equipment, :setup_notes, :status, :admin_note, :approved_by, :approved_at, NOW(), NOW())'
                    );

                    $sampleReservations = [
                        [
                            'reference_no' => 'GYM-' . date('Y') . '-DEMO1',
                            'user_id' => $studentId,
                            'activity_name' => 'Basketball Team Practice',
                            'organization' => 'CTU Basketball Club',
                            'event_type' => 'sports',
                            'offset' => 1,
                            'start_time' => '09:00:00',
                            'end_time' => '11:00:00',
                            'purpose' => 'Regular training session for the varsity basketball team in preparation for the regional tournament.',
                            'attendees' => 40,
                            'equipment' => 'Basketballs, training cones, scoreboard',
                            'setup_notes' => 'Please unlock the equipment room by 8:45 AM.',
                            'status' => 'approved',
                            'admin_note' => 'Approved for regular team practice.',
                        ],
                        [
                            'reference_no' => 'GYM-' . date('Y') . '-DEMO2',
                            'user_id' => $teacherId,
                            'activity_name' => 'PE 2 – Team Sports Class',
                            'organization' => 'College of Engineering',
                            'event_type' => 'class',
                            'offset' => 1,
                            'start_time' => '13:00:00',
                            'end_time' => '15:00:00',
                            'purpose' => 'Practical session for second-year PE students covering basketball and volleyball fundamentals.',
                            'attendees' => 55,
                            'equipment' => 'Volleyballs, nets, whistles',
                            'setup_notes' => 'Two courts with net setup.',
                            'status' => 'approved',
                            'admin_note' => 'Approved as a regular scheduled class.',
                        ],
                        [
                            'reference_no' => 'GYM-' . date('Y') . '-DEMO3',
                            'user_id' => $studentId,
                            'activity_name' => 'Intramurals Opening Program',
                            'organization' => 'Supreme Student Council',
                            'event_type' => 'school_event',
                            'offset' => 3,
                            'start_time' => '14:00:00',
                            'end_time' => '17:00:00',
                            'purpose' => 'Opening ceremony, parade of colleges, and cultural presentations for the annual intramurals.',
                            'attendees' => 320,
                            'equipment' => 'Sound system, chairs, stage decorations',
                            'setup_notes' => 'Need early access for stage setup at 10:00 AM.',
                            'status' => 'pending',
                            'admin_note' => null,
                        ],
                        [
                            'reference_no' => 'GYM-' . date('Y') . '-DEMO4',
                            'user_id' => $teacherId,
                            'activity_name' => 'Faculty General Assembly',
                            'organization' => 'Faculty Association',
                            'event_type' => 'meeting',
                            'offset' => 5,
                            'start_time' => '08:00:00',
                            'end_time' => '10:00:00',
                            'purpose' => 'Quarterly general assembly for faculty members to discuss academic plans and university updates.',
                            'attendees' => 75,
                            'equipment' => 'Chairs, projector, microphone',
                            'setup_notes' => 'Theater-style seating arrangement.',
                            'status' => 'pending',
                            'admin_note' => null,
                        ],
                    ];

                    foreach ($sampleReservations as $reservation) {
                        $date = date('Y-m-d', strtotime('+' . $reservation['offset'] . ' days'));
                        $isApproved = $reservation['status'] === 'approved';
                        $insertReservation->execute([
                            'reference_no' => $reservation['reference_no'],
                            'user_id' => $reservation['user_id'],
                            'activity_name' => $reservation['activity_name'],
                            'organization' => $reservation['organization'],
                            'event_type' => $reservation['event_type'],
                            'reservation_date' => $date,
                            'start_time' => $reservation['start_time'],
                            'end_time' => $reservation['end_time'],
                            'purpose' => $reservation['purpose'],
                            'attendees' => $reservation['attendees'],
                            'equipment' => $reservation['equipment'],
                            'setup_notes' => $reservation['setup_notes'],
                            'status' => $reservation['status'],
                            'admin_note' => $reservation['admin_note'],
                            'approved_by' => $isApproved ? $gymAdminId : null,
                            'approved_at' => $isApproved ? date('Y-m-d H:i:s') : null,
                        ]);
                    }

                    $insertBlock = $database->prepare(
                        'INSERT INTO blocked_periods (title, start_date, end_date, start_time, end_time, reason, created_by, created_at, updated_at)
                         VALUES (:title, :start_date, :end_date, :start_time, :end_time, :reason, :created_by, NOW(), NOW())'
                    );

                    $insertBlock->execute([
                        'title' => 'Gymnasium Maintenance',
                        'start_date' => date('Y-m-d', strtotime('+2 days')),
                        'end_date' => date('Y-m-d', strtotime('+2 days')),
                        'start_time' => '08:00:00',
                        'end_time' => '12:00:00',
                        'reason' => 'Routine cleaning and repair of basketball backboards and lighting fixtures.',
                        'created_by' => $gymAdminId,
                    ]);

                    $insertBlock->execute([
                        'title' => 'Campus Holiday – Gym Closed',
                        'start_date' => date('Y-m-d', strtotime('+8 days')),
                        'end_date' => date('Y-m-d', strtotime('+8 days')),
                        'start_time' => null,
                        'end_time' => null,
                        'reason' => 'The campus gymnasium is closed for the declared local holiday.',
                        'created_by' => $gymAdminId,
                    ]);

                    $notify->execute([
                        'user_id' => $gymAdminId,
                        'title' => 'Reservation requests awaiting review',
                        'message' => 'There are pending reservation requests in the gym administration queue. Review them before they affect the official calendar.',
                        'type' => 'warning',
                        'link' => APP_BASE . '/admin/reservations.php',
                    ]);

                    $notify->execute([
                        'user_id' => $studentId,
                        'title' => 'Reservation approved',
                        'message' => 'Your Basketball Team Practice reservation has been approved for tomorrow at 9:00 AM.',
                        'type' => 'success',
                        'link' => APP_BASE . '/my_reservations.php',
                    ]);
                }

                $audit = $database->prepare(
                    'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
                     VALUES (NULL, :action, :entity_type, NULL, :details, :ip, NOW())'
                );
                $audit->execute([
                    'action' => 'system_installed',
                    'entity_type' => 'system',
                    'details' => json_encode(['version' => '1.0.0', 'sample_data' => !empty($_POST['sample_data'])]),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
                ]);
            }

            $success = true;
            $installed = true;
            $databaseReady = true;
            $_SESSION['_setup_csrf'] = bin2hex(random_bytes(32));
        } catch (Throwable $exception) {
            $errors[] = 'Installation failed: ' . $exception->getMessage();
        }
    }
}

if (empty($_SESSION['_setup_csrf'])) {
    $_SESSION['_setup_csrf'] = bin2hex(random_bytes(32));
}

$canInstall = $databaseReady && !$installed && is_file($schemaPath) && extension_loaded('pdo_mysql');
$base = APP_BASE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install GymSync</title>
    <link rel="icon" href="<?= setup_escape($base) ?>/assets/favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="<?= setup_escape($base) ?>/assets/css/style.css">
</head>
<body class="setup-body">
<div class="setup-card">
    <div class="setup-head">
        <div class="brand brand-light">
            <span class="brand-mark brand-logo"><img src="<?= setup_escape(APP_BASE . '/assets/logo.png?v=3') ?>" alt="Cebu Technological University logo"></span>
            <span class="brand-copy"><strong>Gym<span>Sync</span></strong><small>Gymnasium Reservation System</small></span>
        </div>
        <?php if ($success): ?>
            <h1>GymSync is ready</h1>
            <p>The database was created and the application was seeded successfully. You may now sign in and start using the system.</p>
        <?php elseif ($installed): ?>
            <h1>Installation already complete</h1>
            <p>GymSync already has <?= (int) $userCount ?> registered user<?= $userCount === 1 ? '' : 's' ?>. The installer will not overwrite an existing installation.</p>
        <?php else: ?>
            <h1>Set up your GymSync workspace</h1>
            <p>This one-time installer creates the database tables, default system settings, administrator accounts, and optional demonstration data.</p>
        <?php endif; ?>
    </div>

    <div class="setup-body-inner">
        <?php if ($errors !== []): ?>
            <div class="error-summary">
                <strong><?= setup_icon('alert-triangle') ?> Setup could not continue</strong>
                <ul>
                    <?php foreach ($errors as $error): ?><li><?= setup_escape($error) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="notice notice-success">
                <?= setup_icon('check-circle') ?>
                <div><strong>Installation complete.</strong><br>Default accounts were created. Sign in with one of the accounts below and change the passwords from your profile when appropriate.</div>
            </div>

            <?php if ($credentials !== []): ?>
                <div class="setup-credentials">
                    <?php foreach ($credentials as $account): ?>
                        <div class="credential-card">
                            <span><?= setup_escape(str_replace('_', ' ', $account['role'])) ?></span>
                            <strong><?= setup_escape($account['email']) ?></strong>
                            <code><?= setup_escape($account['password']) ?></code>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="notice notice-warning mt-2">
                <?= setup_icon('shield') ?>
                <div><strong>Security note.</strong><br>Remove or rename <code>setup.php</code> after installation. Change all default passwords before deploying the system.</div>
            </div>

            <div class="flex flex-wrap gap-10 mt-3">
                <a class="btn btn-primary btn-lg" href="<?= setup_escape($base) ?>/login.php"><?= setup_icon('arrow-right') ?> Go to sign in</a>
                <a class="btn btn-secondary btn-lg" href="<?= setup_escape($base) ?>/setup.php">Refresh installer status</a>
            </div>
        <?php elseif ($installed): ?>
            <div class="notice notice-success">
                <?= setup_icon('check-circle') ?>
                <div><strong>GymSync is already installed.</strong><br>Use your authorized account to access the system. If you need to reset the installation, create a backup first and manually remove the existing GymSync database tables.</div>
            </div>
            <a class="btn btn-primary btn-lg" href="<?= setup_escape($base) ?>/login.php"><?= setup_icon('arrow-right') ?> Go to sign in</a>
        <?php else: ?>
            <div class="setup-checks">
                <div class="setup-check">
                    <?= setup_icon('check-circle') ?> PHP <?= setup_escape(PHP_VERSION) ?> is running
                </div>
                <div class="setup-check <?= extension_loaded('pdo_mysql') ? '' : 'error' ?>">
                    <?= setup_icon(extension_loaded('pdo_mysql') ? 'check-circle' : 'alert-triangle') ?> PDO MySQL extension
                </div>
                <div class="setup-check <?= $databaseReady ? '' : 'error' ?>">
                    <?= setup_icon($databaseReady ? 'check-circle' : 'alert-triangle') ?>
                    MySQL connection · <?= setup_escape(DB_USER . '@' . DB_HOST . ':' . DB_PORT) ?>
                </div>
                <div class="setup-check <?= is_file($schemaPath) ? '' : 'error' ?>">
                    <?= setup_icon(is_file($schemaPath) ? 'check-circle' : 'alert-triangle') ?> Database schema file
                </div>
            </div>

            <div class="notice notice-info mb-3">
                <?= setup_icon('info') ?>
                <div><strong>Database target:</strong> <code><?= setup_escape(DB_NAME) ?></code><br>If your MySQL credentials differ, update <code>config/config.php</code> before running the installer.</div>
            </div>

            <form method="post">
                <input type="hidden" name="_csrf" value="<?= setup_escape($_SESSION['_setup_csrf']) ?>">
                <label class="checkbox mb-3">
                    <input type="checkbox" name="sample_data" value="1" checked>
                    <span>Load demonstration accounts, reservations, notifications, and maintenance blocks. This is useful for presenting the system but can be skipped for a clean production database.</span>
                </label>
                <button type="submit" class="btn btn-primary btn-lg btn-block" <?= $canInstall ? '' : 'disabled' ?>>
                    <?= setup_icon('plus-circle') ?> Install GymSync
                </button>
            </form>
            <p class="auth-terms mt-2">The installer creates the <code><?= setup_escape(DB_NAME) ?></code> database if it does not exist. Existing GymSync tables are never dropped.</p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
