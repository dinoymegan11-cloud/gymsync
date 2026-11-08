<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/reservations.php';

$admin = require_roles(['gym_admin', 'system_admin']);
$errors = [];
$editBlock = null;

$form = [
    'id' => 0,
    'title' => '',
    'start_date' => date('Y-m-d'),
    'end_date' => date('Y-m-d'),
    'all_day' => 0,
    'start_time' => '08:00',
    'end_time' => '12:00',
    'reason' => '',
];

if (request_method() === 'POST') {
    verify_csrf();
    $action = (string) post('action');

    if ($action === 'delete') {
        $blockId = (int) post('block_id');
        $statement = db()->prepare('SELECT * FROM blocked_periods WHERE id = :id');
        $statement->execute(['id' => $blockId]);
        $block = $statement->fetch();

        if ($block) {
            $delete = db()->prepare('DELETE FROM blocked_periods WHERE id = :id');
            $delete->execute(['id' => $blockId]);
            audit_log('block_deleted', 'block', $blockId, ['title' => $block['title'], 'start_date' => $block['start_date'], 'end_date' => $block['end_date']]);
            flash('success', 'The blocked period was removed from the gym calendar.');
        } else {
            flash('danger', 'The blocked period could not be found.');
        }

        redirect('admin/blocks.php');
    }

    if ($action === 'save') {
        $form = [
            'id' => (int) post('block_id'),
            'title' => trim((string) post('title')),
            'start_date' => trim((string) post('start_date')),
            'end_date' => trim((string) post('end_date')),
            'all_day' => post('all_day') ? 1 : 0,
            'start_time' => trim((string) post('start_time')),
            'end_time' => trim((string) post('end_time')),
            'reason' => trim((string) post('reason')),
        ];

        if (mb_strlen($form['title']) < 3) {
            $errors[] = 'Please enter a title for the blocked period.';
        }

        $startObject = DateTime::createFromFormat('!Y-m-d', $form['start_date']);
        $endObject = DateTime::createFromFormat('!Y-m-d', $form['end_date']);
        if (!$startObject || $startObject->format('Y-m-d') !== $form['start_date']) {
            $errors[] = 'Please choose a valid start date.';
        }
        if (!$endObject || $endObject->format('Y-m-d') !== $form['end_date']) {
            $errors[] = 'Please choose a valid end date.';
        }
        if ($startObject && $endObject && $endObject < $startObject) {
            $errors[] = 'The end date cannot be earlier than the start date.';
        }

        if (!$form['all_day']) {
            $form['start_time'] = normalize_time($form['start_time']);
            $form['end_time'] = normalize_time($form['end_time']);
            if ($form['start_time'] === '' || $form['end_time'] === '') {
                $errors[] = 'Please provide valid start and end times, or mark the block as all day.';
            } elseif (time_to_minutes($form['end_time']) <= time_to_minutes($form['start_time'])) {
                $errors[] = 'The end time must be later than the start time.';
            }
        }

        if ($errors === []) {
            $startTime = $form['all_day'] ? null : $form['start_time'];
            $endTime = $form['all_day'] ? null : $form['end_time'];
            $affected = reservations_affected_by_period($form['start_date'], $form['end_date'], $startTime, $endTime);

            if ($form['id'] > 0) {
                $statement = db()->prepare(
                    'UPDATE blocked_periods
                     SET title = :title, start_date = :start_date, end_date = :end_date,
                         start_time = :start_time, end_time = :end_time, reason = :reason,
                         created_by = :created_by, updated_at = NOW()
                     WHERE id = :id'
                );
                $statement->execute([
                    'title' => $form['title'],
                    'start_date' => $form['start_date'],
                    'end_date' => $form['end_date'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'reason' => $form['reason'] !== '' ? $form['reason'] : null,
                    'created_by' => (int) $admin['id'],
                    'id' => $form['id'],
                ]);
                $blockId = $form['id'];
                audit_log('block_updated', 'block', $blockId, [
                    'title' => $form['title'],
                    'start_date' => $form['start_date'],
                    'end_date' => $form['end_date'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);
                flash('success', 'The blocked period was updated.');
            } else {
                $statement = db()->prepare(
                    'INSERT INTO blocked_periods (title, start_date, end_date, start_time, end_time, reason, created_by, created_at, updated_at)
                     VALUES (:title, :start_date, :end_date, :start_time, :end_time, :reason, :created_by, NOW(), NOW())'
                );
                $statement->execute([
                    'title' => $form['title'],
                    'start_date' => $form['start_date'],
                    'end_date' => $form['end_date'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'reason' => $form['reason'] !== '' ? $form['reason'] : null,
                    'created_by' => (int) $admin['id'],
                ]);
                $blockId = (int) db()->lastInsertId();
                audit_log('block_created', 'block', $blockId, [
                    'title' => $form['title'],
                    'start_date' => $form['start_date'],
                    'end_date' => $form['end_date'],
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);
                flash('success', 'The gym is now blocked for the selected period.');
            }

            $timeLabel = $startTime !== null ? format_time_range($startTime, $endTime) : 'All day';
            $dateLabel = $form['start_date'] === $form['end_date'] ? format_date($form['start_date']) : format_date($form['start_date']) . ' – ' . format_date($form['end_date']);

            foreach ($affected as $affectedReservation) {
                $isApproved = $affectedReservation['status'] === 'approved';
                notify_user(
                    (int) $affectedReservation['user_id'],
                    $isApproved ? 'Approved reservation affected by gym block' : 'Pending request affected by gym block',
                    'The gym has been marked unavailable for ' . $affectedReservation['activity_name'] . ' on ' . $dateLabel . ' (' . $timeLabel . ') due to: ' . $form['title'] . '. ' . ($isApproved ? 'The gym administration will contact you regarding this change.' : 'Your request may be rejected or rescheduled.'),
                    $isApproved ? 'danger' : 'warning',
                    url('reservation_detail.php?id=' . $affectedReservation['id'])
                );
            }

            if ($affected !== []) {
                audit_log('block_affected_reservations', 'block', $blockId, [
                    'reservation_ids' => array_column($affected, 'id'),
                    'count' => count($affected),
                ]);
                flash('warning', count($affected) . ' affected reservation' . (count($affected) === 1 ? '' : 's') . ' were notified about the schedule block.');
            }

            redirect('admin/blocks.php');
        }
    }
}

if (request_method() === 'GET' && (int) query('edit', 0) > 0) {
    $statement = db()->prepare('SELECT * FROM blocked_periods WHERE id = :id');
    $statement->execute(['id' => (int) query('edit')]);
    $editBlock = $statement->fetch();

    if ($editBlock) {
        $form = [
            'id' => (int) $editBlock['id'],
            'title' => $editBlock['title'],
            'start_date' => $editBlock['start_date'],
            'end_date' => $editBlock['end_date'],
            'all_day' => $editBlock['start_time'] === null ? 1 : 0,
            'start_time' => $editBlock['start_time'] ? substr($editBlock['start_time'], 0, 5) : '08:00',
            'end_time' => $editBlock['end_time'] ? substr($editBlock['end_time'], 0, 5) : '12:00',
            'reason' => $editBlock['reason'] ?? '',
        ];
    }
}

$blocksStatement = db()->query(
    'SELECT b.*, u.first_name, u.last_name
     FROM blocked_periods b
     LEFT JOIN users u ON u.id = b.created_by
     ORDER BY CASE WHEN b.end_date >= CURDATE() THEN 0 ELSE 1 END, b.start_date ASC, b.start_time ASC
     LIMIT 100'
);
$blocks = $blocksStatement->fetchAll();

foreach ($blocks as &$block) {
    $block['affected_count'] = $block['end_date'] >= date('Y-m-d')
        ? count(reservations_affected_by_period($block['start_date'], $block['end_date'], $block['start_time'], $block['end_time']))
        : 0;
}
unset($block);

$pageTitle = 'Maintenance & Blocked Periods';
$activeNav = 'admin-blocks';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Maintenance & blocked periods</h1>
        <p>Reserve the gym for cleaning, repairs, holidays, and other periods when it cannot be used.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('admin/reservations.php')) ?>"><?= icon('check-square') ?> Reservation requests</a>
        <a class="btn btn-primary" href="<?= e(url('calendar.php')) ?>"><?= icon('calendar') ?> Gym calendar</a>
    </div>
</div>

<div class="grid grid-1-2">
    <div class="card">
        <div class="card-header">
            <div>
                <h2><?= $editBlock ? 'Edit blocked period' : 'Block a period' ?></h2>
                <p>Approved and pending reservations that overlap will be notified.</p>
            </div>
            <?php if ($editBlock): ?><a class="link-button" href="<?= e(url('admin/blocks.php')) ?>">Cancel edit</a><?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($errors !== []): ?>
                <div class="error-summary">
                    <strong><?= icon('alert-triangle') ?> Could not save the block</strong>
                    <ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>
            <form method="post" id="block-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="block_id" value="<?= (int) $form['id'] ?>">
                <div class="form-grid">
                    <div class="form-group full">
                        <label for="title">Block title <span class="required">*</span></label>
                        <input class="form-control" type="text" id="title" name="title" value="<?= e($form['title']) ?>" placeholder="e.g. Gymnasium maintenance" maxlength="150" required>
                    </div>
                    <div class="form-group">
                        <label for="start_date">Start date <span class="required">*</span></label>
                        <input class="form-control" type="date" id="start_date" name="start_date" value="<?= e($form['start_date']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="end_date">End date <span class="required">*</span></label>
                        <input class="form-control" type="date" id="end_date" name="end_date" value="<?= e($form['end_date']) ?>" required>
                    </div>
                    <div class="form-group full">
                        <label class="checkbox">
                            <input type="checkbox" id="all_day" name="all_day" value="1"<?= checkbox_checked($form['all_day']) ?>>
                            <span>Block the entire day (or every day in the selected range)</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="block_start_time">Start time</label>
                        <input class="form-control" type="time" id="block_start_time" name="start_time" value="<?= e($form['all_day'] ? '08:00' : $form['start_time']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="block_end_time">End time</label>
                        <input class="form-control" type="time" id="block_end_time" name="end_time" value="<?= e($form['all_day'] ? '12:00' : $form['end_time']) ?>">
                    </div>
                    <div class="form-group full">
                        <label for="reason">Reason / internal notes</label>
                        <textarea class="form-control" id="reason" name="reason" placeholder="Explain why the gym is unavailable." maxlength="1000" style="min-height:88px;"><?= e($form['reason']) ?></textarea>
                    </div>
                </div>
                <div class="form-actions">
                    <?php if ($editBlock): ?><a class="btn btn-ghost" href="<?= e(url('admin/blocks.php')) ?>">Cancel</a><?php endif; ?>
                    <button type="submit" class="btn btn-primary"><?= icon('wrench') ?> <?= $editBlock ? 'Save changes' : 'Block this period' ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <div>
                <h2>Scheduled blocked periods</h2>
                <p>Upcoming periods appear first. Past blocks remain for the record.</p>
            </div>
            <span class="badge badge-neutral"><?= count($blocks) ?></span>
        </div>
        <?php if ($blocks === []): ?>
            <div class="card-body">
                <div class="empty-state compact">
                    <div class="empty-icon"><?= icon('wrench') ?></div>
                    <h3>No blocked periods</h3>
                    <p>The full gym schedule is currently open to reservation requests.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Block</th><th>Period</th><th>Affected</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($blocks as $block): ?>
                        <?php $isPast = $block['end_date'] < date('Y-m-d'); ?>
                        <tr>
                            <td>
                                <span class="primary-cell"><?= e($block['title']) ?></span>
                                <span class="subtext"><?= e(truncate_text($block['reason'] ?: 'No reason provided', 80)) ?></span>
                                <?php if ($block['first_name']): ?><span class="subtext">Created by <?= e(trim($block['first_name'] . ' ' . $block['last_name'])) ?></span><?php endif; ?>
                            </td>
                            <td>
                                <span class="nowrap"><?= e(format_date($block['start_date'])) ?><?= $block['start_date'] !== $block['end_date'] ? ' – ' . e(format_date($block['end_date'])) : '' ?></span>
                                <span class="subtext"><?= $block['start_time'] ? e(format_time_range($block['start_time'], $block['end_time'])) : 'All day' ?> <?= $isPast ? '· Past' : '' ?></span>
                            </td>
                            <td>
                                <?php if ($block['affected_count'] > 0): ?>
                                    <span class="badge badge-pending"><?= (int) $block['affected_count'] ?> reservation<?= (int) $block['affected_count'] === 1 ? '' : 's' ?></span>
                                <?php else: ?>
                                    <span class="text-muted text-small">None</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a class="table-icon-button" href="<?= e(url('admin/blocks.php?edit=' . $block['id'])) ?>" title="Edit block"><?= icon('pencil') ?></a>
                                    <form method="post" data-confirm="Delete this blocked period? The gym will become available for new requests.">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="block_id" value="<?= (int) $block['id'] ?>">
                                        <button type="submit" class="table-icon-button danger" title="Delete block"><?= icon('trash') ?></button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$extraScripts = '<script>
document.addEventListener("DOMContentLoaded", function () {
    var allDay = document.getElementById("all_day");
    var start = document.getElementById("block_start_time");
    var end = document.getElementById("block_end_time");
    if (!allDay || !start || !end) return;
    function sync() {
        start.disabled = allDay.checked;
        end.disabled = allDay.checked;
    }
    allDay.addEventListener("change", sync);
    sync();
});
</script>';
require __DIR__ . '/../includes/footer.php';
?>
