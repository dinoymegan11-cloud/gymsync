<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$user = auth_user();
if ($user === null) {
    flash('warning', 'Please sign in or create an account to reserve the gym.');
    redirect('login.php?next=reserve.php');
}

$form = [
    'activity_name' => '',
    'organization' => $user['organization'] ?: $user['department'],
    'event_type' => 'other',
    'reservation_date' => date('Y-m-d', strtotime('+1 day')),
    'start_time' => '08:00',
    'end_time' => '10:00',
    'attendees' => '',
    'purpose' => '',
    'equipment' => '',
    'setup_notes' => '',
];
$errors = [];

if (request_method() === 'POST') {
    verify_csrf();

    foreach ($form as $key => $value) {
        $form[$key] = trim((string) post($key, $value));
    }

    $result = create_reservation($form, $user);

    if ($result['ok']) {
        flash('success', 'Reservation request ' . $result['reference'] . ' was submitted successfully. Please wait for the gym administrator review.');

        if (!empty($result['pending_conflicts'])) {
            $count = count($result['pending_conflicts']);
            flash('warning', 'Note: ' . $count . ' other pending request' . ($count === 1 ? '' : 's') . ' overlap' . ($count === 1 ? 's' : '') . ' with this time. The administrator will review the competing requests.');
        }

        redirect('reservation_detail.php?id=' . $result['id']);
    }

    $errors = $result['errors'];
}

$pageTitle = 'Reserve the Gym';
$activeNav = 'reserve';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Reserve the gymnasium</h1>
        <p>Choose a schedule, describe your activity, and submit it for administrator review.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('calendar.php')) ?>"><?= icon('calendar') ?> Check calendar</a>
    </div>
</div>

