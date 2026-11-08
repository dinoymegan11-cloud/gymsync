<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

$user = require_login();
$upcomingStatement = db()->prepare(
    "SELECT r.*, u.first_name, u.last_name
     FROM reservations r
     JOIN users u ON u.id = r.user_id
     WHERE r.status = 'approved' AND r.reservation_date >= :today
     ORDER BY r.reservation_date ASC, r.start_time ASC
     LIMIT 5"
);
$upcomingStatement->execute(['today' => date('Y-m-d')]);
$upcomingSchedule = $upcomingStatement->fetchAll();

$blockStatement = db()->prepare(
    'SELECT * FROM blocked_periods WHERE end_date >= :today ORDER BY start_date ASC, start_time ASC LIMIT 5'
);
$blockStatement->execute(['today' => date('Y-m-d')]);
$upcomingBlocks = $blockStatement->fetchAll();

$pageTitle = 'Gym Availability Calendar';
$activeNav = 'calendar';
$extraHead = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.css">';
require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Gym availability calendar</h1>
        <p>See approved bookings, pending requests, and blocked periods before choosing a schedule.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-secondary" href="<?= e(url('my_reservations.php')) ?>"><?= icon('clipboard-list') ?> My reservations</a>
        <a class="btn btn-primary" href="<?= e(url('reserve.php')) ?>"><?= icon('plus-circle') ?> Reserve the gym</a>
    </div>
</div>

<div class="grid grid-2-1">
    <div class="card calendar-card">
        <div class="calendar-toolbar">
            <div class="calendar-legend">
                <span class="legend-item"><span class="legend-dot approved"></span> Approved</span>
                <span class="legend-item"><span class="legend-dot pending"></span> Pending</span>
                <span class="legend-item"><span class="legend-dot blocked"></span> Blocked / closed</span>
            </div>
            <span class="text-small text-muted">Click any event for details</span>
        </div>
        <div id="gym-calendar" class="calendar-loading">Loading gym schedule…</div>
    </div>

    <div class="calendar-side">
        <div class="card calendar-info-card">
            <h3><?= icon('calendar', 'icon-sm') ?> At a glance</h3>
            <div class="info-row"><span>Operating hours</span><strong><?= e(format_time(setting('opening_time', '06:00'))) ?> – <?= e(format_time(setting('closing_time', '22:00'))) ?></strong></div>
            <div class="info-row"><span>Buffer between bookings</span><strong><?= (int) setting_int('buffer_minutes', 0) ?> min</strong></div>
            <div class="info-row"><span>Advance booking limit</span><strong><?= (int) setting_int('max_advance_days', 90) ?> days</strong></div>
            <div class="info-row"><span>Maximum attendees</span><strong><?= number_format(setting_int('max_attendees', 1000)) ?></strong></div>
            <div class="notice notice-info mt-2">
                <?= icon('info') ?>
                <div>Pending requests do not block the calendar yet. Approved bookings are protected from overlapping reservations.</div>
            </div>
        </div>

        <div class="card calendar-info-card">
            <div class="flex-between mb-2">
                <h3 class="mb-0">Upcoming approved</h3>
                <span class="badge badge-approved"><?= count($upcomingSchedule) ?></span>
            </div>
            <?php if ($upcomingSchedule === []): ?>
                <p class="text-small text-muted mb-0">No approved reservations are scheduled ahead.</p>
            <?php else: ?>
                <div class="day-agenda">
                    <?php foreach ($upcomingSchedule as $item): ?>
                        <div class="agenda-item">
                            <div class="agenda-time"><?= e(format_date($item['reservation_date'], 'M j')) ?><br><?= e(format_time($item['start_time'])) ?></div>
                            <div class="agenda-content">
                                <strong><?= e($item['activity_name']) ?></strong>
                                <small><?= e($item['organization'] ?: event_type_label($item['event_type'])) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card calendar-info-card">
            <div class="flex-between mb-2">
                <h3 class="mb-0">Upcoming blocked periods</h3>
                <span class="badge badge-rejected"><?= count($upcomingBlocks) ?></span>
            </div>
            <?php if ($upcomingBlocks === []): ?>
                <p class="text-small text-muted mb-0">No maintenance or closure periods are scheduled.</p>
            <?php else: ?>
                <div class="day-agenda">
                    <?php foreach ($upcomingBlocks as $block): ?>
                        <div class="agenda-item">
                            <div class="agenda-time"><?= e(format_date($block['start_date'], 'M j')) ?><br><?= $block['start_time'] ? e(format_time($block['start_time'])) : 'All day' ?></div>
                            <div class="agenda-content">
                                <strong><?= e($block['title']) ?></strong>
                                <small><?= $block['start_date'] !== $block['end_date'] ? e(format_date($block['start_date']) . ' – ' . format_date($block['end_date'])) : 'Unavailable' ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<dialog class="modal modal-lg" id="calendar-event-dialog">
    <div class="modal-head">
        <div>
            <h2 id="event-dialog-title">Reservation details</h2>
            <p id="event-dialog-subtitle"></p>
        </div>
        <button type="button" class="modal-close" data-modal-close aria-label="Close"><?= icon('x') ?></button>
    </div>
    <div class="modal-body">
        <div class="meta-grid">
            <div class="meta-item">
                <span class="meta-icon"><?= icon('calendar') ?></span>
                <div class="meta-copy"><span>Date</span><strong id="event-dialog-date">—</strong></div>
            </div>
            <div class="meta-item">
                <span class="meta-icon"><?= icon('clock') ?></span>
                <div class="meta-copy"><span>Time</span><strong id="event-dialog-time">—</strong></div>
            </div>
            <div class="meta-item">
                <span class="meta-icon"><?= icon('building') ?></span>
                <div class="meta-copy"><span>Organization / office</span><strong id="event-dialog-organization">—</strong></div>
            </div>
            <div class="meta-item">
                <span class="meta-icon"><?= icon('clipboard-list') ?></span>
                <div class="meta-copy"><span>Activity type</span><strong id="event-dialog-type">—</strong></div>
            </div>
        </div>
        <div class="notice mt-3" id="event-dialog-notice">
            <?= icon('info') ?>
            <div id="event-dialog-message"></div>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-modal-close>Close</button>
        <a class="btn btn-primary hidden" id="event-dialog-link" href="#"><?= icon('external-link') ?> Open record</a>
    </div>
