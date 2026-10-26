<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$user = require_login();
$userId = (int) $user['id'];
$profileErrors = [];
$passwordErrors = [];

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'update_profile') {
        $firstName = trim((string) post('first_name'));
        $lastName = trim((string) post('last_name'));
        $email = strtolower(trim((string) post('email')));
        $studentId = trim((string) post('student_id'));
        $department = trim((string) post('department'));
        $organization = trim((string) post('organization'));
        $phone = trim((string) post('phone'));

        if (mb_strlen($firstName) < 2 || mb_strlen($firstName) > 80) {
            $profileErrors[] = 'Please enter a valid first name.';
        }
        if (mb_strlen($lastName) < 2 || mb_strlen($lastName) > 80) {
            $profileErrors[] = 'Please enter a valid last name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $profileErrors[] = 'Please enter a valid email address.';
        }
        if (mb_strlen($department) < 2) {
            $profileErrors[] = 'Please enter your college, department, or office.';
        }

        if ($profileErrors === []) {
            $statement = db()->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id <> :id');
            $statement->execute(['email' => $email, 'id' => $userId]);
            if ((int) $statement->fetchColumn() > 0) {
                $profileErrors[] = 'That email address is already used by another account.';
            }
        }

        if ($profileErrors === []) {
            $statement = db()->prepare(
                'UPDATE users
                 SET first_name = :first_name, last_name = :last_name, email = :email, student_id = :student_id,
                     department = :department, organization = :organization, phone = :phone, updated_at = NOW()
                 WHERE id = :id'
            );
            $statement->execute([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'student_id' => $studentId !== '' ? $studentId : null,
                'department' => $department,
                'organization' => $organization !== '' ? $organization : null,
                'phone' => $phone !== '' ? $phone : null,
                'id' => $userId,
            ]);

            audit_log('profile_updated', 'user', $userId);
            auth_user(true);
            flash('success', 'Your profile information has been updated.');
            redirect('profile.php');
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string) post('current_password');
        $newPassword = (string) post('new_password');
        $confirmPassword = (string) post('confirm_password');

        if (!password_verify($currentPassword, $user['password_hash'])) {
            $passwordErrors[] = 'Your current password is incorrect.';
        }
        if (mb_strlen($newPassword) < 8) {
            $passwordErrors[] = 'The new password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
            $passwordErrors[] = 'The new password must include uppercase and lowercase letters and at least one number.';
        }
        if ($newPassword !== $confirmPassword) {
            $passwordErrors[] = 'The new password confirmation does not match.';
        }
        if ($currentPassword !== '' && $currentPassword === $newPassword) {
            $passwordErrors[] = 'The new password must be different from your current password.';
        }

        if ($passwordErrors === []) {
            $statement = db()->prepare('UPDATE users SET password_hash = :password_hash, updated_at = NOW() WHERE id = :id');
            $statement->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);

            audit_log('password_changed', 'user', $userId);
            flash('success', 'Your password has been changed successfully.');
            redirect('profile.php');
        }
    }
}

$user = auth_user(true);
$countStatement = db()->prepare('SELECT status, COUNT(*) AS total FROM reservations WHERE user_id = :user_id GROUP BY status');
$countStatement->execute(['user_id' => $userId]);
$reservationCounts = [];
foreach ($countStatement->fetchAll() as $row) {
    $reservationCounts[$row['status']] = (int) $row['total'];
}

$pageTitle = 'My Profile';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>My profile</h1>
        <p>Manage your personal information, contact details, and account security.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('my_reservations.php')) ?>"><?= icon('clipboard-list') ?> My reservations</a>
    </div>
</div>

