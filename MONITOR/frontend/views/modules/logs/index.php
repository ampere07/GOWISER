<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);

$module  = $_GET['module']  ?? '';
$action  = $_GET['action']  ?? '';
$search  = trim($_GET['q']  ?? '');
$dateFrom= $_GET['date_from']?? '';
$dateTo  = $_GET['date_to']  ?? '';
$page    = max(1, (int)($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];

if ($module) { $where[] = 'al.module = ?';        $params[] = $module; }
if ($action) { $where[] = 'al.action = ?';         $params[] = $action; }
if ($search) {
    $where[]  = '(al.description LIKE ? OR u.username LIKE ? OR u.role LIKE ? OR s.account_number LIKE ?)';
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($dateFrom) { $where[] = 'DATE(al.logged_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo)   { $where[] = 'DATE(al.logged_at) <= ?'; $params[] = $dateTo; }

$whereStr = implode(' AND ', $where);

$total = db()->prepare("
    SELECT COUNT(*)
    FROM activity_log al
    LEFT JOIN users u ON u.user_id = al.user_id
    LEFT JOIN subscribers s ON s.subscriber_id = al.subscriber_id
    WHERE {$whereStr}
");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();

$pag = paginate($totalCount, $page, 50);

$logs = db()->prepare("
    SELECT al.*, u.firstname, u.lastname, u.username, u.role AS user_role,
           s.account_number, s.firstname AS sub_first, s.lastname AS sub_last
    FROM activity_log al
    LEFT JOIN users u ON u.user_id = al.user_id
    LEFT JOIN subscribers s ON s.subscriber_id = al.subscriber_id
    WHERE {$whereStr}
    ORDER BY al.logged_at DESC
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$logs->execute($params);
$logs = $logs->fetchAll();

// Module/action lists for filter
$modules = db()->query("SELECT DISTINCT module FROM activity_log ORDER BY module")->fetchAll(PDO::FETCH_COLUMN);
$actions = db()->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle   = 'Activity Log';
$breadcrumbs = [['label' => 'Activity Log']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Activity Log</h4>
        <p class="text-muted small mb-0"><?= number_format($totalCount) ?> entries</p>
    </div>
</div>

<!-- Filters -->
<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-sm-6 col-lg-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Search…" value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="module" class="form-select form-select-sm">
                <option value="">All Modules</option>
                <?php foreach ($modules as $m): ?>
                <option value="<?= $m ?>" <?= $module === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="action" class="form-select form-select-sm">
                <option value="">All Actions</option>
                <?php foreach ($actions as $a): ?>
                <option value="<?= $a ?>" <?= $action === $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-auto">
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>" title="From date">
        </div>
        <div class="col-sm-6 col-lg-auto">
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>" title="To date">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <a href="?" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
        </div>
    </form>
</div>

<div class="card border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Time</th>
                        <th>User</th>
                        <th>Role</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>Subscriber</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-5 text-muted">
                            <i class="bi bi-journal-x d-block fs-2 mb-2"></i>No log entries found.
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <?php
                    $actionColors = [
                        'create'   => 'success',
                        'update'   => 'primary',
                        'delete'   => 'danger',
                        'login'    => 'info',
                        'logout'   => 'secondary',
                        'suspend'  => 'warning',
                        'activate' => 'success',
                        'payment'  => 'success',
                    ];
                    $aColor = $actionColors[$log['action']] ?? 'secondary';
                    ?>
                    <tr>
                        <td class="ps-3 small text-muted text-nowrap"><?= formatDate($log['logged_at'], 'M d H:i:s') ?></td>
                        <td class="small">
                            <?= $log['username'] ? '<span class="font-monospace">@' . e($log['username']) . '</span>' : '<em class="text-muted">System</em>' ?>
                        </td>
                        <td class="small">
                            <?= $log['user_role'] ? e(ROLES[$log['user_role']] ?? ucfirst($log['user_role'])) : '<span class="text-muted">System</span>' ?>
                        </td>
                        <td>
                            <span class="badge bg-secondary bg-opacity-10 text-secondary border"><?= e($log['module']) ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?= $aColor ?>"><?= e($log['action']) ?></span>
                        </td>
                        <td class="small" style="max-width:350px;">
                            <span class="text-truncate d-block" title="<?= e($log['description']) ?>">
                                <?= e($log['description']) ?>
                            </span>
                        </td>
                        <td class="small">
                            <?php if ($log['account_number']): ?>
                            <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $log['subscriber_id'] ?>"
                               class="text-decoration-none"><?= e($log['account_number']) ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td class="small font-monospace text-muted"><?= e($log['ip_address'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pag['total_pages'] > 1): ?>
    <div class="card-footer bg-transparent border-top d-flex align-items-center justify-content-between">
        <small class="text-muted">Showing <?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $totalCount)) ?> of <?= number_format($totalCount) ?></small>
        <?= renderPagination($pag, '?' . http_build_query(array_filter(['q' => $search, 'module' => $module, 'action' => $action, 'date_from' => $dateFrom, 'date_to' => $dateTo]))) ?>
    </div>
    <?php endif; ?>
</div>

<?php include BASE_PATH . '/includes/footer.php'; ?>
