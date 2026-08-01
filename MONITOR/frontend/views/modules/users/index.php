<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_SUPERADMIN);

// Delete user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    verifyCsrf();
    $uid = (int)$_POST['user_id'];
    if ($uid === currentUserId()) { flashMessage('danger', 'Cannot delete your own account.'); redirect(BASE_URL . '/modules/users/'); }

    $u = db()->prepare("SELECT user_id, firstname, lastname, username, email, role, router_id, is_active FROM users WHERE user_id = ?");
    $u->execute([$uid]);
    $uRow = $u->fetch();

    if (!$uRow) {
        flashMessage('danger', 'User not found.');
    } elseif ($uRow['role'] === ROLE_SUPERADMIN && !hasRole(ROLE_SUPERADMIN)) {
        flashMessage('danger', 'Cannot delete a Super Administrator.');
    } else {
        db()->prepare("UPDATE users SET is_active = 0 WHERE user_id = ?")->execute([$uid]);
        logActivity(
            'users',
            'deactivate',
            "Deactivated system user #{$uid}: {$uRow['firstname']} {$uRow['lastname']} (@{$uRow['username']}), email {$uRow['email']}, role " . (ROLES[$uRow['role']] ?? $uRow['role']) . ", router " . ($uRow['router_id'] ?: 'none') . ".",
            null,
            [
                'user_id' => $uid,
                'firstname' => $uRow['firstname'],
                'lastname' => $uRow['lastname'],
                'username' => $uRow['username'],
                'email' => $uRow['email'],
                'role' => $uRow['role'],
                'router_id' => $uRow['router_id'],
                'is_active' => $uRow['is_active'],
            ],
            [
                'user_id' => $uid,
                'firstname' => $uRow['firstname'],
                'lastname' => $uRow['lastname'],
                'username' => $uRow['username'],
                'email' => $uRow['email'],
                'role' => $uRow['role'],
                'router_id' => $uRow['router_id'],
                'is_active' => 0,
            ]
        );
        flashMessage('success', 'User deactivated.');
    }
    redirect(BASE_URL . '/modules/users/');
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = DEFAULT_PER_PAGE;

// Search & filter params
$search    = trim($_GET['search'] ?? '');
$filterRole   = $_GET['role']   ?? '';
$filterStatus = $_GET['status'] ?? '';

// Validate filter values
$validRoles = array_keys(ROLES);
if (!in_array($filterRole, $validRoles, true)) $filterRole = '';
if (!in_array($filterStatus, ['active', 'inactive'], true)) $filterStatus = '';

// Build WHERE clause
$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = "(u.firstname LIKE ? OR u.lastname LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR CONCAT(u.firstname,' ',u.lastname) LIKE ?)";
    $like     = '%' . $search . '%';
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($filterRole !== '') {
    $where[]  = "u.role = ?";
    $params[] = $filterRole;
}
if ($filterStatus === 'active') {
    $where[]  = "u.is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $where[]  = "u.is_active = 0";
}
$whereSQL = implode(' AND ', $where);

