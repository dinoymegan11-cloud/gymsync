<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

$user = require_roles(['gym_admin', 'system_admin']);
redirect(is_system_admin($user) ? 'admin/users.php' : 'admin/reservations.php');
