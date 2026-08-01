<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_CASHIER, ROLE_ADMIN, ROLE_SUPERADMIN);

$pageTitle   = 'Archived Subscribers';
$breadcrumbs = [
    ['label' => 'Subscribers', 'url' => BASE_URL . '/modules/subscribers/'],
    ['label' => 'Archived'],
];

const SESS_ARCH = 'filter_subscribers_archived';

if (isset($_GET['_clear'])) {
    unset($_SESSION[SESS_ARCH]);
    redirect(BASE_URL . '/modules/subscribers/archived');
}
if (isset($_GET['_filter'])) {
    $_SESSION[SESS_ARCH] = [
        'q'         => trim($_GET['q']   ?? ''),
        'conn_type' => $_GET['conn_type'] ?? '',
        'plan_id'   => (int)($_GET['plan_id'] ?? 0),
    ];
    redirect(BASE_URL . '/modules/subscribers/archived');
}

$f        = $_SESSION[SESS_ARCH] ?? [];
$search   = $f['q']         ?? '';
$connType = $f['conn_type'] ?? '';
$planId   = (int)($f['plan_id'] ?? 0);
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = DEFAULT_PER_PAGE;

$selRouterId = selectedRouterId();

$where  = ['s.status = ?'];
$params = [SUB_STATUS_ARCHIVED];

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
           s.address, s.contact_number, s.connection_type, s.ppp_username, s.updated_at,
           p.title AS plan_title,
           r.name  AS router_name
    FROM subscribers s
    LEFT JOIN plans   p ON p.plan_id   = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id
    WHERE {$whereStr}
    ORDER BY s.updated_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$stmt->execute($params);
$subscribers = $stmt->fetchAll();

include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Archived Subscribers</h4>
        <p class="text-muted small mb-0"><?= number_format($totalCount) ?> archived</p>
    </div>
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

<!-- Table -->
<div class="card" id="list">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Account #</th>
                        <th>Subscriber</th>
                        <th>Type</th>
                        <th>Plan</th>
                        <th>Router</th>
                        <th>Archived On</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($subscribers)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-archive d-block fs-2 mb-2"></i>
                            No archived subscribers found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($subscribers as $sub): ?>
                    <tr>
                        <td class="ps-3">
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
                            <span class="badge <?= ($sub['connection_type'] ?? '') === 'ppp' ? 'bg-primary' : 'bg-info text-dark' ?> opacity-75">
                                <?= strtoupper(e($sub['connection_type'] ?? '—')) ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= e($sub['plan_title'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($sub['router_name'] ?? '—') ?></td>
                        <td class="small text-muted"><?= formatDate($sub['updated_at'], 'M d, Y') ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $sub['subscriber_id'] ?>" class="btn btn-sm btn-outline-secondary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
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

<?php
include BASE_PATH . '/includes/footer.php';
