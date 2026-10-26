<?php
declare(strict_types=1);
?>
<?php if (!empty($authPage)): ?>
        </div>
        <p class="auth-copyright">© <?= date('Y') ?> GymSync · Cebu Technological University – Daanbantayan Campus</p>
    </section>
</div>
<?php else: ?>
        </main>
        <footer class="app-footer">
            <span>© <?= date('Y') ?> GymSync · Cebu Technological University – Daanbantayan Campus</span>
            <span>All reservations are subject to gym administration review.</span>
        </footer>
    </div>
</div>
<?php endif; ?>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?= $extraScripts ?? '' ?>
</body>
</html>
