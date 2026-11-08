<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

$admin = require_roles(['gym_admin', 'system_admin']);
$reservationId = (int) (query('id', post('reservation_id', 0)));
$reservation = $reservationId > 0 ? get_reservation($reservationId) : null;

if (!$reservation) {
    flash('danger', 'The reservation could not be found.');
    redirect('admin/reservations.php');
}

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'approve') {
        $result = approve_reservation($reservation, $admin, trim((string) post('admin_note')));
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
    } elseif ($action === 'reject') {
        $result = reject_reservation($reservation, $admin, trim((string) post('rejection_reason')));
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
    } elseif ($action === 'cancel') {
        $result = cancel_reservation($reservation, $admin, trim((string) post('cancellation_reason')));
        flash($result['ok'] ? 'success' : 'danger', $result['message']);
    } elseif ($action === 'review_reschedule') {
        $rescheduleId = (int) post('reschedule_id');
        $statement = db()->prepare('SELECT * FROM reschedule_requests WHERE id = :id AND reservation_id = :reservation_id LIMIT 1');
        $statement->execute(['id' => $rescheduleId, 'reservation_id' => (int) $reservation['id']]);
        $change = $statement->fetch();

        if (!$change) {
            flash('danger', 'The schedule change request could not be found.');
        } else {
            $result = review_reschedule_request($change, $admin, (string) post('decision'), trim((string) post('admin_note')));
            flash($result['ok'] ? 'success' : 'danger', $result['message']);
        }
    } elseif ($action === 'check_in') {
        if ($reservation['status'] !== 'approved' || $reservation['reservation_date'] > date('Y-m-d')) {
            flash('danger', 'Check-in is only available on or after the reservation date for approved bookings.');
        } else {
            $statement = db()->prepare('UPDATE reservations SET checked_in_at = NOW(), checked_in_by = :admin_id, no_show = 0, updated_at = NOW() WHERE id = :id');
            $statement->execute(['admin_id' => (int) $admin['id'], 'id' => (int) $reservation['id']]);
            audit_log('reservation_checked_in', 'reservation', (int) $reservation['id'], ['by' => full_name($admin)]);
            notify_user(
                (int) $reservation['user_id'],
                'Reservation check-in recorded',
                'Your attendance for ' . $reservation['reference_no'] . ' (' . $reservation['activity_name'] . ') has been recorded.',
                'success',
                url('reservation_detail.php?id=' . $reservation['id'])
            );
            flash('success', 'Check-in recorded for this reservation.');
        }
    } elseif ($action === 'no_show') {
        if ($reservation['status'] !== 'approved' || $reservation['reservation_date'] > date('Y-m-d')) {
            flash('danger', 'A no-show can only be recorded on or after the reservation date.');
        } else {
            $statement = db()->prepare("UPDATE reservations SET no_show = 1, status = 'completed', checked_in_at = NULL, updated_at = NOW() WHERE id = :id");
            $statement->execute(['id' => (int) $reservation['id']]);
            audit_log('reservation_no_show', 'reservation', (int) $reservation['id'], ['by' => full_name($admin)]);
            notify_user(
                (int) $reservation['user_id'],
                'Reservation marked as no-show',
                'The reservation ' . $reservation['reference_no'] . ' for ' . $reservation['activity_name'] . ' was marked as a no-show because the group did not check in.',
                'warning',
                url('reservation_detail.php?id=' . $reservation['id'])
            );
            flash('success', 'No-show recorded and the reservation was closed.');
        }
    } elseif ($action === 'complete') {
        if ($reservation['status'] !== 'approved') {
            flash('danger', 'Only approved reservations can be marked as completed.');
        } else {
            $statement = db()->prepare("UPDATE reservations SET status = 'completed', updated_at = NOW() WHERE id = :id");
            $statement->execute(['id' => (int) $reservation['id']]);
            audit_log('reservation_completed', 'reservation', (int) $reservation['id']);
            flash('success', 'Reservation marked as completed.');
        }
    }

    redirect('admin/reservation_view.php?id=' . $reservation['id']);
}

$reservation = get_reservation((int) $reservation['id']);
$conflicts = check_conflicts(
    $reservation['reservation_date'],
    $reservation['start_time'],
    $reservation['end_time'],
    (int) $reservation['id']
);

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
$pendingChange = null;
foreach ($rescheduleHistory as $change) {
    if ($change['status'] === 'pending') {
        $pendingChange = $change;
        break;
    }
}

$canManage = in_array($reservation['status'], ['pending', 'approved'], true);
$canRecordAttendance = $reservation['status'] === 'approved' && $reservation['reservation_date'] <= date('Y-m-d');

