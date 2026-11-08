<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

$currentUser = require_roles(['system_admin']);
$errors = [];
$editing = false;
$editUser = null;

$form = [
    'id' => 0,
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'student_id' => '',
    'role' => 'student',
    'department' => '',
    'organization' => '',
    'phone' => '',
    'status' => 'active',
];

$allowedRoles = ['student', 'teacher', 'visitor', 'gym_admin', 'system_admin'];
$allowedStatuses = ['active', 'inactive'];

function active_system_admin_count(int $excludingId = 0): int
{
    $statement = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'system_admin' AND status = 'active' AND id != :id");
    $statement->execute(['id' => $excludingId]);

    return (int) $statement->fetchColumn();
}

function validate_password_value(string $password, string $confirmation): array
{
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = 'The password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'The password must include at least one letter and one number.';
    }
    if ($password !== $confirmation) {
        $errors[] = 'The password confirmation does not match.';
    }

    return $errors;
}

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action');
    $targetId = (int) post('user_id');

    if ($action === 'toggle_status') {
        $statement = db()->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $targetId]);
        $target = $statement->fetch();

        if (!$target) {
            flash('danger', 'The selected account could not be found.');
        } elseif ((int) $target['id'] === (int) $currentUser['id']) {
            flash('danger', 'You cannot deactivate your own account.');
        } else {
            $newStatus = $target['status'] === 'active' ? 'inactive' : 'active';
            if ($target['role'] === 'system_admin' && $newStatus === 'inactive' && active_system_admin_count((int) $target['id']) === 0) {
                flash('danger', 'At least one active system administrator must remain.');
            } else {
                $update = db()->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
                $update->execute(['status' => $newStatus, 'id' => (int) $target['id']]);
                audit_log('user_status_changed', 'user', (int) $target['id'], ['from' => $target['status'], 'to' => $newStatus]);
                notify_user(
                    (int) $target['id'],
                    'Account status updated',
                    'Your GymSync account has been ' . ($newStatus === 'active' ? 'reactivated' : 'deactivated') . ' by a system administrator.',
                    $newStatus === 'active' ? 'success' : 'warning'
                );
                flash('success', 'Account ' . ($newStatus === 'active' ? 'reactivated' : 'deactivated') . ' successfully.');
            }
        }

        redirect('admin/users.php');
    }

    if ($action === 'reset_password') {
        $statement = db()->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $targetId]);
        $target = $statement->fetch();
        $newPassword = (string) post('new_password');
        $confirmation = (string) post('confirm_password');

        if (!$target) {
            flash('danger', 'The selected account could not be found.');
            redirect('admin/users.php');
        }

        $errors = validate_password_value($newPassword, $confirmation);
        if ($errors === []) {
            $update = db()->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
            $update->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => (int) $target['id']]);
            audit_log('user_password_reset', 'user', (int) $target['id']);
            notify_user(
                (int) $target['id'],
                'Your password was reset',
                'A system administrator reset the password for your GymSync account. Sign in with the new password provided to you.',
                'warning'
            );
            flash('success', 'Password reset successfully for ' . full_name($target) . '.');
            redirect('admin/users.php?edit=' . (int) $target['id']);
        }

        $editing = true;
        $editUser = $target;
        $form = [
            'id' => (int) $target['id'],
            'first_name' => $target['first_name'],
            'last_name' => $target['last_name'],
            'email' => $target['email'],
            'student_id' => $target['student_id'] ?? '',
            'role' => $target['role'],
            'department' => $target['department'] ?? '',
            'organization' => $target['organization'] ?? '',
            'phone' => $target['phone'] ?? '',
            'status' => $target['status'],
        ];
    }

    if ($action === 'save') {
        $form = [
            'id' => $targetId,
            'first_name' => trim((string) post('first_name')),
            'last_name' => trim((string) post('last_name')),
            'email' => strtolower(trim((string) post('email'))),
            'student_id' => trim((string) post('student_id')),
            'role' => (string) post('role', 'student'),
            'department' => trim((string) post('department')),
            'organization' => trim((string) post('organization')),
            'phone' => trim((string) post('phone')),
            'status' => (string) post('status', 'active'),
        ];

        if (mb_strlen($form['first_name']) < 2) {
            $errors[] = 'Please enter the first name.';
        }
        if (mb_strlen($form['last_name']) < 2) {
            $errors[] = 'Please enter the last name.';
        }
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!in_array($form['role'], $allowedRoles, true)) {
            $errors[] = 'Please choose a valid account role.';
        }
        if (!in_array($form['status'], $allowedStatuses, true)) {
            $errors[] = 'Please choose a valid account status.';
        }

        $existing = null;
        if ($form['id'] > 0) {
            $statement = db()->prepare('SELECT * FROM users WHERE id = :id');
            $statement->execute(['id' => $form['id']]);
            $existing = $statement->fetch();
            if (!$existing) {
                $errors[] = 'The account you are editing could not be found.';
            }
        }

        $duplicate = db()->prepare('SELECT COUNT(*) FROM users WHERE email = :email AND id != :id');
        $duplicate->execute(['email' => $form['email'], 'id' => $form['id']]);
        if ((int) $duplicate->fetchColumn() > 0) {
            $errors[] = 'Another account already uses this email address.';
        }

        if ($existing && (int) $existing['id'] === (int) $currentUser['id']) {
            if ($form['role'] !== 'system_admin') {
                $errors[] = 'You cannot change your own system administrator role.';
            }
            if ($form['status'] !== 'active') {
                $errors[] = 'You cannot deactivate your own account.';
            }
        }

        if ($existing && $existing['role'] === 'system_admin' && ($form['role'] !== 'system_admin' || $form['status'] !== 'active') && active_system_admin_count((int) $existing['id']) === 0) {
            $errors[] = 'At least one active system administrator must remain.';
        }

        if ($form['id'] === 0) {
            $errors = array_merge($errors, validate_password_value((string) post('password'), (string) post('confirm_password')));
        }

        if ($errors === [] && $existing) {
            $update = db()->prepare(
                'UPDATE users
                 SET first_name = :first_name, last_name = :last_name, email = :email, student_id = :student_id,
                     role = :role, department = :department, organization = :organization, phone = :phone,
                     status = :status, updated_at = NOW()
                 WHERE id = :id'
            );
            $update->execute([
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'email' => $form['email'],
                'student_id' => $form['student_id'] !== '' ? $form['student_id'] : null,
                'role' => $form['role'],
                'department' => $form['department'] !== '' ? $form['department'] : null,
                'organization' => $form['organization'] !== '' ? $form['organization'] : null,
                'phone' => $form['phone'] !== '' ? $form['phone'] : null,
                'status' => $form['status'],
                'id' => (int) $existing['id'],
            ]);
            audit_log('user_updated', 'user', (int) $existing['id'], [
                'name' => $form['first_name'] . ' ' . $form['last_name'],
                'email' => $form['email'],
                'role' => $form['role'],
                'status' => $form['status'],
            ]);
            flash('success', 'The account was updated successfully.');
            redirect('admin/users.php');
        }

        if ($errors === []) {
            $insert = db()->prepare(
                'INSERT INTO users (first_name, last_name, email, student_id, password_hash, role, department, organization, phone, status, created_at, updated_at)
                 VALUES (:first_name, :last_name, :email, :student_id, :password_hash, :role, :department, :organization, :phone, :status, NOW(), NOW())'
            );
            $insert->execute([
                'first_name' => $form['first_name'],
                'last_name' => $form['last_name'],
                'email' => $form['email'],
                'student_id' => $form['student_id'] !== '' ? $form['student_id'] : null,
                'password_hash' => password_hash((string) post('password'), PASSWORD_DEFAULT),
                'role' => $form['role'],
                'department' => $form['department'] !== '' ? $form['department'] : null,
                'organization' => $form['organization'] !== '' ? $form['organization'] : null,
                'phone' => $form['phone'] !== '' ? $form['phone'] : null,
                'status' => $form['status'],
            ]);
            $newId = (int) db()->lastInsertId();
            audit_log('user_created', 'user', $newId, ['name' => $form['first_name'] . ' ' . $form['last_name'], 'email' => $form['email'], 'role' => $form['role']]);
            notify_user($newId, 'Welcome to GymSync', 'Your GymSync account has been created by a system administrator. You can now sign in and reserve the campus gymnasium.', 'success');
            flash('success', 'The new account was created successfully.');
            redirect('admin/users.php');
        }

        if ($form['id'] > 0) {
            $editing = true;
            $editUser = $existing;
        }
    }
}

