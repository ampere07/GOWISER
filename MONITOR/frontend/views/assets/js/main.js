/* ============================================================
   NetManager ISP Billing System — Main JavaScript
   ============================================================ */

(function () {
    'use strict';

    // ── Toast helper ─────────────────────────────────────────────
    window.showToast = function (message, type) {
        type = type || 'info';
        const container = document.getElementById('toastContainer') || createToastContainer();
        const id        = 'toast-' + Date.now() + '-' + Math.random().toString(36).slice(2);
        const icons     = { success: 'check-circle-fill', danger: 'x-circle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
        const icon      = icons[type] || 'info-circle-fill';

        const html = `<div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert" aria-live="assertive">
            <div class="d-flex">
                <div class="toast-body"><i class="bi bi-${icon} me-2"></i>${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div></div>`;

        container.insertAdjacentHTML('beforeend', html);
        const el    = document.getElementById(id);
        const toast = new bootstrap.Toast(el, { delay: 5000 });
        toast.show();
        el.addEventListener('hidden.bs.toast', () => el.remove());
    };

    function createToastContainer() {
        const div     = document.createElement('div');
        div.id        = 'toastContainer';
        div.className = 'toast-container position-fixed bottom-0 end-0 p-3';
        div.style.zIndex = '9999';
        document.body.appendChild(div);
        return div;
    }

    // ── Reload toast (shown on router timeout) ───────────────────
    let _reloadToastShown = false;
    window.showReloadToast = function (message) {
        if (_reloadToastShown) return;
        _reloadToastShown = true;
        const container = document.getElementById('toastContainer') || createToastContainer();
        const id = 'toast-reload-' + Date.now();
        container.insertAdjacentHTML('beforeend', `
            <div id="${id}" class="toast border-0 text-bg-warning" role="alert" aria-live="assertive" data-bs-autohide="false">
                <div class="d-flex align-items-center">
                    <div class="toast-body fw-semibold">
                        <i class="bi bi-wifi-off me-2"></i>${escHtml(message)}
                    </div>
                    <div class="ms-auto d-flex gap-1 me-2">
                        <button class="btn btn-sm btn-dark" onclick="location.reload()">Reload</button>
                        <button type="button" class="btn-close" data-bs-dismiss="toast"></button>
                    </div>
                </div>
            </div>`);
        const el = document.getElementById(id);
        new bootstrap.Toast(el, { autohide: false }).show();
        el.addEventListener('hidden.bs.toast', () => { el.remove(); _reloadToastShown = false; });
    };

    // ── fetchWithTimeout: wraps fetch() with AbortController ─────
    window.fetchWithTimeout = function (url, options, ms) {
        ms = ms || 15000;
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), ms);
        return fetch(url, Object.assign({}, options, { signal: ctrl.signal }))
            .finally(() => clearTimeout(timer));
    };

    // ── Read flash messages from embedded JSON ────────────────────
    function initFlashToasts() {
        const el = document.getElementById('flashData');
        if (!el) return;
        try {
            const messages = JSON.parse(el.textContent || el.innerHTML);
            if (Array.isArray(messages)) {
                messages.forEach(m => showToast(m.message, m.type || 'info'));
            }
        } catch (e) {}
    }

    function initPageToasts() {
        document.querySelectorAll('.nm-page-toasts').forEach(function (el) {
            try {
                var items = JSON.parse(el.textContent || el.innerHTML);
                if (Array.isArray(items)) items.forEach(function (a) { showToast(a.message, a.type || 'info'); });
            } catch (e) {}
        });
    }

    // ── Utility: HTML escape ─────────────────────────────────────
    window.escHtml = function (str) {
        const d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str ?? '')));
        return d.innerHTML;
    };

    // ── Sidebar toggle ────────────────────────────────────────────
    const sidebarToggle = document.getElementById('sidebarToggle');
    const body          = document.body;
    const wrapper       = document.querySelector('.wrapper');

    if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth >= 768) {
        wrapper?.classList.add('sidebar-collapsed');
    }

    sidebarToggle?.addEventListener('click', function () {
        if (window.innerWidth >= 768) {
            wrapper?.classList.toggle('sidebar-collapsed');
            localStorage.setItem('sidebarCollapsed', wrapper?.classList.contains('sidebar-collapsed') ? 'true' : 'false');
        } else {
            body.classList.toggle('sidebar-open');
        }
    });

    document.addEventListener('click', function (e) {
        if (window.innerWidth < 768 && body.classList.contains('sidebar-open')) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar && !sidebar.contains(e.target) && !sidebarToggle?.contains(e.target)) {
                body.classList.remove('sidebar-open');
            }
        }
    });

    // ── Theme toggle (dark / light) ───────────────────────────────
    const themeToggle = document.getElementById('themeToggle');
    const themeIcon   = document.getElementById('themeIcon');

    function applyTheme(theme) {
        // Bootstrap only understands 'light' | 'dark'; green uses light base
        document.documentElement.setAttribute('data-bs-theme', theme === 'dark' ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('nmTheme', theme);
        if (themeIcon) {
            themeIcon.className = theme === 'dark'  ? 'bi bi-sun-fill'
                                : theme === 'green' ? 'bi bi-moon-stars-fill'
                                :                     'bi bi-tree-fill';
        }
        if (themeToggle) themeToggle.title = theme === 'dark'  ? 'Switch to Light'
                                           : theme === 'green' ? 'Switch to Dark'
                                           :                     'Switch to Green';
    }

    // Sync icon with the theme already applied by the anti-flash inline script
    applyTheme(document.documentElement.getAttribute('data-theme') || 'light');

    themeToggle?.addEventListener('click', function () {
        const current = document.documentElement.getAttribute('data-theme') || 'light';
        const next = current === 'light' ? 'dark' : current === 'dark' ? 'green' : 'light';
        applyTheme(next);
    });

    // ── Logout confirmation ───────────────────────────────────────
    document.querySelectorAll('.logout-confirm').forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const href = this.href;
            if (typeof Swal !== 'undefined') {
                Swal.fire({
                    title: 'Logout?',
                    text: 'Are you sure you want to end your session?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Logout',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc3545',
                }).then(r => { if (r.isConfirmed) window.location.href = href; });
            } else {
                window.location.href = href;
            }
        });
    });

    // ── Modal confirm helper (replaces all confirm() dialogs) ─────
    window.confirmAction = function (options) {
        const defaults = {
            title: 'Are you sure?',
            text: '',
            icon: 'warning',
            confirmText: 'Yes, Proceed',
            cancelText: 'Cancel',
            confirmColor: '#dc3545',
        };
        const cfg = Object.assign({}, defaults, typeof options === 'string' ? { text: options } : options);

        return new Promise(resolve => {
            if (typeof Swal === 'undefined') {
                resolve(confirm(cfg.text || cfg.title));
                return;
            }
            Swal.fire({
                title: cfg.title,
                html: cfg.html || (cfg.text ? `<span>${escHtml(cfg.text)}</span>` : ''),
                icon: cfg.icon,
                showCancelButton: true,
                confirmButtonText: cfg.confirmText,
                cancelButtonText: cfg.cancelText,
                confirmButtonColor: cfg.confirmColor,
                reverseButtons: true,
            }).then(r => resolve(r.isConfirmed));
        });
    };

    // ── Replace form[data-confirm] with SweetAlert ───────────────
    document.querySelectorAll('form[data-confirm]').forEach(function (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            const confirmed = await confirmAction(form.dataset.confirm || 'Are you sure?');
            if (confirmed) form.submit();
        });
    });

    // ── DataTables auto-init ──────────────────────────────────────
    document.querySelectorAll('[data-datatable]').forEach(function (table) {
        if (typeof $.fn.DataTable !== 'undefined') {
            $(table).DataTable({
                pageLength: 25,
                language: { search: '', searchPlaceholder: 'Search...' },
                columnDefs: [{ orderable: false, targets: -1 }],
            });
        }
    });

    // ── Bootstrap tooltips ───────────────────────────────────────
    document.querySelectorAll('[data-bs-toggle="tooltip"], [title]:not(input):not(select)').forEach(function (el) {
        if (typeof bootstrap !== 'undefined') {
            bootstrap.Tooltip.getOrCreateInstance(el, { trigger: 'hover' });
        }
    });

    // ── Copy-to-clipboard ─────────────────────────────────────────
    document.querySelectorAll('[data-copy]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const text = this.dataset.copy;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(() => showToast('Copied to clipboard', 'success'));
            }
        });
    });

    // ── Refresh sidebar router status ─────────────────────────────
    document.getElementById('refreshRouters')?.addEventListener('click', function () {
        const icon = this.querySelector('i');
        icon?.classList.add('spinning');
        loadSidebarRouterStatus(true);
        setTimeout(() => icon?.classList.remove('spinning'), 2500);
    });

    // Guard: prevent overlapping live requests (same pattern as Dashboard 2's livePolling flag)
    let _sbLivePending = false;

    function renderSidebarStatus(r) {
        const sidebar = document.getElementById('sidebarRouterStatus');
        if (!sidebar) return;
        if (r.name) sidebar.dataset.routerName = r.name;
        const online  = !!r.online;
        const name    = r.name || sidebar.dataset.routerName || '—';
        const cpu     = online && r.cpu_load != null ? parseInt(r.cpu_load) : null;
        const cpuPct  = online ? (cpu != null ? cpu : 0) : 100;
        const cpuCls  = !online ? 'cpu-offline' : (cpuPct >= 80 ? 'cpu-crit' : (cpuPct >= 50 ? 'cpu-warn' : ''));
        const cpuTxt  = online ? (cpu != null ? cpu + '%' : '—') : 'Offline';
        const dot = online
            ? '<span class="status-dot-pulse" style="width:7px;height:7px;"></span>'
            : '<span class="status-dot bg-danger" style="width:7px;height:7px;border-radius:50%;display:inline-block;flex-shrink:0;"></span>';
        sidebar.innerHTML = `
            <div class="sb-router-row">
                ${dot}
                <span class="sb-router-row-name">${escHtml(name)}</span>
                <div class="sb-cpu-bar"><div class="sb-cpu-bar-fill ${cpuCls}" style="width:${cpuPct}%"></div></div>
                <span class="sb-router-row-cpu">${cpuTxt}</span>
            </div>`;

        // Keep header dot in sync with the latest known status
        const headerDot  = document.getElementById('headerRouterDot');
        const headerLink = document.getElementById('headerRouterLink');
        if (headerDot) {
            headerDot.className = online ? 'status-dot-pulse' : 'status-dot bg-danger';
        }
        if (headerLink) {
            headerLink.title = 'Switch router — ' + (online ? 'Online' : 'Offline');
        }
    }

    function loadSidebarRouterStatus(useLive) {
        const sidebar = document.getElementById('sidebarRouterStatus');
        if (!sidebar || typeof BASE_URL === 'undefined') return;

        const routerId = parseInt(sidebar.dataset.routerId || '0');
        if (!routerId) {
            sidebar.innerHTML = '<span class="sb-status-loading">No router selected</span>';
            return;
        }

        const isAdmin = ['admin', 'superadmin'].includes(window.CURRENT_ROLE || '');

        if (useLive && isAdmin) {
            if (_sbLivePending) return; // skip if a live request is already in flight
            _sbLivePending = true;
            // quick=1: lightweight path for CPU/status/counts, with RADIUS counted when configured.
            fetchWithTimeout(BASE_URL + '/api/router_status.php?id=' + routerId + '&live=1&quick=1', {}, 20000)
                .then(r => r.json())
                .then(d => {
                    _sbLivePending = false;
                    if (!d.success) return;
                    renderSidebarStatus(d);
                })
                .catch(() => { _sbLivePending = false; });
            return;
        }

        // Cached mode (all users / non-admin): DB read only, no TCP to router
        fetchWithTimeout(BASE_URL + '/api/router_status.php?id=' + routerId, {}, 8000)
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;
                renderSidebarStatus(d);
            })
            .catch(() => {});
    }

    if (document.getElementById('sidebarRouterStatus')) {
        const _sbIsAdmin = ['admin', 'superadmin'].includes(window.CURRENT_ROLE || '');
        loadSidebarRouterStatus(false);  // all users: cached instantly (last-known state)
        if (_sbIsAdmin) {
            loadSidebarRouterStatus(true);                             // admin: live fires immediately on load
            setInterval(() => loadSidebarRouterStatus(true),  15000); // admin: live every 15 s
        } else {
            setInterval(() => loadSidebarRouterStatus(false), 30000); // non-admin: cached every 30 s
        }
    }

    // ── Router group chat ────────────────────────────────────────
    function initGroupChat() {
        const modalEl = document.getElementById('groupChatModal');
        const listEl  = document.getElementById('groupChatMessages');
        const formEl  = document.getElementById('groupChatForm');
        const inputEl = document.getElementById('groupChatInput');
        const reload  = document.getElementById('groupChatReload');
        const countEl = document.getElementById('groupChatCount');
        const charsEl = document.getElementById('groupChatChars');
        if (!modalEl || !listEl || !formEl || !inputEl || typeof BASE_URL === 'undefined') return;

        let timer = null;
        let loading = false;

        function chatOk(response) {
            if (response && response.success) return true;
            showToast((response && response.message) || 'An error occurred.', 'danger');
            return false;
        }

        function setChatCount(count) {
            if (!countEl) return;
            countEl.textContent = count ? `${count} message${count === 1 ? '' : 's'} today` : 'Today';
        }

        function senderInitials(name) {
            const parts = String(name || 'User').trim().split(/\s+/).filter(Boolean);
            return ((parts[0]?.[0] || 'U') + (parts[1]?.[0] || '')).toUpperCase();
        }

        function updateComposerMeta() {
            const len = (inputEl.value || '').length;
            if (charsEl) charsEl.textContent = `${len}/1000`;
            inputEl.style.height = 'auto';
            inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
        }

        function setLoadingState() {
            listEl.innerHTML = '<div class="nm-chat-state">' +
                '<div class="spinner-border spinner-border-sm text-primary" role="status"></div>' +
                '<span>Loading messages...</span>' +
                '</div>';
        }

        function renderMessages(messages) {
            setChatCount(messages.length);
            if (!messages.length) {
                listEl.innerHTML = '<div class="nm-chat-empty">' +
                    '<div class="nm-chat-empty-icon"><i class="bi bi-chat-square-text"></i></div>' +
                    '<div class="fw-semibold">No messages yet today</div>' +
                    '</div>';
                return;
            }
            listEl.innerHTML = messages.map(function (m) {
                const mine = parseInt(m.user_id, 10) === parseInt(window.CURRENT_USER_ID || 0, 10);
                const name = m.sender_name || 'User';
                const body = m.unsent_at
                    ? '<em class="text-muted">This message was unsent.</em>'
                    : escHtml(m.message).replace(/\n/g, '<br>');
                const unsend = mine && m.can_unsend && !m.unsent_at
                    ? `<button type="button" class="btn btn-link btn-sm p-0 nm-chat-unsend" data-id="${m.message_id}" title="Unsend message">Unsend</button>`
                    : '';
                return `<div class="nm-chat-item ${mine ? 'is-mine' : ''}">
                    <div class="nm-chat-avatar" aria-hidden="true">${escHtml(senderInitials(name))}</div>
                    <div class="nm-chat-stack">
                        <div class="nm-chat-meta">
                            <span class="fw-semibold">${escHtml(name)}</span>
                            <span>${escHtml(m.created_label || '')}</span>
                            ${unsend}
                        </div>
                        <div class="nm-chat-bubble">${body}</div>
                    </div>
                </div>`;
            }).join('');
            listEl.scrollTop = listEl.scrollHeight;
        }

        function loadMessages(showSpinner) {
            if (loading) return;
            loading = true;
            if (showSpinner) {
                setLoadingState();
            }
            fetch(BASE_URL + '/api/group_chat.php?action=list')
                .then(r => r.json())
                .then(d => {
                    loading = false;
                    if (!chatOk(d)) return;
                    renderMessages(d.messages || []);
                })
                .catch(() => {
                    loading = false;
                    showToast('Unable to load chat messages.', 'danger');
                });
        }

        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            const message = (inputEl.value || '').trim();
            if (!message) return;
            const fd = new FormData();
            fd.append('action', 'send');
            fd.append('message', message);
            fd.append(window.CSRF_TOKEN_NAME || '_csrf_token', window.CSRF_TOKEN || '');
            inputEl.disabled = true;
            fetch(BASE_URL + '/api/group_chat.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    inputEl.disabled = false;
                    inputEl.focus();
                    if (!chatOk(d)) return;
                    inputEl.value = '';
                    updateComposerMeta();
                    renderMessages(d.messages || []);
                })
                .catch(() => {
                    inputEl.disabled = false;
                    showToast('Unable to send message.', 'danger');
                });
        });

        inputEl.addEventListener('input', updateComposerMeta);
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                formEl.requestSubmit();
            }
        });

        listEl.addEventListener('click', function (e) {
            const btn = e.target.closest('.nm-chat-unsend');
            if (!btn) return;
            const fd = new FormData();
            fd.append('action', 'unsend');
            fd.append('message_id', btn.dataset.id || '');
            fd.append(window.CSRF_TOKEN_NAME || '_csrf_token', window.CSRF_TOKEN || '');
            fetch(BASE_URL + '/api/group_chat.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (!chatOk(d)) return;
                    renderMessages(d.messages || []);
                })
                .catch(() => showToast('Unable to unsend message.', 'danger'));
        });

        reload?.addEventListener('click', () => loadMessages(true));
        modalEl.addEventListener('shown.bs.modal', function () {
            loadMessages(true);
            timer = setInterval(() => loadMessages(false), 10000);
            setTimeout(() => {
                updateComposerMeta();
                inputEl.focus();
            }, 150);
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (timer) clearInterval(timer);
            timer = null;
        });
    }
    initGroupChat();

    // ── Handle AJAX errors ────────────────────────────────────────
    window.handleAjaxError = function (response) {
        if (!response.success) {
            showToast(response.message || 'An error occurred.', 'danger');
            return false;
        }
        return true;
    };

    // ── Format money ──────────────────────────────────────────────
    window.formatMoney = function (amount) {
        const sym = window.CURRENCY_SYMBOL || '₱';
        return sym + parseFloat(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    };

    // ── Notifications: expired / expiring subscribers ─────────────
    function loadNotifications() {
        if (typeof BASE_URL === 'undefined') return;

        fetch(BASE_URL + '/api/notifications.php')
            .then(r => r.json())
            .then(d => {
                if (!d.success) return;

                const badge   = document.getElementById('notifBadge');
                const list    = document.getElementById('notifList');
                const footer  = document.getElementById('notifFooter');
                const total   = document.getElementById('notifExpiredTotal');
                const groups  = d.groups        || [];
                const expired = d.total_expired || 0;

                if (badge) {
                    const n = d.badge || 0;
                    badge.textContent  = n > 99 ? '99+' : n;
                    badge.style.display = n > 0 ? '' : 'none';
                }

                if (footer) {
                    footer.classList.toggle('d-none', expired === 0);
                    if (total) total.textContent = expired;
                }

                if (!list) return;

                if (groups.length === 0) {
                    list.innerHTML = '<div class="text-center py-4 text-muted small">' +
                        '<i class="bi bi-check-circle d-block fs-4 mb-1 text-success"></i>' +
                        'No subscribers expiring soon</div>';
                    return;
                }

                const icons = { yesterday: 'bi-dash-circle', today: 'bi-exclamation-circle', tomorrow: 'bi-clock' };
                const colors = { yesterday: 'text-secondary', today: 'text-danger', tomorrow: 'text-warning' };

                list.innerHTML = groups.map(g => {
                    const url = `${BASE_URL}/modules/subscribers/index.php?exp_date=${g.key}&_filter=1`;
                    return `<div role="button"
                               class="dropdown-item d-flex align-items-center justify-content-between py-2 px-3 border-bottom"
                               style="white-space:normal;cursor:pointer;"
                               onclick="window.location.href='${url}'">
                        <span class="d-flex align-items-center gap-2">
                            <i class="bi ${icons[g.key] || 'bi-circle'} ${colors[g.key] || ''}"></i>
                            <span class="small"><strong>${g.count}</strong> subscriber${g.count !== 1 ? 's' : ''} ${g.label}</span>
                        </span>
                        <i class="bi bi-chevron-right text-muted" style="font-size:.75rem;"></i>
                    </div>`;
                }).join('');
            })
            .catch(() => {});
    }

    function initNotifDropdown() {
        const dropdown = document.getElementById('notifDropdown');
        if (dropdown) {
            dropdown.closest('.dropdown')?.addEventListener('show.bs.dropdown', loadNotifications);
        }
    }

    // ── CSRF param helper ─────────────────────────────────────────
    window.csrfParam = function () {
        return encodeURIComponent(CSRF_TOKEN_NAME) + '=' + encodeURIComponent(CSRF_TOKEN);
    };

    // ── Session security ──────────────────────────────────────────
    (function () {
        let warnShown     = false;
        let countdownTimer;
        const POLL_INTERVAL  = 60000; // ping every 60s
        const WARN_THRESHOLD = 120;   // show warning when 2 min left

        function pollSession() {
            fetch(BASE_URL + '/api/check_session.php', { cache: 'no-store' })
                .then(r => r.json())
                .then(d => {
                    if (!d.valid) {
                        // Session gone — redirect immediately
                        clearInterval(countdownTimer);
                        Swal.fire({
                            title: 'Session Expired',
                            text: 'Your session has expired. You will be redirected to login.',
                            icon: 'warning',
                            allowOutsideClick: false,
                            allowEscapeKey: false,
                            showConfirmButton: false,
                            timer: 3000,
                        }).then(() => { window.location.href = BASE_URL + '/login.php'; });
                        return;
                    }
                })
                .catch(() => {}); // ignore network errors
        }

        // 1-minute idle warning (client-side countdown)
        let idleSeconds  = 0;
        const IDLE_LIMIT = 28 * 60; // 28 minutes; warn at 27 min

        function resetIdle() { idleSeconds = 0; warnShown = false; }
        ['mousemove', 'keydown', 'click', 'touchstart', 'scroll'].forEach(ev =>
            document.addEventListener(ev, resetIdle, { passive: true })
        );

        setInterval(() => {
            idleSeconds++;
            if (idleSeconds >= IDLE_LIMIT - 60 && !warnShown) {
                warnShown = true;
                let secs = 60;
                Swal.fire({
                    title: '<i class="bi bi-clock-history text-warning"></i> Session Expiring Soon',
                    html: `Your session will expire in <strong id="sessionCountdown">60</strong> seconds due to inactivity.<br>
                           Move your mouse or press a key to stay logged in.`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Stay Logged In',
                    cancelButtonText: 'Log Out Now',
                    allowOutsideClick: false,
                    timer: 60000,
                    timerProgressBar: true,
                    didOpen: () => {
                        countdownTimer = setInterval(() => {
                            secs--;
                            const el = document.getElementById('sessionCountdown');
                            if (el) el.textContent = secs;
                        }, 1000);
                    },
                    willClose: () => { clearInterval(countdownTimer); }
                }).then(result => {
                    if (result.isConfirmed) {
                        resetIdle();
                        pollSession(); // ping to refresh session
                    } else {
                        window.location.href = BASE_URL + '/logout.php';
                    }
                }).catch(() => {});
            }
            if (idleSeconds >= IDLE_LIMIT) {
                window.location.href = BASE_URL + '/logout.php';
            }
        }, 1000);

        setInterval(pollSession, POLL_INTERVAL);
    })();

    // ── Required field asterisks ──────────────────────────────────
    function markRequiredLabels() {
        document.querySelectorAll('input[required], select[required], textarea[required]').forEach(function (input) {
            // Find associated label: by for/id first, then closest ancestor col/mb div
            let label = null;
            if (input.id) label = document.querySelector('label[for="' + input.id + '"]');
            if (!label) {
                const wrap = input.closest('.col, [class*="col-"], .mb-3, .mb-4, .input-group');
                if (wrap) {
                    // Go up one more level to reach the column wrapper that holds the label
                    const col = wrap.closest('[class*="col-"]') || wrap;
                    label = col.querySelector('label.form-label');
                }
            }
            if (!label) return;
            // Skip if asterisk already present
            if (label.querySelector('.req-star') || label.textContent.includes('*')) return;
            const star = document.createElement('span');
            star.className = 'req-star text-danger ms-1';
            star.setAttribute('aria-hidden', 'true');
            star.textContent = '*';
            label.appendChild(star);
        });
    }

    // ── Init (after DOM ready) ────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        initFlashToasts();
        initPageToasts();
        loadNotifications();
        initNotifDropdown();
        markRequiredLabels();
    });

})();
