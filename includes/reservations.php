<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function time_to_minutes(?string $time): int
{
    if (!$time) {
        return 0;
    }
    $parts = explode(':', $time);

    return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
}

function normalize_time(?string $time): string
{
    $timestamp = strtotime('1970-01-01 ' . (string) $time);

    return $timestamp === false ? '' : date('H:i:s', $timestamp);
}

function intervals_conflict(string $startA, string $endA, string $startB, string $endB, int $buffer = 0): bool
{
    $aStart = time_to_minutes($startA);
    $aEnd = time_to_minutes($endA);
    $bStart = time_to_minutes($startB);
    $bEnd = time_to_minutes($endB);

    return ($aStart - $buffer < $bEnd) && ($aEnd + $buffer > $bStart);
}

function get_reservation(int $id): ?array
{
    $statement = db()->prepare(
        'SELECT r.*,
                u.first_name, u.last_name, u.email, u.role, u.department AS user_department,
                u.organization AS user_organization, u.phone,
                a.first_name AS approver_first_name, a.last_name AS approver_last_name,
                c.first_name AS canceller_first_name, c.last_name AS canceller_last_name
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN users a ON a.id = r.approved_by
         LEFT JOIN users c ON c.id = r.cancelled_by
         WHERE r.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $id]);
    $reservation = $statement->fetch();

    return $reservation ?: null;
}

function can_view_reservation(array $reservation, array $user): bool
{
    return (int) $reservation['user_id'] === (int) $user['id'] || is_admin($user);
}