$pageTitle = 'Review Reservation';
$activeNav = 'admin-reservations';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <a class="link-button" href="<?= e(url('admin/reservations.php')) ?>"><?= icon('arrow-left') ?> Back to reservation queue</a>
        <h1 style="margin-top:8px;">Review reservation</h1>
        <p>Evaluate the request, resolve conflicts, and record the final decision.</p>
    </div>
    <div class="page-actions">
        <?php if ($reservation['status'] === 'pending'): ?>
            <button type="button" class="btn btn-danger" data-modal-open="reject-dialog"><?= icon('ban') ?> Reject</button>
            <button type="button" class="btn btn-success" data-modal-open="approve-dialog"><?= icon('check') ?> Approve request</button>
        <?php elseif ($reservation['status'] === 'approved'): ?>
            <?php if ($canRecordAttendance): ?>
                <form method="post" data-confirm="Record check-in for this reservation?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="check_in">
                    <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                    <button type="submit" class="btn btn-success"><?= icon('user-check') ?> Record check-in</button>
                </form>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" data-modal-open="cancel-dialog"><?= icon('ban') ?> Cancel reservation</button>
        <?php endif; ?>
    </div>
</div>

<?php if ($reservation['status'] === 'pending' && $conflicts['blocking'] !== []): ?>
    <div class="notice notice-danger mb-2">
        <?= icon('alert-triangle') ?>
        <div>
            <strong>This request currently conflicts with an approved booking or blocked period.</strong><br>
            <?= e(conflict_message($conflicts)) ?> — You must reject this request or resolve the other schedule before it can be approved.
        </div>
    </div>
