<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_SUPERADMIN);

$addrRegions = db()->query("SELECT DISTINCT region FROM address ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

// Check max routers
if (getRouterCount() >= MAX_ROUTERS) {
    flashMessage('danger', 'Maximum of ' . MAX_ROUTERS . ' routers reached.');
    redirect(BASE_URL . '/modules/routers/');
}

require_once BASE_PATH . '/lib/RouterosAPI.php';
$errors   = [];
$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'name'         => trim($_POST['name']         ?? ''),
        'address'      => trim($_POST['address']      ?? ''),
        'region'       => trim($_POST['region']       ?? ''),
        'province'     => trim($_POST['province']     ?? ''),
        'municipality' => trim($_POST['municipality'] ?? ''),
        'manager'      => trim($_POST['manager']      ?? ''),
        'host'         => trim($_POST['host']         ?? ''),
        'port'         => (int)($_POST['port']        ?? 8728),
        'api_port'     => (int)($_POST['api_port']    ?? 0) ?: null,
        'username'     => trim($_POST['username']     ?? ''),
        'password'     => trim($_POST['password']     ?? ''),
        'brand'        => $_POST['brand']             ?? 'mikrotik',
        'status'       => $_POST['status']            ?? 'online',
        'auth_type'    => in_array($_POST['auth_type'] ?? '', ['local','radius']) ? $_POST['auth_type'] : 'local',
    ];

    if (empty($data['name']))                    $errors[] = 'Router name is required.';
    if (empty($data['host']))                    $errors[] = 'Host (IP/hostname) is required.';
    elseif (!validateRouterHost($data['host']))  $errors[] = 'Host must be a valid IPv4 address or hostname (no URL schemes).';
    if ($data['port'] < 1 || $data['port'] > 65535) $errors[] = 'Port must be between 1 and 65535.';
    if (empty($data['username']))                $errors[] = 'API username is required.';
    if (empty($data['password']))                $errors[] = 'API password is required.';

    // Test connection on save
    if (empty($errors) && !isset($_POST['skip_test'])) {
        $apiPort = $data['api_port'] ?: $data['port'];
        $test = RouterosAPI::testConnection($data['host'], $apiPort, $data['username'], $data['password'], 5);
        if (!$test['success']) {
            if (!isset($_POST['force_save'])) {
                $errors[] = 'Connection test failed: ' . $test['error'];
                $errors[] = 'Check the credentials and try again, or check "Save anyway" to force-save.';
                $testResult = $test;
            }
        }
    }

    if (empty($errors)) {
        $encPw = encryptData($data['password']);
        db()->prepare("
            INSERT INTO routers (name, address, region, province, municipality, manager, host, port, api_port, username, password, brand, status, auth_type)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $data['name'], $data['address'] ?: null,
            $data['region'] ?: null, $data['province'] ?: null, $data['municipality'] ?: null,
            $data['manager'] ?: null, $data['host'],
            $data['port'], $data['api_port'], $data['username'],
            $encPw, $data['brand'], $data['status'], $data['auth_type'],
        ]);
        $newId = (int)db()->lastInsertId();
        logActivity('routers', 'create', "Added router: {$data['name']} ({$data['host']})");
        flashMessage('success', "Router '{$data['name']}' added successfully.");
        redirect(BASE_URL . '/modules/routers/');
    }
}

$pageTitle   = 'Add Router';
$breadcrumbs = [['label' => 'Routers', 'url' => BASE_URL . '/modules/routers/'], ['label' => 'Add Router']];
include BASE_PATH . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Add Router</h4>
    <a href="./" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?= inlineToasts([['message' => 'MikroTik API: Enable API access via IP → Services → API (port 8728) or API-SSL (port 8729). Create a dedicated API user with full or custom policy. Remaining slots: ' . (MAX_ROUTERS - getRouterCount()) . ' of ' . MAX_ROUTERS . '.', 'type' => 'info']]) ?>

