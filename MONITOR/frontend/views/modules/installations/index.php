<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_LINEMAN, ROLE_ADMIN, ROLE_SUPERADMIN);

$status    = $_GET['status'] ?? 'Pending';
$validStat = ['Pending', 'Ongoing', 'Approved'];
if (!in_array($status, $validStat, true)) $status = 'Pending';

$pageTitle   = 'Installations — ' . $status;
$breadcrumbs = [
    ['label' => 'Installations'],
    ['label' => $status],
];

$isLineman = hasRole(ROLE_LINEMAN);
$myId      = currentUserId();

// ── Filters (session-persisted per status tab) ─────────────────────────────
$SESS_KEY = 'jo_filter_' . $status;

if (isset($_GET['_clear'])) {
    unset($_SESSION[$SESS_KEY]);
    redirect(BASE_URL . '/modules/installations/?status=' . $status);
}

if (isset($_GET['_filter'])) {
    $_SESSION[$SESS_KEY] = [
        'search'    => trim($_GET['search']    ?? ''),
        'date_from' => trim($_GET['date_from'] ?? ''),
        'date_to'   => trim($_GET['date_to']   ?? ''),
    ];
    redirect(BASE_URL . '/modules/installations/?status=' . $status);
}

$f = $_SESSION[$SESS_KEY] ?? [];
$search    = $f['search']    ?? '';
$dateFrom  = $f['date_from'] ?? '';
$dateTo    = $f['date_to']   ?? '';
$hasFilter = $search !== '' || $dateFrom !== '' || $dateTo !== '';

// ── Build query ────────────────────────────────────────────────────────────
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = DEFAULT_PER_PAGE;

$where  = ['i.status = ?'];
$params = [$status];

if ($isLineman) {
    $where[]  = 'i.user_id = ?';
    $params[] = $myId;
}

