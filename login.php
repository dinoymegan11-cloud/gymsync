<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$authRedirect = auth_redirect_target();
require_guest($authRedirect);

$errors = [];
$email = '';

if (request_method() === 'POST') {
    verify_csrf();
    $email = strtolower(trim((string) post('email')));
    $password = (string) post('password');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if ($errors === []) {
        $statement = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute(['email' => $email]);
        $account = $statement->fetch();

        if (!$account || !password_verify($password, $account['password_hash'])) {
            $errors[] = 'The email address or password is incorrect.';
            audit_log('login_failed', 'user', $account ? (int) $account['id'] : null, ['email' => $email]);
        } elseif ($account['status'] !== 'active') {
            $errors[] = 'This account has been deactivated. Please contact the system administrator.';
            audit_log('login_blocked_inactive', 'user', (int) $account['id']);
        } else {
            login_user($account);
            flash('success', 'Welcome back, ' . $account['first_name'] . '.');
            redirect($authRedirect);
        }
    }
}

$authPage = true;
$pageTitle = 'Sign in';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-title">
    <a class="brand mobile-brand" href="<?= e(url('login.php')) ?>">
        <span class="brand-mark brand-logo"><img src="<?= e(url('assets/logo.png?v=3')) ?>" alt="Cebu Technological University logo"></span>
        <span class="brand-copy"><strong>Gym<span>Sync</span></strong><small>Campus gym portal</small></span>
    </a>
    <h2>Welcome back </h2>
    <p>Sign in to book your time, check availability, and manage your gym reservations with ease.</p>
</div>

<?php if ($errors !== []): ?>
    <div class="error-summary">
        <strong><?= icon('alert-triangle') ?> Sign-in unsuccessful</strong>
        <ul>
            <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" class="auth-form" novalidate>
    <?= csrf_field() ?>
    <?php if ($authRedirect === 'reserve.php'): ?><input type="hidden" name="next" value="reserve.php"><?php endif; ?>
    <div class="form-group">
        <label for="email">Email address</label>
        <div class="input-icon">
            <?= icon('mail') ?>
            <input class="form-control" type="email" id="email" name="email" value="<?= e($email) ?>" placeholder="you@ctu.edu.ph" autocomplete="email" required autofocus>
        </div>
    </div>
    <div class="form-group">
        <label for="login-password">Password</label>
        <div class="input-icon">
            <?= icon('lock') ?>
            <input class="form-control" type="password" id="login-password" name="password" placeholder="Enter your password" autocomplete="current-password" required style="padding-right: 42px;">
            <button type="button" class="password-toggle" data-toggle-password="#login-password" aria-label="Show password"><?= icon('eye') ?></button>
        </div>
    </div>
    <button type="submit" class="btn btn-primary btn-lg btn-block"><?= icon('arrow-right') ?> Sign in</button>
</form>

<div class="auth-divider">New to GymSync?</div>
<p class="auth-register-link">Create an account to submit and monitor reservation requests. <a href="<?= e(url('register.php' . ($authRedirect === 'reserve.php' ? '?next=reserve.php' : ''))) ?>">Register here</a></p>

<p class="auth-terms text-center">By signing in, you agree to use the gymnasium in accordance with university policies and approved reservation schedules.</p>
<?php require __DIR__ . '/includes/footer.php'; ?>
