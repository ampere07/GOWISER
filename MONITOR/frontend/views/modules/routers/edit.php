<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_SUPERADMIN);

$addrRegions = db()->query("SELECT DISTINCT region FROM address ORDER BY region")->fetchAll(PDO::FETCH_COLUMN);

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM routers WHERE router_id = ?");
$stmt->execute([$id]);
$router = $stmt->fetch();
if (!$router) { flashMessage('danger', 'Router not found.'); redirect(BASE_URL . '/modules/routers/'); }

$errors = [];
$tab    = $_GET['tab'] ?? 'details';

// ── Save router details ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_details'])) {
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
        'brand'        => $_POST['brand']             ?? 'mikrotik',
        'status'       => $_POST['status']            ?? 'online',
        'auth_type'    => in_array($_POST['auth_type'] ?? '', ['local','radius']) ? $_POST['auth_type'] : 'local',
    ];

    if (empty($data['name']))                    $errors[] = 'Name required.';
    if (empty($data['host']))                    $errors[] = 'Host required.';
    elseif (!validateRouterHost($data['host']))  $errors[] = 'Host must be a valid IPv4 address or hostname (no URL schemes).';
    if ($data['port'] < 1 || $data['port'] > 65535) $errors[] = 'Port must be between 1 and 65535.';
    if (empty($data['username']))                $errors[] = 'Username required.';

    $newPassword = trim($_POST['password'] ?? '');
    $encPw = $newPassword !== '' ? encryptData($newPassword) : $router['password'];

    if (empty($errors)) {
        db()->prepare("
            UPDATE routers SET name=?,address=?,region=?,province=?,municipality=?,manager=?,host=?,port=?,api_port=?,username=?,password=?,brand=?,status=?,auth_type=?,updated_at=?
            WHERE router_id=?
        ")->execute([
            $data['name'], $data['address'] ?: null,
            $data['region'] ?: null, $data['province'] ?: null, $data['municipality'] ?: null,
            $data['manager'] ?: null, $data['host'],
            $data['port'], $data['api_port'], $data['username'],
            $encPw, $data['brand'], $data['status'], $data['auth_type'],
            appNow(), $id,
        ]);
        // Build change summary for audit (never log actual password values)
        $changes = [];
        foreach (['name','host','port','api_port','username','brand','status','address','region','province','municipality','manager','auth_type'] as $f) {
            if ((string)($router[$f] ?? '') !== (string)($data[$f] ?? '')) {
                $changes[$f] = ['from' => $router[$f] ?? '', 'to' => $data[$f] ?? ''];
            }
        }
        if ($newPassword !== '') $changes['password'] = ['from' => '***', 'to' => '***changed***'];
        logActivity('routers', 'update', "Updated router: {$data['name']}", null,
            array_combine(array_keys($changes), array_column($changes, 'from')),
            array_combine(array_keys($changes), array_column($changes, 'to'))
        );
        flashMessage('success', 'Router updated.');
        redirect(BASE_URL . '/modules/routers/edit?id=' . $id . '&tab=details');
    }
    $router = array_merge($router, $data);
}

// ── Save router policy ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_policy'])) {
    verifyCsrf();
    $onExpire   = in_array($_POST['on_expire'] ?? '', ['disable','change_profile']) ? $_POST['on_expire'] : 'disable';
    $pppProfile = trim($_POST['expire_ppp_profile'] ?? '');
    $hsProfile  = trim($_POST['expire_hs_profile']  ?? '');

    db()->prepare("
        INSERT INTO router_policies (router_id, on_expire, expire_ppp_profile, expire_hs_profile)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            on_expire = VALUES(on_expire),
            expire_ppp_profile = VALUES(expire_ppp_profile),
            expire_hs_profile  = VALUES(expire_hs_profile),
            updated_at = ?
    ")->execute([$id, $onExpire, $pppProfile ?: null, $hsProfile ?: null, appNow()]);

    logActivity('routers', 'policy', "Updated expiry policy for router: {$router['name']}");
    flashMessage('success', 'Expiry policy saved.');
    redirect(BASE_URL . '/modules/routers/edit?id=' . $id . '&tab=policy');
}