if ($search !== '') {
    $where[]  = '(s.account_number LIKE ? OR s.firstname LIKE ? OR s.lastname LIKE ? OR CONCAT(s.firstname," ",s.lastname) LIKE ? OR s.contact_number LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

if ($dateFrom !== '') {
    $where[]  = 'DATE(i.created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[]  = 'DATE(i.created_at) <= ?';
    $params[] = $dateTo;
}

$whereStr = implode(' AND ', $where);

$total = db()->prepare("SELECT COUNT(*) FROM installations i JOIN subscribers s ON s.subscriber_id = i.subscriber_id WHERE {$whereStr}");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();

$pag = paginate($totalCount, $page, $perPage);

$stmt = db()->prepare("
    SELECT i.installation_id, i.status AS install_status, i.remark, i.created_at, i.updated_at,
           s.subscriber_id, s.account_number, s.firstname, s.lastname,
           s.address, s.contact_number, s.connection_type,
           s.street, s.barangay, s.municipality,
           p.title AS plan_title,
           r.name  AS router_name,
           u.user_id AS lm_id, u.firstname AS lm_first, u.lastname AS lm_last
    FROM installations i
    JOIN subscribers s ON s.subscriber_id = i.subscriber_id
    LEFT JOIN plans   p ON p.plan_id = s.plan_id
    LEFT JOIN routers r ON r.router_id = s.router_id
    LEFT JOIN users   u ON u.user_id  = i.user_id
    WHERE {$whereStr}
    ORDER BY i.updated_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$stmt->execute($params);
$jobs = $stmt->fetchAll();

// Count badges for tabs (same user scope, no search filter applied)
$tabCounts = [];
foreach ($validStat as $st) {
    $cWhere  = ['i.status = ?'];
    $cParams = [$st];
    if ($isLineman) { $cWhere[] = 'i.user_id = ?'; $cParams[] = $myId; }
    $c = db()->prepare("SELECT COUNT(*) FROM installations i WHERE " . implode(' AND ', $cWhere));
    $c->execute($cParams);
    $tabCounts[$st] = (int)$c->fetchColumn();
}

include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Installations</h4>
        <p class="text-muted small mb-0"><?= number_format($totalCount) ?> <?= $status ?> installation<?= $totalCount !== 1 ? 's' : '' ?><?= $hasFilter ? ' (filtered)' : '' ?></p>
    </div>
</div>

<!-- Status Tabs -->
<ul class="nav nav-tabs mb-3">
    <?php
    $tabColors = ['Pending' => 'secondary', 'Ongoing' => 'warning', 'Approved' => 'success'];
    foreach ($validStat as $st):
        $isActive = $st === $status;
        $cnt = $tabCounts[$st];
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $isActive ? 'active fw-semibold' : '' ?>"
           href="?status=<?= $st ?>">
            <?= $st ?>
            <?php if ($cnt > 0): ?>
            <span class="badge bg-<?= $tabColors[$st] ?> ms-1"><?= $cnt ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- Search & Date Filter -->
<form method="GET" action="" class="card mb-3">
    <div class="card-body py-2">
        <input type="hidden" name="status" value="<?= e($status) ?>">
        <input type="hidden" name="_filter" value="1">
        <div class="row g-2 align-items-end">
            <div class="col-sm-4">
                <label for="jo-search" class="form-label small fw-medium mb-1">Search</label>
                <input type="text" id="jo-search" name="search" class="form-control form-control-sm"
                       placeholder="Name, account #, contact…"
                       value="<?= e($search) ?>">
            </div>
            <div class="col-sm-3">
                <label for="jo-date-from" class="form-label small fw-medium mb-1">Date From</label>
                <input type="date" id="jo-date-from" name="date_from" class="form-control form-control-sm"
                       value="<?= e($dateFrom) ?>">
            </div>
            <div class="col-sm-3">
                <label for="jo-date-to" class="form-label small fw-medium mb-1">Date To</label>
                <input type="date" id="jo-date-to" name="date_to" class="form-control form-control-sm"
                       value="<?= e($dateTo) ?>">
            </div>
            <div class="col-sm-2 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary flex-grow-1">
                    <i class="bi bi-search me-1"></i>Filter
                </button>
                <?php if ($hasFilter): ?>
                <a href="?status=<?= $status ?>&_clear=1" class="btn btn-sm btn-outline-secondary" title="Clear filters">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<div class="card" id="list">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Account #</th>
                        <th>Subscriber</th>
                        <th>Contact</th>
                        <th>Plan</th>
                        <th>Router</th>
                        <?php if (!$isLineman): ?>
                        <th>Lineman</th>
                        <?php endif; ?>
                        <th>Remark</th>
                        <th>Assigned</th>
                        <th><?= $status === 'Approved' ? 'Completed' : 'Updated' ?></th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($jobs)): ?>
                    <tr>
                        <td colspan="<?= $isLineman ? 9 : 10 ?>" class="text-center py-5 text-muted">
                            <i class="bi bi-clipboard2-x d-block fs-2 mb-2"></i>
                            <?= $hasFilter ? 'No results match your filter.' : 'No ' . strtolower($status) . ' installations found.' ?>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($jobs as $job):
                        $addr = implode(', ', array_filter([
                            $job['street'] ?? '',
                            $job['barangay'] ?? '',
                            $job['municipality'] ?? '',
                        ])) ?: ($job['address'] ?? '—');
                    ?>
                    <tr>
                        <td class="ps-3">
                            <span class="font-monospace small fw-medium"><?= e($job['account_number']) ?></span>
                        </td>
                        <td>
                            <div class="fw-medium"><?= e($job['firstname'] . ' ' . $job['lastname']) ?></div>
                            <div class="text-muted small text-truncate" style="max-width:200px;"><?= e($addr) ?></div>
                        </td>
                        <td class="small text-muted"><?= e($job['contact_number'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($job['plan_title'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e($job['router_name'] ?? '—') ?></td>
                        <?php if (!$isLineman): ?>
                        <td class="small text-muted"><?= e(trim(($job['lm_first'] ?? '') . ' ' . ($job['lm_last'] ?? ''))) ?: '—' ?></td>
                        <?php endif; ?>
                        <td class="small text-muted" style="max-width:180px;">
                            <?php if (!empty($job['remark'])): ?>
                            <span class="text-truncate d-inline-block" style="max-width:170px;" title="<?= e($job['remark']) ?>">
                                <?= e($job['remark']) ?>
                            </span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= formatDate($job['created_at'], 'M d, Y') ?></td>
                        <td class="small text-muted"><?= formatDate($job['updated_at'], 'M d, Y') ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= BASE_URL ?>/modules/installations/view?id=<?= $job['installation_id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View / Edit">
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
            of <?= number_format($totalCount) ?>
        </small>
        <?= renderPagination($pag, '?status=' . $status, 'list') ?>
    </div>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/includes/footer.php'; ?>
