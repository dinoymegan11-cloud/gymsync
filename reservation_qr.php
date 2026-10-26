<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/reservations.php';

require_roles(['gym_admin', 'system_admin']);

$pageTitle = 'Reservation QR Code';
$activeNav = 'reservation-qr';
$extraScripts = '<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    const container = document.getElementById("reservation-qr");
    const message = document.getElementById("reservation-qr-message");
    const target = window.location.origin + container.dataset.target;

    if (typeof QRCode === "undefined") {
        message.textContent = "The QR code could not load. Check your internet connection and refresh this page.";
        return;
    }

    new QRCode(container, {
        text: target,
        width: 256,
        height: 256,
        colorDark: "#102c26",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.M
    });
    document.getElementById("reservation-qr-link").textContent = target;
});
</script>';

require __DIR__ . '/includes/header.php';
?>
<div class="page-heading">
    <div>
        <h1>Gym reservation QR code</h1>
        <p>Display this QR code at the gym so users can open the reservation form on their phones.</p>
    </div>
    <div class="page-actions">
        <button type="button" class="btn btn-primary" onclick="window.print()"><?= icon('download') ?> Print QR code</button>
    </div>
</div>

<div class="card card-pad reservation-qr-card">
    <div id="reservation-qr" class="reservation-qr" data-target="<?= e(url('reserve.php')) ?>" role="img" aria-label="QR code linking to the gym reservation form"></div>
    <p id="reservation-qr-message" class="form-hint" aria-live="polite">Loading QR code…</p>
    <div class="reservation-qr-copy">
        <h2>Scan to reserve the gym</h2>
        <p>Users who are not signed in will be asked to log in or create an account, then returned to the reservation form.</p>
        <span id="reservation-qr-link" class="text-small"></span>
        <p class="form-hint mt-2">If using XAMPP, open this page with your computer's network address before printing. A localhost QR code will not open on other phones.</p>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