// Load current policy
$policy = db()->prepare("SELECT * FROM router_policies WHERE router_id = ?");
$policy->execute([$id]);
$policy = $policy->fetch() ?: ['on_expire' => 'disable', 'expire_ppp_profile' => '', 'expire_hs_profile' => ''];

$isRadiusRouter = ($router['auth_type'] ?? 'local') === 'radius';
$policyTerm = $isRadiusRouter ? 'group' : 'profile';
$policyTermTitle = ucfirst($policyTerm);
$policyItemsTerm = $isRadiusRouter ? 'groups' : 'profiles';

$isOnline  = $router['status'] === 'online';
$pageTitle   = 'Edit Router';
$breadcrumbs = [['label' => 'Routers', 'url' => BASE_URL . '/modules/routers/'], ['label' => 'Edit Router']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Edit Router — <?= e($router['name']) ?></h4>
        <p class="text-muted small mb-0">
            <span class="badge <?= $isOnline ? 'bg-success' : ($router['status'] === 'offline' ? 'bg-danger' : 'bg-warning text-dark') ?>">
                <?= ucfirst($router['status']) ?>
            </span>
        </p>
    </div>
    <a href="<?= BASE_URL ?>/modules/routers/" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?= inlineToasts($errors) ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'details' ? 'active' : '' ?>"
           href="?tab=details">
            Details
        </a>
    </li>
    <?php if ($isOnline): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'policy' ? 'active' : '' ?>"
           href="?tab=policy">
            Expiry Policy
        </a>
    </li>
    <?php endif; ?>
</ul>

<?php if ($tab === 'details'): ?>
<!-- ── Details Tab ───────────────────────────────────────────── -->
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0">
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <input type="hidden" name="save_details" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterName">Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editRouterName" class="form-control" value="<?= e($router['name']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterBrand">Brand</label>
                            <select name="brand" id="editRouterBrand" class="form-select">
                                <?php foreach (ROUTER_BRANDS as $val => $label): ?>
                                <option value="<?= $val ?>" <?= $router['brand'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="editRouterHost">Host / IP <span class="text-danger">*</span></label>
                            <input type="text" name="host" id="editRouterHost" class="form-control font-monospace"
                                   value="<?= e($router['host']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterPort">Port</label>
                            <input type="number" name="port" id="editRouterPort" class="form-control" value="<?= $router['port'] ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterApiPort">API Port</label>
                            <input type="number" name="api_port" id="editRouterApiPort" class="form-control"
                                   value="<?= $router['api_port'] ?: '' ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterUsername">API Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" id="editRouterUsername" class="form-control"
                                   value="<?= e($router['username']) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterPw">New Password
                                <small class="text-muted fw-normal">(blank = keep)</small>
                            </label>
                            <input type="password" name="password" id="editRouterPw" class="form-control" placeholder="Leave blank to keep current">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="editRouterAddress">Location / Address</label>
                            <input type="text" name="address" id="editRouterAddress" class="form-control" value="<?= e($router['address'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="editRouterRegion">Region</label>
                            <select name="region" id="editRouterRegion" class="form-select">
                                <option value="">— Select Region —</option>
                                <?php foreach ($addrRegions as $r): ?>
                                <option value="<?= e($r) ?>" <?= ($router['region'] ?? '') === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="editRouterProvince">Province</label>
                            <select name="province" id="editRouterProvince" class="form-select" disabled>
                                <option value="">— Select Region first —</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="editRouterMunicipality">Municipality / City</label>
                            <select name="municipality" id="editRouterMunicipality" class="form-select" disabled>
                                <option value="">— Select Province first —</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="editRouterManager">Branch Manager / Authorized Signatory</label>
                            <input type="text" name="manager" id="editRouterManager" class="form-control"
                                   value="<?= e($router['manager'] ?? '') ?>" placeholder="Printed as report signatory">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterStatus">Status</label>
                            <select name="status" id="editRouterStatus" class="form-select">
                                <?php foreach (['online','offline','maintenance'] as $s): ?>
                                <option value="<?= $s ?>" <?= $router['status'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouterAuthType">Authentication Type</label>
                            <select name="auth_type" id="editRouterAuthType" class="form-select">
                                <option value="local"  <?= ($router['auth_type'] ?? 'local') === 'local'  ? 'selected' : '' ?>>Local (PPP / Hotspot)</option>
                                <option value="radius" <?= ($router['auth_type'] ?? '') === 'radius' ? 'selected' : '' ?>>RADIUS (User Manager)</option>
                            </select>
                            <div class="form-text">Local = PPP secrets / Hotspot users. RADIUS = MikroTik User Manager.</div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        <a href="<?= BASE_URL ?>/modules/routers/" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'policy' && $isOnline): ?>
<!-- ── Expiry Policy Tab ──────────────────────────────────────── -->
<div class="row justify-content-center">
    <div class="col-lg-7">

        <?= inlineToasts([['message' => 'This policy runs automatically when a subscriber\'s subscription expires. ' . e($policyTermTitle) . 's are fetched live from ' . e($router['name']) . '.', 'type' => 'info']]) ?>

        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold">On Subscription Expiry</h6>
            </div>
            <div class="card-body">
                <form method="POST" id="policyForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="save_policy" value="1">

                    <!-- Action radio -->
                    <div class="mb-4">
                        <label class="form-label fw-medium" for="actionDisable">Action when subscription expires</label>
                        <div class="d-flex flex-column gap-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="on_expire"
                                       id="actionDisable" value="disable"
                                       <?= ($policy['on_expire'] ?? 'disable') === 'disable' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="actionDisable">
                                    <i class="bi bi-slash-circle text-danger me-1"></i>
                                    <strong>Disable account</strong>
                                    <div class="text-muted small">The <?= $isRadiusRouter ? 'User Manager' : 'PPP/Hotspot' ?> account will be disabled on the router — no internet access.</div>
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="on_expire"
                                       id="actionProfile" value="change_profile"
                                       <?= ($policy['on_expire'] ?? '') === 'change_profile' ? 'checked' : '' ?>>
                                <label class="form-check-label" for="actionProfile">
                                    <i class="bi bi-arrow-left-right text-warning me-1"></i>
                                    <strong>Change to restricted <?= e($policyTerm) ?></strong>
                                    <div class="text-muted small">Switch the account to a limited <?= e($policyTerm) ?> instead of disabling.</div>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Profile selectors (shown only when change_profile is selected) -->
                    <div id="profileSection" style="<?= ($policy['on_expire'] ?? 'disable') === 'change_profile' ? '' : 'display:none;' ?>">
                        <?= inlineToasts([['message' => 'Make sure the chosen ' . e($policyItemsTerm) . ' already exist on the MikroTik router.', 'type' => 'warning']]) ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-medium" for="pppProfileSel">
                                    <?= $isRadiusRouter ? 'Default User Manager Expired Group' : 'PPP Expired Profile' ?>
                                </label>
                                <select name="expire_ppp_profile" id="pppProfileSel" class="form-select">
                                    <option value="">— Loading… —</option>
                                </select>
                                <div class="form-text">
                                    <?= $isRadiusRouter ? 'Used for PPP/PPPoE User Manager subscribers, and also for Hotspot when no Hotspot group is selected.' : 'Applied to PPP/PPPoE subscribers on expiry.' ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-medium" for="hsProfileSel">
                                    <?= $isRadiusRouter ? 'Hotspot User Manager Expired Group (optional)' : 'Hotspot Expired Profile' ?>
                                </label>
                                <select name="expire_hs_profile" id="hsProfileSel" class="form-select">
                                    <option value="">— Loading… —</option>
                                </select>
                                <div class="form-text">
                                    <?= $isRadiusRouter ? 'Optional override for Hotspot User Manager subscribers. Leave blank to use the default group.' : 'Applied to Hotspot subscribers on expiry.' ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Policy
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($policy['on_expire'])): ?>
        <div class="card border-0 mt-3">
            <div class="card-header bg-transparent border-bottom py-3">
                <h6 class="mb-0 fw-semibold text-muted">Current Policy</h6>
            </div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">On Expiry</dt>
                    <dd class="col-7">
                        <?php if ($policy['on_expire'] === 'disable'): ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle">Disable account</span>
                        <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle">Change <?= e($policyTerm) ?></span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($policy['on_expire'] === 'change_profile'): ?>
                    <dt class="col-5 text-muted">PPP <?= e($policyTermTitle) ?></dt>
                    <dd class="col-7 font-monospace"><?= e($policy['expire_ppp_profile'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Hotspot <?= e($policyTermTitle) ?></dt>
                    <dd class="col-7 font-monospace"><?= e($policy['expire_hs_profile'] ?? '—') ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<?php
$routerId         = $id;
$savedPppProfile  = e($policy['expire_ppp_profile'] ?? '');
$savedHsProfile   = e($policy['expire_hs_profile']  ?? '');
$isRadiusRouterJs = $isRadiusRouter ? 'true' : 'false';
$jsInitRegion     = json_encode($router['region']       ?? '');
$jsInitProv       = json_encode($router['province']     ?? '');
$jsInitMuni       = json_encode($router['municipality'] ?? '');

$extraScripts = <<<JS
<script>
const ROUTER_ID      = {$routerId};
const SAVED_PPP      = '{$savedPppProfile}';
const SAVED_HS       = '{$savedHsProfile}';
const IS_RADIUS_ROUTER = {$isRadiusRouterJs};
const POLICY_TERM = IS_RADIUS_ROUTER ? 'group' : 'profile';
const POLICY_TERMS = IS_RADIUS_ROUTER ? 'groups' : 'profiles';

// Toggle profile section on radio change
document.querySelectorAll('[name="on_expire"]').forEach(r => {
    r.addEventListener('change', function () {
        const show = this.value === 'change_profile';
        document.getElementById('profileSection').style.display = show ? '' : 'none';
        if (show && document.getElementById('pppProfileSel')?.options.length <= 1) {
            loadProfiles();
        }
    });
});

function populateSel(sel, profiles, saved) {
    sel.innerHTML = '<option value="">— None —</option>';
    profiles.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p; opt.textContent = p;
        if (p === saved) opt.selected = true;
        sel.appendChild(opt);
    });
}

function loadProfiles() {
    const pppSel = document.getElementById('pppProfileSel');
    const hsSel  = document.getElementById('hsProfileSel');
    if (!pppSel || !hsSel) return;

    pppSel.innerHTML = IS_RADIUS_ROUTER ? '<option>Loading PPP User Manager groups…</option>' : '<option>Loading PPP profiles…</option>';
    hsSel.innerHTML  = IS_RADIUS_ROUTER ? '<option>Loading Hotspot User Manager groups…</option>' : '<option>Loading Hotspot profiles…</option>';

    Promise.all([
        fetch(BASE_URL + '/api/router_profiles.php?router_id=' + ROUTER_ID + '&type=ppp').then(r => r.json()),
        fetch(BASE_URL + '/api/router_profiles.php?router_id=' + ROUTER_ID + '&type=hotspot').then(r => r.json()),
    ]).then(([ppp, hs]) => {
        populateSel(pppSel, ppp.profiles || [], SAVED_PPP);
        populateSel(hsSel,  hs.profiles  || [], SAVED_HS);
    }).catch(() => {
        pppSel.innerHTML = '<option value="">Could not load ' + POLICY_TERMS + '</option>';
        hsSel.innerHTML  = '<option value="">Could not load ' + POLICY_TERMS + '</option>';
    });
}

// Auto-load profiles if change_profile is already selected
if (document.querySelector('[name="on_expire"]:checked')?.value === 'change_profile') {
    loadProfiles();
}

// ── Cascading address selects ─────────────────────────────────
(function () {
    const selRegion = document.getElementById('editRouterRegion');
    const selProv   = document.getElementById('editRouterProvince');
    const selMuni   = document.getElementById('editRouterMunicipality');
    if (!selRegion) return;

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

    const INIT_REGION = {$jsInitRegion};
    const INIT_PROV   = {$jsInitProv};
    const INIT_MUNI   = {$jsInitMuni};
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

include BASE_PATH . '/includes/footer.php';
?>