<?php endif; ?>

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
                <h2>Request details</h2>
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
                        <div class="meta-copy"><span>Requester</span><strong><?= e(trim($reservation['first_name'] . ' ' . $reservation['last_name'])) ?></strong></div>
                    </div>
                    <div class="meta-item">
                        <span class="meta-icon"><?= icon('mail') ?></span>
                        <div class="meta-copy"><span>Email</span><strong><?= e($reservation['email']) ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="detail-section">
                <h2>Purpose of use</h2>
                <p><?= e($reservation['purpose']) ?></p>
            </div>

            <?php if ($reservation['equipment']): ?>
                <div class="detail-section"><h2>Equipment & setup requirements</h2><p><?= e($reservation['equipment']) ?></p></div>
            <?php endif; ?>

            <?php if ($reservation['setup_notes']): ?>
                <div class="detail-section"><h2>Additional notes from requester</h2><p><?= e($reservation['setup_notes']) ?></p></div>
            <?php endif; ?>

            <div class="detail-section">
                <h2>Conflict check</h2>
                <?php if ($conflicts['blocking'] === [] && $conflicts['pending'] === []): ?>
                    <div class="notice notice-success"><?= icon('check-circle') ?><div><strong>No overlapping bookings.</strong><br>The selected time is free of approved reservations, blocked periods, and competing pending requests.</div></div>
                <?php else: ?>
                    <?php if ($conflicts['blocking'] !== []): ?>
                        <div class="notice notice-danger mb-2">
                            <?= icon('alert-triangle') ?>
                            <div>
                                <strong>Blocking conflict detected</strong><br>
                                <?= e(conflict_message($conflicts)) ?>.
                                <?php $buffer = setting_int('buffer_minutes', 0); if ($buffer > 0): ?><br><span class="text-small">A <?= $buffer ?>-minute buffer is included in conflict checks.</span><?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($conflicts['pending'] !== []): ?>
                        <div class="notice notice-warning">
                            <?= icon('info') ?>
                            <div><strong>Competing pending request<?= count($conflicts['pending']) === 1 ? '' : 's' ?></strong><br>
                                <?php foreach ($conflicts['pending'] as $pending): ?>
                                    <?= e($pending['title']) ?> · <?= e(format_time_range($pending['start_time'], $pending['end_time'])) ?> · <?= e($pending['requester']) ?><br>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($reservation['admin_note'] || $reservation['rejection_reason']): ?>
                <div class="detail-section">
                    <h2>Administrator remarks</h2>
                    <div class="notice <?= $reservation['status'] === 'rejected' ? 'notice-danger' : 'notice-info' ?>"><?= icon('shield') ?><div><?= e($reservation['admin_note'] ?: $reservation['rejection_reason']) ?></div></div>
                </div>
            <?php endif; ?>

            <?php if ($reservation['status'] === 'cancelled'): ?>
                <div class="detail-section">
                    <h2>Cancellation details</h2>
                    <div class="notice notice-warning"><?= icon('ban') ?><div><strong>Cancelled <?= e(format_datetime($reservation['cancelled_at'])) ?></strong><br><?= e($reservation['cancellation_reason'] ?: 'No reason provided.') ?><?php if ($reservation['canceller_first_name']): ?><br>By <?= e(trim($reservation['canceller_first_name'] . ' ' . $reservation['canceller_last_name'])) ?><?php endif; ?></div></div>
                </div>
            <?php endif; ?>

            <div class="detail-section">
                <h2>Schedule change requests</h2>
                <?php if ($rescheduleHistory === []): ?>
                    <p class="text-muted">No schedule changes have been requested.</p>
                <?php else: ?>
                    <?php foreach ($rescheduleHistory as $change): ?>
                        <div class="notice <?= $change['status'] === 'pending' ? 'notice-warning' : 'notice-info' ?> mb-2">
                            <?= icon($change['status'] === 'pending' ? 'refresh' : 'history') ?>
                            <div style="width:100%;">
                                <div class="flex-between flex-wrap">
                                    <strong><?= e(format_date($change['new_date'], 'D, M j, Y')) ?> · <?= e(format_time_range($change['new_start_time'], $change['new_end_time'])) ?></strong>
                                    <span class="badge <?= $change['status'] === 'approved' ? 'badge-approved' : ($change['status'] === 'rejected' ? 'badge-rejected' : 'badge-pending') ?>"><?= e(ucfirst($change['status'])) ?></span>
                                </div>
                                <div class="text-small mt-1"><?= e($change['reason']) ?> · requested <?= e(time_ago($change['created_at'])) ?></div>
                                <?php if ($change['admin_note']): ?><div class="text-small mt-1"><strong>Admin note:</strong> <?= e($change['admin_note']) ?></div><?php endif; ?>
                                <?php if ($change['status'] === 'pending'): ?>
                                    <div class="flex flex-wrap mt-2" style="gap:8px;">
                                        <form method="post" data-confirm="Approve this new schedule and update the official calendar?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="review_reschedule">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                            <input type="hidden" name="reschedule_id" value="<?= (int) $change['id'] ?>">
                                            <input type="hidden" name="decision" value="approve">
                                            <button type="submit" class="btn btn-success btn-sm"><?= icon('check') ?> Approve new schedule</button>
                                        </form>
                                        <form method="post" class="flex" style="gap:6px;align-items:center;" data-confirm="Reject this schedule change?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="review_reschedule">
                                            <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                            <input type="hidden" name="reschedule_id" value="<?= (int) $change['id'] ?>">
                                            <input type="hidden" name="decision" value="reject">
                                            <input class="form-control" type="text" name="admin_note" placeholder="Reason for rejection" style="min-height:33px;font-size:11.5px;width:180px;" required>
                                            <button type="submit" class="btn btn-danger btn-sm"><?= icon('x') ?> Reject</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="detail-side side-sticky">
            <div class="detail-section">
                <h2>Requester</h2>
                <div class="profile-header">
                    <span class="avatar avatar-lg"><?= e(initials($reservation['first_name'], $reservation['last_name'])) ?></span>
                    <div>
                        <h2 style="font-size:16px;"><?= e(trim($reservation['first_name'] . ' ' . $reservation['last_name'])) ?></h2>
                        <p><?= e(role_label($reservation['role'])) ?></p>
                    </div>
                </div>
                <div class="mt-2">
                    <div class="detail-stat"><span>Email</span><strong><?= e($reservation['email']) ?></strong></div>
                    <div class="detail-stat"><span>Phone</span><strong><?= e($reservation['phone'] ?: '—') ?></strong></div>
                    <div class="detail-stat"><span>Department</span><strong><?= e($reservation['user_department'] ?: '—') ?></strong></div>
                    <div class="detail-stat"><span>Organization</span><strong><?= e($reservation['organization'] ?: $reservation['user_organization'] ?: '—') ?></strong></div>
                </div>
            </div>

            <div class="detail-section">
                <h2>Decision & status</h2>
                <div class="detail-stat"><span>Current status</span><strong><?= status_badge($reservation['status']) ?></strong></div>
                <div class="detail-stat"><span>Reference</span><strong><?= e($reservation['reference_no']) ?></strong></div>
                <?php if ($reservation['approved_at']): ?><div class="detail-stat"><span>Approved</span><strong><?= e(format_datetime($reservation['approved_at'])) ?></strong></div><?php endif; ?>
                <?php if ($reservation['approver_first_name']): ?><div class="detail-stat"><span>Reviewed by</span><strong><?= e(trim($reservation['approver_first_name'] . ' ' . $reservation['approver_last_name'])) ?></strong></div><?php endif; ?>
                <?php if ($reservation['checked_in_at']): ?><div class="detail-stat"><span>Check-in</span><strong><?= e(format_datetime($reservation['checked_in_at'])) ?></strong></div><?php endif; ?>
                <?php if ((int) $reservation['no_show'] === 1): ?><div class="detail-stat"><span>Attendance</span><strong class="text-danger">No-show</strong></div><?php endif; ?>
            </div>

            <?php if ($canManage): ?>
                <div class="detail-section">
                    <h2>Actions</h2>
                    <div class="action-stack">
                        <?php if ($reservation['status'] === 'pending'): ?>
                            <button type="button" class="btn btn-success" data-modal-open="approve-dialog"><?= icon('check') ?> Approve request</button>
                            <button type="button" class="btn btn-danger" data-modal-open="reject-dialog"><?= icon('ban') ?> Reject request</button>
                        <?php endif; ?>
                        <?php if ($reservation['status'] === 'approved'): ?>
                            <?php if ($canRecordAttendance): ?>
                                <form method="post" data-confirm="Record check-in for this reservation?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="check_in">
                                    <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-block"><?= icon('user-check') ?> Record check-in</button>
                                </form>
                                <form method="post" data-confirm="Mark this reservation as a no-show? This will close the reservation.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="no_show">
                                    <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-block"><?= icon('alert-triangle') ?> Mark as no-show</button>
                                </form>
                                <form method="post" data-confirm="Mark this reservation as completed?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="complete">
                                    <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
                                    <button type="submit" class="btn btn-secondary btn-block"><?= icon('check-square') ?> Mark as completed</button>
                                </form>
                            <?php endif; ?>
                            <button type="button" class="btn btn-danger" data-modal-open="cancel-dialog"><?= icon('ban') ?> Cancel reservation</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($reservation['status'] === 'approved' && !$canRecordAttendance): ?>
                        <p class="form-hint mt-2">Attendance actions become available on <?= e(format_date($reservation['reservation_date'])) ?>.</p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="detail-section">
                <h2>Need to notify the requester?</h2>
                <p class="text-small text-muted mb-0">Every approval, rejection, cancellation, and reschedule decision automatically creates an in-app notification for the requester.</p>
            </div>
        </div>
    </div>
