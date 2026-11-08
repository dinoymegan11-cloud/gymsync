<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

header('Content-Type: application/json; charset=utf-8');

if (request_method() !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $user = require_login();
    verify_csrf(true);

    $date = trim((string) post('reservation_date'));
    $start = normalize_time((string) post('start_time'));
    $end = normalize_time((string) post('end_time'));
    $excludeId = (int) post('exclude_id', 0);

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        echo json_encode(['checked' => true, 'valid' => false, 'message' => 'Please choose a valid date.', 'blocking' => [], 'pending' => []]);
        exit;
    }

    if ($start === '' || $end === '' || time_to_minutes($end) <= time_to_minutes($start)) {
        echo json_encode(['checked' => true, 'valid' => false, 'message' => 'Please choose a valid start and end time.', 'blocking' => [], 'pending' => []]);
        exit;
    }

    $opening = normalize_time(setting('opening_time', '06:00'));
    $closing = normalize_time(setting('closing_time', '22:00'));
    if (time_to_minutes($start) < time_to_minutes($opening) || time_to_minutes($end) > time_to_minutes($closing)) {
        echo json_encode([
            'checked' => true,
            'valid' => false,
            'message' => 'The selected time is outside gym operating hours (' . format_time($opening) . ' – ' . format_time($closing) . ').',
            'blocking' => [],
            'pending' => [],
        ]);
        exit;
    }

    $validExcludeId = null;
    if ($excludeId > 0) {
        $excluded = get_reservation($excludeId);
        if ($excluded && ((int) $excluded['user_id'] === (int) $user['id'] || is_admin($user))) {
            $validExcludeId = $excludeId;
        }
    }

    $conflicts = check_conflicts($date, $start, $end, $validExcludeId);
    $isAdmin = is_admin($user);

    if (!$isAdmin) {
        foreach ($conflicts['pending'] as &$pending) {
            $pending['title'] = 'Pending gym request';
            $pending['activity_name'] = 'Pending request';
            $pending['organization'] = '';
            $pending['requester'] = '';
        }
        unset($pending);
    }

    echo json_encode([
        'checked' => true,
        'valid' => true,
        'blocking' => $conflicts['blocking'],
        'pending' => $conflicts['pending'],
        'is_admin' => $isAdmin,
        'buffer_minutes' => setting_int('buffer_minutes', 0),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'checked' => true,
        'message' => APP_DEBUG ? $exception->getMessage() : 'Unable to check availability right now.',
    ]);
}
