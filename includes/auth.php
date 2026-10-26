<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function auth_user(bool $refresh = false): ?array
{
    static $user = null;
    static $loaded = false;

    if ($loaded && !$refresh) {
        return $user;
    }

    $loaded = true;
    $user = null;

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $statement = db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $statement->execute(['id' => (int) $_SESSION['user_id']]);
    $record = $statement->fetch();

    if (!$record || $record['status'] !== 'active') {
        unset($_SESSION['user_id']);
        return null;
    }

    $user = $record;

    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    $statement = db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $statement->execute(['id' => (int) $user['id']]);

    audit_log('login', 'user', (int) $user['id'], ['email' => $user['email']], (int) $user['id']);
}

function logout_user(): void
{
    $user = auth_user();
    if ($user) {
        audit_log('logout', 'user', (int) $user['id']);
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) ($params['secure'] ?? false), (bool) ($params['httponly'] ?? true));
    }

    session_destroy();
}

function wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

    return str_contains($accept, 'application/json') || strtolower($requestedWith) === 'xmlhttprequest';
}

function require_login(): array
{
    $user = auth_user();

    if ($user === null) {
        if (wants_json()) {
            header('Content-Type: application/json');
            http_response_code(401);
            echo json_encode(['ok' => false, 'message' => 'Authentication required.']);
            exit;
        }

        flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }

    return $user;
}

function require_guest(string $redirectPath = 'index.php'): void
{
    if (auth_user() !== null) {
        redirect($redirectPath);
    }
}

function auth_redirect_target(): string
{
    $target = (string) post('next', query('next', ''));

    return $target === 'reserve.php' ? 'reserve.php' : 'index.php';
}

function require_roles(array $roles): array
{
    $user = require_login();

    if (!in_array($user['role'], $roles, true)) {
        if (wants_json()) {
            header('Content-Type: application/json');
            http_response_code(403);
            echo json_encode(['ok' => false, 'message' => 'You do not have permission to perform this action.']);
            exit;
        }

        http_response_code(403);
        $pageTitle = 'Access denied';
        require __DIR__ . '/header.php';
        echo '<div class="empty-state card"><div class="empty-icon">' . icon('shield') . '</div><h2>Access denied</h2><p>Your account does not have permission to view this page.</p><a class="btn btn-primary" href="' . e(url('index.php')) . '">Return to dashboard</a></div>';
        require __DIR__ . '/footer.php';
        exit;
    }

    return $user;
}

function is_admin(?array $user = null): bool
{
    $user ??= auth_user();

    return $user !== null && in_array($user['role'], ['gym_admin', 'system_admin'], true);
}

function is_system_admin(?array $user = null): bool
{
    $user ??= auth_user();

    return $user !== null && $user['role'] === 'system_admin';
}