function check_conflicts(string $date, string $start, string $end, ?int $excludeReservationId = null): array
{
    $buffer = setting_int('buffer_minutes', 0);
    $conflicts = ['blocking' => [], 'pending' => []];

    $reservationSql = "SELECT r.id, r.activity_name, r.organization, r.reservation_date, r.start_time, r.end_time,
                              r.status, r.user_id, u.first_name, u.last_name
                       FROM reservations r
                       JOIN users u ON u.id = r.user_id
                       WHERE r.reservation_date = :date
                         AND r.status IN ('approved', 'pending')";
    $params = ['date' => $date];

    if ($excludeReservationId !== null) {
        $reservationSql .= ' AND r.id <> :exclude_id';
        $params['exclude_id'] = $excludeReservationId;
    }

    $reservationSql .= ' ORDER BY r.start_time ASC';
    $statement = db()->prepare($reservationSql);
    $statement->execute($params);

    foreach ($statement->fetchAll() as $row) {
        if (!intervals_conflict($start, $end, $row['start_time'], $row['end_time'], $buffer)) {
            continue;
        }

        $item = [
            'kind' => 'reservation',
            'id' => (int) $row['id'],
            'title' => $row['activity_name'],
            'organization' => $row['organization'],
            'date' => $row['reservation_date'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'status' => $row['status'],
            'user_id' => (int) $row['user_id'],
            'requester' => trim($row['first_name'] . ' ' . $row['last_name']),
        ];

        if ($row['status'] === 'approved') {
            $conflicts['blocking'][] = $item;
        } else {
            $conflicts['pending'][] = $item;
        }
    }

    $blockStatement = db()->prepare(
        'SELECT id, title, start_date, end_date, start_time, end_time, reason
         FROM blocked_periods
         WHERE start_date <= :date_end AND end_date >= :date_start
         ORDER BY start_date ASC'
    );
    $blockStatement->execute(['date_end' => $date, 'date_start' => $date]);

    foreach ($blockStatement->fetchAll() as $block) {
        $allDay = $block['start_time'] === null || $block['end_time'] === null;
        $conflictsWithBlock = $allDay
            || intervals_conflict($start, $end, $block['start_time'], $block['end_time'], $buffer);

        if ($conflictsWithBlock) {
            $conflicts['blocking'][] = [
                'kind' => 'block',
                'id' => (int) $block['id'],
                'title' => $block['title'],
                'organization' => 'Gym administration',
                'date' => $date,
                'start_time' => $block['start_time'],
                'end_time' => $block['end_time'],
                'status' => 'blocked',
                'user_id' => 0,
                'requester' => 'Gym administration',
            ];
        }
    }

    return $conflicts;
}

function conflict_message(array $conflicts): string
{
    $parts = [];

    foreach ($conflicts['blocking'] as $item) {
        if ($item['kind'] === 'block') {
            $window = $item['start_time'] ? format_time_range($item['start_time'], $item['end_time']) : 'All day';
            $parts[] = 'Blocked: ' . $item['title'] . ' (' . $window . ')';
        } else {
            $parts[] = $item['title'] . ' (' . format_time_range($item['start_time'], $item['end_time']) . ')';
        }
    }

    return implode('; ', $parts);
}

function validate_reservation_request(array $data, ?int $excludeReservationId = null): array
{
    $errors = [];
    $date = trim((string) ($data['reservation_date'] ?? ''));
    $start = normalize_time($data['start_time'] ?? '');
    $end = normalize_time($data['end_time'] ?? '');
    $activity = trim((string) ($data['activity_name'] ?? ''));
    $purpose = trim((string) ($data['purpose'] ?? ''));
    $attendees = (int) ($data['attendees'] ?? 0);

    if ($activity === '' || mb_strlen($activity) < 3) {
        $errors[] = 'Please enter the activity or event name.';
    } elseif (mb_strlen($activity) > 150) {
        $errors[] = 'The activity name must be 150 characters or fewer.';
    }

    if ($purpose === '' || mb_strlen($purpose) < 10) {
        $errors[] = 'Please provide a purpose of at least 10 characters.';
    } elseif (mb_strlen($purpose) > 2000) {
        $errors[] = 'The purpose is too long (maximum 2,000 characters).';
    }

    if ($attendees < 1) {
        $errors[] = 'The expected number of attendees must be at least 1.';
    }

    $maxAttendees = setting_int('max_attendees', 1000);
    if ($maxAttendees > 0 && $attendees > $maxAttendees) {
        $errors[] = 'The expected number of attendees exceeds the gym capacity of ' . $maxAttendees . '.';
    }

    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        $errors[] = 'Please choose a valid reservation date.';
    }

    if ($start === '' || $end === '') {
        $errors[] = 'Please provide a valid start and end time.';
    } elseif (time_to_minutes($end) <= time_to_minutes($start)) {
        $errors[] = 'The end time must be later than the start time.';
    }

    if ($dateObject) {
        $today = new DateTime('today');
        $maxDate = (clone $today)->modify('+' . setting_int('max_advance_days', 90) . ' days');

        if ($dateObject < $today) {
            $errors[] = 'Reservations cannot be made for a date in the past.';
        }

        if ($dateObject > $maxDate) {
            $errors[] = 'Reservations can only be made up to ' . setting_int('max_advance_days', 90) . ' days in advance.';
        }

        if ($start !== '' && $dateObject->format('Y-m-d') === $today->format('Y-m-d')) {
            $requestedStart = new DateTime($date . ' ' . $start);
            if ($requestedStart <= new DateTime()) {
                $errors[] = 'The selected start time has already passed. Please choose a later time.';
            }
        }
    }

    if ($start !== '' && $end !== '') {
        $opening = normalize_time(setting('opening_time', '06:00'));
        $closing = normalize_time(setting('closing_time', '22:00'));
        $startMinutes = time_to_minutes($start);
        $endMinutes = time_to_minutes($end);

        if ($startMinutes < time_to_minutes($opening) || $endMinutes > time_to_minutes($closing)) {
            $errors[] = 'Requests must be within gym operating hours (' . format_time($opening) . ' – ' . format_time($closing) . ').';
        }

        $duration = $endMinutes - $startMinutes;
        $maxDuration = setting_int('max_duration_hours', 0);
        if ($duration < 30) {
            $errors[] = 'A reservation must be at least 30 minutes long.';
        }
        if ($maxDuration > 0 && $duration > $maxDuration * 60) {
            $errors[] = 'A reservation may not exceed ' . $maxDuration . ' hours.';
        }
    }

    $conflicts = ['blocking' => [], 'pending' => []];
    if ($date !== '' && $start !== '' && $end !== '' && time_to_minutes($end) > time_to_minutes($start)) {
        $conflicts = check_conflicts($date, $start, $end, $excludeReservationId);
        if ($conflicts['blocking'] !== []) {
            $errors[] = 'The selected schedule conflicts with an approved reservation or blocked period. Please choose another time.';
        }
    }

    return [
        'errors' => $errors,
        'conflicts' => $conflicts,
        'date' => $date,
        'start_time' => $start,
        'end_time' => $end,
    ];
}

