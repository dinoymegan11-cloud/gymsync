<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return APP_BASE . '/' . ltrim($path, '/');
}

function asset_url(string $path = ''): string
{
    return url('assets/' . ltrim($path, '/'));
}

function redirect(string $path, int $status = 302): never
{
    header('Location: ' . (preg_match('#^https?://#i', $path) ? $path : url($path)), true, $status);
    exit;
}

function request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

function query(string $key, mixed $default = ''): mixed
{
    return $_GET[$key] ?? $default;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(bool $json = false): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $valid = is_string($token) && $token !== '' && hash_equals(csrf_token(), $token);

    if (!$valid) {
        if ($json) {
            header('Content-Type: application/json');
            http_response_code(419);
            echo json_encode(['ok' => false, 'message' => 'Your session has expired. Please refresh the page.']);
            exit;
        }
        http_response_code(419);
        exit('Your session has expired. Please go back, refresh the page, and try again.');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flashes'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['_flashes'] ?? [];
    unset($_SESSION['_flashes']);

    return $flashes;
}

function settings_all(bool $refresh = false): array
{
    static $settings = null;

    if ($settings === null || $refresh) {
        $settings = [];
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $exception) {
            $settings = [];
        }
    }

    return $settings;
}

function setting(string $key, mixed $default = null): mixed
{
    $settings = settings_all();

    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

function save_setting(string $key, string $value): void
{
    $statement = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    );
    $statement->execute(['key' => $key, 'value' => $value]);
    settings_all(true);
}

function current_user_id(): ?int
{
    return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
}

function audit_log(string $action, ?string $entityType = null, ?int $entityId = null, mixed $details = null, ?int $userId = null): void
{
    try {
        if (is_array($details)) {
            $details = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $statement = db()->prepare(
            'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address, created_at)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip, NOW())'
        );
        $statement->execute([
            'user_id' => $userId ?? current_user_id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
            'ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'cli'), 0, 45),
        ]);
    } catch (Throwable $exception) {
        error_log('GymSync audit log failed: ' . $exception->getMessage());
    }
}

function notify_user(int $userId, string $title, string $message, string $type = 'info', ?string $link = null): void
{
    $statement = db()->prepare(
        'INSERT INTO notifications (user_id, title, message, type, link, created_at)
         VALUES (:user_id, :title, :message, :type, :link, NOW())'
    );
    $statement->execute([
        'user_id' => $userId,
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'link' => $link,
    ]);
}

function notify_roles(array $roles, string $title, string $message, string $type = 'info', ?string $link = null): void
{
    if ($roles === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $statement = db()->prepare("SELECT id FROM users WHERE role IN ($placeholders) AND status = 'active'");
    $statement->execute(array_values($roles));

    foreach ($statement->fetchAll() as $row) {
        notify_user((int) $row['id'], $title, $message, $type, $link);
    }
}

function notify_admins(string $title, string $message, string $type = 'info', ?string $link = null): void
{
    notify_roles(['gym_admin', 'system_admin'], $title, $message, $type, $link);
}

function unread_notification_count(): int
{
    static $count = null;

    if ($count === null) {
        $userId = current_user_id();
        if ($userId === null) {
            return 0;
        }
        $statement = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :id AND is_read = 0');
        $statement->execute(['id' => $userId]);
        $count = (int) $statement->fetchColumn();
    }

    return $count;
}

function format_date(?string $date, string $format = 'M j, Y'): string
{
    if (!$date) {
        return '—';
    }
    $timestamp = strtotime($date);

    return $timestamp ? date($format, $timestamp) : '—';
}

function format_time(?string $time): string
{
    if (!$time) {
        return '—';
    }
    $timestamp = strtotime('1970-01-01 ' . $time);

    return $timestamp ? date('g:i A', $timestamp) : '—';
}

function format_time_range(?string $start, ?string $end): string
{
    return format_time($start) . ' – ' . format_time($end);
}

function format_datetime(?string $datetime, string $format = 'M j, Y g:i A'): string
{
    if (!$datetime) {
        return '—';
    }
    $timestamp = strtotime($datetime);

    return $timestamp ? date($format, $timestamp) : '—';
}

function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }

    $timestamp = strtotime($datetime);
    if (!$timestamp) {
        return '—';
    }

    $seconds = time() - $timestamp;
    if ($seconds < 60) {
        return 'Just now';
    }
    if ($seconds < 3600) {
        $minutes = (int) floor($seconds / 60);
        return $minutes . ' min ago';
    }
    if ($seconds < 86400) {
        $hours = (int) floor($seconds / 3600);
        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
    }
    if ($seconds < 604800) {
        $days = (int) floor($seconds / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    return format_date(date('Y-m-d', $timestamp));
}

function initials(string $firstName, string $lastName): string
{
    return strtoupper(mb_substr($firstName, 0, 1) . mb_substr($lastName, 0, 1));
}

function role_label(string $role): string
{
    return [
        'student' => 'Student / Organization Rep.',
        'teacher' => 'Teacher / Faculty',
        'visitor' => 'Visitor / External User',
        'gym_admin' => 'Gym Administrator',
        'system_admin' => 'System Administrator',
    ][$role] ?? ucwords(str_replace('_', ' ', $role));
}

function role_short_label(string $role): string
{
    return [
        'student' => 'Student',
        'teacher' => 'Faculty',
        'visitor' => 'Visitor',
        'gym_admin' => 'Gym Admin',
        'system_admin' => 'System Admin',
    ][$role] ?? ucwords(str_replace('_', ' ', $role));
}

function event_type_label(string $type): string
{
    return [
        'class' => 'Regular Class',
        'sports' => 'Sports Practice',
        'school_event' => 'School Event',
        'organization' => 'Organization Activity',
        'meeting' => 'Meeting',
        'other' => 'Other',
    ][$type] ?? ucwords(str_replace('_', ' ', $type));
}

function status_label(string $status): string
{
    return [
        'pending' => 'Pending review',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'completed' => 'Completed',
    ][$status] ?? ucwords($status);
}

function status_badge(string $status): string
{
    $allowed = ['pending', 'approved', 'rejected', 'cancelled', 'completed'];
    $status = in_array($status, $allowed, true) ? $status : 'cancelled';

    return '<span class="badge badge-' . e($status) . '"><span class="badge-dot"></span>' . e(status_label($status)) . '</span>';
}

function icon(string $name, string $class = ''): string
{
    $classes = trim('icon ' . $class);

    return '<svg class="' . e($classes) . '" aria-hidden="true" focusable="false"><use href="' . e(url('assets/icons.svg')) . '#icon-' . e($name) . '"></use></svg>';
}

function truncate_text(string $text, int $limit = 80): string
{
    $text = trim(strip_tags($text));
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, $limit - 1) . '…';
}

function full_name(array $user): string
{
    return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}

function setting_int(string $key, int $default = 0): int
{
    $value = setting($key, $default);

    return is_numeric($value) ? (int) $value : $default;
}

function option_selected(mixed $value, mixed $expected): string
{
    return (string) $value === (string) $expected ? ' selected' : '';
}

function checkbox_checked(mixed $value): string
{
    return $value ? ' checked' : '';
}

function pagination_url(int $page): string
{
    $params = $_GET;
    $params['page'] = $page;

    return '?' . http_build_query($params);
}
