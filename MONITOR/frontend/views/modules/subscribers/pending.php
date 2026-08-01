<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

$pageTitle   = 'Applications';
$breadcrumbs = [
    ['label' => 'Subscribers', 'url' => BASE_URL . '/modules/subscribers/'],
    ['label' => 'Applications'],
];

const SESS_PEND = 'filter_subscribers_pending';

if (isset($_GET['_clear'])) {
    unset($_SESSION[SESS_PEND]);
    redirect(BASE_URL . '/modules/subscribers/pending');
}
if (isset($_GET['_filter'])) {
    $_SESSION[SESS_PEND] = [
        'q'         => trim($_GET['q']   ?? ''),
        'conn_type' => $_GET['conn_type'] ?? '',
        'plan_id'   => (int)($_GET['plan_id'] ?? 0),
    ];
    redirect(BASE_URL . '/modules/subscribers/pending');
}

// ── Assign to lineman (POST) ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_lineman'])) {
    verifyCsrf();
    requireMinRole(ROLE_ADMIN);

    $linemanId   = (int)($_POST['lineman_id'] ?? 0);
    $remark      = trim($_POST['assign_remark'] ?? '');
    $subIds      = array_map('intval', (array)($_POST['subscriber_ids'] ?? []));
    $subIds      = array_filter($subIds);

    if (!$linemanId || empty($subIds)) {
        flashMessage('danger', 'Please select at least one subscriber and a lineman.');
        redirect(BASE_URL . '/modules/subscribers/pending');
    }

    // Verify lineman exists and has lineman role
    $lm = db()->prepare("SELECT user_id FROM users WHERE user_id = ? AND role = 'lineman' AND is_active = 1");
    $lm->execute([$linemanId]);
    if (!$lm->fetch()) {
        flashMessage('danger', 'Invalid lineman selected.');
        redirect(BASE_URL . '/modules/subscribers/pending');
    }

    $inserted = 0;
    $pdo = db();

    $chkStmt = $pdo->prepare("SELECT subscriber_id FROM subscribers WHERE subscriber_id = ? AND status = 'pending'");
    $delStmt = $pdo->prepare("DELETE FROM installations WHERE subscriber_id = ?");
    $insStmt = $pdo->prepare("INSERT INTO installations (subscriber_id, user_id, remark, status) VALUES (?, ?, ?, 'Pending')");

    try {
        $pdo->beginTransaction();
        foreach ($subIds as $sid) {
            $chkStmt->execute([$sid]);
            if (!$chkStmt->fetch()) continue;

            $delStmt->execute([$sid]);
            $insStmt->execute([$sid, $linemanId, $remark ?: null]);
            $inserted++;
        }
        $pdo->commit();
    } catch (PDOException $e) {
        try { if ($pdo->inTransaction()) $pdo->rollBack(); } catch (PDOException $re) {}
        flashMessage('danger', 'Database error — assignments not saved. Please try again.');
        redirect(BASE_URL . '/modules/subscribers/pending');
    }

    if ($inserted === 0) {
        flashMessage('warning', 'No valid pending subscribers were found in the selection.');
    } else {
        logActivity('installations', 'assign',
            "Assigned {$inserted} subscriber(s) to lineman #{$linemanId}" . ($remark ? ": {$remark}" : ''));
        flashMessage('success', "{$inserted} subscriber" . ($inserted !== 1 ? 's' : '') . " assigned to lineman.");
    }
    redirect(BASE_URL . '/modules/subscribers/pending');
}