function generate_reference(): string
{
    do {
        $reference = 'GYM-' . date('Y') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
        $statement = db()->prepare('SELECT COUNT(*) FROM reservations WHERE reference_no = :reference');
        $statement->execute(['reference' => $reference]);
    } while ((int) $statement->fetchColumn() > 0);

    return $reference;
}

function create_reservation(array $data, array $user): array
{
    $validation = validate_reservation_request($data);
    if ($validation['errors'] !== []) {
        return ['ok' => false, 'errors' => $validation['errors'], 'conflicts' => $validation['conflicts']];
    }

    $reference = generate_reference();
    $statement = db()->prepare(
        'INSERT INTO reservations
            (reference_no, user_id, activity_name, organization, event_type, reservation_date, start_time, end_time,
             purpose, attendees, equipment, setup_notes, status, created_at, updated_at)
         VALUES
            (:reference_no, :user_id, :activity_name, :organization, :event_type, :reservation_date, :start_time, :end_time,
             :purpose, :attendees, :equipment, :setup_notes, :status, NOW(), NOW())'
    );

    $statement->execute([
        'reference_no' => $reference,
        'user_id' => (int) $user['id'],
        'activity_name' => trim((string) $data['activity_name']),
        'organization' => trim((string) ($data['organization'] ?? '')),
        'event_type' => in_array($data['event_type'] ?? '', ['class', 'sports', 'school_event', 'organization', 'meeting', 'other'], true) ? $data['event_type'] : 'other',
        'reservation_date' => $validation['date'],
        'start_time' => $validation['start_time'],
        'end_time' => $validation['end_time'],
        'purpose' => trim((string) $data['purpose']),
        'attendees' => (int) $data['attendees'],
        'equipment' => trim((string) ($data['equipment'] ?? '')),
        'setup_notes' => trim((string) ($data['setup_notes'] ?? '')),
        'status' => 'pending',
    ]);

    $reservationId = (int) db()->lastInsertId();
    $reservation = get_reservation($reservationId);

    notify_user(
        (int) $user['id'],
        'Reservation request received',
        'Your request ' . $reference . ' for ' . $data['activity_name'] . ' on ' . format_date($validation['date']) . ' is now pending review.',
        'info',
        url('reservation_detail.php?id=' . $reservationId)
    );

    notify_admins(
        'New reservation request',
        full_name($user) . ' submitted ' . $data['activity_name'] . ' on ' . format_date($validation['date']) . ' (' . format_time_range($validation['start_time'], $validation['end_time']) . ').',
        'info',
        url('admin/reservation_view.php?id=' . $reservationId)
    );

    audit_log('reservation_submitted', 'reservation', $reservationId, [
        'reference' => $reference,
        'activity' => $data['activity_name'],
        'date' => $validation['date'],
        'start' => $validation['start_time'],
        'end' => $validation['end_time'],
    ]);

    if ($validation['conflicts']['pending'] !== []) {
        audit_log('pending_conflict_detected', 'reservation', $reservationId, [
            'reference' => $reference,
            'competing_requests' => array_column($validation['conflicts']['pending'], 'id'),
        ]);
    }

    return [
        'ok' => true,
        'id' => $reservationId,
        'reference' => $reference,
        'reservation' => $reservation,
        'pending_conflicts' => $validation['conflicts']['pending'],
    ];
}

