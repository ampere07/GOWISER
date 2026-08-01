<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once BASE_PATH . '/lib/RouterosAPI.php';
requireMinRole(ROLE_ADMIN);

$selectedRouterId = selectedRouterId();
$canAssignAllRouters = currentRole() === ROLE_SUPERADMIN;
$planScopeSql = '';
$planScopeParams = [];
if (!$canAssignAllRouters) {
    if (!$selectedRouterId) {
        flashMessage('danger', 'Router not selected.');
        redirect(BASE_URL . '/modules/plans/');
    }
    $planScopeSql = ' AND router_id = ?';
    $planScopeParams[] = $selectedRouterId;
}

$id   = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM plans WHERE plan_id = ?{$planScopeSql}");
$stmt->execute(array_merge([$id], $planScopeParams));
$plan = $stmt->fetch();
if (!$plan) { flashMessage('danger', 'Plan not found.'); redirect(BASE_URL . '/modules/plans/'); }

$routerSql = "SELECT router_id, name, host, port, api_port, username, password, COALESCE(auth_type,'local') AS auth_type FROM routers";
if ($canAssignAllRouters) {
    $routers = db()->query($routerSql . " ORDER BY name")->fetchAll();
} else {
    $routerStmt = db()->prepare($routerSql . " WHERE router_id = ? ORDER BY name");
    $routerStmt->execute([$selectedRouterId]);
    $routers = $routerStmt->fetchAll();
}
$errors  = [];

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

    if (empty($data['title']))    $errors[] = 'Title required.';
    if ($data['amount'] < 0)      $errors[] = 'Amount cannot be negative.';
    if ($data['speed_mbps'] <= 0) $errors[] = 'Speed must be > 0.';

    if (empty($errors)) {
        db()->prepare("
            UPDATE plans
            SET title=?,description=?,amount=?,speed_mbps=?,burst_mbps=?,billing_cycle=?,plan_type=?,ppp_profile=?,router_id=?,portal_enabled=?,is_active=?
            WHERE plan_id=?{$planScopeSql}
        ")->execute(array_merge([
            $data['title'], $data['description'] ?: null,
            $data['amount'], $data['speed_mbps'], $data['burst_mbps'],
            $data['billing_cycle'], $data['plan_type'], $data['ppp_profile'] ?: null,
            $data['router_id'], $data['portal_enabled'], $data['is_active'],
            $id,
        ], $planScopeParams));
        logActivity('plans', 'update', "Updated plan: {$data['title']}");

        // Sync to selected routers
        $syncMsg = '';
        if (!empty($syncToRouters)) {
            $ok  = 0;
            $tot = 0;
            foreach ($routers as $router) {
                if (!in_array((int)$router['router_id'], $syncToRouters, true)) continue;
                $tot++;
                $api = new RouterosAPI($router['host'], (int)($router['api_port'] ?: $router['port']), 5);
                if ($api->connect($router['username'], decryptData($router['password']))) {
                    $result = $api->syncPlanProfile($data['title'], $data['speed_mbps'], $data['burst_mbps'], $data['plan_type']);
                    $api->disconnect();
                    if (!empty(array_filter($result))) $ok++;
                }
            }
            $syncMsg = " Synced profile to {$ok}/{$tot} router(s).";
        }

        flashMessage('success', 'Plan updated.' . $syncMsg);
        redirect(BASE_URL . '/modules/plans/');
    }
    $plan = array_merge($plan, $data);
}

