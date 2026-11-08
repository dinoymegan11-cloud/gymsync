<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$user = require_login();
$reservationId = (int) query('id', 0);
$reservation = $reservationId > 0 ? get_reservation($reservationId) : null;

if (!$reservation) {
    http_response_code(404);
    $pageTitle = 'Reservation not found';
    require __DIR__ . '/includes/header.php';
    render_empty_state('alert-triangle', 'Reservation not found', 'The reservation you are looking for does not exist or may have been removed.', '<a class="btn btn-primary" href="' . e(url('my_reservations.php')) . '">Back to my reservations</a>');
    require __DIR__ . '/includes/footer.php';
    exit;
}

if (!can_view_reservation($reservation, $user)) {
    http_response_code(403);
    $pageTitle = 'Access denied';
    require __DIR__ . '/includes/header.php';
    render_empty_state('shield', 'Access denied', 'You do not have permission to view this reservation.', '<a class="btn btn-primary" href="' . e(url('my_reservations.php')) . '">Back to my reservations</a>');
    require __DIR__ . '/includes/footer.php';
    exit;
}

$isOwner = (int) $reservation['user_id'] === (int) $user['id'];
$canManage = ($isOwner || is_admin($user)) && in_array($reservation['status'], ['pending', 'approved'], true);
$pendingChange = get_pending_reschedule_for((int) $reservation['id']);
$cancelErrors = [];
$rescheduleErrors = [];
$rescheduleValues = [
    'reservation_date' => $reservation['reservation_date'],
    'start_time' => substr($reservation['start_time'], 0, 5),
    'end_time' => substr($reservation['end_time'], 0, 5),
    'change_reason' => '',
];
$openRescheduleDialog = false;

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'cancel_reservation') {
        $result = cancel_reservation($reservation, $user, trim((string) post('cancellation_reason')));
        if ($result['ok']) {
            flash('success', $result['message']);
            redirect('reservation_detail.php?id=' . $reservation['id']);
        }
        $cancelErrors[] = $result['message'];
    }

    if ($action === 'request_reschedule') {
        $rescheduleValues = [
            'reservation_date' => trim((string) post('reservation_date')),
            'start_time' => trim((string) post('start_time')),
            'end_time' => trim((string) post('end_time')),
            'change_reason' => trim((string) post('change_reason')),
        ];

        $result = submit_reschedule_request($reservation, $user, $rescheduleValues);
        if ($result['ok']) {
            flash('success', $result['message']);
            redirect('reservation_detail.php?id=' . $reservation['id']);
        }

        $rescheduleErrors = $result['errors'];
        $openRescheduleDialog = true;
    }
}

$reservation = get_reservation((int) $reservation['id']);
$pendingChange = get_pending_reschedule_for((int) $reservation['id']);
$canManage = ($isOwner || is_admin($user)) && in_array($reservation['status'], ['pending', 'approved'], true);

$historyStatement = db()->prepare(
    'SELECT rr.*, u.first_name, u.last_name, a.first_name AS admin_first_name, a.last_name AS admin_last_name
     FROM reschedule_requests rr
     JOIN users u ON u.id = rr.user_id
     LEFT JOIN users a ON a.id = rr.reviewed_by
     WHERE rr.reservation_id = :id
     ORDER BY rr.created_at DESC'
);
$historyStatement->execute(['id' => $reservation['id']]);
$rescheduleHistory = $historyStatement->fetchAll();

