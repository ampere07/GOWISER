<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireLogin();
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

$routerId = (int)(selectedRouterId() ?: 0);
$isSuperadmin = currentRole() === ROLE_SUPERADMIN;
$routers  = $isSuperadmin ? db()->query("SELECT router_id, name FROM routers ORDER BY name")->fetchAll() : [];
$canSend  = canSendMessages();

// Pagination
$perPage  = 15;
$curPage  = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($curPage - 1) * $perPage;

$logWhere  = $routerId ? 'WHERE n.router_id = ?' : '';
$logParams = $routerId ? [$routerId] : [];
$totalStmt = db()->prepare("SELECT COUNT(*) FROM notifications n {$logWhere}");
$totalStmt->execute($logParams);
$totalLogs = (int)$totalStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));
$curPage    = min($curPage, $totalPages);

$logStmt = db()->prepare("
    SELECT n.*, u.firstname, u.lastname, r.name AS router_name
    FROM notifications n
    LEFT JOIN users   u ON u.user_id   = n.sent_by
    LEFT JOIN routers r ON r.router_id = n.router_id
    {$logWhere}
    ORDER BY n.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$logStmt->execute($logParams);
$logs = $logStmt->fetchAll();

$pageTitle   = 'SMS Notifications';
$breadcrumbs = [['label' => 'Notifications']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-0">SMS Notifications</h4>
        <div class="text-muted small"><?= $canSend ? 'Send bulk or targeted SMS to subscribers' : 'View SMS notification history' ?></div>
    </div>
    <?php if (!SMS_ENABLED): ?>
    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>SMS Disabled</span>
    <?php endif; ?>
</div>

<div class="row g-4">

    <!-- ── Compose Panel ──────────────────────────────── -->
    <?php if ($canSend): ?>
    <div class="col-lg-5">
        <div class="card border-0 h-100">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                <i class="bi bi-send me-2 text-primary"></i>Compose Message
            </div>
            <div class="card-body">

                <!-- Router selector -->
                <div class="mb-3">
                    <label class="form-label fw-medium" for="notifRouter">Router</label>
                    <?php if ($isSuperadmin): ?>
                    <select id="notifRouter" class="form-select">
                        <option value="">— All Routers —</option>
                        <?php foreach ($routers as $r): ?>
                        <option value="<?= $r['router_id'] ?>" <?= $r['router_id'] == $routerId ? 'selected' : '' ?>>
                            <?= e($r['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php else: ?>
                    <input type="hidden" id="notifRouter" value="<?= (int)$routerId ?>">
                    <div class="form-control-plaintext small">
                        <i class="bi bi-router me-1"></i><?= e(selectedRouterName() ?: 'Default Router') ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Target type -->
                <div class="mb-3">
                    <label class="form-label fw-medium">Send To</label>
                    <div class="d-flex flex-column gap-1" id="targetCards">

                        <label class="target-card" data-type="all">
                            <input type="radio" name="target_type" value="all" class="d-none" checked>
                            <div class="d-flex align-items-center justify-content-between px-2 py-1 rounded border" style="cursor:pointer;">
                                <div class="d-flex align-items-center gap-2 small">
                                    <i class="bi bi-people-fill text-primary" style="font-size:.8rem;"></i>
                                    <span class="fw-medium">All Subscribers</span>
                                </div>
                                <span class="badge bg-primary" id="badgeAll" style="font-size:.7rem;">—</span>
                            </div>
                        </label>

                        <label class="target-card" data-type="expired_today">
                            <input type="radio" name="target_type" value="expired_today" class="d-none">
                            <div class="d-flex align-items-center justify-content-between px-2 py-1 rounded border" style="cursor:pointer;">
                                <div class="d-flex align-items-center gap-2 small">
                                    <i class="bi bi-calendar-x-fill text-danger" style="font-size:.8rem;"></i>
                                    <span class="fw-medium">Expired Today</span>
                                </div>
                                <span class="badge bg-danger" id="badgeExpired" style="font-size:.7rem;">—</span>
                            </div>
                        </label>

                        <label class="target-card" data-type="barangay">
                            <input type="radio" name="target_type" value="barangay" class="d-none">
                            <div class="d-flex align-items-center justify-content-between px-2 py-1 rounded border" style="cursor:pointer;">
                                <div class="d-flex align-items-center gap-2 small">
                                    <i class="bi bi-geo-alt-fill text-success" style="font-size:.8rem;"></i>
                                    <span class="fw-medium">By Barangay</span>
                                </div>
                                <span class="badge bg-secondary" id="badgeBarangay" style="font-size:.7rem;">—</span>
                            </div>
                        </label>

                        <label class="target-card" data-type="single">
                            <input type="radio" name="target_type" value="single" class="d-none">
                            <div class="d-flex align-items-center justify-content-between px-2 py-1 rounded border" style="cursor:pointer;">
                                <div class="d-flex align-items-center gap-2 small">
                                    <i class="bi bi-person-fill text-warning" style="font-size:.8rem;"></i>
                                    <span class="fw-medium">Single Subscriber</span>
                                </div>
                                <span class="badge bg-secondary" style="font-size:.7rem;">1</span>
                            </div>
                        </label>

                    </div>
                </div>

                <!-- Barangay select (shown only when barangay target selected) -->
                <div class="mb-3 d-none" id="barangayWrap">
                    <label class="form-label fw-medium" for="barangaySelect">Select Barangay</label>
                    <select id="barangaySelect" class="form-select">
                        <option value="">— Loading… —</option>
                    </select>
                    <div class="form-text" id="barangayCountHint"></div>
                </div>

                <!-- Single subscriber search -->
                <div class="mb-3 d-none" id="singleWrap">
                    <label class="form-label fw-medium" for="subSearch">Search Subscriber</label>
                    <input type="text" id="subSearch" class="form-control" placeholder="Name or account number…" autocomplete="off">
                    <div id="subResults" class="list-group mt-1 shadow-sm" style="max-height:180px;overflow-y:auto;position:absolute;z-index:999;width:calc(100% - 2rem);"></div>
                    <input type="hidden" id="subIdHidden">
                    <div class="form-text" id="subSelectedHint"></div>
                </div>

                <!-- Message -->
                <div class="mb-3">
                    <label class="form-label fw-medium" for="smsBody">Message <span class="text-danger">*</span></label>
                    <textarea id="smsBody" class="form-control" rows="4" maxlength="320"
                              placeholder="Hi {firstname}, your subscription expires on {subscription_end}."></textarea>
                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted">Variables are replaced per subscriber</small>
                        <small><span id="smsCharCount" class="text-muted">0</span>/320</small>
                    </div>
                    <!-- Variable chips -->
                    <div class="mt-2">
                        <div class="text-muted mb-1" style="font-size:.72rem;">Click to insert variable:</div>
                        <div class="d-flex flex-wrap gap-1" id="varChips">
                            <span class="badge border text-primary border-primary-subtle bg-primary-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{firstname}">firstname</span>
                            <span class="badge border text-primary border-primary-subtle bg-primary-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{lastname}">lastname</span>
                            <span class="badge border text-primary border-primary-subtle bg-primary-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{fullname}">fullname</span>
                            <span class="badge border text-success border-success-subtle bg-success-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{account_number}">account_number</span>
                            <span class="badge border text-warning border-warning-subtle bg-warning-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{subscription_end}">subscription_end</span>
                            <span class="badge border text-warning border-warning-subtle bg-warning-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{days_left}">days_left</span>
                            <span class="badge border text-secondary border-secondary-subtle bg-secondary-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{plan}">plan</span>
                            <span class="badge border text-secondary border-secondary-subtle bg-secondary-subtle var-chip" style="cursor:pointer;font-size:.72rem;" data-var="{contact_number}">contact_number</span>
                        </div>
                    </div>
                </div>

                <div id="sendAlert" class="d-none"></div>

                <button type="button" class="btn btn-primary w-100" id="sendBtn" <?= !SMS_ENABLED ? 'disabled title="SMS is disabled"' : '' ?>>
                    <i class="bi bi-send me-2"></i>Send SMS
                </button>

            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Log Panel ──────────────────────────────────── -->
    <div class="<?= $canSend ? 'col-lg-7' : 'col-12' ?>">
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between">
                <span class="fw-semibold">Send History</span>
                <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                    <i class="bi bi-arrow-clockwise"></i>
                </button>
            </div>
            <div class="card-body p-0">
                <?php if (empty($logs)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-chat-dots fs-2 d-block mb-2"></i>No notifications sent yet.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                        <th class="ps-3">Date</th>
                                <th>Target</th>
                                <th>Router</th>
                                <th class="text-center">Sent</th>
                                <th class="text-center">Failed</th>
                                <th>By</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="ps-3 small text-nowrap"><?= formatDate($log['created_at'], 'M d, Y h:i A') ?></td>
                            <td class="small">
                                <?php
                                $tIcon = match($log['target_type']) {
                                    'barangay'     => 'bi-geo-alt text-success',
                                    'expired_today'=> 'bi-calendar-x text-danger',
                                    'single'       => 'bi-person text-warning',
                                    default        => 'bi-people text-primary',
                                };
                                $tLabel = match($log['target_type']) {
                                    'barangay'     => 'Barangay',
                                    'expired_today'=> 'Expired Today',
                                    'single'       => 'Single',
                                    default        => 'All',
                                };
                                ?>
                                <i class="bi <?= $tIcon ?> me-1"></i><?= $tLabel ?>
                                <?php if ($log['target_label']): ?>
                                <div class="text-muted" style="font-size:.72rem;"><?= e($log['target_label']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?= e($log['router_name'] ?? '—') ?></td>
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $log['total_sent'] ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($log['total_failed'] > 0): ?>
                                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $log['total_failed'] ?></span>
                                <?php else: ?>
                                <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= e(($log['firstname'] ?? '') . ' ' . ($log['lastname'] ?? '')) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($totalPages > 1): ?>
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-top">
                    <small class="text-muted">
                        Page <?= $curPage ?> of <?= $totalPages ?>
                        &nbsp;·&nbsp; <?= $totalLogs ?> total
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $curPage <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $curPage - 1 ?>">‹</a>
                            </li>
                            <?php for ($p = max(1, $curPage - 2); $p <= min($totalPages, $curPage + 2); $p++): ?>
                            <li class="page-item <?= $p === $curPage ? 'active' : '' ?>">
                                <a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a>
                            </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $curPage >= $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $curPage + 1 ?>">›</a>
                            </li>
                        </ul>
                    </nav>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<JS
<script>
let barangayData  = {};
let selectedSubId = null;

const notifRouter    = document.getElementById('notifRouter');
const barangayWrap   = document.getElementById('barangayWrap');
const barangaySelect = document.getElementById('barangaySelect');
const barangayHint   = document.getElementById('barangayCountHint');
const singleWrap     = document.getElementById('singleWrap');
const subSearch      = document.getElementById('subSearch');
const subResults     = document.getElementById('subResults');
const subIdHidden    = document.getElementById('subIdHidden');
const subSelectedHint= document.getElementById('subSelectedHint');
const smsBody        = document.getElementById('smsBody');
const smsCharCount   = document.getElementById('smsCharCount');
const sendBtn        = document.getElementById('sendBtn');
const sendAlert      = document.getElementById('sendAlert');

// ── Character counter ────────────────────────────────────────
smsBody?.addEventListener('input', function () {
    const len = this.value.length;
    smsCharCount.textContent = len;
    smsCharCount.className   = len > 280 ? 'text-danger fw-semibold' : len > 160 ? 'text-warning fw-semibold' : 'text-muted';
});

// ── Variable chip insertion ───────────────────────────────────
document.querySelectorAll('.var-chip').forEach(chip => {
    chip.addEventListener('click', function () {
        const variable = this.dataset.var;
        const start    = smsBody.selectionStart;
        const end      = smsBody.selectionEnd;
        const before   = smsBody.value.substring(0, start);
        const after    = smsBody.value.substring(end);
        smsBody.value  = before + variable + after;
        const pos      = start + variable.length;
        smsBody.setSelectionRange(pos, pos);
        smsBody.focus();
        smsBody.dispatchEvent(new Event('input'));
    });
});

// ── Target card selection ────────────────────────────────────
function getSelectedType() {
    return document.querySelector('input[name="target_type"]:checked')?.value || 'all';
}

document.querySelectorAll('.target-card').forEach(card => {
    card.addEventListener('click', function () {
        const radio = this.querySelector('input[type=radio]');
        if (radio) radio.checked = true;

        document.querySelectorAll('.target-card > div').forEach(d => {
            d.classList.remove('border-primary', 'bg-primary-subtle');
        });
        this.querySelector('div').classList.add('border-primary', 'bg-primary-subtle');

        const type = this.dataset.type;
        barangayWrap.classList.toggle('d-none', type !== 'barangay');
        singleWrap.classList.toggle('d-none', type !== 'single');
    });
});

// Activate first card on load
document.querySelector('.target-card')?.click();

// ── Load counts from API ─────────────────────────────────────
function loadCounts() {
    const rid = notifRouter.value;
    fetch(BASE_URL + '/api/notification_counts.php' + (rid ? '?router_id=' + rid : ''))
        .then(r => r.json())
        .then(d => {
            if (!d.success) return;
            document.getElementById('badgeAll').textContent     = d.count_all;
            document.getElementById('badgeExpired').textContent = d.count_expired_today;

            barangayData = d.barangays || {};
            const total  = Object.values(barangayData).reduce((a, b) => a + b, 0);
            document.getElementById('badgeBarangay').textContent = total;

            // Rebuild barangay dropdown
            barangaySelect.innerHTML = '<option value="">— Select Barangay —</option>';
            for (const [addr, cnt] of Object.entries(barangayData)) {
                const opt = document.createElement('option');
                opt.value       = addr;
                opt.textContent = addr + ' (' + cnt + ')';
                barangaySelect.appendChild(opt);
            }
            barangayHint.textContent = '';
        });
}

notifRouter.addEventListener('change', loadCounts);
barangaySelect.addEventListener('change', function () {
    const cnt = barangayData[this.value] ?? 0;
    barangayHint.textContent = cnt ? cnt + ' subscriber(s) in this area' : '';
});
loadCounts();

// ── Subscriber search (single mode) ─────────────────────────
let searchTimeout;
subSearch?.addEventListener('input', function () {
    clearTimeout(searchTimeout);
    selectedSubId = null;
    subIdHidden.value = '';
    subSelectedHint.textContent = '';

    const q = this.value.trim();
    if (q.length < 2) { subResults.innerHTML = ''; return; }

    searchTimeout = setTimeout(() => {
        const rid = notifRouter.value;
        let qs = '?q=' + encodeURIComponent(q);
        if (rid) qs += '&router_id=' + rid;

        fetch(BASE_URL + '/api/subscriber_search.php' + qs)
            .then(r => r.json())
            .then(d => {
                subResults.innerHTML = '';
                if (!d.success || !d.data?.length) {
                    subResults.innerHTML = '<div class="list-group-item text-muted small">No results</div>';
                    return;
                }
                const subs = d.data;
                subs.forEach(sub => {
                    const item = document.createElement('button');
                    item.type      = 'button';
                    item.className = 'list-group-item list-group-item-action small';
                    item.innerHTML = '<strong>' + sub.account_number + '</strong> — '
                        + sub.firstname + ' ' + sub.lastname
                        + ' <span class="text-muted">(' + (sub.contact_number || 'no phone') + ')</span>';
                    item.addEventListener('click', () => {
                        selectedSubId        = sub.subscriber_id;
                        subIdHidden.value    = sub.subscriber_id;
                        subSearch.value      = sub.account_number + ' — ' + sub.firstname + ' ' + sub.lastname;
                        subSelectedHint.textContent = sub.contact_number ? 'Will send to: ' + sub.contact_number : 'No contact number on record';
                        subSelectedHint.className   = sub.contact_number ? 'form-text text-success' : 'form-text text-danger';
                        subResults.innerHTML = '';
                    });
                    subResults.appendChild(item);
                });
            });
    }, 300);
});

document.addEventListener('click', e => {
    if (!singleWrap.contains(e.target)) subResults.innerHTML = '';
});

// ── Send ─────────────────────────────────────────────────────
sendBtn?.addEventListener('click', function () {
    const type    = getSelectedType();
    const message = smsBody.value.trim();
    sendAlert.className = 'd-none';

    if (!message) {
        showAlert('danger', 'Please enter a message before sending.');
        return;
    }
    if (type === 'barangay' && !barangaySelect.value) {
        showAlert('danger', 'Please select a barangay.');
        return;
    }
    if (type === 'single' && !subIdHidden.value) {
        showAlert('danger', 'Please select a subscriber.');
        return;
    }

    const countEl = type === 'all'          ? document.getElementById('badgeAll')
                  : type === 'expired_today' ? document.getElementById('badgeExpired')
                  : type === 'barangay'      ? null
                  : null;
    const count = type === 'barangay'
        ? (barangayData[barangaySelect.value] ?? 0)
        : type === 'single' ? 1
        : parseInt(countEl?.textContent || 0);

    Swal.fire({
        title: 'Send SMS?',
        html: 'This will send <strong>' + count + '</strong> message(s).<br><small class="text-muted">' + message.substring(0,60) + (message.length > 60 ? '…' : '') + '</small>',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Send',
    }).then(r => {
        if (!r.isConfirmed) return;

        sendBtn.disabled  = true;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending…';

        const fd = new FormData();
        fd.append(CSRF_TOKEN_NAME, CSRF_TOKEN);
        fd.append('target_type', type);
        fd.append('message', message);
        fd.append('router_id', notifRouter.value || '');
        if (type === 'barangay') fd.append('barangay', barangaySelect.value);
        if (type === 'single')   fd.append('subscriber_id', subIdHidden.value);

        fetch(BASE_URL + '/api/send_notification.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                sendBtn.disabled  = false;
                sendBtn.innerHTML = '<i class="bi bi-send me-2"></i>Send SMS';
                if (d.success) {
                    const icon = d.failed > 0 ? 'warning' : 'success';
                    let html = d.message;
                    if (d.errors?.length) {
                        html += '<br><small class="text-muted">' + d.errors.join('<br>') + '</small>';
                    }
                    Swal.fire({ title: 'Done!', html, icon })
                        .then(() => location.reload());
                } else {
                    Swal.fire('Error', d.message, 'error');
                }
            })
            .catch(() => {
                sendBtn.disabled  = false;
                sendBtn.innerHTML = '<i class="bi bi-send me-2"></i>Send SMS';
                Swal.fire('Error', 'Network error. Please try again.', 'error');
            });
    });
});

function showAlert(type, msg) {
    sendAlert.className = 'alert alert-' + type + ' py-2 small';
    sendAlert.textContent = msg;
}
</script>
JS;

include BASE_PATH . '/includes/footer.php';
