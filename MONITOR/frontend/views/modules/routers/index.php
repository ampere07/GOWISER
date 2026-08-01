<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_SUPERADMIN);

// Delete router
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_router'])) {
    verifyCsrf();
    $rid = (int)$_POST['router_id'];
    $cnt = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE router_id = ?");
    $cnt->execute([$rid]);
    $r = db()->prepare("SELECT name, status FROM routers WHERE router_id = ?");
    $r->execute([$rid]);
    $routerForDelete = $r->fetch();
    if (!$routerForDelete) {
        flashMessage('danger', 'Router not found.');
    } elseif (($routerForDelete['status'] ?? '') !== ROUTER_ONLINE) {
        flashMessage('danger', 'Cannot delete a disconnected router.');
    } elseif ((int)$cnt->fetchColumn() > 0) {
        flashMessage('danger', 'Cannot delete router with assigned subscribers.');
    } else {
        $name = $routerForDelete['name'];
        db()->prepare("DELETE FROM routers WHERE router_id = ?")->execute([$rid]);
        logActivity('routers', 'delete', "Deleted router: {$name}");
        flashMessage('success', 'Router deleted.');
    }
    redirect(BASE_URL . '/modules/routers/');
}

$routers = db()->query("
    SELECT r.*, COUNT(s.subscriber_id) AS sub_count
    FROM routers r
    LEFT JOIN subscribers s ON s.router_id = r.router_id
    GROUP BY r.router_id
    ORDER BY r.name
")->fetchAll();

$totalRouters = count($routers);
$pageTitle    = 'Routers';
$breadcrumbs  = [['label' => 'Routers']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Routers</h4>
        <p class="text-muted small mb-0"><?= $totalRouters ?> / <?= MAX_ROUTERS ?> configured</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= BASE_URL ?>/modules/radius_groups/" class="btn btn-outline-secondary">
            <i class="bi bi-shield-lock me-1"></i>RADIUS Groups
        </a>
        <?php if ($totalRouters < MAX_ROUTERS): ?>
        <a href="add" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i>Add Router
        </a>
        <?php else: ?>
        <button class="btn btn-secondary" disabled title="Maximum <?= MAX_ROUTERS ?> routers allowed">
            <i class="bi bi-plus-circle me-1"></i>Add Router (Max reached)
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($totalRouters >= MAX_ROUTERS): ?>
<?= inlineToasts([['message' => 'Maximum of ' . MAX_ROUTERS . ' routers reached. Remove an existing router to add a new one.', 'type' => 'warning']]) ?>
<?php endif; ?>

<div class="row g-3" id="routerList">
    <?php if (empty($routers)): ?>
    <?= inlineToasts([['message' => 'No routers configured. Go to Routers → Add to set up your first router.', 'type' => 'info']]) ?>
    <?php else: ?>
    <?php foreach ($routers as $router): ?>
    <div class="col-md-6 col-xl-4">
        <div class="card border-0 h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="kpi-icon bg-primary-subtle text-primary">
                            <i class="bi bi-router-fill"></i>
                        </div>
                        <div>
                            <div class="fw-bold"><?= e($router['name']) ?></div>
                            <div class="text-muted small font-monospace"><?= e($router['host']) ?>:<?= $router['api_port'] ?: $router['port'] ?></div>
                        </div>
                    </div>
                    <span class="badge <?= $router['status'] === 'online' ? 'bg-success' : ($router['status'] === 'offline' ? 'bg-danger' : 'bg-warning text-dark') ?>">
                        <?= ucfirst($router['status']) ?>
                    </span>
                </div>

                <div class="small text-muted mb-2">
                    Portal:
                    <?php if (!empty($router['portal_enabled']) && $router['status'] === ROUTER_ONLINE): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span><br>
                    <?php else: ?>
                    <span class="badge bg-secondary-subtle text-secondary border">Inactive</span><br>
                    <?php endif; ?>
                    <?php if ($router['brand'] !== 'mikrotik'): ?>
                    Brand: <?= ucfirst($router['brand']) ?><br>
                    <?php endif; ?>
                    <?php if ($router['last_sync']): ?>
                    Last sync: <?= timeDiffHuman($router['last_sync']) ?><br>
                    <?php endif; ?>
                    <?php
                    $locParts = array_filter([
                        $router['municipality'] ?? '',
                        $router['province']     ?? '',
                        $router['region']       ?? '',
                    ]);
                    if ($locParts): ?>
                    <span><i class="bi bi-geo-alt me-1"></i><?= e(implode(', ', $locParts)) ?></span>
                    <?php elseif (!empty($router['address'])): ?>
                    <span><i class="bi bi-geo-alt me-1"></i><?= e($router['address']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top d-flex gap-2">
                <?php if ($router['status'] === 'online'): ?>
                <button class="btn btn-sm btn-success flex-grow-1 btn-test-conn"
                        data-id="<?= $router['router_id'] ?>">
                    <i class="bi bi-wifi"></i> Already Connected
                </button>
                <?php else: ?>
                <button class="btn btn-sm btn-outline-info flex-grow-1 btn-test-conn"
                        data-id="<?= $router['router_id'] ?>">
                    <i class="bi bi-wifi"></i> Test Connection
                </button>
                <?php endif; ?>
                <a href="edit/<?= $router['router_id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-pencil"></i>
                </a>
                <?php if ($router['sub_count'] == 0 && $router['status'] === ROUTER_ONLINE): ?>
                <form method="POST" class="d-inline form-delete-router">
                    <?= csrfField() ?>
                    <input type="hidden" name="router_id" value="<?= $router['router_id'] ?>">
                    <button type="button" name="delete_router"
                            class="btn btn-sm btn-outline-danger btn-delete-router"
                            data-name="<?= e($router['name']) ?>">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
                <?php else: ?>
                <button type="button"
                        class="btn btn-sm btn-outline-danger btn-delete-blocked"
                        data-name="<?= e($router['name']) ?>"
                        data-count="<?= $router['sub_count'] ?>"
                        data-status="<?= e($router['status']) ?>">
                    <i class="bi bi-trash"></i>
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
$extraScripts = <<<'JS'
<script>
// Show connection-failure modal with troubleshooting tips + Retry
function showConnectionErrorModal(btn, routerName, routerHost, errMsg) {
    Swal.fire({
        title: 'Connection Failed',
        html: `<div class="text-start">
            <div class="mb-3 p-2 bg-light rounded">
                <div class="fw-semibold">${routerName}</div>
                <div class="text-muted small font-monospace">${routerHost}</div>
            </div>
            <div class="alert alert-danger py-2 px-3 mb-3 small">
                <strong>Error:</strong> ${errMsg}
            </div>
            <div class="small text-muted">
                <p class="fw-semibold mb-1 text-dark">Troubleshooting tips:</p>
                <ul class="mb-0 ps-3">
                    <li>Verify the API port (default: <code>8728</code>) is correct</li>
                    <li>Enable API in RouterOS → IP → Services</li>
                    <li>Check username and password credentials</li>
                    <li>Ensure no firewall blocks port <code>8728</code></li>
                    <li>Confirm the router IP/hostname is reachable from this server</li>
                </ul>
            </div>
        </div>`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonText: '<i class="bi bi-arrow-clockwise me-1"></i> Retry',
        cancelButtonText: 'Close',
        confirmButtonColor: '#0d6efd',
        reverseButtons: true,
        customClass: { htmlContainer: 'text-start' },
    }).then(result => {
        if (result.isConfirmed) {
            btn.className = btn.className.replace('btn-danger', 'btn-outline-info');
            btn.innerHTML = '<i class="bi bi-wifi"></i> Test Connection';
            btn.disabled  = false;
            btn.click();
        }
    });
}

// Blocked delete — router has subscribers
document.querySelectorAll('.btn-delete-blocked').forEach(btn => {
    btn.addEventListener('click', function() {
        const name  = this.dataset.name;
        const count = this.dataset.count;
        const status = this.dataset.status;
        const reason = status !== 'online'
            ? 'This router is not connected. Router delete is disabled until it is online.'
            : `This router has <strong>${count} subscriber${count > 1 ? 's' : ''}</strong> assigned to it.
                    Reassign or remove all subscribers before deleting this router.`;
        Swal.fire({
            title: 'Cannot Delete Router',
            html: `<div class="text-start">
                <div class="mb-3 p-2 bg-light rounded fw-semibold">${name}</div>
                <div class="alert alert-warning py-2 px-3 mb-0 small">
                    ${reason}
                </div>
            </div>`,
            icon: 'error',
            confirmButtonText: 'OK',
            confirmButtonColor: '#6c757d',
        });
    });
});

// Delete router confirmation
document.querySelectorAll('.btn-delete-router').forEach(btn => {
    btn.addEventListener('click', function() {
        const name = this.dataset.name;
        const form = this.closest('form');
        Swal.fire({
            title: 'Delete router ' + name + '?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-trash me-1"></i> Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(result => {
            if (result.isConfirmed) {
                const hidden = document.createElement('input');
                hidden.type  = 'hidden';
                hidden.name  = 'delete_router';
                hidden.value = '1';
                form.appendChild(hidden);
                form.submit();
            }
        });
    });
});

// Test connection
document.querySelectorAll('.btn-test-conn').forEach(btn => {
    btn.addEventListener('click', function() {
        const id      = this.dataset.id;
        const btn     = this;
        const origHtml = btn.innerHTML;
        btn.disabled  = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing…';

        fetch(BASE_URL + '/api/router_status.php?id=' + id + '&live=1')
            .then(r => r.json())
            .then(d => {
                btn.disabled = false;
                if (d.online) {
                    btn.innerHTML = '<i class="bi bi-wifi"></i> Online';
                    btn.className = btn.className.replace('btn-outline-info', 'btn-success');
                    Swal.fire({
                        title: 'Connected!',
                        html: `<div class="text-start small">
                            <div class="mb-1 fw-semibold">${d.identity || d.name}</div>
                            ${d.cpu_load !== null ? `<div class="mb-1 text-muted">CPU Load: <strong>${d.cpu_load}%</strong></div>` : ''}
                            ${d.uptime ? `<div class="text-muted">Uptime: <strong>${d.uptime}</strong></div>` : ''}
                        </div>`,
                        icon: 'success',
                        timer: 3000,
                        timerProgressBar: true,
                        showConfirmButton: false,
                    });
                } else {
                    btn.innerHTML = '<i class="bi bi-wifi-off"></i> Offline';
                    btn.className = btn.className.replace('btn-outline-info', 'btn-danger');
                    showConnectionErrorModal(btn, d.name || 'Router', d.host || '', d.error || 'Router is unreachable.');
                }
            })
            .catch(() => {
                btn.disabled  = false;
                btn.innerHTML = origHtml;
                showConnectionErrorModal(btn, 'Router', '', 'Network error — could not reach the status API.');
            });
    });
});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
