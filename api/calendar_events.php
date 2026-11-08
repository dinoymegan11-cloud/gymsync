<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $user = require_login();
    $rangeStart = substr((string) query('start', date('Y-m-01')), 0, 10);
    $rangeEnd = substr((string) query('end', date('Y-m-t')), 0, 10);

    $startObject = DateTime::createFromFormat('!Y-m-d', $rangeStart);
    $endObject = DateTime::createFromFormat('!Y-m-d', $rangeEnd);
    if (!$startObject || !$endObject) {
        throw new InvalidArgumentException('Invalid calendar date range.');
    }

    $rangeStart = $startObject->format('Y-m-d');
    $rangeEnd = $endObject->format('Y-m-d');

    $statement = db()->prepare(
        "SELECT r.id, r.reference_no, r.user_id, r.activity_name, r.organization, r.event_type,
                r.reservation_date, r.start_time, r.end_time, r.status,
                u.first_name, u.last_name
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         WHERE r.status IN ('approved', 'pending')
           AND r.reservation_date >= :range_start
           AND r.reservation_date < :range_end
         ORDER BY r.reservation_date ASC, r.start_time ASC"
    );
    $statement->execute(['range_start' => $rangeStart, 'range_end' => $rangeEnd]);
    $reservations = $statement->fetchAll();

    $events = [];

    foreach ($reservations as $reservation) {
        $isOwner = (int) $reservation['user_id'] === (int) $user['id'];
        $isAdmin = is_admin($user);
        $status = $reservation['status'];
        $canSeeDetails = $status === 'approved' || $isOwner || $isAdmin;

        if ($canSeeDetails) {
            $title = $reservation['activity_name'];
        } else {
            $title = 'Pending gym request';
        }

        if ($status === 'pending' && ($isOwner || $isAdmin)) {
            $title = 'Pending · ' . $title;
        }

        $url = null;
        if ($isAdmin) {
            $url = url('admin/reservation_view.php?id=' . $reservation['id']);
        } elseif ($isOwner) {
            $url = url('reservation_detail.php?id=' . $reservation['id']);
        }

        $events[] = [
            'id' => 'reservation-' . $reservation['id'],
            'title' => $title,
            'start' => $reservation['reservation_date'] . 'T' . $reservation['start_time'],
            'end' => $reservation['reservation_date'] . 'T' . $reservation['end_time'],
            'allDay' => false,
            'backgroundColor' => $status === 'approved' ? '#15803d' : '#d97706',
            'borderColor' => $status === 'approved' ? '#15803d' : '#d97706',
            'textColor' => '#ffffff',
            'url' => $url,
            'classNames' => [$status === 'approved' ? 'event-approved' : 'event-pending'],
            'extendedProps' => [
                'kind' => 'reservation',
                'recordId' => (int) $reservation['id'],
                'reference' => $canSeeDetails ? $reservation['reference_no'] : null,
                'status' => $status,
                'statusLabel' => status_label($status),
                'organization' => $canSeeDetails ? $reservation['organization'] : '',
                'eventType' => $canSeeDetails ? event_type_label($reservation['event_type']) : 'Pending request',
                'requester' => $canSeeDetails ? trim($reservation['first_name'] . ' ' . $reservation['last_name']) : 'Another requester',
                'startLabel' => format_time($reservation['start_time']),
                'endLabel' => format_time($reservation['end_time']),
                'dateLabel' => format_date($reservation['reservation_date'], 'l, F j, Y'),
                'url' => $url,
                'isOwner' => $isOwner,
            ],
        ];
    }

    $blockStatement = db()->prepare(
        'SELECT id, title, start_date, end_date, start_time, end_time, reason
         FROM blocked_periods
         WHERE start_date < :range_end AND end_date >= :range_start
         ORDER BY start_date ASC'
    );
    $blockStatement->execute(['range_start' => $rangeStart, 'range_end' => $rangeEnd]);
    $blocks = $blockStatement->fetchAll();

    foreach ($blocks as $block) {
        $allDay = $block['start_time'] === null || $block['end_time'] === null;
        $url = is_admin($user) ? url('admin/blocks.php?edit=' . $block['id']) : null;

        if ($allDay) {
            $endExclusive = date('Y-m-d', strtotime($block['end_date'] . ' +1 day'));
            $events[] = [
                'id' => 'block-' . $block['id'],
                'title' => 'Gym closed · ' . $block['title'],
                'start' => $block['start_date'],
                'end' => $endExclusive,
                'allDay' => true,
                'backgroundColor' => '#b91c1c',
                'borderColor' => '#b91c1c',
                'textColor' => '#ffffff',
                'url' => $url,
                'classNames' => ['event-blocked'],
                'extendedProps' => [
                    'kind' => 'block',
                    'recordId' => (int) $block['id'],
                    'status' => 'blocked',
                    'statusLabel' => 'Unavailable',
                    'organization' => 'Gym administration',
                    'eventType' => 'Maintenance / blocked period',
                    'reason' => $block['reason'],
                    'startLabel' => 'All day',
                    'endLabel' => 'All day',
                    'dateLabel' => format_date($block['start_date'], 'l, F j, Y') . ($block['start_date'] !== $block['end_date'] ? ' – ' . format_date($block['end_date'], 'l, F j, Y') : ''),
                    'url' => $url,
                ],
            ];
            continue;
        }

        $cursor = max($block['start_date'], $rangeStart);
        $last = min($block['end_date'], date('Y-m-d', strtotime($rangeEnd . ' -1 day')));

        while ($cursor <= $last) {
            $events[] = [
                'id' => 'block-' . $block['id'] . '-' . $cursor,
                'title' => 'Gym closed · ' . $block['title'],
                'start' => $cursor . 'T' . $block['start_time'],
                'end' => $cursor . 'T' . $block['end_time'],
                'allDay' => false,
                'backgroundColor' => '#b91c1c',
                'borderColor' => '#b91c1c',
                'textColor' => '#ffffff',
                'url' => $url,
                'classNames' => ['event-blocked'],
                'extendedProps' => [
                    'kind' => 'block',
                    'recordId' => (int) $block['id'],
                    'status' => 'blocked',
                    'statusLabel' => 'Unavailable',
                    'organization' => 'Gym administration',
                    'eventType' => 'Maintenance / blocked period',
                    'reason' => $block['reason'],
                    'startLabel' => format_time($block['start_time']),
                    'endLabel' => format_time($block['end_time']),
                    'dateLabel' => format_date($cursor, 'l, F j, Y'),
                    'url' => $url,
                ],
            ];
            $cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
        }
    }

    echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => APP_DEBUG ? $exception->getMessage() : 'Unable to load calendar events.']);
}