function approve_reservation(array $reservation, array $admin, string $note = ''): array
{
    if ($reservation['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'Only pending requests can be approved.'];
    }

    if ($reservation['reservation_date'] < date('Y-m-d')) {
        return ['ok' => false, 'message' => 'This request is for a past date and can no longer be approved.'];
    }

    $conflicts = check_conflicts(
        $reservation['reservation_date'],
        $reservation['start_time'],
        $reservation['end_time'],
        (int) $reservation['id']
    );

    if ($conflicts['blocking'] !== []) {
        audit_log('approval_blocked_by_conflict', 'reservation', (int) $reservation['id'], [
            'conflicts' => $conflicts['blocking'],
        ]);

        return [
            'ok' => false,
            'message' => 'This request can no longer be approved because it now conflicts with another approved booking or blocked period: ' . conflict_message($conflicts),
        ];
    }

    $statement = db()->prepare(
        "UPDATE reservations
         SET status = 'approved', admin_note = :note, approved_by = :admin_id, approved_at = NOW(), updated_at = NOW()
         WHERE id = :id AND status = 'pending'"
    );
    $statement->execute([
        'note' => $note !== '' ? $note : null,
        'admin_id' => (int) $admin['id'],
        'id' => (int) $reservation['id'],
    ]);

    audit_log('reservation_approved', 'reservation', (int) $reservation['id'], [
        'reference' => $reservation['reference_no'],
        'note' => $note,
    ]);

    notify_user(
        (int) $reservation['user_id'],
        'Reservation approved',
        'Your reservation ' . $reservation['reference_no'] . ' for ' . $reservation['activity_name'] . ' on ' . format_date($reservation['reservation_date']) . ' has been approved.',
        'success',
        url('reservation_detail.php?id=' . $reservation['id'])
    );

    notify_competing_pending($reservation);

    return ['ok' => true, 'message' => 'Reservation approved and added to the official gym calendar.'];
}

function notify_competing_pending(array $approvedReservation): void
{
    $buffer = setting_int('buffer_minutes', 0);
    $statement = db()->prepare(
        "SELECT r.id, r.reference_no, r.user_id, r.activity_name, r.start_time, r.end_time
         FROM reservations r
         WHERE r.reservation_date = :date
           AND r.status = 'pending'
           AND r.id <> :id"
    );
    $statement->execute([
        'date' => $approvedReservation['reservation_date'],
        'id' => (int) $approvedReservation['id'],
    ]);

    foreach ($statement->fetchAll() as $pending) {
        if (!intervals_conflict($approvedReservation['start_time'], $approvedReservation['end_time'], $pending['start_time'], $pending['end_time'], $buffer)) {
            continue;
        }

        notify_user(
            (int) $pending['user_id'],
            'Competing request approved',
            'Another request for ' . format_date($approvedReservation['reservation_date']) . ' at ' . format_time_range($approvedReservation['start_time'], $approvedReservation['end_time']) . ' has been approved. Your request ' . $pending['reference_no'] . ' overlaps with that time and may need to be rescheduled.',
            'warning',
            url('reservation_detail.php?id=' . $pending['id'])
        );

        audit_log('pending_request_overlap', 'reservation', (int) $pending['id'], [
            'approved_reservation_id' => (int) $approvedReservation['id'],
        ]);
    }
}

function reject_reservation(array $reservation, array $admin, string $reason): array
{
    if ($reservation['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'Only pending requests can be rejected.'];
    }

    if (trim($reason) === '') {
        return ['ok' => false, 'message' => 'A rejection reason is required.'];
    }

    $statement = db()->prepare(
        "UPDATE reservations
         SET status = 'rejected', rejection_reason = :reason, admin_note = :note, approved_by = :admin_id, updated_at = NOW()
         WHERE id = :id AND status = 'pending'"
    );
    $statement->execute([
        'reason' => trim($reason),
        'note' => trim($reason),
        'admin_id' => (int) $admin['id'],
        'id' => (int) $reservation['id'],
    ]);

    audit_log('reservation_rejected', 'reservation', (int) $reservation['id'], [
        'reference' => $reservation['reference_no'],
        'reason' => trim($reason),
    ]);

    notify_user(
        (int) $reservation['user_id'],
        'Reservation rejected',
        'Your reservation ' . $reservation['reference_no'] . ' for ' . $reservation['activity_name'] . ' was not approved. Reason: ' . trim($reason),
        'danger',
        url('reservation_detail.php?id=' . $reservation['id'])
    );

    return ['ok' => true, 'message' => 'Reservation rejected and the requester has been notified.'];
}

function cancel_reservation(array $reservation, array $actor, string $reason = ''): array
{
    if (!in_array($reservation['status'], ['pending', 'approved'], true)) {
        return ['ok' => false, 'message' => 'This reservation can no longer be cancelled.'];
    }

    $statement = db()->prepare(
        "UPDATE reservations
         SET status = 'cancelled', cancelled_by = :cancelled_by, cancelled_at = NOW(),
             cancellation_reason = :reason, updated_at = NOW()
         WHERE id = :id AND status IN ('pending', 'approved')"
    );
    $statement->execute([
        'cancelled_by' => (int) $actor['id'],
        'reason' => trim($reason) !== '' ? trim($reason) : null,
        'id' => (int) $reservation['id'],
    ]);

    $statement = db()->prepare(
        "UPDATE reschedule_requests
         SET status = 'rejected', admin_note = :note, reviewed_by = :reviewed_by, reviewed_at = NOW(), updated_at = NOW()
         WHERE reservation_id = :id AND status = 'pending'"
    );
    $statement->execute([
        'note' => 'Closed automatically because the reservation was cancelled.',
        'reviewed_by' => (int) $actor['id'],
        'id' => (int) $reservation['id'],
    ]);
    $closedChanges = $statement->rowCount();

    audit_log('reservation_cancelled', 'reservation', (int) $reservation['id'], [
        'reference' => $reservation['reference_no'],
        'reason' => trim($reason),
        'closed_reschedule_requests' => $closedChanges,
    ]);

    if ((int) $reservation['user_id'] !== (int) $actor['id']) {
        notify_user(
            (int) $reservation['user_id'],
            'Reservation cancelled',
            'Your reservation ' . $reservation['reference_no'] . ' for ' . $reservation['activity_name'] . ' on ' . format_date($reservation['reservation_date']) . ' has been cancelled by an administrator.' . (trim($reason) !== '' ? ' Reason: ' . trim($reason) : ''),
            'warning',
            url('reservation_detail.php?id=' . $reservation['id'])
        );
    }

    if (!is_admin($actor) || (int) $reservation['user_id'] === (int) $actor['id']) {
        notify_admins(
            'Reservation cancelled',
            full_name($actor) . ' cancelled ' . $reservation['reference_no'] . ' (' . format_date($reservation['reservation_date']) . ' at ' . format_time_range($reservation['start_time'], $reservation['end_time']) . ').',
            'warning',
            url('admin/reservation_view.php?id=' . $reservation['id'])
        );
    }

    return ['ok' => true, 'message' => 'Reservation cancelled.'];
}

function get_pending_reschedule_for(int $reservationId): ?array
{
    $statement = db()->prepare(
        "SELECT rr.*, u.first_name, u.last_name
         FROM reschedule_requests rr
         JOIN users u ON u.id = rr.user_id
         WHERE rr.reservation_id = :id AND rr.status = 'pending'
         ORDER BY rr.created_at DESC
         LIMIT 1"
    );
    $statement->execute(['id' => $reservationId]);

    return $statement->fetch() ?: null;
}

function submit_reschedule_request(array $reservation, array $user, array $data): array
{
    if (!in_array($reservation['status'], ['pending', 'approved'], true)) {
        return ['ok' => false, 'errors' => ['Only pending or approved reservations can be rescheduled.']];
    }

    if (get_pending_reschedule_for((int) $reservation['id'])) {
        return ['ok' => false, 'errors' => ['There is already a pending schedule-change request for this reservation.']];
    }

    $validationData = array_merge($data, [
        'activity_name' => $reservation['activity_name'],
        'purpose' => $reservation['purpose'],
        'attendees' => $reservation['attendees'],
    ]);
    $validation = validate_reservation_request($validationData, (int) $reservation['id']);
    if ($validation['errors'] !== []) {
        return ['ok' => false, 'errors' => $validation['errors'], 'conflicts' => $validation['conflicts']];
    }

    $reason = trim((string) ($data['change_reason'] ?? ''));
    if ($reason === '') {
        return ['ok' => false, 'errors' => ['Please explain why the schedule needs to change.']];
    }

    $statement = db()->prepare(
        "INSERT INTO reschedule_requests
            (reservation_id, user_id, new_date, new_start_time, new_end_time, reason, status, created_at, updated_at)
         VALUES
            (:reservation_id, :user_id, :new_date, :new_start_time, :new_end_time, :reason, 'pending', NOW(), NOW())"
    );
    $statement->execute([
        'reservation_id' => (int) $reservation['id'],
        'user_id' => (int) $user['id'],
        'new_date' => $validation['date'],
        'new_start_time' => $validation['start_time'],
        'new_end_time' => $validation['end_time'],
        'reason' => $reason,
    ]);

    $requestId = (int) db()->lastInsertId();

    audit_log('reschedule_requested', 'reservation', (int) $reservation['id'], [
        'reschedule_request_id' => $requestId,
        'new_date' => $validation['date'],
        'new_start' => $validation['start_time'],
        'new_end' => $validation['end_time'],
        'reason' => $reason,
    ]);

    notify_admins(
        'Schedule change requested',
        full_name($user) . ' requested a new schedule for ' . $reservation['reference_no'] . ': ' . format_date($validation['date']) . ' at ' . format_time_range($validation['start_time'], $validation['end_time']) . '.',
        'warning',
        url('admin/reservation_view.php?id=' . $reservation['id'])
    );

    return ['ok' => true, 'request_id' => $requestId, 'message' => 'Schedule change request submitted for administrator review.'];
}

function review_reschedule_request(array $request, array $admin, string $decision, string $note = ''): array
{
    if ($request['status'] !== 'pending') {
        return ['ok' => false, 'message' => 'This schedule change has already been reviewed.'];
    }

    $reservation = get_reservation((int) $request['reservation_id']);
    if (!$reservation) {
        return ['ok' => false, 'message' => 'The linked reservation could not be found.'];
    }

    if ($decision === 'approve') {
        if (!in_array($reservation['status'], ['pending', 'approved'], true)) {
            return ['ok' => false, 'message' => 'This reservation is no longer active, so the schedule change cannot be approved.'];
        }

        if ($request['new_date'] < date('Y-m-d')) {
            return ['ok' => false, 'message' => 'The proposed schedule is for a past date and can no longer be approved.'];
        }

        if ($request['new_date'] === date('Y-m-d') && new DateTime($request['new_date'] . ' ' . $request['new_start_time']) <= new DateTime()) {
            return ['ok' => false, 'message' => 'The proposed start time has already passed and can no longer be approved.'];
        }

        $conflicts = check_conflicts(
            $request['new_date'],
            $request['new_start_time'],
            $request['new_end_time'],
            (int) $reservation['id']
        );

        if ($conflicts['blocking'] !== []) {
            audit_log('reschedule_approval_blocked', 'reservation', (int) $reservation['id'], [
                'reschedule_request_id' => (int) $request['id'],
                'conflicts' => $conflicts['blocking'],
            ]);

            return [
                'ok' => false,
                'message' => 'The proposed schedule conflicts with an approved booking or blocked period: ' . conflict_message($conflicts),
            ];
        }

        db()->beginTransaction();
        try {
            $statement = db()->prepare(
                "UPDATE reservations
                 SET reservation_date = :date, start_time = :start_time, end_time = :end_time,
                     status = 'approved', approved_by = :admin_id, approved_at = NOW(),
                     admin_note = :note, updated_at = NOW()
                 WHERE id = :id"
            );
            $statement->execute([
                'date' => $request['new_date'],
                'start_time' => $request['new_start_time'],
                'end_time' => $request['new_end_time'],
                'admin_id' => (int) $admin['id'],
                'note' => trim($note) !== '' ? trim($note) : $reservation['admin_note'],
                'id' => (int) $reservation['id'],
            ]);

            $statement = db()->prepare(
                "UPDATE reschedule_requests
                 SET status = 'approved', admin_note = :note, reviewed_by = :admin_id, reviewed_at = NOW(), updated_at = NOW()
                 WHERE id = :id AND status = 'pending'"
            );
            $statement->execute([
                'note' => trim($note) !== '' ? trim($note) : null,
                'admin_id' => (int) $admin['id'],
                'id' => (int) $request['id'],
            ]);

            db()->commit();
        } catch (Throwable $exception) {
            db()->rollBack();
            throw $exception;
        }

        audit_log('reschedule_approved', 'reservation', (int) $reservation['id'], [
            'reschedule_request_id' => (int) $request['id'],
            'new_date' => $request['new_date'],
            'new_start' => $request['new_start_time'],
            'new_end' => $request['new_end_time'],
        ]);

        notify_user(
            (int) $reservation['user_id'],
            'Schedule change approved',
            'Your reservation ' . $reservation['reference_no'] . ' has been moved to ' . format_date($request['new_date']) . ' from ' . format_time_range($request['new_start_time'], $request['new_end_time']) . '.',
            'success',
            url('reservation_detail.php?id=' . $reservation['id'])
        );

        $updated = get_reservation((int) $reservation['id']);
        notify_competing_pending($updated);

        return ['ok' => true, 'message' => 'The new schedule was approved and the calendar has been updated.'];
    }

    if ($decision === 'reject') {
        if (trim($note) === '') {
            return ['ok' => false, 'message' => 'Please include a reason for rejecting the schedule change.'];
        }

        $statement = db()->prepare(
            "UPDATE reschedule_requests
             SET status = 'rejected', admin_note = :note, reviewed_by = :admin_id, reviewed_at = NOW(), updated_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $statement->execute([
            'note' => trim($note),
            'admin_id' => (int) $admin['id'],
            'id' => (int) $request['id'],
        ]);

        audit_log('reschedule_rejected', 'reservation', (int) $reservation['id'], [
            'reschedule_request_id' => (int) $request['id'],
            'reason' => trim($note),
        ]);

        notify_user(
            (int) $reservation['user_id'],
            'Schedule change rejected',
            'Your requested schedule change for ' . $reservation['reference_no'] . ' was rejected. Reason: ' . trim($note),
            'danger',
            url('reservation_detail.php?id=' . $reservation['id'])
        );

        return ['ok' => true, 'message' => 'The schedule change request was rejected.'];
    }

    return ['ok' => false, 'message' => 'Invalid review action.'];
}

function send_upcoming_reminders(): void
{
    $hours = setting_int('auto_reminder_hours', 24);
    if ($hours <= 0) {
        return;
    }

    $statement = db()->prepare(
        "SELECT id, reference_no, user_id, activity_name, reservation_date, start_time
         FROM reservations
         WHERE status = 'approved'
           AND reminder_sent_at IS NULL
           AND TIMESTAMP(reservation_date, start_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :hours HOUR)"
    );
    $statement->execute(['hours' => $hours]);

    foreach ($statement->fetchAll() as $reservation) {
        notify_user(
            (int) $reservation['user_id'],
            'Upcoming gym reservation',
            'Reminder: ' . $reservation['activity_name'] . ' (' . $reservation['reference_no'] . ') is scheduled for ' . format_date($reservation['reservation_date']) . ' at ' . format_time($reservation['start_time']) . '.',
            'info',
            url('reservation_detail.php?id=' . $reservation['id'])
        );

        $update = db()->prepare('UPDATE reservations SET reminder_sent_at = NOW() WHERE id = :id');
        $update->execute(['id' => (int) $reservation['id']]);
    }
}

function reservations_affected_by_period(string $startDate, string $endDate, ?string $startTime = null, ?string $endTime = null, array $statuses = ['approved', 'pending']): array
{
    $statuses = array_values(array_filter($statuses, static fn ($status) => in_array($status, ['pending', 'approved'], true)));
    if ($statuses === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($statuses), '?'));
    $statement = db()->prepare(
        "SELECT r.*, u.first_name, u.last_name, u.email
         FROM reservations r
         JOIN users u ON u.id = r.user_id
         WHERE r.reservation_date BETWEEN ? AND ?
           AND r.status IN ($placeholders)
         ORDER BY r.reservation_date ASC, r.start_time ASC"
    );
    $statement->execute(array_merge([$startDate, $endDate], $statuses));
    $reservations = $statement->fetchAll();

    if ($startTime === null || $endTime === null) {
        return $reservations;
    }

    $buffer = setting_int('buffer_minutes', 0);

    return array_values(array_filter(
        $reservations,
        static fn (array $reservation) => intervals_conflict(
            $reservation['start_time'],
            $reservation['end_time'],
            $startTime,
            $endTime,
            $buffer
        )
    ));
}

function reservation_calendar_color(string $status): string
{
    return [
        'approved' => '#15803d',
        'pending' => '#b45309',
        'blocked' => '#b91c1c',
        'cancelled' => '#64748b',
        'completed' => '#0f766e',
    ][$status] ?? '#475569';
}
