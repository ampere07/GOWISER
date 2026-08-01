<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/lib/RouterosAPI.php';
requireMinRole(ROLE_ADMIN);

$errors      = [];
$syncResults = [];
$selectedRouterId = selectedRouterId();
$canAssignAllRouters = currentRole() === ROLE_SUPERADMIN;
if (!$canAssignAllRouters && !$selectedRouterId) {
    flashMessage('danger', 'Router not selected.');
    redirect(BASE_URL . '/modules/plans/');
}

$routerSql = "SELECT router_id, name, host, port, api_port, username, password, COALESCE(auth_type,'local') AS auth_type FROM routers";
if ($canAssignAllRouters) {
    $routers = db()->query($routerSql . " ORDER BY name")->fetchAll();
} else {
    $routerStmt = db()->prepare($routerSql . " WHERE router_id = ? ORDER BY name");
    $routerStmt->execute([$selectedRouterId]);
    $routers = $routerStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'title'         => trim($_POST['title']         ?? ''),
        'description'   => trim($_POST['description']   ?? ''),
        'amount'        => (float)($_POST['amount']     ?? 0),
        'speed_mbps'    => (int)($_POST['speed_mbps']   ?? 0),
        'burst_mbps'    => (int)($_POST['burst_mbps']   ?? 0) ?: null,
        'billing_cycle' => $_POST['billing_cycle']      ?? 'monthly',
        'plan_type'     => $_POST['plan_type']          ?? 'ppp',
        'ppp_profile'   => trim($_POST['ppp_profile']   ?? ''),
        'router_id'     => $canAssignAllRouters ? ((int)($_POST['router_id'] ?? 0) ?: null) : $selectedRouterId,
        'portal_enabled'=> isset($_POST['portal_enabled']) ? 1 : 0,
        'is_active'     => isset($_POST['is_active']) ? 1 : 0,
    ];
    $syncToRouters = array_map('intval', (array)($_POST['sync_routers'] ?? []));
    if (!$canAssignAllRouters) {
        $syncToRouters = array_values(array_intersect($syncToRouters, [$selectedRouterId]));
    }

    if (empty($data['title']))      $errors[] = 'Plan title is required.';
    if ($data['amount'] < 0)        $errors[] = 'Amount cannot be negative.';
    if ($data['speed_mbps'] <= 0)   $errors[] = 'Speed must be greater than 0.';

    if (empty($errors)) {
        db()->prepare("
            INSERT INTO plans (title, description, amount, speed_mbps, burst_mbps, billing_cycle, plan_type, ppp_profile, router_id, portal_enabled, is_active)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $data['title'], $data['description'] ?: null,
            $data['amount'], $data['speed_mbps'], $data['burst_mbps'],
            $data['billing_cycle'], $data['plan_type'], $data['ppp_profile'] ?: null,
            $data['router_id'], $data['portal_enabled'], $data['is_active'],
        ]);
        $newId = (int)db()->lastInsertId();
        logActivity('plans', 'create', "Created plan: {$data['title']}");

        // Sync to selected routers as PPP/Hotspot profiles
        if (!empty($syncToRouters)) {
            foreach ($routers as $router) {
                if (!in_array((int)$router['router_id'], $syncToRouters, true)) continue;
                $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 5);
                if ($api->connect($router['username'], decryptData($router['password']))) {
                    $result = $api->syncPlanProfile(
                        $data['title'],
                        $data['speed_mbps'],
                        $data['burst_mbps'],
                        $data['plan_type']
                    );
                    $api->disconnect();
                    $ok = array_filter($result);
                    $syncResults[] = [
                        'router' => $router['name'],
                        'status' => !empty($ok) ? 'synced' : 'failed',
                        'detail' => implode(', ', array_keys($ok)) ?: 'nothing synced',
                    ];
                } else {
                    $syncResults[] = ['router' => $router['name'], 'status' => 'offline', 'detail' => $api->error];
                }
            }
        }

        $syncMsg = '';
        if (!empty($syncResults)) {
            $ok  = count(array_filter($syncResults, fn($r) => $r['status'] === 'synced'));
            $tot = count($syncResults);
            $syncMsg = " Synced to {$ok}/{$tot} router(s).";
        }

        flashMessage('success', "Plan '{$data['title']}' created.{$syncMsg}");
        redirect(BASE_URL . '/modules/plans/');
    }
}