$pageTitle = $reservation['activity_name'];
$activeNav = 'my-reservations';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <a class="link-button" href="<?= e(url(is_admin($user) ? 'admin/reservations.php' : 'my_reservations.php')) ?>"><?= icon('arrow-left') ?> Back to reservations</a>
        <h1 style="margin-top:8px;">Reservation details</h1>
        <p>Review the request information, status, and schedule history.</p>
    </div>
    <div class="page-actions">
        <?php if ($canManage): ?>
            <button type="button" class="btn btn-secondary" data-modal-open="cancel-dialog"><?= icon('ban') ?> Cancel reservation</button>
            <?php if (!$pendingChange): ?>
                <button type="button" class="btn btn-primary" data-modal-open="reschedule-dialog"><?= icon('refresh') ?> Request schedule change</button>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="detail-head">
        <div>
            <div class="detail-title-row">
                <?= status_badge($reservation['status']) ?>
                <span class="tag"><?= icon('ticket') ?> <?= e($reservation['reference_no']) ?></span>
                <?php if ($pendingChange): ?><span class="badge badge-pending">Schedule change pending</span><?php endif; ?>
            </div>
            <h1><?= e($reservation['activity_name']) ?></h1>
            <p><?= e($reservation['organization'] ?: 'No organization specified') ?> · <?= e(event_type_label($reservation['event_type'])) ?></p>
            <span class="detail-ref"><?= icon('clock') ?> Submitted <?= e(format_datetime($reservation['created_at'])) ?></span>
        </div>
        <div class="text-right">
            <span class="meta-copy"><span>Reservation date</span><strong style="font-size:17px;"><?= e(format_date($reservation['reservation_date'], 'D, M j, Y')) ?></strong></span>
            <div class="tag mt-1"><?= icon('clock') ?> <?= e(format_time_range($reservation['start_time'], $reservation['end_time'])) ?></div>
        </div>
    </div>

    <div class="detail-body">
        <div class="detail-main">
            <div class="detail-section">
                <h2>Schedule & request information</h2>
                <div class="meta-grid">
                    <div class="meta-item">
                        <span class="meta-icon"><?= icon('calendar') ?></span>
                        <div class="meta-copy"><span>Date</span><strong><?= e(format_date($reservation['reservation_date'], 'l, F j, Y')) ?></strong></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon"><?= icon('clock') ?></span>
                        <div class="meta-copy"><span>Time</span><strong><?= e(format_time_range($reservation['start_time'], $reservation['end_time'])) ?></strong></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon"><?= icon('building') ?></span>
                        <div class="meta-copy"><span>Organization / department</span><strong><?= e($reservation['organization'] ?: '—') ?></strong></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon"><?= icon('users') ?></span>
                        <div class="meta-copy"><span>Expected attendees</span><strong><?= number_format((int) $reservation['attendees']) ?> people</strong></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon"><?= icon('user') ?></span>
                        <div class="meta-copy"><span>Requested by</span><strong><?= e(trim($reservation['first_name'] . ' ' . $reservation['last_name'])) ?></strong></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon"><?= icon('mail') ?></span>
                        <div class="meta-copy"><span>Contact email</span><strong><?= e($reservation['email']) ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h2>Purpose of use</h2>
                <p><?= e($reservation['purpose']) ?></p>
            </div>

            <?php if ($reservation['equipment']): ?>
                <div class="detail-section">
                    <h2>Equipment & setup requirements</h2>
                    <p><?= e($reservation['equipment']) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($reservation['setup_notes']): ?>
                <div class="detail-section">
                    <h2>Additional notes</h2>
                    <p><?= e($reservation['setup_notes']) ?></p>
                </div>
            <?php endif; ?>

            <?php if ($reservation['admin_note'] || $reservation['rejection_reason']): ?>
                <div class="detail-section">
                    <h2>Administrator remarks</h2>
                    <div class="notice <?= $reservation['status'] === 'rejected' ? 'notice-danger' : 'notice-info' ?>">
                        <?= icon($reservation['status'] === 'rejected' ? 'alert-triangle' : 'shield') ?>
                        <div><?= e($reservation['rejection_reason'] ?: $reservation['admin_note']) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($reservation['status'] === 'cancelled'): ?>
                <div class="detail-section">
                    <h2>Cancellation information</h2>
                    <div class="notice notice-warning">
                        <?= icon('ban') ?>
                        <div>
                            <strong>Cancelled <?= e(format_datetime($reservation['cancelled_at'])) ?></strong><br>
                            <?= e($reservation['cancellation_reason'] ?: 'No cancellation reason was provided.') ?>
                            <?php if ($reservation['canceller_first_name']): ?><br>By <?= e(trim($reservation['canceller_first_name'] . ' ' . $reservation['canceller_last_name'])) ?><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="detail-section">
                <h2>Schedule change history</h2>
                <?php if ($rescheduleHistory === []): ?>
                    <p class="text-muted">No schedule change requests have been made for this reservation.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($rescheduleHistory as $change): ?>
                            <div class="timeline-item">
                                <strong>
                                    <?= e(format_date($change['new_date'])) ?> · <?= e(format_time_range($change['new_start_time'], $change['new_end_time'])) ?>
                                    <span class="badge <?= $change['status'] === 'approved' ? 'badge-approved' : ($change['status'] === 'rejected' ? 'badge-rejected' : 'badge-pending') ?>" style="margin-left:5px;"><?= e(ucfirst($change['status'])) ?></span>
                                </strong>
                                <small><?= e($change['reason']) ?> · requested <?= e(time_ago($change['created_at'])) ?></small>
                                <?php if ($change['admin_note']): ?><small>Admin note: <?= e($change['admin_note']) ?></small><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="detail-side side-sticky">
            <div class="detail-section">
                <h2>Reservation status</h2>
                <div class="detail-stat"><span>Current status</span><strong><?= status_badge($reservation['status']) ?></strong></div>
                <div class="detail-stat"><span>Reference number</span><strong><?= e($reservation['reference_no']) ?></strong></div>
                <div class="detail-stat"><span>Date requested</span><strong><?= e(format_date($reservation['reservation_date'])) ?></strong></div>
                <div class="detail-stat"><span>Time requested</span><strong><?= e(format_time_range($reservation['start_time'], $reservation['end_time'])) ?></strong></div>
                <?php if ($reservation['approved_at']): ?>
                    <div class="detail-stat"><span>Approved on</span><strong><?= e(format_datetime($reservation['approved_at'])) ?></strong></div>
                <?php endif; ?>
                <?php if ($reservation['approver_first_name']): ?>
                    <div class="detail-stat"><span>Reviewed by</span><strong><?= e(trim($reservation['approver_first_name'] . ' ' . $reservation['approver_last_name'])) ?></strong></div>
                <?php endif; ?>
                <?php if ($reservation['checked_in_at']): ?>
                    <div class="detail-stat"><span>Checked in</span><strong><?= e(format_datetime($reservation['checked_in_at'])) ?></strong></div>
                <?php elseif ((int) $reservation['no_show'] === 1): ?>
                    <div class="detail-stat"><span>Attendance</span><strong class="text-danger">Marked as no-show</strong></div>
                <?php endif; ?>
            </div>

            <?php if ($pendingChange): ?>
                <div class="notice notice-warning mb-2">
                    <?= icon('refresh') ?>
                    <div>
                        <strong>Schedule change under review</strong><br>
                        Proposed: <?= e(format_date($pendingChange['new_date'])) ?> at <?= e(format_time_range($pendingChange['new_start_time'], $pendingChange['new_end_time'])) ?>.<br>
                        <span class="text-small"><?= e($pendingChange['reason']) ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($reservation['status'] === 'pending'): ?>
                <div class="notice notice-warning">
                    <?= icon('clock') ?>
                    <div><strong>Awaiting review</strong><br>The gym administrator has not yet made a decision. You will be notified once the request is reviewed.</div>
                </div>
            <?php elseif ($reservation['status'] === 'approved'): ?>
                <div class="notice notice-success">
                    <?= icon('check-circle') ?>
                    <div><strong>Approved and protected</strong><br>This time is reserved on the official gym calendar. Present your reference number when you arrive.</div>
                </div>
            <?php elseif ($reservation['status'] === 'rejected'): ?>
                <div class="notice notice-danger">
                    <?= icon('ban') ?>
                    <div><strong>Request rejected</strong><br><?= e($reservation['rejection_reason'] ?: 'Please contact the gym office for more information.') ?></div>
                </div>
            <?php endif; ?>

            <div class="action-stack mt-3">
                <?php if ($canManage): ?>
                    <button type="button" class="btn btn-secondary" data-modal-open="cancel-dialog"><?= icon('ban') ?> Cancel this reservation</button>
                    <?php if (!$pendingChange): ?>
                        <button type="button" class="btn btn-secondary" data-modal-open="reschedule-dialog"><?= icon('refresh') ?> Request schedule change</button>
                    <?php endif; ?>
                <?php endif; ?>
                <a class="btn btn-ghost" href="<?= e(url(is_admin($user) ? 'admin/reservations.php' : 'my_reservations.php')) ?>"><?= icon('arrow-left') ?> Back to reservations</a>
            </div>
            <p class="form-hint mt-2">Cancellations and schedule changes are recorded in the reservation audit trail.</p>
        </div>
    </div>
