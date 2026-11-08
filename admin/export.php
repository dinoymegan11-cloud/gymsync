<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';
require_once __DIR__ . '/../includes/reports.php';

$admin = require_roles(['gym_admin', 'system_admin']);
$filters = report_filters();
$reservations = report_reservations($filters);

$filename = 'gymsync-reservations-' . $filters['from'] . '-to-' . $filters['to'] . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$output = fopen('php://output', 'w');
if ($output === false) {
    exit;
}

$csvSafe = static function ($value): string {
    $value = (string) $value;
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $value;
    }

    return $value;
};

fwrite($output, "\xEF\xBB\xBF");
fputcsv($output, [
    'Reference',
    'Reservation date',
    'Start time',
    'End time',
    'Duration (hours)',
    'Activity',
    'Activity type',
    'Organization',
    'Department',
    'Requester',
    'Email',
    'Phone',
    'Attendees',
    'Status',
    'Attendance',
    'Approved by',
    'Submitted at',
    'Purpose',
    'Equipment',
    'Setup notes',
    'Admin remarks',
]);

foreach ($reservations as $reservation) {
    $hours = max(0, (time_to_minutes($reservation['end_time']) - time_to_minutes($reservation['start_time'])) / 60);
    $attendance = 'Not recorded';
    if ((int) $reservation['no_show'] === 1) {
        $attendance = 'No-show';
    } elseif ($reservation['checked_in_at']) {
        $attendance = 'Checked in ' . format_datetime($reservation['checked_in_at']);
    }

    fputcsv($output, array_map($csvSafe, [
        $reservation['reference_no'],
        $reservation['reservation_date'],
        $reservation['start_time'],
        $reservation['end_time'],
        number_format($hours, 2, '.', ''),
        $reservation['activity_name'],
        event_type_label($reservation['event_type']),
        $reservation['organization'] ?: $reservation['user_organization'],
        $reservation['user_department'],
        trim($reservation['first_name'] . ' ' . $reservation['last_name']),
        $reservation['email'],
        $reservation['phone'],
        $reservation['attendees'],
        status_label($reservation['status']),
        $attendance,
        $reservation['approver_first_name'] ? trim($reservation['approver_first_name'] . ' ' . $reservation['approver_last_name']) : '',
        $reservation['created_at'],
        $reservation['purpose'],
        $reservation['equipment'],
        $reservation['setup_notes'],
        $reservation['admin_note'] ?: $reservation['rejection_reason'],
    ]));
}

fclose($output);
audit_log('report_exported', 'report', null, [
    'from' => $filters['from'],
    'to' => $filters['to'],
    'status' => $filters['status'],
    'event_type' => $filters['event_type'],
    'search' => $filters['search'],
    'records' => count($reservations),
    'by' => full_name($admin),
]);
exit;
