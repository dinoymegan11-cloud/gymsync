(() => {
    'use strict';

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    const sidebar = qs('#sidebar');
    const sidebarOverlay = qs('.sidebar-overlay');

    const closeSidebar = () => {
        if (!sidebar) return;
        sidebar.classList.remove('open');
        sidebarOverlay?.classList.remove('open');
        document.body.style.overflow = '';
    };

    const openSidebar = () => {
        if (!sidebar) return;
        sidebar.classList.add('open');
        sidebarOverlay?.classList.add('open');
        document.body.style.overflow = 'hidden';
    };

    qsa('[data-sidebar-open]').forEach((button) => {
        button.addEventListener('click', openSidebar);
    });

    qsa('[data-sidebar-close]').forEach((button) => {
        button.addEventListener('click', closeSidebar);
    });

    qsa('.nav-link').forEach((link) => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 1024) closeSidebar();
        });
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024) closeSidebar();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeSidebar();
    });

    const dismissFlash = (flash) => {
        if (!flash) return;
        flash.style.transition = 'opacity .18s ease, transform .18s ease';
        flash.style.opacity = '0';
        flash.style.transform = 'translateY(-4px)';
        window.setTimeout(() => flash.remove(), 180);
    };

    qsa('[data-dismiss-flash]').forEach((button) => {
        button.addEventListener('click', () => dismissFlash(button.closest('.flash')));
    });

    qsa('.flash').forEach((flash) => {
        window.setTimeout(() => dismissFlash(flash), 8000);
    });

    qsa('[data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = qs(button.dataset.togglePassword);
            if (!input) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            button.classList.toggle('is-visible', !showing);
        });
    });

    qsa('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Are you sure?')) {
                event.preventDefault();
            }
        });
    });

    qsa('[data-confirm-click]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (!window.confirm(link.dataset.confirmClick || 'Are you sure?')) {
                event.preventDefault();
            }
        });
    });

    qsa('[data-modal-open]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = qs('#' + button.dataset.modalOpen);
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });

    qsa('[data-modal-close]').forEach((button) => {
        button.addEventListener('click', () => {
            button.closest('dialog')?.close();
        });
    });

    qsa('dialog.modal').forEach((dialog) => {
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    });

    qsa('dialog[data-auto-open]').forEach((dialog) => {
        if (typeof dialog.showModal === 'function') dialog.showModal();
    });

    qsa('[data-print]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    const escapeHtml = (value) => String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const formatTime = (value) => {
        if (!value) return '';
        const parts = String(value).split(':');
        let hours = Number(parts[0]);
        const minutes = parts[1] || '00';
        const suffix = hours >= 12 ? 'PM' : 'AM';
        hours = hours % 12 || 12;
        return `${hours}:${minutes} ${suffix}`;
    };

    const availabilityForms = qsa('[data-availability-form]');

    availabilityForms.forEach((form) => {
        const result = qs(form.dataset.availabilityTarget || '#availability-check');
        const dateInput = qs('[name="reservation_date"]', form);
        const startInput = qs('[name="start_time"]', form);
        const endInput = qs('[name="end_time"]', form);
        const excludeInput = qs('[name="exclude_id"]', form);
        const endpoint = form.dataset.availabilityUrl;
        let timer = null;
        let controller = null;

        if (!result || !dateInput || !startInput || !endInput || !endpoint) return;

        const render = (data) => {
            if (!data || !data.checked) {
                result.className = 'availability-result';
                result.innerHTML = 'Choose a date, start time, and end time to check availability.';
                return;
            }

            const conflicts = data.blocking || [];
            const pending = data.pending || [];

            if (data.valid === false) {
                result.className = 'availability-result danger';
                result.innerHTML = `<strong>Invalid schedule</strong>${escapeHtml(data.message || 'Please review the selected schedule.')}`;
                return;
            }

            if (conflicts.length > 0) {
                const items = conflicts.map((item) => {
                    const windowText = item.start_time
                        ? `${formatTime(item.start_time)} – ${formatTime(item.end_time)}`
                        : 'All day';
                    return `<li><strong>${escapeHtml(item.title)}</strong> · ${escapeHtml(windowText)}${item.kind === 'block' ? ' · Gym closed' : ''}</li>`;
                }).join('');
                result.className = 'availability-result danger';
                result.innerHTML = `<strong>Not available</strong>The selected time overlaps with an existing booking or blocked period.<ul>${items}</ul>`;
                return;
            }

            if (pending.length > 0) {
                const items = pending.map((item) => {
                    if (data.is_admin) {
                        return `<li>${escapeHtml(item.activity_name || item.title)} · ${formatTime(item.start_time)} – ${formatTime(item.end_time)} · ${escapeHtml(item.requester || '')}</li>`;
                    }
                    return `<li>Pending request · ${formatTime(item.start_time)} – ${formatTime(item.end_time)}</li>`;
                }).join('');
                result.className = 'availability-result warning';
                result.innerHTML = `<strong>Available with a pending request</strong>No approved booking blocks this time, but another request is awaiting review. The administrator may need to resolve the overlap.<ul>${items}</ul>`;
                return;
            }

            result.className = 'availability-result success';
            result.innerHTML = `<strong>Time is available</strong>The selected schedule has no approved bookings, blocked periods, or competing pending requests.`;
        };

        const check = async () => {
            const date = dateInput.value;
            const start = startInput.value;
            const end = endInput.value;

            if (!date || !start || !end) {
                render({ checked: false });
                return;
            }

            if (controller) controller.abort();
            controller = new AbortController();

            result.className = 'availability-result';
            result.textContent = 'Checking availability…';

            const payload = new FormData();
            payload.append('_csrf', form.dataset.csrf || '');
            payload.append('reservation_date', date);
            payload.append('start_time', start);
            payload.append('end_time', end);
            if (excludeInput?.value) payload.append('exclude_id', excludeInput.value);

            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    body: payload,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    signal: controller.signal,
                });
                const data = await response.json();
                if (!response.ok) {
                    throw new Error(data.message || 'Unable to check availability.');
                }
                render(data);
            } catch (error) {
                if (error.name === 'AbortError') return;
                result.className = 'availability-result danger';
                result.innerHTML = `<strong>Availability check failed</strong>${escapeHtml(error.message || 'Please try again.')}`;
            }
        };

        const scheduleCheck = () => {
            window.clearTimeout(timer);
            timer = window.setTimeout(check, 350);
        };

        [dateInput, startInput, endInput].forEach((input) => {
            input.addEventListener('change', scheduleCheck);
            input.addEventListener('input', scheduleCheck);
        });

        if (dateInput.value && startInput.value && endInput.value) {
            check();
        }
    });

    qsa('input[type="date"][data-min-today]').forEach((input) => {
        const today = new Date();
        const timezoneOffset = today.getTimezoneOffset() * 60000;
        input.min = new Date(today - timezoneOffset).toISOString().split('T')[0];
    });

    qsa('[data-duration-fields]').forEach((group) => {
        const start = qs('[name="start_time"]', group);
        const end = qs('[name="end_time"]', group);
        const output = qs('[data-duration-output]', group);

        if (!start || !end || !output) return;

        const update = () => {
            if (!start.value || !end.value) {
                output.textContent = '';
                return;
            }
            const [startHour, startMinute] = start.value.split(':').map(Number);
            const [endHour, endMinute] = end.value.split(':').map(Number);
            const duration = (endHour * 60 + endMinute) - (startHour * 60 + startMinute);

            if (duration <= 0) {
                output.textContent = 'End time must be later than the start time.';
                output.classList.add('text-danger');
                return;
            }
            output.classList.remove('text-danger');
            const hours = Math.floor(duration / 60);
            const minutes = duration % 60;
            output.textContent = `${hours > 0 ? hours + ' hr' + (hours === 1 ? '' : 's') : ''}${hours > 0 && minutes > 0 ? ' ' : ''}${minutes > 0 ? minutes + ' min' : ''}`;
        };

        start.addEventListener('change', update);
        end.addEventListener('change', update);
        update();
    });
})();