</div>

<?php if ($canManage): ?>
<dialog class="modal" id="cancel-dialog"<?= $cancelErrors !== [] ? ' data-auto-open' : '' ?>>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel_reservation">
        <div class="modal-head">
            <div>
                <h2>Cancel reservation</h2>
                <p><?= e($reservation['activity_name']) ?> · <?= e(format_date($reservation['reservation_date'])) ?></p>
            </div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($cancelErrors !== []): ?>
                <div class="error-summary"><strong><?= icon('alert-triangle') ?> Cannot cancel</strong><ul><?php foreach ($cancelErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <div class="notice notice-warning mb-2">
                <?= icon('alert-triangle') ?>
                <div>Cancelling releases this time on the gym calendar. This action cannot be undone.</div>
            </div>
            <div class="form-group">
                <label for="cancellation_reason">Reason for cancellation</label>
                <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" placeholder="Let the gym administrator know why this reservation is being cancelled." maxlength="1000"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Keep reservation</button>
            <button type="submit" class="btn btn-danger"><?= icon('ban') ?> Confirm cancellation</button>
        </div>
    </form>
</dialog>

<?php if (!$pendingChange): ?>
<dialog class="modal modal-lg" id="reschedule-dialog"<?= $openRescheduleDialog ? ' data-auto-open' : '' ?>>
    <form method="post" data-availability-form data-availability-target="#reschedule-availability" data-availability-url="<?= e(url('api/check_conflict.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-duration-fields>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="request_reschedule">
        <input type="hidden" name="exclude_id" value="<?= (int) $reservation['id'] ?>">
        <div class="modal-head">
            <div>
                <h2>Request a schedule change</h2>
                <p>Propose a new date and time. The gym administrator must approve the change.</p>
            </div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($rescheduleErrors !== []): ?>
                <div class="error-summary"><strong><?= icon('alert-triangle') ?> Change request could not be submitted</strong><ul><?php foreach ($rescheduleErrors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>
            <div class="form-grid">
                <div class="form-group">
                    <label for="new_date">New date <span class="required">*</span></label>
                    <input class="form-control" type="date" id="new_date" name="reservation_date" value="<?= e($rescheduleValues['reservation_date']) ?>" min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime('+' . setting_int('max_advance_days', 90) . ' days'))) ?>" required>
                </div>
                <div class="form-group">
                    <label>New time <span class="required">*</span></label>
                    <div class="form-row">
                        <input class="form-control" type="time" name="start_time" value="<?= e($rescheduleValues['start_time']) ?>" required aria-label="New start time">
                        <span class="text-muted">to</span>
                        <input class="form-control" type="time" name="end_time" value="<?= e($rescheduleValues['end_time']) ?>" required aria-label="New end time">
                    </div>
                    <span class="form-hint" data-duration-output></span>
                </div>
                <div class="form-group full">
                    <label for="change_reason">Reason for the change <span class="required">*</span></label>
                    <textarea class="form-control" id="change_reason" name="change_reason" placeholder="Explain why the reservation needs to be moved." maxlength="1000" required><?= e($rescheduleValues['change_reason']) ?></textarea>
                </div>
            </div>
            <div class="availability-result" id="reschedule-availability">Choose a new date and time to check availability.</div>
            <p class="form-hint mt-2">The original schedule remains reserved while this change is under review.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-primary"><?= icon('send') ?> Submit change request</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
