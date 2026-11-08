<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

if (request_method() !== 'POST') {
    redirect(auth_user() ? 'index.php' : 'login.php');
}

verify_csrf();
logout_user();
flash('success', 'You have been signed out securely.');
redirect('login.php');
