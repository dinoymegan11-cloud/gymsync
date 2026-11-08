<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$authRedirect = auth_redirect_target();
require_guest($authRedirect);

$registrationOpen = (string) setting('allow_registration', '1') === '1';
$errors = [];
$form = [
    'first_name' => '',
    'last_name' => '',
    'email' => '',
    'student_id' => '',
    'role' => 'student',
    'department' => '',
    'organization' => '',
    'phone' => '',
];

if (request_method() === 'POST') {
    verify_csrf();

    if (!$registrationOpen) {
        $errors[] = 'Public registration is currently disabled. Please contact the system administrator.';
    }

    foreach ($form as $key => $value) {
        if ($key !== 'role') {
            $form[$key] = trim((string) post($key));
        }
    }
    $form['role'] = (string) post('role', 'student');

    $password = (string) post('password');
    $passwordConfirmation = (string) post('password_confirmation');

    if (mb_strlen($form['first_name']) < 2 || mb_strlen($form['first_name']) > 80) {
        $errors[] = 'Please enter a valid first name.';
    }
    if (mb_strlen($form['last_name']) < 2 || mb_strlen($form['last_name']) > 80) {
        $errors[] = 'Please enter a valid last name.';
    }
    if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (!in_array($form['role'], ['student', 'teacher', 'visitor'], true)) {
        $errors[] = 'Please choose a valid account type.';
    }
    if ($form['role'] !== 'visitor' && mb_strlen($form['department']) < 2) {
        $errors[] = 'Please enter your college, department, or office.';
    }
    if (mb_strlen($password) < 8) {
        $errors[] = 'Your password must be at least 8 characters long.';
    } elseif (!preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $errors[] = 'Your password must include uppercase and lowercase letters and at least one number.';
    }
    if ($password !== $passwordConfirmation) {
        $errors[] = 'The password confirmation does not match.';
    }
    if (!post('terms')) {
        $errors[] = 'Please confirm that you will follow the gymnasium reservation policies.';
    }

    if ($errors === []) {
        $statement = db()->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
        $statement->execute(['email' => strtolower($form['email'])]);
        if ((int) $statement->fetchColumn() > 0) {
            $errors[] = 'An account with this email address already exists.';
        }
    }

    if ($errors === []) {
        $statement = db()->prepare(
            'INSERT INTO users
                (first_name, last_name, email, student_id, password_hash, role, department, organization, phone, status, created_at, updated_at)
             VALUES
                (:first_name, :last_name, :email, :student_id, :password_hash, :role, :department, :organization, :phone, :status, NOW(), NOW())'
        );
        $statement->execute([
            'first_name' => $form['first_name'],
            'last_name' => $form['last_name'],
            'email' => strtolower($form['email']),
            'student_id' => $form['student_id'] !== '' ? $form['student_id'] : null,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $form['role'],
            'department' => $form['department'],
            'organization' => $form['organization'] !== '' ? $form['organization'] : null,
            'phone' => $form['phone'] !== '' ? $form['phone'] : null,
            'status' => 'active',
        ]);

        $newUserId = (int) db()->lastInsertId();
        $statement = db()->prepare('SELECT * FROM users WHERE id = :id');
        $statement->execute(['id' => $newUserId]);
        $newUser = $statement->fetch();

        audit_log('account_registered', 'user', $newUserId, [
            'email' => $form['email'],
            'role' => $form['role'],
        ], $newUserId);

        notify_user(
            $newUserId,
            'Welcome to GymSync',
            'Your account is ready. You can now check gym availability and submit reservation requests.',
            'success',
            url('index.php')
        );

        notify_admins(
            'New user registered',
            full_name($newUser) . ' created a ' . role_short_label($form['role']) . ' account using ' . $form['email'] . '.',
            'info',
            url('admin/users.php')
        );

        login_user($newUser);
        flash('success', 'Your account was created successfully. Welcome to GymSync!');
        redirect($authRedirect);
    }
}

$authPage = true;
$pageTitle = 'Create account';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-title">
    <a class="brand mobile-brand" href="<?= e(url('login.php')) ?>">
        <span class="brand-mark brand-logo"><img src="<?= e(url('assets/logo.png?v=3')) ?>" alt="Cebu Technological University logo"></span>
        <span class="brand-copy"><strong>Gym<span>Sync</span></strong><small>Campus gym portal</small></span>
    </a>
    <h2>Join GymSync </h2>
    <p>Create an account to easily book the gym, manage reservations, and stay updated on approvals.</p>