<div class="grid grid-2-1">
    <div class="card">
        <div class="card-header">
            <div>
                <h2>Reservation request form</h2>
                <p>Fields marked with <span class="text-danger">*</span> are required.</p>
            </div>
            <span class="badge badge-info">Step 3 of 3</span>
        </div>
        <div class="card-body">
            <div class="stepper">
                <div class="step done"><span class="step-num"><?= icon('check', 'icon-sm') ?></span><span>Check availability</span></div>
                <span class="step-line"></span>
                <div class="step done"><span class="step-num"><?= icon('check', 'icon-sm') ?></span><span>Activity details</span></div>
                <span class="step-line"></span>
                <div class="step active"><span class="step-num">3</span><span>Submit request</span></div>
            </div>

            <?php if ($errors !== []): ?>
                <div class="error-summary">
                    <strong><?= icon('alert-triangle') ?> Your request could not be submitted</strong>
                    <ul>
                        <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" data-availability-form data-availability-target="#availability-check" data-availability-url="<?= e(url('api/check_conflict.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-duration-fields novalidate>
                <?= csrf_field() ?>
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="activity_name">Activity or event name <span class="required">*</span></label>
                        <input class="form-control" type="text" id="activity_name" name="activity_name" value="<?= e($form['activity_name']) ?>" maxlength="150" placeholder="e.g. Basketball Team Practice" required>
                    </div>

                    <div class="form-group">
                        <label for="organization">Requesting organization / department <span class="required">*</span></label>
                        <input class="form-control" type="text" id="organization" name="organization" value="<?= e($form['organization']) ?>" maxlength="160" placeholder="e.g. Supreme Student Council" required>
                    </div>

                    <div class="form-group">
                        <label for="event_type">Activity type <span class="required">*</span></label>
                        <select class="form-control" id="event_type" name="event_type" required>
                            <?php foreach (['class' => 'Regular Class', 'sports' => 'Sports Practice', 'school_event' => 'School Event', 'organization' => 'Organization Activity', 'meeting' => 'Meeting', 'other' => 'Other'] as $value => $label): ?>
                                <option value="<?= e($value) ?>"<?= option_selected($form['event_type'], $value) ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="reservation_date">Date of reservation <span class="required">*</span></label>
                        <input class="form-control" type="date" id="reservation_date" name="reservation_date" value="<?= e($form['reservation_date']) ?>" min="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d', strtotime('+' . setting_int('max_advance_days', 90) . ' days'))) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Reserved time <span class="required">*</span></label>
                        <div class="form-row">
                            <input class="form-control" type="time" name="start_time" value="<?= e($form['start_time']) ?>" min="<?= e(substr(normalize_time(setting('opening_time', '06:00')), 0, 5)) ?>" max="<?= e(substr(normalize_time(setting('closing_time', '22:00')), 0, 5)) ?>" required aria-label="Start time">
                            <span class="text-muted">to</span>
                            <input class="form-control" type="time" name="end_time" value="<?= e($form['end_time']) ?>" min="<?= e(substr(normalize_time(setting('opening_time', '06:00')), 0, 5)) ?>" max="<?= e(substr(normalize_time(setting('closing_time', '22:00')), 0, 5)) ?>" required aria-label="End time">
                        </div>
                        <span class="form-hint" data-duration-output></span>
                    </div>

                    <div class="form-group">
                        <label for="attendees">Expected attendees <span class="required">*</span></label>
                        <input class="form-control" type="number" id="attendees" name="attendees" value="<?= e($form['attendees']) ?>" min="1" max="<?= (int) setting_int('max_attendees', 1000) ?>" placeholder="e.g. 40" required>
                        <span class="form-hint">Gym capacity: <?= number_format(setting_int('max_attendees', 1000)) ?> people.</span>
                    </div>

                    <div class="form-group">
                        <label for="equipment">Equipment or setup requirements</label>
                        <input class="form-control" type="text" id="equipment" name="equipment" value="<?= e($form['equipment']) ?>" maxlength="500" placeholder="e.g. chairs, sound system, volleyball nets">
                    </div>

                    <div class="form-group full">
                        <label for="purpose">Purpose of use <span class="required">*</span></label>
                        <textarea class="form-control" id="purpose" name="purpose" maxlength="2000" placeholder="Describe the activity and why the gymnasium is needed." required><?= e($form['purpose']) ?></textarea>
                    </div>

                    <div class="form-group full">
                        <label for="setup_notes">Additional notes for the gym staff</label>
                        <textarea class="form-control" id="setup_notes" name="setup_notes" maxlength="1000" placeholder="Any special arrangements, early access needs, or safety considerations." style="min-height:82px;"><?= e($form['setup_notes']) ?></textarea>
                    </div>
                </div>

                <div class="availability-result" id="availability-check">
                    Choose a date, start time, and end time to check availability.
                </div>

                <div class="form-actions between">
                    <a class="btn btn-ghost" href="<?= e(url('index.php')) ?>">Cancel</a>
                    <button type="submit" class="btn btn-primary btn-lg"><?= icon('send') ?> Submit reservation request</button>
                </div>
            </form>
        </div>
    </div>

    <div class="calendar-side">
        <div class="card calendar-info-card">
            <h3><?= icon('shield', 'icon-sm') ?> Before you submit</h3>
            <ul class="helper-list">
                <li><?= icon('check-circle') ?><span>Check the date and time carefully. Requests with conflicts cannot be approved.</span></li>
                <li><?= icon('check-circle') ?><span>Requests must be within <?= e(format_time(setting('opening_time', '06:00'))) ?> – <?= e(format_time(setting('closing_time', '22:00'))) ?>.</span></li>
                <li><?= icon('check-circle') ?><span>Keep a <?= (int) setting_int('buffer_minutes', 0) ?>-minute buffer between activities for preparation and cleanup.</span></li>
                <li><?= icon('check-circle') ?><span>Reservations can be made up to <?= (int) setting_int('max_advance_days', 90) ?> days in advance.</span></li>
                <li><?= icon('check-circle') ?><span>Approval is handled by the gym administrator based on school policies.</span></li>
            </ul>
        </div>

        <div class="card calendar-info-card">
            <h3><?= icon('info', 'icon-sm') ?> How approval works</h3>
            <div class="timeline">
                <div class="timeline-item"><strong>Submit your request</strong><small>The system immediately checks for overlaps.</small></div>
                <div class="timeline-item"><strong>Administrator review</strong><small>The gym office evaluates the request and any competing bookings.</small></div>
                <div class="timeline-item"><strong>Receive a decision</strong><small>You will be notified once the request is approved or rejected.</small></div>
                <div class="timeline-item"><strong>Approved and protected</strong><small>The time is added to the official calendar and cannot be double-booked.</small></div>
            </div>
        </div>

        <div class="card calendar-info-card">
            <h3><?= icon('phone', 'icon-sm') ?> Need assistance?</h3>
            <div class="info-row"><span>Gym office email</span><strong><?= e(setting('contact_email', 'gymnasium@ctu.edu.ph')) ?></strong></div>
            <div class="info-row"><span>Contact number</span><strong><?= e(setting('contact_phone', '(032) 000-0000')) ?></strong></div>
            <div class="info-row"><span>Current account</span><strong><?= e(full_name($user)) ?></strong></div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