$currency    = getSetting('currency_symbol', '₱');
$pageTitle   = 'Add Plan';
$breadcrumbs = [['label' => 'Plans', 'url' => BASE_URL . '/modules/plans/'], ['label' => 'Add Plan']];
include BASE_PATH . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Add Plan</h4>
    <a href="./" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<?= inlineToasts($errors) ?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0">
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-medium" for="planTitle">Plan Title <span class="text-danger">*</span></label>
                            <input type="text" name="title" id="planTitle" class="form-control" required
                                   value="<?= e($_POST['title'] ?? '') ?>" placeholder="e.g. Basic 10Mbps">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="planDesc">Description</label>
                            <textarea name="description" id="planDesc" class="form-control" rows="2"><?= e($_POST['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="planAmount">Monthly Amount (<?= e($currency) ?>) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><?= e($currency) ?></span>
                                <input type="number" name="amount" id="planAmount" class="form-control" step="0.01" min="0"
                                       value="<?= e($_POST['amount'] ?? '') ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="planBilling">Billing Cycle</label>
                            <select name="billing_cycle" id="planBilling" class="form-select">
                                <option value="monthly"   <?= ($_POST['billing_cycle'] ?? 'monthly') === 'monthly'   ? 'selected' : '' ?>>Monthly</option>
                                <option value="quarterly" <?= ($_POST['billing_cycle'] ?? '') === 'quarterly' ? 'selected' : '' ?>>Quarterly</option>
                                <option value="annual"    <?= ($_POST['billing_cycle'] ?? '') === 'annual'    ? 'selected' : '' ?>>Annual</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="speedInput">Download Speed (Mbps) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="speed_mbps" class="form-control" min="1"
                                       value="<?= e($_POST['speed_mbps'] ?? '') ?>" required id="speedInput">
                                <span class="input-group-text">Mbps</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="burstInput">Burst Speed (Mbps)</label>
                            <div class="input-group">
                                <input type="number" name="burst_mbps" class="form-control" min="0"
                                       value="<?= e($_POST['burst_mbps'] ?? '') ?>" placeholder="Optional" id="burstInput">
                                <span class="input-group-text">Mbps</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="planTypeInput">Plan Type</label>
                            <select name="plan_type" class="form-select" id="planTypeInput">
                                <option value="ppp"     <?= ($_POST['plan_type'] ?? 'ppp') === 'ppp'     ? 'selected' : '' ?>>PPP / PPPoE</option>
                                <option value="hotspot" <?= ($_POST['plan_type'] ?? '') === 'hotspot' ? 'selected' : '' ?>>Hotspot</option>
                                <option value="both"    <?= ($_POST['plan_type'] ?? '') === 'both'    ? 'selected' : '' ?>>Both</option>
                            </select>
                        </div>

                        <!-- Rate limit preview -->
                        <div class="col-12">
                            <div class="text-muted small py-1" id="rateLimitPreview">
                                MikroTik rate-limit: <code id="rateLimitVal">—</code>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="routerSelect">Assign to Router</label>
                            <select name="router_id" class="form-select" id="routerSelect">
                                <?php if ($canAssignAllRouters): ?>
                                <option value="">— All Routers —</option>
                                <?php endif; ?>
                                <?php foreach ($routers as $r): ?>
                                <option value="<?= $r['router_id'] ?>"
                                        data-auth-type="<?= e($r['auth_type'] ?? 'local') ?>"
                                        <?= ($canAssignAllRouters ? (int)($_POST['router_id'] ?? 0) : (int)$selectedRouterId) === (int)$r['router_id'] ? 'selected' : '' ?>>
                                    <?= e($r['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium" id="profileFieldLabel" for="profileSelect">MikroTik Profile Name</label>
                            <div id="profileSelectWrap">
                                <select name="ppp_profile" class="form-select font-monospace" id="profileSelect">
                                    <option value="">— auto-uses plan title if blank —</option>
                                    <?php if (!empty($_POST['ppp_profile'])): ?>
                                    <option value="<?= e($_POST['ppp_profile']) ?>" selected><?= e($_POST['ppp_profile']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text" id="profileHelp">
                                    Select a router above to load its existing profiles, or leave blank to auto-use the plan title.
                                </div>
                                <div id="profileCustomWrap" class="mt-2 d-none">
                                    <input type="text" id="profileCustomInput" class="form-control font-monospace form-control-sm"
                                           placeholder="Type profile name...">
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check me-4">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                       <?= !isset($_POST['is_active']) || $_POST['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium" for="isActive">Active</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="portal_enabled" id="portalEnabled"
                                       <?= !empty($_POST['portal_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium" for="portalEnabled">Show in Payment Portal</label>
                            </div>
                        </div>
                    </div>

                    <!-- MikroTik Sync -->
                    <?php if (!empty($routers)): ?>
                    <div class="card border border-primary-subtle mb-3">
                        <div class="card-header bg-primary-subtle py-2">
                            <h6 class="mb-0 fw-semibold text-primary">
                                <i class="bi bi-router me-2"></i>Sync to MikroTik as Profile
                            </h6>
                        </div>
                        <div class="card-body py-3">
                            <p class="text-muted small mb-2">
                                Select routers to automatically create this plan as a PPP/Hotspot profile.
                            </p>
                            <div class="row g-2">
                                <?php foreach ($routers as $r):
                                    $checked = in_array((int)$r['router_id'], (array)($_POST['sync_routers'] ?? []));
                                ?>
                                <div class="col-sm-6 col-lg-4">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox"
                                               name="sync_routers[]"
                                               value="<?= $r['router_id'] ?>"
                                               id="sync_<?= $r['router_id'] ?>"
                                               <?= $checked ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="sync_<?= $r['router_id'] ?>">
                                            <i class="bi bi-router me-1 text-muted"></i><?= e($r['name']) ?>
                                            <span class="text-muted">(<?= e($r['host']) ?>)</span>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle me-1"></i>Create Plan
                        </button>
                        <a href="./" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$extraScripts = <<<'JS'
<script>
function updateRateLimitPreview() {
    const speed = parseInt(document.getElementById('speedInput').value) || 0;
    const burst = parseInt(document.getElementById('burstInput').value) || 0;
    let rl = speed ? `${speed}M/${speed}M` : '—';
    if (burst && burst > speed) rl += ` ${burst}M/${burst}M ${speed}M/${speed}M`;
    document.getElementById('rateLimitVal').textContent = rl;
}
document.getElementById('speedInput')?.addEventListener('input', updateRateLimitPreview);
document.getElementById('burstInput')?.addEventListener('input', updateRateLimitPreview);
updateRateLimitPreview();

// ── Profile select loader ─────────────────────────────────────
function getRouterAuthType() {
    const sel = document.getElementById('routerSelect');
    if (!sel || !sel.value) return 'local';
    const opt = sel.options[sel.selectedIndex];
    return opt ? (opt.dataset.authType || 'local') : 'local';
}

function updateProfileLabel(isRadius) {
    const label = document.getElementById('profileFieldLabel');
    const customInput = document.getElementById('profileCustomInput');
    if (label) label.textContent = isRadius ? 'RADIUS Group Name' : 'MikroTik Profile Name';
    if (customInput) customInput.placeholder = isRadius ? 'Type group name…' : 'Type profile name…';
}

function loadProfiles(routerId, planType, currentVal) {
    const isRadius = getRouterAuthType() === 'radius';
    const fetchType = isRadius ? 'radius' : planType;
    const sel  = document.getElementById('profileSelect');
    const help = document.getElementById('profileHelp');
    updateProfileLabel(isRadius);
    if (!routerId) {
        sel.innerHTML = '<option value="">— auto-uses plan title if blank —</option>';
        if (currentVal) sel.innerHTML += `<option value="${currentVal}" selected>${currentVal}</option>`;
        help.textContent = isRadius
            ? 'Select a router above to load its RADIUS groups, or leave blank to auto-use the plan title.'
            : 'Select a router above to load its existing profiles, or leave blank to auto-use the plan title.';
        return;
    }
    sel.innerHTML = '<option value="">⏳ Loading…</option>';
    sel.disabled  = true;
    help.textContent = '';
    fetch(`${BASE_URL}/api/router_profiles.php?router_id=${routerId}&type=${fetchType}`)
        .then(r => r.json())
        .then(d => {
            const serverIsRadius = (d.auth_type === 'radius');
            updateProfileLabel(serverIsRadius);
            sel.disabled = false;
            sel.innerHTML = '<option value="">— auto-uses plan title if blank —</option>';
            if (d.profiles && d.profiles.length) {
                d.profiles.forEach(p => {
                    sel.appendChild(new Option(p, p));
                });
                sel.appendChild(new Option('✏️ Type custom name…', '__custom__'));
                help.textContent = serverIsRadius
                    ? `${d.profiles.length} group(s) loaded from router.`
                    : `${d.profiles.length} profile(s) loaded from router.`;
                if (currentVal && d.profiles.includes(currentVal)) {
                    sel.value = currentVal;
                } else if (currentVal) {
                    sel.value = '__custom__';
                    showCustomInput(currentVal);
                }
            } else {
                sel.appendChild(new Option('✏️ Type custom name…', '__custom__'));
                help.textContent = d.error || (serverIsRadius
                    ? 'No groups found on this router. Type a custom group name.'
                    : 'No profiles found on this router. Type a custom name.');
                if (currentVal) { sel.value = '__custom__'; showCustomInput(currentVal); }
            }
        })
        .catch(() => {
            sel.disabled = false;
            sel.innerHTML = '<option value="">— auto-uses plan title if blank —</option>';
            sel.appendChild(new Option('✏️ Type custom name…', '__custom__'));
            help.textContent = isRadius ? 'Could not load groups. Type a custom name.' : 'Could not load profiles. Type a custom name.';
            if (currentVal) { sel.value = '__custom__'; showCustomInput(currentVal); }
        });
}

function showCustomInput(val) {
    const wrap  = document.getElementById('profileCustomWrap');
    const input = document.getElementById('profileCustomInput');
    const sel   = document.getElementById('profileSelect');
    wrap.classList.remove('d-none');
    if (val) input.value = val;
    // Sync custom input back to hidden select value so form submits correctly
    input.addEventListener('input', function () {
        // We'll transfer value on form submit
    });
}

function hideCustomInput() {
    document.getElementById('profileCustomWrap').classList.add('d-none');
    document.getElementById('profileCustomInput').value = '';
}

document.getElementById('profileSelect')?.addEventListener('change', function () {
    if (this.value === '__custom__') {
        showCustomInput('');
    } else {
        hideCustomInput();
    }
});

// Before submit: if custom, copy input value into select
document.querySelector('form')?.addEventListener('submit', function () {
    const sel   = document.getElementById('profileSelect');
    const input = document.getElementById('profileCustomInput');
    if (sel.value === '__custom__') {
        const opt = new Option(input.value, input.value, true, true);
        sel.add(opt);
        sel.value = input.value;
    }
});

// Reload profiles when router or plan type changes
document.getElementById('routerSelect')?.addEventListener('change', function () {
    const planType = document.getElementById('planTypeInput').value;
    const cur      = document.getElementById('profileSelect').value;
    loadProfiles(this.value, planType, cur === '__custom__' ? document.getElementById('profileCustomInput').value : cur);
});
document.getElementById('planTypeInput')?.addEventListener('change', function () {
    const routerId = document.getElementById('routerSelect').value;
    loadProfiles(routerId, this.value, '');
});

// Set correct label on page load (on validation error repopulation, router may be pre-selected)
updateProfileLabel(getRouterAuthType() === 'radius');

// Initial load if router already selected (on validation error repopulation)
(function () {
    const routerId = document.getElementById('routerSelect').value;
    const planType = document.getElementById('planTypeInput').value;
    if (routerId) loadProfiles(routerId, planType, document.getElementById('profileSelect').value);
})();
</script>
JS;

include BASE_PATH . '/includes/footer.php';