$currency    = getSetting('currency_symbol', '₱');
$pageTitle   = 'Edit Plan';
$breadcrumbs = [['label' => 'Plans', 'url' => BASE_URL . '/modules/plans/'], ['label' => 'Edit Plan']];
include BASE_PATH . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Edit Plan — <?= e($plan['title']) ?></h4>
    <a href="<?= BASE_URL ?>/modules/plans/" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
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
                            <input type="text" name="title" id="planTitle" class="form-control" value="<?= e($plan['title']) ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-medium" for="planDesc">Description</label>
                            <textarea name="description" id="planDesc" class="form-control" rows="2"><?= e($plan['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="planAmount">Amount (<?= e($currency) ?>) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><?= e($currency) ?></span>
                                <input type="number" name="amount" id="planAmount" class="form-control" step="0.01" min="0"
                                       value="<?= e($plan['amount']) ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="planBilling">Billing Cycle</label>
                            <select name="billing_cycle" id="planBilling" class="form-select">
                                <?php foreach (['monthly','quarterly','annual'] as $c): ?>
                                <option value="<?= $c ?>" <?= $plan['billing_cycle'] === $c ? 'selected' : '' ?>><?= ucfirst($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="speedInput">Speed (Mbps) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" name="speed_mbps" class="form-control" min="1"
                                       value="<?= e($plan['speed_mbps']) ?>" required id="speedInput">
                                <span class="input-group-text">Mbps</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="burstInput">Burst Speed (Mbps)</label>
                            <div class="input-group">
                                <input type="number" name="burst_mbps" class="form-control" min="0"
                                       value="<?= e($plan['burst_mbps'] ?? '') ?>" id="burstInput">
                                <span class="input-group-text">Mbps</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-medium" for="planTypeInput">Plan Type</label>
                            <select name="plan_type" class="form-select" id="planTypeInput">
                                <option value="ppp"     <?= $plan['plan_type'] === 'ppp'     ? 'selected' : '' ?>>PPP / PPPoE</option>
                                <option value="hotspot" <?= $plan['plan_type'] === 'hotspot' ? 'selected' : '' ?>>Hotspot</option>
                                <option value="both"    <?= $plan['plan_type'] === 'both'    ? 'selected' : '' ?>>Both</option>
                            </select>
                        </div>

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
                                        <?= (int)$plan['router_id'] === (int)$r['router_id'] ? 'selected' : '' ?>>
                                    <?= e($r['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-medium d-flex align-items-center gap-2" id="profileFieldLabel" for="profileSelect">
                                <span id="profileFieldLabelText">MikroTik Profile Name</span>
                                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" id="btnLoadProfiles"
                                        title="Load profiles from the assigned router">
                                    <i class="bi bi-arrow-repeat me-1"></i>Load from Router
                                </button>
                            </label>
                            <div id="profileSelectWrap">
                                <select name="ppp_profile" class="form-select font-monospace" id="profileSelect">
                                    <option value="">— auto-uses plan title if blank —</option>
                                    <?php if (!empty($plan['ppp_profile'])): ?>
                                    <option value="<?= e($plan['ppp_profile']) ?>" selected><?= e($plan['ppp_profile']) ?></option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text" id="profileHelp">
                                    <?php if (!empty($plan['ppp_profile'])): ?>
                                    Saved: <code class="text-success"><?= e($plan['ppp_profile']) ?></code> — click <em>Load from Router</em> to refresh the list.
                                    <?php else: ?>
                                    Click <em>Load from Router</em> to fetch profiles, or leave blank to auto-use the plan title.
                                    <?php endif; ?>
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
                                       <?= $plan['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium" for="isActive">Active</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="portal_enabled" id="portalEnabled"
                                       <?= !empty($plan['portal_enabled']) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium" for="portalEnabled">Show in Payment Portal</label>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($routers)): ?>
                    <div class="card border border-primary-subtle mb-3">
                        <div class="card-header bg-primary-subtle py-2">
                            <h6 class="mb-0 fw-semibold text-primary">
                                <i class="bi bi-arrow-repeat me-2"></i>Re-sync Profile to MikroTik
                            </h6>
                        </div>
                        <div class="card-body py-3">
                            <p class="text-muted small mb-2">Check routers to push updated rate-limit profile.</p>
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
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        <a href="<?= BASE_URL ?>/modules/plans/" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$savedProfile = e($plan['ppp_profile'] ?? '');
$extraScripts = <<<JS
<script>
function updateRateLimitPreview() {
    const speed = parseInt(document.getElementById('speedInput').value) || 0;
    const burst = parseInt(document.getElementById('burstInput').value) || 0;
    let rl = speed ? `\${speed}M/\${speed}M` : '—';
    if (burst && burst > speed) rl += ` \${burst}M/\${burst}M \${speed}M/\${speed}M`;
    document.getElementById('rateLimitVal').textContent = rl;
}
document.getElementById('speedInput')?.addEventListener('input', updateRateLimitPreview);
document.getElementById('burstInput')?.addEventListener('input', updateRateLimitPreview);
updateRateLimitPreview();

// ── Profile select loader ─────────────────────────────────────
const SAVED_PROFILE = '{$savedProfile}';

function getRouterAuthType() {
    const sel = document.getElementById('routerSelect');
    if (!sel || !sel.value) return 'local';
    const opt = sel.options[sel.selectedIndex];
    return opt ? (opt.dataset.authType || 'local') : 'local';
}

function updateProfileLabel(isRadius) {
    const labelText = document.getElementById('profileFieldLabelText');
    const btn = document.getElementById('btnLoadProfiles');
    const customInput = document.getElementById('profileCustomInput');
    if (labelText) labelText.textContent = isRadius ? 'RADIUS Group Name' : 'MikroTik Profile Name';
    if (btn) btn.title = isRadius ? 'Load groups from the assigned router' : 'Load profiles from the assigned router';
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
        if (currentVal) sel.innerHTML += `<option value="\${currentVal}" selected>\${currentVal}</option>`;
        help.textContent = isRadius
            ? 'Select a router above to load its RADIUS groups, or leave blank to auto-use the plan title.'
            : 'Select a router above to load its existing profiles, or leave blank to auto-use the plan title.';
        return;
    }
    sel.innerHTML = '<option value="">⏳ Loading…</option>';
    sel.disabled  = true;
    help.textContent = '';
    fetch(`\${BASE_URL}/api/router_profiles.php?router_id=\${routerId}&type=\${fetchType}`)
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
                    ? `\${d.profiles.length} group(s) loaded from router.`
                    : `\${d.profiles.length} profile(s) loaded from router.`;
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
    wrap.classList.remove('d-none');
    if (val) input.value = val;
}

function hideCustomInput() {
    document.getElementById('profileCustomWrap').classList.add('d-none');
    document.getElementById('profileCustomInput').value = '';
}

document.getElementById('profileSelect')?.addEventListener('change', function () {
    if (this.value === '__custom__') { showCustomInput(''); } else { hideCustomInput(); }
});

function currentProfile() {
    const sel = document.getElementById('profileSelect');
    if (!sel) return SAVED_PROFILE;
    if (sel.value === '__custom__') return document.getElementById('profileCustomInput')?.value || SAVED_PROFILE;
    return sel.value || SAVED_PROFILE;
}

// Set correct label on page load (router may already be selected)
updateProfileLabel(getRouterAuthType() === 'radius');

document.getElementById('btnLoadProfiles')?.addEventListener('click', function () {
    const routerId = document.getElementById('routerSelect').value;
    const planType = document.getElementById('planTypeInput').value;
    if (!routerId) {
        document.getElementById('profileHelp').textContent = 'Select a router first.';
        return;
    }
    loadProfiles(routerId, planType, currentProfile());
});

document.getElementById('routerSelect')?.addEventListener('change', function () {
    const planType = document.getElementById('planTypeInput').value;
    loadProfiles(this.value, planType, currentProfile());
});
document.getElementById('planTypeInput')?.addEventListener('change', function () {
    const routerId = document.getElementById('routerSelect').value;
    if (routerId) loadProfiles(routerId, this.value, currentProfile());
});

document.querySelector('form')?.addEventListener('submit', function () {
    // Re-enable select in case it's still disabled mid-load so value is submitted
    document.getElementById('profileSelect').disabled = false;
    const sel   = document.getElementById('profileSelect');
    const input = document.getElementById('profileCustomInput');
    if (sel.value === '__custom__') {
        const opt = new Option(input.value, input.value, true, true);
        sel.add(opt);
        sel.value = input.value;
    }
});
</script>
JS;

include BASE_PATH . '/includes/footer.php';
