<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

function report_valid_date(string $date): bool
{
    $object = DateTime::createFromFormat('!Y-m-d', $date);

    return $object !== false && $object->format('Y-m-d') === $date;
}

function report_filters(): array
{
    $today = date('Y-m-d');
    $from = trim((string) query('from', date('Y-m-01')));
    $to = trim((string) query('to', $today));

    if (!report_valid_date($from)) {
        $from = date('Y-m-01');
    }
    if (!report_valid_date($to)) {
        $to = $today;
    }
    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    $status = (string) query('status', '');
    if (!in_array($status, ['pending', 'approved', 'rejected', 'cancelled', 'completed'], true)) {
        $status = '';
    }

    $eventType = (string) query('event_type', '');
    if (!array_key_exists($eventType, event_type_label_map())) {
        $eventType = '';
    }

    return [
        'from' => $from,
        'to' => $to,
        'status' => $status,
        'event_type' => $eventType,
        'search' => trim((string) query('search', '')),
    ];
}

function event_type_label_map(): array
{
    return [
        'class' => 'Regular Class',
        'sports' => 'Sports Practice',
        'school_event' => 'School Event',
        'organization' => 'Organization Activity',
        'meeting' => 'Meeting',
        'other' => 'Other',
    ];
}

function report_where(array $filters, array &$params): string
{
    $where = ['r.reservation_date BETWEEN :from_date AND :to_date'];
    $params['from_date'] = $filters['from'];
    $params['to_date'] = $filters['to'];

    if ($filters['status'] !== '') {
        $where[] = 'r.status = :status';
        $params['status'] = $filters['status'];
    }
    if ($filters['event_type'] !== '') {
        $where[] = 'r.event_type = :event_type';
        $params['event_type'] = $filters['event_type'];
    }
    if ($filters['search'] !== '') {
        $where[] = '(r.activity_name LIKE :search_activity OR r.reference_no LIKE :search_reference OR r.organization LIKE :search_organization OR r.purpose LIKE :search_purpose OR CONCAT(u.first_name, \' \', u.last_name) LIKE :search_requester)';
        $params['search_activity'] = '%' . $filters['search'] . '%';
        $params['search_reference'] = '%' . $filters['search'] . '%';
        $params['search_organization'] = '%' . $filters['search'] . '%';
        $params['search_purpose'] = '%' . $filters['search'] . '%';
        $params['search_requester'] = '%' . $filters['search'] . '%';
    }

    return implode(' AND ', $where);
}

function report_reservations(array $filters): array
{
    $params = [];
    $where = report_where($filters, $params);
    $statement = db()->prepare(
        "SELECT r.*,
                u.first_name, u.last_name, u.email, u.phone, u.role, u.department AS user_department,
                u.organization AS user_organization,
                a.first_name AS approver_first_name, a.last_name AS approver_last_name,
                c.first_name AS canceller_first_name, c.last_name AS canceller_last_name
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN users a ON a.id = r.approved_by
         LEFT JOIN users c ON c.id = r.cancelled_by
         WHERE $where
         ORDER BY r.reservation_date DESC, r.start_time DESC"
    );
    $statement->execute($params);

    return $statement->fetchAll();
}

function report_stats(array $reservations): array
{
    $statuses = array_fill_keys(['pending', 'approved', 'rejected', 'cancelled', 'completed'], 0);
    $types = array_fill_keys(array_keys(event_type_label_map()), 0);
    $stats = [
        'total' => count($reservations),
        'statuses' => $statuses,
        'types' => $types,
        'booked_hours' => 0.0,
        'unique_requesters' => 0,
        'no_shows' => 0,
        'checked_in' => 0,
        'peak_hours' => [],
        'top_requesters' => [],
    ];

    $requesters = [];
    $peak = [];
    $top = [];

    foreach ($reservations as $reservation) {
        $status = $reservation['status'];
        if (isset($stats['statuses'][$status])) {
            $stats['statuses'][$status]++;
        }
        if (isset($stats['types'][$reservation['event_type']])) {
            $stats['types'][$reservation['event_type']]++;
        }

        $requesters[(int) $reservation['user_id']] = true;
        $isUsage = in_array($status, ['approved', 'completed'], true);
        $hours = max(0, (time_to_minutes($reservation['end_time']) - time_to_minutes($reservation['start_time'])) / 60);

        if ($isUsage) {
            $stats['booked_hours'] += $hours;
            $hour = substr($reservation['start_time'], 0, 2) . ':00';
            $peak[$hour] = ($peak[$hour] ?? 0) + 1;

            $userId = (int) $reservation['user_id'];
            if (!isset($top[$userId])) {
                $top[$userId] = [
                    'name' => trim($reservation['first_name'] . ' ' . $reservation['last_name']),
                    'first_name' => $reservation['first_name'],
                    'last_name' => $reservation['last_name'],
                    'role' => $reservation['role'],
                    'count' => 0,
                    'hours' => 0.0,
                ];
            }
            $top[$userId]['count']++;
            $top[$userId]['hours'] += $hours;
        }

        if ((int) $reservation['no_show'] === 1) {
            $stats['no_shows']++;
        }
        if ($reservation['checked_in_at']) {
            $stats['checked_in']++;
        }
    }

    $stats['unique_requesters'] = count($requesters);
    arsort($peak);
    $stats['peak_hours'] = array_slice($peak, 0, 6, true);
    uasort($top, static fn (array $a, array $b) => $b['count'] <=> $a['count']);
    $stats['top_requesters'] = array_slice($top, 0, 6, true);

    return $stats;
}

function report_monthly_trend(int $months = 6): array
{
    $start = date('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
    $statement = db()->prepare(
        "SELECT DATE_FORMAT(reservation_date, '%Y-%m') AS month_key,
                COUNT(*) AS total,
                SUM(CASE WHEN status IN ('approved', 'completed') THEN 1 ELSE 0 END) AS booked,
                SUM(CASE WHEN status IN ('approved', 'completed') THEN TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 3600 ELSE 0 END) AS hours
         FROM reservations
         WHERE reservation_date >= :start_date
         GROUP BY DATE_FORMAT(reservation_date, '%Y-%m')
         ORDER BY month_key ASC"
    );
    $statement->execute(['start_date' => $start]);
    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[$row['month_key']] = [
            'label' => date('M Y', strtotime($row['month_key'] . '-01')),
            'total' => (int) $row['total'],
            'booked' => (int) $row['booked'],
            'hours' => (float) $row['hours'],
        ];
    }

    $trend = [];
    for ($i = $months - 1; $i >= 0; $i--) {
        $key = date('Y-m', strtotime('-' . $i . ' months'));
        $trend[] = $rows[$key] ?? [
            'label' => date('M Y', strtotime($key . '-01')),
            'total' => 0,
            'booked' => 0,
            'hours' => 0.0,
        ];
    }

    return $trend;
}