</dialog>
<?php
$openingTime = normalize_time(setting('opening_time', '06:00'));
$closingTime = normalize_time(setting('closing_time', '22:00'));
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    var calendarEl = document.getElementById("gym-calendar");
    if (!calendarEl || typeof FullCalendar === "undefined") return;
    calendarEl.classList.remove("calendar-loading");
    calendarEl.textContent = "";

    var dialog = document.getElementById("calendar-event-dialog");
    var link = document.getElementById("event-dialog-link");
    var notice = document.getElementById("event-dialog-notice");

    var calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 760 ? "listMonth" : "dayGridMonth",
        headerToolbar: {
            left: "prev,next today",
            center: "title",
            right: "dayGridMonth,timeGridWeek,listMonth"
        },
        buttonText: { today: "Today", month: "Month", week: "Week", list: "List" },
        height: "auto",
        contentHeight: "auto",
        expandRows: true,
        dayMaxEvents: 3,
        nowIndicator: true,
        slotMinTime: "' . e($openingTime) . '",
        slotMaxTime: "' . e($closingTime) . '",
        eventTimeFormat: { hour: "numeric", minute: "2-digit", meridiem: "short" },
        events: "' . e(url('api/calendar_events.php')) . '",
        eventClick: function (info) {
            info.jsEvent.preventDefault();
            var props = info.event.extendedProps;
            document.getElementById("event-dialog-title").textContent = info.event.title;
            document.getElementById("event-dialog-subtitle").textContent = props.reference ? "Reference " + props.reference : "Official gym schedule";
            document.getElementById("event-dialog-date").textContent = props.dateLabel || "—";
            document.getElementById("event-dialog-time").textContent = (props.startLabel || "—") + " – " + (props.endLabel || "—");
            document.getElementById("event-dialog-organization").textContent = props.organization || "Gym administration";
            document.getElementById("event-dialog-type").textContent = props.eventType || "Gym activity";

            var message = "";
            if (props.kind === "block") {
                message = props.reason || "The gym is unavailable during this period.";
                notice.className = "notice notice-danger mt-3";
            } else if (props.status === "pending") {
                message = "This request is awaiting gym administrator review. Pending requests are not yet protected on the official calendar.";
                notice.className = "notice notice-warning mt-3";
            } else {
                message = "This reservation has been approved and is protected from overlapping bookings.";
                notice.className = "notice notice-success mt-3";
            }
            document.getElementById("event-dialog-message").textContent = message;

            if (props.url) {
                link.href = props.url;
                link.classList.remove("hidden");
            } else {
                link.classList.add("hidden");
            }

            if (typeof dialog.showModal === "function") dialog.showModal();
        },
        eventDidMount: function (info) {
            info.el.setAttribute("title", info.event.title);
        }
    });

    calendar.render();
});
</script>';
require __DIR__ . '/includes/footer.php';
?>