<?= inlineToasts($errors) ?>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0">
            <div class="card-body">
                <form method="POST" novalidate id="routerForm">
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerName">Router Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="routerName" class="form-control" required
                                   value="<?= e($_POST['name'] ?? '') ?>" placeholder="e.g. Main Router">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerBrand">Brand</label>
                            <select name="brand" id="routerBrand" class="form-select">
                                <?php foreach (ROUTER_BRANDS as $val => $label): ?>
                                <option value="<?= $val ?>" <?= ($_POST['brand'] ?? 'mikrotik') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="routerHost">Host / IP Address <span class="text-danger">*</span></label>
                            <input type="text" name="host" id="routerHost" class="form-control font-monospace" required
                                   value="<?= e($_POST['host'] ?? '') ?>" placeholder="192.168.1.1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerPort">Winbox/Config Port</label>
                            <input type="number" name="port" id="routerPort" class="form-control" min="1" max="65535"
                                   value="<?= e($_POST['port'] ?? '8728') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerApiPort">API Port
                                <small class="text-muted fw-normal">(8728=API, 8729=API-SSL)</small>
                            </label>
                            <input type="number" name="api_port" id="routerApiPort" class="form-control" min="1" max="65535"
                                   value="<?= e($_POST['api_port'] ?? '8728') ?>" placeholder="8728">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerUsername">API Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-person"></i></span>
                                <input type="text" name="username" id="routerUsername" class="form-control" required
                                       value="<?= e($_POST['username'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerPw">API Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-key"></i></span>
                                <input type="password" name="password" class="form-control" id="routerPw" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="
                                    const i=document.getElementById('routerPw');
                                    i.type=i.type==='password'?'text':'password'">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="routerAddress">Location / Address</label>
                            <input type="text" name="address" id="routerAddress" class="form-control"
                                   value="<?= e($_POST['address'] ?? '') ?>" placeholder="e.g. Main Office, Server Room">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="routerRegion">Region</label>
                            <select name="region" id="routerRegion" class="form-select">
                                <option value="">— Select Region —</option>
                                <?php foreach ($addrRegions as $r): ?>
                                <option value="<?= e($r) ?>" <?= ($_POST['region'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="routerProvince">Province</label>
                            <select name="province" id="routerProvince" class="form-select" disabled>
                                <option value="">— Select Region first —</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="routerMunicipality">Municipality / City</label>
                            <select name="municipality" id="routerMunicipality" class="form-select" disabled>
                                <option value="">— Select Province first —</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="routerManager">Branch Manager / Authorized Signatory</label>
                            <input type="text" name="manager" id="routerManager" class="form-control"
                                   value="<?= e($_POST['manager'] ?? '') ?>" placeholder="Printed as report signatory">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerStatus">Initial Status</label>
                            <select name="status" id="routerStatus" class="form-select">
                                <option value="online"      <?= ($_POST['status'] ?? 'online') === 'online'      ? 'selected' : '' ?>>Online</option>
                                <option value="maintenance" <?= ($_POST['status'] ?? '') === 'maintenance' ? 'selected' : '' ?>>Maintenance</option>
                                <option value="offline"     <?= ($_POST['status'] ?? '') === 'offline'     ? 'selected' : '' ?>>Offline</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerAuthType">Authentication Type</label>
                            <select name="auth_type" id="routerAuthType" class="form-select">
                                <option value="local"  <?= ($_POST['auth_type'] ?? 'local') === 'local'  ? 'selected' : '' ?>>Local (PPP / Hotspot)</option>
                                <option value="radius" <?= ($_POST['auth_type'] ?? '') === 'radius' ? 'selected' : '' ?>>RADIUS (User Manager)</option>
                            </select>
                            <div class="form-text">Local = PPP secrets / Hotspot users. RADIUS = MikroTik User Manager.</div>
                        </div>
                    </div>

                    <?php if (!empty($testResult)): ?>
                    <div class="mt-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="force_save" id="forceSave">
                            <label class="form-check-label text-warning" for="forceSave">
                                Save anyway (connection test failed)
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Add Router
                        </button>
                        <button type="submit" name="skip_test" value="1" class="btn btn-outline-secondary">
                            <i class="bi bi-plus me-1"></i>Save Without Testing
                        </button>
                        <a href="./" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$initRegion = e($_POST['region']       ?? '');
$initProv   = e($_POST['province']     ?? '');
$initMuni   = e($_POST['municipality'] ?? '');

$extraScripts = <<<JS
<script>
(function () {
    const selRegion = document.getElementById('routerRegion');
    const selProv   = document.getElementById('routerProvince');
    const selMuni   = document.getElementById('routerMunicipality');

    function resetSelect(sel, placeholder, disable) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        sel.disabled = disable;
    }

    function loadOptions(sel, url, placeholder, selected, onDone) {
        sel.innerHTML = '<option value="">Loading…</option>';
        sel.disabled = true;
        fetch(url)
            .then(r => r.json())
            .then(d => {
                resetSelect(sel, placeholder, false);
                (d.data || []).forEach(v => {
                    const opt = document.createElement('option');
                    opt.value = v; opt.textContent = v;
                    if (v === selected) opt.selected = true;
                    sel.appendChild(opt);
                });
                if (onDone) onDone();
            })
            .catch(() => resetSelect(sel, placeholder, false));
    }

    selRegion.addEventListener('change', function () {
        resetSelect(selProv, '— Select Region first —', true);
        resetSelect(selMuni, '— Select Province first —', true);
        if (!this.value) return;
        loadOptions(selProv, BASE_URL + '/api/address.php?level=provinces&p=' + encodeURIComponent(this.value), '— Select Province —', '');
    });

    selProv.addEventListener('change', function () {
        resetSelect(selMuni, '— Select Province first —', true);
        if (!this.value) return;
        loadOptions(selMuni, BASE_URL + '/api/address.php?level=municipalities&p=' + encodeURIComponent(this.value), '— Select Municipality —', '');
    });

    // Restore saved values on validation error re-display
    const INIT_REGION = '{$initRegion}';
    const INIT_PROV   = '{$initProv}';
    const INIT_MUNI   = '{$initMuni}';
    if (INIT_REGION) {
        loadOptions(selProv, BASE_URL + '/api/address.php?level=provinces&p=' + encodeURIComponent(INIT_REGION), '— Select Province —', INIT_PROV, function () {
            if (INIT_PROV) {
                loadOptions(selMuni, BASE_URL + '/api/address.php?level=municipalities&p=' + encodeURIComponent(INIT_PROV), '— Select Municipality —', INIT_MUNI);
            }
        });
    }
})();
</script>
JS;

include BASE_PATH . '/includes/footer.php'; ?>
