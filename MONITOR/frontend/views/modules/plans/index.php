<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);

$planWriteRouterId = selectedRouterId();
$planWriteScopeSql = '';
$planWriteScopeParams = [];
if (currentRole() !== ROLE_SUPERADMIN) {
    if (!$planWriteRouterId) {
        flashMessage('danger', 'Router not selected.');
        redirect(BASE_URL . '/modules/plans/');
    }
    $planWriteScopeSql = ' AND router_id = ?';
    $planWriteScopeParams[] = $planWriteRouterId;
}

// Toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_plan'])) {
    requireMinRole(ROLE_ADMIN);
    verifyCsrf();
    $pid    = (int)$_POST['plan_id'];
    $active = (int)$_POST['is_active'];
    $update = db()->prepare("UPDATE plans SET is_active = ? WHERE plan_id = ?{$planWriteScopeSql}");
    $update->execute(array_merge([$active ? 0 : 1, $pid], $planWriteScopeParams));
    $changed = $update->rowCount() > 0;
    flashMessage($changed ? 'success' : 'danger', $changed ? 'Plan status updated.' : 'Plan not found for this router.');
    redirect(BASE_URL . '/modules/plans/');
}

// Toggle payment portal visibility
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_portal'])) {
    requireMinRole(ROLE_ADMIN);
    verifyCsrf();
    $pid = (int)($_POST['plan_id'] ?? 0);
    $row = db()->prepare("SELECT title, portal_enabled FROM plans WHERE plan_id = ?{$planWriteScopeSql}");
    $row->execute(array_merge([$pid], $planWriteScopeParams));
    $planForToggle = $row->fetch();
    if ($planForToggle) {
        $enabled = !empty($planForToggle['portal_enabled']) ? 0 : 1;
        db()->prepare("UPDATE plans SET portal_enabled = ? WHERE plan_id = ?{$planWriteScopeSql}")
            ->execute(array_merge([$enabled, $pid], $planWriteScopeParams));
        logActivity('plans', 'update', "Payment portal " . ($enabled ? 'enabled' : 'disabled') . " for plan: {$planForToggle['title']}");
        flashMessage('success', 'Plan portal setting updated.');
    } else {
        flashMessage('danger', 'Plan not found for this router.');
    }
    redirect(BASE_URL . '/modules/plans/');
}

// Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_plan'])) {
    requireMinRole(ROLE_ADMIN);
    verifyCsrf();
    $pid = (int)$_POST['plan_id'];
    $plan = db()->prepare("SELECT plan_id FROM plans WHERE plan_id = ?{$planWriteScopeSql}");
    $plan->execute(array_merge([$pid], $planWriteScopeParams));
    if (!$plan->fetch()) {
        flashMessage('danger', 'Plan not found for this router.');
        redirect(BASE_URL . '/modules/plans/');
    }
    $cnt = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE plan_id = ?");
    $cnt->execute([$pid]);
    if ((int)$cnt->fetchColumn() > 0) {
        flashMessage('danger', 'Cannot delete: plan has subscribers. Deactivate instead.');
    } else {
        db()->prepare("DELETE FROM plans WHERE plan_id = ?{$planWriteScopeSql}")
            ->execute(array_merge([$pid], $planWriteScopeParams));
        logActivity('plans', 'delete', "Deleted plan ID {$pid}");
        flashMessage('success', 'Plan deleted.');
    }
    redirect(BASE_URL . '/modules/plans/');
}