if (request_method() === 'GET' && (int) query('edit', 0) > 0) {
    $statement = db()->prepare('SELECT * FROM users WHERE id = :id');
    $statement->execute(['id' => (int) query('edit')]);
    $editUser = $statement->fetch();

    if ($editUser) {
        $editing = true;
        $form = [
            'id' => (int) $editUser['id'],
            'first_name' => $editUser['first_name'],
            'last_name' => $editUser['last_name'],
            'email' => $editUser['email'],
            'student_id' => $editUser['student_id'] ?? '',
            'role' => $editUser['role'],
            'department' => $editUser['department'] ?? '',
            'organization' => $editUser['organization'] ?? '',
            'phone' => $editUser['phone'] ?? '',
            'status' => $editUser['status'],
        ];
    }
}

$roleFilter = (string) query('role', '');
if (!in_array($roleFilter, $allowedRoles, true)) {
    $roleFilter = '';
}
$statusFilter = (string) query('status', '');
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
$search = trim((string) query('search', ''));
$page = max(1, (int) query('page', 1));
$perPage = 12;

$where = ['1 = 1'];
$params = [];
if ($roleFilter !== '') {
    $where[] = 'u.role = :role';
    $params['role'] = $roleFilter;
}
if ($statusFilter !== '') {
    $where[] = 'u.status = :status';
    $params['status'] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(CONCAT(u.first_name, " ", u.last_name) LIKE :search_name OR u.email LIKE :search_email OR u.student_id LIKE :search_id OR u.department LIKE :search_department OR u.organization LIKE :search_organization)';
    $params['search_name'] = '%' . $search . '%';
    $params['search_email'] = '%' . $search . '%';
    $params['search_id'] = '%' . $search . '%';
    $params['search_department'] = '%' . $search . '%';
    $params['search_organization'] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

$countStatement = db()->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql");
$countStatement->execute($params);
$total = (int) $countStatement->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$usersStatement = db()->prepare(
    "SELECT u.*,
            (SELECT COUNT(*) FROM reservations r WHERE r.user_id = u.id) AS reservation_count
     FROM users u
     WHERE $whereSql
     ORDER BY CASE u.status WHEN 'active' THEN 0 ELSE 1 END,
              CASE u.role WHEN 'system_admin' THEN 0 WHEN 'gym_admin' THEN 1 WHEN 'teacher' THEN 2 ELSE 3 END,
              u.last_name ASC, u.first_name ASC
     LIMIT $perPage OFFSET $offset"
);
$usersStatement->execute($params);
$users = $usersStatement->fetchAll();

$accountStats = db()->query(
    "SELECT COUNT(*) AS total,
            SUM(status = 'active') AS active,
            SUM(status = 'inactive') AS inactive,
            SUM(role IN ('gym_admin', 'system_admin')) AS admins
     FROM users"
)->fetch();

$hasFilters = $roleFilter !== '' || $statusFilter !== '' || $search !== '';
$pageTitle = 'Users & Roles';
$activeNav = 'admin-users';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Users & roles</h1>
        <p>Manage campus accounts, permissions, and access to GymSync.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('admin/audit.php')) ?>"><?= icon('history') ?> Audit logs</a>
        <?php if ($editing): ?><a class="btn btn-primary" href="<?= e(url('admin/users.php')) ?>"><?= icon('plus-circle') ?> Add new user</a><?php endif; ?>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card stat-blue"><div class="stat-top"><span class="stat-label">Total accounts</span><span class="stat-icon"><?= icon('users') ?></span></div><p class="stat-value"><?= (int) ($accountStats['total'] ?? 0) ?></p><div class="stat-meta"><?= icon('user') ?> Registered GymSync users</div></div>
    <div class="stat-card stat-green"><div class="stat-top"><span class="stat-label">Active accounts</span><span class="stat-icon"><?= icon('user-check') ?></span></div><p class="stat-value"><?= (int) ($accountStats['active'] ?? 0) ?></p><div class="stat-meta"><?= icon('check-circle') ?> Able to sign in</div></div>
    <div class="stat-card stat-amber"><div class="stat-top"><span class="stat-label">Administrators</span><span class="stat-icon"><?= icon('shield') ?></span></div><p class="stat-value"><?= (int) ($accountStats['admins'] ?? 0) ?></p><div class="stat-meta"><?= icon('lock') ?> Gym and system admins</div></div>
