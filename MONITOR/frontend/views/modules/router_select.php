<?php
require_once dirname(__DIR__) . '/config/config.php';
requireRole(ROLE_SUPERADMIN);

$isSwitch  = isset($_GET['switch']);
$redirect  = $_GET['redirect'] ?? (BASE_URL . '/modules/dashboard/');

// Sanitise redirect URL — allow only relative paths within the app
if (!str_starts_with($redirect, BASE_URL)) {
    $redirect = BASE_URL . '/modules/dashboard/';
}

// Fetch all routers
$routers = db()->query("SELECT * FROM routers ORDER BY name")->fetchAll();

// 0 routers → skip to dashboard
if (empty($routers)) {
    clearSelectedRouter();
    flashMessage('info', 'No routers configured. You can add routers under Network → Routers.');
    redirect(BASE_URL . '/modules/dashboard/');
}

// POST: select a router
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $routerId = (int)($_POST['router_id'] ?? 0);
    foreach ($routers as $r) {
        if ((int)$r['router_id'] === $routerId) {
            setSelectedRouter($routerId, $r['name']);
            logActivity('auth', 'router_select', "Selected router: {$r['name']}");
            if (!$isSwitch) {
                flashMessage('success', "Connected to router: {$r['name']}");
            } else {
                flashMessage('success', "Switched to router: {$r['name']}");
            }
            redirect($redirect);
        }
    }
    flashMessage('danger', 'Invalid router selection.');
}

// 1 router → auto-select for all roles
if (!$isSwitch && count($routers) === 1) {
    setSelectedRouter((int)$routers[0]['router_id'], $routers[0]['name']);
    logActivity('auth', 'router_select', "Auto-selected router: {$routers[0]['name']}");
    redirect($redirect);
}

$pageTitle   = $isSwitch ? 'Switch Router' : 'Select Router';
$breadcrumbs = [['label' => $pageTitle]];
include BASE_PATH . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <div class="text-center mb-5">
            <div class="mb-3">
                <i class="bi bi-router text-primary" style="font-size:3rem;"></i>
            </div>
            <h3 class="fw-bold mb-1">
                <?= $isSwitch ? 'Switch Router' : 'Select a Router' ?>
            </h3>
            <p class="text-muted">
                <?= $isSwitch
                    ? 'Choose which router you want to work with. Current: <strong>' . e(selectedRouterName() ?: 'None') . '</strong>'
                    : 'Choose the router you will manage for this session.' ?>
            </p>
        </div>

        <form method="POST" id="routerSelectForm">
            <?= csrfField() ?>
            <input type="hidden" name="router_id" id="selectedRouterId" value="">

            <div class="row g-3 mb-4">
                <?php foreach ($routers as $router):
                    $isCurrent = (int)$router['router_id'] === selectedRouterId();
                ?>
                <div class="col-sm-6">
                    <div class="card border-0 shadow-sm router-select-card h-100 <?= $isCurrent ? 'selected' : '' ?>"
                         data-id="<?= $router['router_id'] ?>"
                         data-name="<?= e($router['name']) ?>">
                        <div class="card-body p-4">
                            <div class="d-flex align-items-start justify-content-between mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="kpi-icon bg-primary-subtle text-primary flex-shrink-0">
                                        <i class="bi bi-router-fill"></i>
                                    </div>
                                    <div>
                                        <div class="fw-bold"><?= e($router['name']) ?></div>
                                        <div class="text-muted small"><?= e($router['host']) ?></div>
                                    </div>
                                </div>
                                <?php if ($isCurrent): ?>
                                <span class="badge bg-primary">Current</span>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex gap-3 small text-muted">
                                <span><i class="bi bi-hdd-network me-1"></i>Port <?= e($router['port']) ?></span>
                                <span><i class="bi bi-cpu me-1"></i><?= ucfirst(e($router['brand'])) ?></span>
                            </div>
                            <?php
                            $locParts = array_filter([
                                $router['municipality'] ?? '',
                                $router['province']     ?? '',
                                $router['region']       ?? '',
                            ]);
                            if ($locParts): ?>
                            <div class="text-muted small mt-2 text-truncate">
                                <i class="bi bi-geo-alt me-1"></i><?= e(implode(', ', $locParts)) ?>
                            </div>
                            <?php elseif ($router['address']): ?>
                            <div class="text-muted small mt-2 text-truncate">
                                <i class="bi bi-geo-alt me-1"></i><?= e($router['address']) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-transparent border-top py-2">
                            <button type="button"
                                    class="btn <?= $isCurrent ? 'btn-primary' : 'btn-outline-primary' ?> w-100 btn-sm select-router-btn"
                                    data-id="<?= $router['router_id'] ?>">
                                <i class="bi bi-<?= $isCurrent ? 'check-circle-fill' : 'arrow-right-circle' ?> me-1"></i>
                                <?= $isCurrent ? 'Already Selected' : 'Select this Router' ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </form>

        <?php if ($isSwitch && selectedRouterId()): ?>
        <div class="text-center">
            <a href="<?= e($redirect) ?>" class="text-muted small">
                <i class="bi bi-arrow-left me-1"></i>Keep current router and go back
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
let _routerSubmitting = false;

function submitRouter(id) {
    if (_routerSubmitting) return;
    _routerSubmitting = true;
    document.getElementById('selectedRouterId').value = id;
    document.getElementById('routerSelectForm').submit();
}

document.querySelectorAll('.select-router-btn').forEach(btn => {
    btn.addEventListener('click', function(e) {
        e.stopPropagation(); // prevent bubbling to the card listener
        submitRouter(this.dataset.id);
    });
});

// Highlight card on click (anywhere on card except the button, which stops propagation)
document.querySelectorAll('.router-select-card').forEach(card => {
    card.addEventListener('click', function() {
        document.querySelectorAll('.router-select-card').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        submitRouter(this.dataset.id);
    });
});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