</div>

<?php if ($reservation['status'] === 'pending'): ?>
<dialog class="modal" id="approve-dialog">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="approve">
        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
        <div class="modal-head">
            <div><h2>Approve reservation</h2><p><?= e($reservation['activity_name']) ?> · <?= e(format_date($reservation['reservation_date'])) ?></p></div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <?php if ($conflicts['blocking'] !== []): ?>
                <div class="notice notice-danger mb-2"><?= icon('alert-triangle') ?><div>This request has a blocking conflict and cannot be approved until the schedule is resolved.</div></div>
            <?php endif; ?>
            <div class="notice notice-info mb-2"><?= icon('info') ?><div>Approving protects this time on the official gym calendar and notifies the requester.</div></div>
            <div class="form-group">
                <label for="approve_note">Note to requester <span class="text-muted">(optional)</span></label>
                <textarea class="form-control" id="approve_note" name="admin_note" placeholder="Add any instructions for the requester." maxlength="1000"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-success" <?= $conflicts['blocking'] !== [] ? 'disabled' : '' ?>><?= icon('check') ?> Confirm approval</button>
        </div>
    </form>
</dialog>

<dialog class="modal" id="reject-dialog">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject">
        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
        <div class="modal-head">
            <div><h2>Reject reservation</h2><p><?= e($reservation['activity_name']) ?> · <?= e(format_date($reservation['reservation_date'])) ?></p></div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="rejection_reason">Reason for rejection <span class="required">*</span></label>
                <textarea class="form-control" id="rejection_reason" name="rejection_reason" placeholder="Explain why the request cannot be approved." maxlength="1000" required></textarea>
                <span class="form-hint">This reason will be included in the requester's notification.</span>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" class="btn btn-danger"><?= icon('ban') ?> Reject request</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<?php if ($reservation['status'] === 'approved'): ?>
<dialog class="modal" id="cancel-dialog">
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="reservation_id" value="<?= (int) $reservation['id'] ?>">
        <div class="modal-head">
            <div><h2>Cancel reservation</h2><p>A notification will be sent to the requester.</p></div>
            <button type="button" class="modal-close" data-modal-close aria-label="Close"><?= icon('x') ?></button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="cancellation_reason">Reason for cancellation</label>
                <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" placeholder="Provide a reason for cancelling this approved reservation." maxlength="1000"></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-modal-close>Keep reservation</button>
            <button type="submit" class="btn btn-danger"><?= icon('ban') ?> Confirm cancellation</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