</div>

<div class="grid grid-1-2">
    <div class="card">
        <div class="card-header">
            <div>
                <h2><?= $editing ? 'Edit account' : 'Create an account' ?></h2>
                <p><?= $editing ? 'Update account details and permissions.' : 'Add a student, faculty, or administrator account.' ?></p>
            </div>
            <?php if ($editing): ?><a class="link-button" href="<?= e(url('admin/users.php')) ?>">Cancel edit</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($errors !== []): ?>
                <div class="error-summary">
                    <strong><?= icon('alert-triangle') ?> Could not save the account</strong>
                    <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="user_id" value="<?= (int) $form['id'] ?>">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="first_name">First name <span class="required">*</span></label>
                        <input class="form-control" type="text" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" maxlength="80" required>
                    </div>
                    <div class="form-group">
                        <label for="last_name">Last name <span class="required">*</span></label>
                        <input class="form-control" type="text" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" maxlength="80" required>
                    </div>
                    <div class="form-group full">
                        <label for="email">Email address <span class="required">*</span></label>
                        <input class="form-control" type="email" id="email" name="email" value="<?= e($form['email']) ?>" maxlength="160" required>
                    </div>
                    <div class="form-group">
                        <label for="role">Role <span class="required">*</span></label>
                        <select class="form-control" id="role" name="role" required>
                            <?php foreach ($allowedRoles as $role): ?>
                                <option value="<?= e($role) ?>"<?= option_selected($form['role'], $role) ?>><?= e(role_label($role)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="status">Account status <span class="required">*</span></label>
                        <select class="form-control" id="status" name="status" required>
                            <option value="active"<?= option_selected($form['status'], 'active') ?>>Active</option>
                            <option value="inactive"<?= option_selected($form['status'], 'inactive') ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="student_id">Student / employee ID</label>
                        <input class="form-control" type="text" id="student_id" name="student_id" value="<?= e($form['student_id']) ?>" maxlength="50">
                    </div>
                    <div class="form-group">
                        <label for="phone">Phone number</label>
                        <input class="form-control" type="text" id="phone" name="phone" value="<?= e($form['phone']) ?>" maxlength="30">
                    </div>
                    <div class="form-group full">
                        <label for="department">Department</label>
                        <input class="form-control" type="text" id="department" name="department" value="<?= e($form['department']) ?>" maxlength="120">
                    </div>
                    <div class="form-group full">
                        <label for="organization">Organization</label>
                        <input class="form-control" type="text" id="organization" name="organization" value="<?= e($form['organization']) ?>" maxlength="160">
                    </div>
                    <?php if (!$editing): ?>
                        <div class="form-group">
                            <label for="password">Temporary password <span class="required">*</span></label>
                            <div class="input-with-action">
                                <input class="form-control" type="password" id="password" name="password" minlength="8" required>
                                <button type="button" class="input-action" data-toggle-password="#password" aria-label="Show password"><?= icon('eye') ?></button>
                            </div>
                            <span class="form-hint">At least 8 characters with letters and numbers.</span>
                        </div>
                        <div class="form-group">
                            <label for="confirm_password">Confirm password <span class="required">*</span></label>
                            <input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" required>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="form-actions">
                    <?php if ($editing): ?><a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Cancel</a><?php endif; ?>
                    <button type="submit" class="btn btn-primary"><?= icon($editing ? 'check' : 'plus-circle') ?> <?= $editing ? 'Save account' : 'Create account' ?></button>
                </div>
            </form>

            <?php if ($editing && $editUser): ?>
                <hr style="border:0;border-top:1px solid var(--border);margin:22px 0;">
                <h3 style="font-size:14px;margin-bottom:6px;">Reset password</h3>
                <p class="text-small text-muted mb-2">Provide the new password directly to <?= e(full_name($editUser)) ?>. It is not shown again.</p>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="user_id" value="<?= (int) $editUser['id'] ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="new_password">New password</label>
                            <input class="form-control" type="password" id="new_password" name="new_password" minlength="8" required>
                        </div>
                        <div class="form-group">
                            <label for="reset_confirm_password">Confirm new password</label>
                            <input class="form-control" type="password" id="reset_confirm_password" name="confirm_password" minlength="8" required>
                        </div>
                    </div>
                    <div class="form-actions"><button type="submit" class="btn btn-secondary" data-confirm="Reset this user's password?"><?= icon('lock') ?> Reset password</button></div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div>
        <form method="get" class="filter-bar">
            <div class="search-field"><?= icon('search') ?><input class="form-control" type="search" name="search" value="<?= e($search) ?>" placeholder="Search name, email, ID, department"></div>
            <div class="form-group">
                <label for="role-filter">Role</label>
                <select class="form-control" id="role-filter" name="role">
                    <option value="">All roles</option>
                    <?php foreach ($allowedRoles as $role): ?><option value="<?= e($role) ?>"<?= option_selected($roleFilter, $role) ?>><?= e(role_short_label($role)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="status-filter">Status</label>
                <select class="form-control" id="status-filter" name="status">
                    <option value="">All</option>
                    <option value="active"<?= option_selected($statusFilter, 'active') ?>>Active</option>
                    <option value="inactive"<?= option_selected($statusFilter, 'inactive') ?>>Inactive</option>
                </select>
            </div>
            <button type="submit" class="btn btn-secondary"><?= icon('filter') ?> Apply</button>
            <?php if ($hasFilters): ?><a class="btn btn-ghost" href="<?= e(url('admin/users.php')) ?>">Clear</a><?php endif; ?>
        </form>

        <div class="card">
            <div class="card-header"><div><h2>Accounts</h2><p><?= number_format($total) ?> account<?= $total === 1 ? '' : 's' ?> found.</p></div></div>
            <?php if ($users === []): ?>
                <div class="empty-state compact">
                    <div class="empty-icon"><?= icon('users') ?></div>
                    <h3>No accounts found</h3>
                    <p>Try a different filter or create a new account.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>User</th><th>Role</th><th>Activity</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $listUser): ?>
                            <?php
                            $roleClass = in_array($listUser['role'], ['system_admin', 'gym_admin'], true) ? 'badge-role' : 'badge-neutral';
                            ?>
                            <tr>
                                <td>
                                    <div class="user-cell">
                                        <span class="avatar"><?= e(initials($listUser['first_name'], $listUser['last_name'])) ?></span>
                                        <div>
                                            <strong><?= e(full_name($listUser)) ?><?= (int) $listUser['id'] === (int) $currentUser['id'] ? ' <span class="text-muted">(you)</span>' : '' ?></strong>
                                            <small><?= e($listUser['email']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge <?= e($roleClass) ?>"><?= e(role_short_label($listUser['role'])) ?></span>
                                    <span class="subtext"><?= e($listUser['department'] ?: $listUser['organization'] ?: '—') ?></span>
                                </td>
                                <td>
                                    <span class="primary-cell"><?= number_format((int) $listUser['reservation_count']) ?> reservation<?= (int) $listUser['reservation_count'] === 1 ? '' : 's' ?></span>
                                    <span class="subtext">Last login: <?= e($listUser['last_login_at'] ? time_ago($listUser['last_login_at']) : 'Never') ?></span>
                                </td>
                                <td><span class="status-indicator <?= $listUser['status'] === 'active' ? 'active' : 'inactive' ?>"><span class="dot"></span><?= e(ucfirst($listUser['status'])) ?></span></td>
                                <td>
                                    <div class="table-actions">
                                        <a class="table-icon-button" href="<?= e(url('admin/users.php?edit=' . $listUser['id'])) ?>" title="Edit account"><?= icon('pencil') ?></a>
                                        <?php if ((int) $listUser['id'] !== (int) $currentUser['id']): ?>
                                            <form method="post" data-confirm="<?= $listUser['status'] === 'active' ? 'Deactivate' : 'Reactivate' ?> this account?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="toggle_status">
                                                <input type="hidden" name="user_id" value="<?= (int) $listUser['id'] ?>">
                                                <button type="submit" class="table-icon-button <?= $listUser['status'] === 'active' ? 'danger' : '' ?>" title="<?= $listUser['status'] === 'active' ? 'Deactivate account' : 'Reactivate account' ?>"><?= icon($listUser['status'] === 'active' ? 'ban' : 'user-check') ?></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
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
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