<div class="grid grid-1-2">
    <div class="calendar-side">
        <div class="card card-pad">
            <div class="profile-header">
                <span class="avatar avatar-xl"><?= e(initials($user['first_name'], $user['last_name'])) ?></span>
                <div>
                    <h2><?= e(full_name($user)) ?></h2>
                    <p><?= e($user['email']) ?></p>
                    <span class="badge <?= $user['role'] === 'system_admin' ? 'badge-role-system' : ($user['role'] === 'gym_admin' ? 'badge-role-gym' : 'badge-role') ?>"><?= e(role_label($user['role'])) ?></span>
                </div>
            </div>
            <div class="mt-3">
                <div class="detail-stat"><span>Account status</span><strong><span class="status-indicator active"><span class="dot"></span>Active</span></strong></div>
                <div class="detail-stat"><span>Department / office</span><strong><?= e($user['department'] ?: '—') ?></strong></div>
                <div class="detail-stat"><span>Organization</span><strong><?= e($user['organization'] ?: '—') ?></strong></div>
                <div class="detail-stat"><span>Student / employee ID</span><strong><?= e($user['student_id'] ?: '—') ?></strong></div>
                <div class="detail-stat"><span>Member since</span><strong><?= e(format_date($user['created_at'])) ?></strong></div>
                <div class="detail-stat"><span>Last sign-in</span><strong><?= e(format_datetime($user['last_login_at'])) ?></strong></div>
            </div>
        </div>

        <div class="card card-pad">
            <h2 style="font-size:15px;margin-bottom:14px;">Reservation summary</h2>
            <div class="detail-stat"><span>Total requests</span><strong><?= array_sum($reservationCounts) ?></strong></div>
            <div class="detail-stat"><span>Approved</span><strong class="text-success"><?= (int) ($reservationCounts['approved'] ?? 0) ?></strong></div>
            <div class="detail-stat"><span>Pending</span><strong class="text-warning"><?= (int) ($reservationCounts['pending'] ?? 0) ?></strong></div>
            <div class="detail-stat"><span>Rejected</span><strong class="text-danger"><?= (int) ($reservationCounts['rejected'] ?? 0) ?></strong></div>
            <div class="detail-stat"><span>Cancelled</span><strong><?= (int) ($reservationCounts['cancelled'] ?? 0) ?></strong></div>
        </div>
    </div>

    <div>
        <div class="card">
            <div class="card-header">
                <div>
                    <h2>Personal information</h2>
                    <p>Keep your contact details accurate so the gym office can reach you.</p>
                </div>
            </div>
            <div class="card-body">
                <?php if ($profileErrors !== []): ?>
                    <div class="error-summary"><strong><?= icon('alert-triangle') ?> Could not update profile</strong><ul><?php foreach ($profileErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="first_name">First name</label>
                            <input class="form-control" type="text" id="first_name" name="first_name" value="<?= e($user['first_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="last_name">Last name</label>
                            <input class="form-control" type="text" id="last_name" name="last_name" value="<?= e($user['last_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="email">Email address</label>
                            <input class="form-control" type="email" id="email" name="email" value="<?= e($user['email']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="student_id">Student / employee ID</label>
                            <input class="form-control" type="text" id="student_id" name="student_id" value="<?= e($user['student_id']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="department">College / department / office</label>
                            <input class="form-control" type="text" id="department" name="department" value="<?= e($user['department']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="organization">Organization</label>
                            <input class="form-control" type="text" id="organization" name="organization" value="<?= e($user['organization']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="phone">Mobile number</label>
                            <input class="form-control" type="tel" id="phone" name="phone" value="<?= e($user['phone']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Account role</label>
                            <input class="form-control" type="text" value="<?= e(role_label($user['role'])) ?>" readonly>
                            <span class="form-hint">Roles are assigned by the system administrator.</span>
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header">
                <div>
                    <h2>Account security</h2>
                    <p>Use a strong password that you do not use on other websites.</p>
                </div>
            </div>
            <div class="card-body">
                <?php if ($passwordErrors !== []): ?>
                    <div class="error-summary"><strong><?= icon('alert-triangle') ?> Could not change password</strong><ul><?php foreach ($passwordErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="change_password">
                    <div class="form-grid">
                        <div class="form-group full">
                            <label for="current_password">Current password</label>
                            <input class="form-control" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                        </div>
                        <div class="form-group">
                            <label for="new_password">New password</label>
                            <input class="form-control" type="password" id="new_password" name="new_password" autocomplete="new-password" required>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm new password</label>
                            <input class="form-control" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" required>
                        </div>
                    </div>
                    <p class="form-hint mt-2">Minimum 8 characters with uppercase and lowercase letters and at least one number.</p>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-secondary"><?= icon('lock') ?> Change password</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
