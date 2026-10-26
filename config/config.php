<?php
declare(strict_types=1);

define('APP_NAME', 'GymSync');
define('APP_VERSION', '1.0.0');
define('APP_SUBTITLE', 'Gymnasium Reservation System');
define('APP_TIMEZONE', 'Asia/Manila');
define('DB_HOST', getenv('DB_HOST') !== false && getenv('DB_HOST') !== '' ? getenv('DB_HOST') : '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') !== false && getenv('DB_PORT') !== '' ? getenv('DB_PORT') : '3306');
define('DB_NAME', getenv('DB_NAME') !== false && getenv('DB_NAME') !== '' ? getenv('DB_NAME') : 'gymsync');
define('DB_USER', getenv('DB_USER') !== false && getenv('DB_USER') !== '' ? getenv('DB_USER') : 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('APP_DEBUG', getenv('APP_DEBUG') !== false ? filter_var(getenv('APP_DEBUG'), FILTER_VALIDATE_BOOLEAN) : true);

date_default_timezone_set(APP_TIMEZONE);

define('ROOT_PATH', dirname(__DIR__));

$documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT']) : '';
$projectRoot = str_replace('\\', '/', ROOT_PATH);
$basePath = '';

if ($documentRoot !== '' && str_starts_with($projectRoot, $documentRoot)) {
    $basePath = substr($projectRoot, strlen($documentRoot));
} elseif (isset($_SERVER['SCRIPT_NAME'])) {
    $basePath = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
    if ($basePath === '/' || $basePath === '.') {
        $basePath = '';
    }
}

define('APP_BASE', rtrim($basePath, '/'));

if (session_status() === PHP_SESSION_NONE) {
    session_name('gymsync_session');
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => APP_BASE !== '' ? APP_BASE . '/' : '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $isSecure,
    ]);
    session_start();
}

if (APP_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
}