$totalStmt = db()->prepare("SELECT COUNT(*) FROM users u WHERE {$whereSQL}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();
$pag   = paginate($total, $page, $perPage);

// Build pagination query string preserving filters
$filterQS = http_build_query(array_filter([
    'search' => $search,
    'role'   => $filterRole,
    'status' => $filterStatus,
]));
$paginationPrefix = '?' . ($filterQS ? $filterQS . '&' : '') . 'page=';

$usersStmt = db()->prepare("
    SELECT u.*, r.name AS router_name, COUNT(py.payment_id) AS payment_count
    FROM users u
    LEFT JOIN payments py ON py.user_id = u.user_id
    LEFT JOIN routers r ON r.router_id = u.router_id
    WHERE {$whereSQL}
    GROUP BY u.user_id
    ORDER BY FIELD(u.role,'superadmin','admin','user','cashier','lineman'), u.firstname
    LIMIT {$pag['per_page']} OFFSET {$pag['offset']}
");
$usersStmt->execute($params);
$users = $usersStmt->fetchAll();

$pageTitle   = 'Users';
$breadcrumbs = [['label' => 'Users']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">System Users</h4>
        <p class="text-muted small mb-0"><?= number_format($total) ?> user<?= $total !== 1 ? 's' : '' ?></p>
    </div>
    <a href="add" class="btn btn-primary">
        <i class="bi bi-person-plus me-1"></i>Add User
    </a>
</div>

<!-- Search & Filter -->
<div class="filter-bar mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-sm-6 col-lg-3">
            <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search text-muted"></i></span>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Name, username or email…" value="<?= e($search) ?>">
            </div>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="role" class="form-select form-select-sm">
                <option value="">All Roles</option>
                <?php foreach (ROLES as $val => $label): ?>
                <option value="<?= $val ?>" <?= $filterRole === $val ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-6 col-lg-2">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Status</option>
                <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search"></i></button>
            <?php if ($search || $filterRole || $filterStatus): ?>
            <a href="./" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Default Router</th>
                        <th>Last Login</th>
                        <th>Payments</th>
                        <th>Status</th>
                        <th class="pe-3 text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-5">
                            <i class="bi bi-people fs-2 d-block mb-2"></i>
                            No users found<?= ($search || $filterRole || $filterStatus) ? ' matching your filters.' : '.' ?>
                            <?php if ($search || $filterRole || $filterStatus): ?>
                            <a href="./" class="d-block mt-1 small">Clear filters</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php foreach ($users as $user): ?>
                    <tr class="<?= !$user['is_active'] ? 'opacity-50' : '' ?>">
                        <td class="ps-3">
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar-circle <?= $user['user_id'] == currentUserId() ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= strtoupper(substr($user['firstname'], 0, 1)) ?>
                                </div>
                                <div>
                                    <div class="fw-medium"><?= e($user['firstname'] . ' ' . $user['lastname']) ?></div>
                                    <div class="text-muted small">@<?= e($user['username']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td class="small"><?= e($user['email']) ?></td>
                        <td>
                            <?php
                            $roleColors = [
                                'superadmin' => 'danger',
                                'admin'      => 'warning text-dark',
                                'user'       => 'primary',
                                'cashier'    => 'info text-dark',
                                'lineman'    => 'success',
                            ];
                            $rColor = $roleColors[$user['role']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $rColor ?>"><?= e(ROLES[$user['role']] ?? ucfirst($user['role'])) ?></span>
                        </td>
                        <td class="small text-muted"><?= e($user['router_name'] ?? ($user['role'] === ROLE_SUPERADMIN ? 'Switchable' : '—')) ?></td>
                        <td class="small text-muted">
                            <?= $user['last_login'] ? timeDiffHuman($user['last_login']) : 'Never' ?>
                        </td>
                        <td class="small"><?= number_format($user['payment_count']) ?></td>
                        <td>
                            <?= $user['is_active']
                                ? '<span class="badge bg-success">Active</span>'
                                : '<span class="badge bg-secondary">Inactive</span>' ?>
                        </td>
                        <td class="text-end pe-3">
                            <?php if ($user['user_id'] != currentUserId()): ?>
                                <div class="d-flex gap-1 justify-content-end">
                                    <a href="edit/<?= $user['user_id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Account">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <a href="profile/<?= $user['user_id'] ?>" class="btn btn-sm btn-outline-secondary" title="Profile & Security">
                                        <i class="bi bi-person-gear"></i>
                                    </a>
                                    <form method="POST" class="d-inline"
                                          data-confirm="Deactivate user @<?= e(addslashes($user['username'])) ?>?">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                                        <button type="submit" name="delete_user" class="btn btn-sm btn-outline-danger" title="Deactivate">
                                            <i class="bi bi-person-x"></i>
                                        </button>
                                    </form>
                                </div>
                            <?php else: ?>
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="profile" class="btn btn-sm btn-outline-secondary" title="Profile">
                                    <i class="bi bi-person"></i>
                                </a>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-transparent border-top d-flex align-items-center justify-content-between">
        <small class="text-muted">
            <?php if ($total === 0): ?>
                No users found
            <?php elseif ($pag['total_pages'] <= 1): ?>
                <?= number_format($total) ?> user<?= $total !== 1 ? 's' : '' ?>
            <?php else: ?>
                Showing <?= number_format($pag['offset'] + 1) ?>–<?= number_format(min($pag['offset'] + $pag['per_page'], $total)) ?>
                of <?= number_format($total) ?> users
            <?php endif; ?>
        </small>
        <?php if ($pag['total_pages'] > 1): ?>
        <?= renderPagination($pag, $paginationPrefix) ?>
        <?php endif; ?>
    </div>
</div>

<?php include BASE_PATH . '/includes/footer.php'; ?>