// ── Filters ───────────────────────────────────────────────────
$f        = $_SESSION[SESS_PEND] ?? [];
$search   = $f['q']         ?? '';
$connType = $f['conn_type'] ?? '';
$planId   = (int)($f['plan_id'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = DEFAULT_PER_PAGE;

$selRouterId = selectedRouterId();

$where  = ['s.status = ?'];
$params = [SUB_STATUS_PENDING];

if ($search !== '') {
    $where[]  = '(s.firstname LIKE ? OR s.lastname LIKE ? OR CONCAT(s.firstname, \' \', s.lastname) LIKE ? OR s.account_number LIKE ? OR s.ppp_username LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($connType) {
    $where[]  = 's.connection_type = ?';
    $params[] = $connType;
}
if ($selRouterId) {
    $where[]  = 's.router_id = ?';
    $params[] = $selRouterId;
}
if ($planId > 0) {
    $where[]  = 's.plan_id = ?';
    $params[] = $planId;
}

$whereStr = implode(' AND ', $where);

$plansStmt = $selRouterId
    ? db()->prepare("SELECT plan_id, title FROM plans WHERE is_active = 1 AND (router_id = ? OR router_id IS NULL) ORDER BY title")
    : db()->prepare("SELECT plan_id, title FROM plans WHERE is_active = 1 ORDER BY title");
if ($selRouterId) $plansStmt->execute([$selRouterId]);
else $plansStmt->execute();
$plans = $plansStmt->fetchAll();

$total = db()->prepare("SELECT COUNT(*) FROM subscribers s WHERE {$whereStr}");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();

$pag  = paginate($totalCount, $page, $perPage);

$stmt = db()->prepare("
    SELECT s.subscriber_id, s.account_number, s.firstname, s.lastname,
           s.address, s.contact_number, s.connection_type, s.ppp_username, s.created_at,
           p.title AS plan_title,
           r.name  AS router_name,
           i.installation_id, i.status AS install_status,
           u.firstname AS lm_first, u.lastname AS lm_last
    FROM subscribers s
    LEFT JOIN plans        p ON p.plan_id   = s.plan_id
    LEFT JOIN routers      r ON r.router_id = s.router_id
    LEFT JOIN installations i ON i.subscriber_id = s.subscriber_id
    LEFT JOIN users         u ON u.user_id = i.user_id
    WHERE {$whereStr}
    ORDER BY s.created_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$stmt->execute($params);
$subscribers = $stmt->fetchAll();

// Fetch active linemen for the modal dropdown
$linemen = db()->query("
    SELECT user_id, firstname, lastname
    FROM users
    WHERE role = 'lineman' AND is_active = 1
    ORDER BY firstname, lastname
")->fetchAll();

include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Applications</h4>
        <p class="text-muted small mb-0"><?= number_format($totalCount) ?> application<?= $totalCount !== 1 ? 's' : '' ?></p>
    </div>
    <?php if (canModifyRecords()): ?>
    <a href="<?= BASE_URL ?>/modules/subscribers/add" class="btn btn-primary">
        <i class="bi bi-person-plus me-1"></i>Add Subscriber
    </a>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="_filter" value="1">
        <div class="col-sm-6 col-lg-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Name, account, PPP user…" value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="conn_type" class="form-select form-select-sm">
                <option value="">All Types</option>
                <option value="ppp"     <?= $connType === 'ppp'     ? 'selected' : '' ?>>PPP</option>
                <option value="hotspot" <?= $connType === 'hotspot' ? 'selected' : '' ?>>Hotspot</option>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="plan_id" class="form-select form-select-sm">
                <option value="0">All Plans</option>
                <?php foreach ($plans as $pl): ?>
                <option value="<?= $pl['plan_id'] ?>" <?= $planId === (int)$pl['plan_id'] ? 'selected' : '' ?>><?= e($pl['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="?_clear=1" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<!-- Bulk assign action bar (hidden until checkbox is selected) -->
<?php if (hasMinRole(ROLE_ADMIN) && !empty($linemen)): ?>
<div id="bulkBar" class="alert alert-primary d-none mb-3 py-2 px-3 d-flex align-items-center gap-3 flex-wrap">
    <span class="fw-semibold small"><span id="bulkCount">0</span> selected</span>
    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#assignModal">
        <i class="bi bi-person-lines-fill me-1"></i>Assign to Lineman
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="clearSelBtn">
        <i class="bi bi-x-lg me-1"></i>Clear Selection
    </button>
</div>
<?php endif; ?>

<!-- Table -->
<div class="card" id="list">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <?php if (hasMinRole(ROLE_ADMIN) && !empty($linemen)): ?>
                        <th class="ps-3" style="width:38px;">
                            <input class="form-check-input" type="checkbox" id="chkAll" title="Select all">
                        </th>
                        <?php else: ?>
                        <th class="ps-3" style="width:0;"></th>
                        <?php endif; ?>
                        <th>Account #</th>
                        <th>Subscriber</th>
                        <th>Type</th>
                        <th>Plan</th>
                        <th>Router</th>
                        <th>Installation</th>
                        <th>Created</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-5 text-muted">
                            <i class="bi bi-hourglass d-block fs-2 mb-2"></i>
                            No pending subscribers found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($subscribers as $sub): ?>
                    <?php
                        $installBadge = '';
                        if ($sub['install_status']) {
                            $iColor = match($sub['install_status']) {
                                'Approved' => 'success',
                                'Ongoing'  => 'warning',
                                default    => 'secondary',
                            };
                            $linemanName = trim(($sub['lm_first'] ?? '') . ' ' . ($sub['lm_last'] ?? ''));
                            $installBadge = '<span class="badge bg-' . $iColor . '-subtle text-' . $iColor . ' border border-' . $iColor . '-subtle">'
                                          . e($sub['install_status']) . '</span>'
                                          . ($linemanName ? '<div class="text-muted" style="font-size:.7rem;">' . e($linemanName) . '</div>' : '');
                        }
                    ?>
                    <tr>
                        <td class="ps-3">
                            <?php if (hasMinRole(ROLE_ADMIN) && !empty($linemen)): ?>
                            <input class="form-check-input sub-chk" type="checkbox"
                                   value="<?= $sub['subscriber_id'] ?>"
                                   name="subscriber_ids[]">
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $sub['subscriber_id'] ?>" class="fw-medium text-decoration-none font-monospace small">
                                <?= e($sub['account_number']) ?>
                            </a>
                        </td>
                        <td>
                            <div class="fw-medium"><?= e($sub['firstname'] . ' ' . $sub['lastname']) ?></div>
                            <?php if (!empty($sub['address'])): ?>
                            <div class="text-muted small text-truncate" style="max-width:220px;"><?= e($sub['address']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= ($sub['connection_type'] ?? '') === 'ppp' ? 'bg-primary' : 'bg-info text-dark' ?>">
                                <?= strtoupper(e($sub['connection_type'] ?? '—')) ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= e($sub['plan_title'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($sub['router_name'] ?? '—') ?></td>
                        <td class="small">
                            <?= $installBadge ?: '<span class="text-muted">—</span>' ?>
                        </td>
                        <td class="small text-muted"><?= formatDate($sub['created_at'], 'M d, Y') ?></td>
                        <td class="text-end pe-3">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $sub['subscriber_id'] ?>" class="btn btn-sm btn-outline-secondary" title="View">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (canModifyRecords()): ?>
                                <a href="<?= BASE_URL ?>/modules/subscribers/edit/<?= $sub['subscriber_id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (hasMinRole(ROLE_ADMIN)): ?>
                                <button class="btn btn-sm btn-outline-danger btn-delete" title="Delete"
                                        data-id="<?= $sub['subscriber_id'] ?>"
                                        data-name="<?= e($sub['firstname'] . ' ' . $sub['lastname']) ?>">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pag['total_pages'] > 1): ?>
    <div class="card-footer bg-transparent border-top d-flex align-items-center justify-content-between">
        <small class="text-muted">
            Showing <?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $totalCount)) ?>
            of <?= number_format($totalCount) ?> results
        </small>
        <?= renderPagination($pag, '', 'list') ?>
    </div>
    <?php endif; ?>
</div>

<!-- ── Assign to Lineman Modal ─────────────────────────────── -->
<?php if (hasMinRole(ROLE_ADMIN) && !empty($linemen)): ?>
<div class="modal fade" id="assignModal" tabindex="-1" aria-labelledby="assignModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0">
            <form method="POST" id="assignForm">
                <?= csrfField() ?>
                <input type="hidden" name="assign_lineman" value="1">
                <div id="hiddenSubIds"></div>

                <div class="modal-header">
                    <h5 class="modal-title fw-bold" id="assignModalLabel">
                        <i class="bi bi-person-lines-fill me-2 text-primary"></i>Assign to Lineman
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        Assigning <strong id="modalSubCount">0</strong> subscriber(s) for installation.
                        If already assigned, the previous assignment will be replaced.
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Lineman <span class="text-danger">*</span></label>
                        <select name="lineman_id" class="form-select" required>
                            <option value="">— Select Lineman —</option>
                            <?php foreach ($linemen as $lm): ?>
                            <option value="<?= $lm['user_id'] ?>">
                                <?= e($lm['firstname'] . ' ' . $lm['lastname']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-1">
                        <label class="form-label fw-medium">Remark <span class="text-muted fw-normal">(optional)</span></label>
                        <textarea name="assign_remark" class="form-control" rows="3"
                                  placeholder="e.g. Schedule for next week, needs cable pull…"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Assign
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$extraScripts = <<<'JS'
<script>
// ── Checkbox select-all / bulk bar ────────────────────────────
(function () {
    const chkAll    = document.getElementById('chkAll');
    const bulkBar   = document.getElementById('bulkBar');
    const bulkCount = document.getElementById('bulkCount');
    const clearBtn  = document.getElementById('clearSelBtn');
    const hiddenDiv = document.getElementById('hiddenSubIds');
    const modalCnt  = document.getElementById('modalSubCount');

    if (!chkAll) return;

    function getChecked() {
        return [...document.querySelectorAll('.sub-chk:checked')];
    }

    function updateBar() {
        const checked = getChecked();
        const n = checked.length;
        bulkBar.classList.toggle('d-none', n === 0);
        if (bulkCount) bulkCount.textContent = n;
        if (modalCnt)  modalCnt.textContent  = n;

        // Sync hidden inputs for form submit
        if (hiddenDiv) {
            hiddenDiv.innerHTML = '';
            checked.forEach(c => {
                const inp = document.createElement('input');
                inp.type  = 'hidden';
                inp.name  = 'subscriber_ids[]';
                inp.value = c.value;
                hiddenDiv.appendChild(inp);
            });
        }

        // Indeterminate state for select-all
        const all = document.querySelectorAll('.sub-chk');
        chkAll.indeterminate = n > 0 && n < all.length;
        chkAll.checked = n > 0 && n === all.length;
    }

    chkAll.addEventListener('change', function () {
        document.querySelectorAll('.sub-chk').forEach(c => c.checked = this.checked);
        updateBar();
    });

    document.querySelectorAll('.sub-chk').forEach(c => {
        c.addEventListener('change', updateBar);
    });

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            document.querySelectorAll('.sub-chk').forEach(c => c.checked = false);
            chkAll.checked = false;
            updateBar();
        });
    }
})();

// ── Delete subscriber ─────────────────────────────────────────
document.querySelectorAll('.btn-delete').forEach(btn => {
    btn.addEventListener('click', function() {
        const id   = this.dataset.id;
        const name = this.dataset.name;
        Swal.fire({
            title: 'Delete Subscriber?',
            html: `Are you sure you want to delete <strong>${name}</strong>?<br>
                   <small class="text-danger">This permanently removes the record from the database and cannot be undone.</small>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Yes, Delete Permanently',
        }).then(r => {
            if (r.isConfirmed) {
                fetch(BASE_URL + '/modules/subscribers/ajax_actions', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=delete&subscriber_id=${id}&${CSRF_TOKEN_NAME}=${CSRF_TOKEN}`
                })
                .then(r => r.json())
                .then(d => {
                    if (d.success) {
                        Swal.fire('Deleted!', d.message, 'success')
                            .then(() => location.reload());
                    } else {
                        Swal.fire('Error', d.message, 'error');
                    }
                });
            }
        });
    });
});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