// Filters — always scoped to the currently selected router
$selRouterId = selectedRouterId();
$search      = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
if ($selRouterId) {
    $where[]  = '(p.router_id = ? OR p.router_id IS NULL)';
    $params[] = $selRouterId;
}
if ($search !== '') {
    $where[]  = '(p.title LIKE ? OR p.description LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = db()->prepare("SELECT COUNT(*) FROM plans p {$whereSQL}");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = DEFAULT_PER_PAGE;
$pag     = paginate($total, $page, $perPage);

$paginationPrefix = '?';
if ($search) $paginationPrefix .= 'q=' . urlencode($search) . '&';
$paginationPrefix .= 'page=';

$plans = db()->prepare("
    SELECT p.*, r.name AS router_name, COUNT(s.subscriber_id) AS sub_count
    FROM plans p
    LEFT JOIN routers r ON r.router_id = p.router_id
    LEFT JOIN subscribers s ON s.plan_id = p.plan_id AND s.status = 'active'
        " . ($selRouterId ? "AND s.router_id = ?" : "") . "
    {$whereSQL}
    GROUP BY p.plan_id
    ORDER BY p.amount ASC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$plans->execute(array_merge($selRouterId ? [$selRouterId] : [], $params));
$plans = $plans->fetchAll();

$currency    = getSetting('currency_symbol', '₱');
$pageTitle   = 'Plans';
$breadcrumbs = [['label' => 'Plans']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Subscription Plans</h4>
        <p class="text-muted small mb-0"><?= number_format($total) ?> plan<?= $total !== 1 ? 's' : '' ?></p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <div class="btn-group btn-group-sm" role="group" aria-label="View toggle">
            <button type="button" class="btn btn-outline-secondary" id="btnCardView" title="Card view">
                <i class="bi bi-grid-3x3-gap"></i>
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnTableView" title="Table view">
                <i class="bi bi-table"></i>
            </button>
        </div>
        <?php if (hasMinRole(ROLE_ADMIN)): ?>
        <a href="add" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>Add Plan
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter bar -->
<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-sm-8 col-lg-4">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Search plans…" value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <?php if ($search): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php
$emptyCardHtml = '';
if (empty($plans)) {
    ob_start();
    if ($search !== ''): ?>
    <div class="col-12">
        <div class="text-center py-5 text-muted">
            <i class="bi bi-grid d-block fs-2 mb-2"></i>
            No plans match the current filter.
            <a href="?" class="d-block mt-2 small">Clear filters</a>
        </div>
    </div>
    <?php else: ?>
    <div class="col-12">
        <?= inlineToasts([['message' => 'No plans created yet. Go to Plans → Add to create your first plan.', 'type' => 'info']]) ?>
    </div>
    <?php endif;
    $emptyCardHtml = ob_get_clean();
}
?>

<!-- ── Card view ────────────────────────────────────────────── -->
<div class="row g-3" id="viewCards">
    <?php if (empty($plans)): ?>
    <?= $emptyCardHtml ?>
    <?php else: ?>
    <?php foreach ($plans as $plan): ?>
    <div class="col-sm-6 col-xl-4">
        <div class="card border-0 h-100 <?= !$plan['is_active'] ? 'opacity-50' : '' ?>">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <h6 class="fw-bold mb-0"><?= e($plan['title']) ?></h6>
                        <div class="d-flex flex-wrap gap-1 mt-1">
                            <span class="badge <?= $plan['plan_type'] === 'ppp' ? 'bg-primary' : ($plan['plan_type'] === 'hotspot' ? 'bg-info text-dark' : 'bg-secondary') ?>">
                                <?= strtoupper($plan['plan_type']) ?>
                            </span>
                            <?php if ($plan['router_name']): ?>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border">
                                <i class="bi bi-router me-1"></i><?= e($plan['router_name']) ?>
                            </span>
                            <?php endif; ?>
                            <?= !empty($plan['portal_enabled'])
                                ? '<span class="badge bg-success-subtle text-success border"><i class="bi bi-globe2 me-1"></i>Portal</span>'
                                : '<span class="badge bg-light text-muted border"><i class="bi bi-globe2 me-1"></i>Portal Off</span>' ?>
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold fs-5 text-success"><?= e($currency) ?><?= number_format($plan['amount'], 2) ?></div>
                        <div class="text-muted small">/ <?= $plan['billing_cycle'] ?></div>
                    </div>
                </div>
                <?php if ($plan['description']): ?>
                <p class="text-muted small mb-2"><?= e($plan['description']) ?></p>
                <?php endif; ?>
                <div class="d-flex gap-3 mb-3 small">
                    <div>
                        <i class="bi bi-download text-primary me-1"></i>
                        <strong><?= $plan['speed_mbps'] ?>Mbps</strong>
                        <?php if ($plan['burst_mbps']): ?>
                        <span class="text-muted">/ <?= $plan['burst_mbps'] ?>Mbps burst</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small">
                        <i class="bi bi-people me-1"></i><?= number_format($plan['sub_count']) ?> active subs
                    </span>
                    <?= $plan['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?>
                </div>
            </div>
            <?php if (hasMinRole(ROLE_ADMIN)): ?>
            <div class="card-footer bg-transparent border-top d-flex gap-2">
                <a href="edit/<?= $plan['plan_id'] ?>" class="btn btn-sm btn-outline-primary flex-grow-1">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="plan_id"   value="<?= $plan['plan_id'] ?>">
                    <input type="hidden" name="is_active" value="<?= $plan['is_active'] ?>">
                    <button type="submit" name="toggle_plan" class="btn btn-sm <?= $plan['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                        <?= $plan['is_active'] ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?>
                    </button>
                </form>
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="plan_id" value="<?= $plan['plan_id'] ?>">
                    <button type="submit" name="toggle_portal"
                            class="btn btn-sm <?= !empty($plan['portal_enabled']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                            title="<?= !empty($plan['portal_enabled']) ? 'Disable payment portal for this plan' : 'Enable payment portal for this plan' ?>">
                        <i class="bi bi-globe2"></i>
                    </button>
                </form>
                <?php if ($plan['sub_count'] == 0): ?>
                <form method="POST" class="d-inline"
                      data-confirm="Delete plan '<?= e(addslashes($plan['title'])) ?>'? This cannot be undone.">
                    <?= csrfField() ?>
                    <input type="hidden" name="plan_id" value="<?= $plan['plan_id'] ?>">
                    <button type="submit" name="delete_plan" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── Table view ───────────────────────────────────────────── -->
<div id="viewTable" class="d-none">
    <?php if (empty($plans)): ?>
    <?= $emptyCardHtml ?>
    <?php else: ?>
    <div class="card border-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Plan</th>
                        <th>Type</th>
                        <th>Router</th>
                        <th class="text-end">Amount</th>
                        <th>Cycle</th>
                        <th class="text-center">Speed</th>
                        <th class="text-center">Active Subs</th>
                        <th class="text-center">Portal</th>
                        <th class="text-center">Status</th>
                        <?php if (hasMinRole(ROLE_ADMIN)): ?>
                        <th class="pe-3 text-end">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): ?>
                    <tr class="<?= !$plan['is_active'] ? 'text-muted opacity-60' : '' ?>">
                        <td class="ps-3">
                            <div class="fw-semibold"><?= e($plan['title']) ?></div>
                            <?php if ($plan['description']): ?>
                            <div class="text-muted small"><?= e(mb_strimwidth($plan['description'], 0, 60, '…')) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $plan['plan_type'] === 'ppp' ? 'bg-primary' : ($plan['plan_type'] === 'hotspot' ? 'bg-info text-dark' : 'bg-secondary') ?>">
                                <?= strtoupper($plan['plan_type']) ?>
                            </span>
                        </td>
                        <td class="small"><?= $plan['router_name'] ? e($plan['router_name']) : '<span class="text-muted">All</span>' ?></td>
                        <td class="text-end fw-semibold text-success"><?= e($currency) ?><?= number_format($plan['amount'], 2) ?></td>
                        <td class="small text-capitalize"><?= e($plan['billing_cycle']) ?></td>
                        <td class="text-center small">
                            <strong><?= $plan['speed_mbps'] ?>Mbps</strong>
                            <?php if ($plan['burst_mbps']): ?>
                            <span class="text-muted d-block text-xs"><?= $plan['burst_mbps'] ?>Mbps burst</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border"><?= number_format($plan['sub_count']) ?></span>
                        </td>
                        <td class="text-center">
                            <?= !empty($plan['portal_enabled'])
                                ? '<span class="badge bg-success-subtle text-success border">Enabled</span>'
                                : '<span class="badge bg-light text-muted border">Off</span>' ?>
                        </td>
                        <td class="text-center">
                            <?= $plan['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <?php if (hasMinRole(ROLE_ADMIN)): ?>
                        <td class="pe-3 text-end">
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="edit/<?= $plan['plan_id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="plan_id"   value="<?= $plan['plan_id'] ?>">
                                    <input type="hidden" name="is_active" value="<?= $plan['is_active'] ?>">
                                    <button type="submit" name="toggle_plan" class="btn btn-sm <?= $plan['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                        <?= $plan['is_active'] ? '<i class="bi bi-pause"></i>' : '<i class="bi bi-play"></i>' ?>
                                    </button>
                                </form>
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="plan_id" value="<?= $plan['plan_id'] ?>">
                                    <button type="submit" name="toggle_portal"
                                            class="btn btn-sm <?= !empty($plan['portal_enabled']) ? 'btn-outline-success' : 'btn-outline-secondary' ?>"
                                            title="<?= !empty($plan['portal_enabled']) ? 'Disable payment portal for this plan' : 'Enable payment portal for this plan' ?>">
                                        <i class="bi bi-globe2"></i>
                                    </button>
                                </form>
                                <?php if ($plan['sub_count'] == 0): ?>
                                <form method="POST" class="d-inline"
                                      data-confirm="Delete plan '<?= e(addslashes($plan['title'])) ?>'? This cannot be undone.">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="plan_id" value="<?= $plan['plan_id'] ?>">
                                    <button type="submit" name="delete_plan" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<div class="d-flex align-items-center justify-content-between mt-4 flex-wrap gap-2">
    <small class="text-muted">
        Showing <?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $total)) ?>
        of <?= number_format($total) ?> plan<?= $total !== 1 ? 's' : '' ?>
    </small>
    <?php if ($pag['total_pages'] > 1): ?>
    <?= renderPagination($pag, $paginationPrefix, 'list') ?>
    <?php endif; ?>
</div>

<?php
$extraScripts = <<<'JS'
<script>
// ── View toggle (card / table) ────────────────────────────────
(function () {
    const PREF_KEY  = 'plans_view';
    const cards     = document.getElementById('viewCards');
    const table     = document.getElementById('viewTable');
    const btnCards  = document.getElementById('btnCardView');
    const btnTable  = document.getElementById('btnTableView');

    function applyView(mode) {
        const isTable = mode === 'table';
        cards.classList.toggle('d-none', isTable);
        table.classList.toggle('d-none', !isTable);
        btnCards.classList.toggle('active', !isTable);
        btnTable.classList.toggle('active', isTable);
        localStorage.setItem(PREF_KEY, mode);
    }

    btnCards.addEventListener('click', () => applyView('card'));
    btnTable.addEventListener('click', () => applyView('table'));

    applyView(localStorage.getItem(PREF_KEY) || 'card');
})();

// ── SweetAlert2 confirmation for plan delete forms ────────────
document.querySelectorAll('form[data-confirm]').forEach(form => {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const self = this;
        Swal.fire({
            title: 'Delete Plan?',
            html: this.dataset.confirm,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: '<i class="bi bi-trash me-1"></i>Yes, Delete',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
        }).then(r => { if (r.isConfirmed) HTMLFormElement.prototype.submit.call(self); });
    });
});
</script>
JS;

include BASE_PATH . '/includes/footer.php'; ?>