</div>

<?php if (!$registrationOpen): ?>
    <div class="notice notice-warning mb-3">
        <?= icon('lock') ?>
        <div><strong>Registration is closed.</strong><br>New accounts are currently being managed by the system administrator. Please contact the gymnasium office for assistance.</div>
    </div>
    <a class="btn btn-secondary btn-block" href="<?= e(url('login.php')) ?>"><?= icon('arrow-left') ?> Back to sign in</a>
<?php else: ?>
    <?php if ($errors !== []): ?>
        <div class="error-summary">
            <strong><?= icon('alert-triangle') ?> Please correct the following</strong>
            <ul>
                <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <?php if ($authRedirect === 'reserve.php'): ?><input type="hidden" name="next" value="reserve.php"><?php endif; ?>
        <div class="form-grid">
            <div class="form-group">
                <label for="first_name">First name</label>
                <input class="form-control" type="text" id="first_name" name="first_name" value="<?= e($form['first_name']) ?>" autocomplete="given-name" required>
            </div>
            <div class="form-group">
                <label for="last_name">Last name</label>
                <input class="form-control" type="text" id="last_name" name="last_name" value="<?= e($form['last_name']) ?>" autocomplete="family-name" required>
            </div>
        </div>
        <div class="form-group">
            <label for="reg-email">Email address</label>
            <div class="input-icon">
                <?= icon('mail') ?>
                <input class="form-control" type="email" id="reg-email" name="email" value="<?= e($form['email']) ?>" placeholder="you@ctu.edu.ph" autocomplete="email" required>
            </div>
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label for="role">Account type</label>
                <select class="form-control" id="role" name="role" required>
                    <option value="student"<?= option_selected($form['role'], 'student') ?>>Student / Organization Representative</option>
                    <option value="teacher"<?= option_selected($form['role'], 'teacher') ?>>Teacher / Faculty / Department Representative</option>
                    <option value="visitor"<?= option_selected($form['role'], 'visitor') ?>>Visitor / External User</option>
                </select>
            </div>
            <div class="form-group">
                <label for="student_id">Student / Employee / Visitor ID <span class="text-muted">(optional)</span></label>
                <input class="form-control" type="text" id="student_id" name="student_id" value="<?= e($form['student_id']) ?>" placeholder="e.g. 2023-00123 or Visitor-001">
            </div>
        </div>
        <div class="form-group">
            <label for="department">College / Department / Office <span class="text-muted"><?= $form['role'] === 'visitor' ? '(optional for visitors)' : '' ?></span></label>
            <input class="form-control" type="text" id="department" name="department" value="<?= e($form['department']) ?>" placeholder="e.g. College of Education">
        </div>
        <div class="form-group">
            <label for="organization">Organization / Affiliation <span class="text-muted"><?= $form['role'] === 'visitor' ? '(optional for visitors)' : '(optional)' ?></span></label>
            <input class="form-control" type="text" id="organization" name="organization" value="<?= e($form['organization']) ?>" placeholder="e.g. Supreme Student Council or Community Group">
        </div>
        <div class="form-group">
            <label for="phone">Mobile number <span class="text-muted">(optional)</span></label>
            <input class="form-control" type="tel" id="phone" name="phone" value="<?= e($form['phone']) ?>" placeholder="e.g. 0917 000 0000" autocomplete="tel">
        </div>
        <div class="form-grid">
            <div class="form-group">
                <label for="reg-password">Password</label>
                <input class="form-control" type="password" id="reg-password" name="password" placeholder="At least 8 characters" autocomplete="new-password" required>
            </div>
            <div class="form-group">
                <label for="reg-password-confirmation">Confirm password</label>
                <input class="form-control" type="password" id="reg-password-confirmation" name="password_confirmation" placeholder="Repeat password" autocomplete="new-password" required>
            </div>
        </div>
        <p class="form-hint">Use at least 8 characters with uppercase and lowercase letters and a number.</p>
        <label class="checkbox">
            <input type="checkbox" name="terms" value="1" required>
            <span>I will use the gymnasium responsibly and follow approved reservation schedules and university policies.</span>
        </label>
        <button type="submit" class="btn btn-primary btn-lg btn-block"><?= icon('user-check') ?> Create account</button>
    </form>
    <p class="auth-register-link">Already have an account? <a href="<?= e(url('login.php' . ($authRedirect === 'reserve.php' ? '?next=reserve.php' : ''))) ?>">Sign in</a></p>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
