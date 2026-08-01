<?php
require_once dirname(__DIR__) . '/config/config.php';
requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);

// ── POST: fill gap account numbers with temp/malformed subscribers ─
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'repair_gaps') {
    verifyCsrf();
    $year     = date('Y');
    $repaired = 0;
    $skipped  = 0;

    try {
        // All used standard sequences for this year
        $seqStmt = db()->prepare(
            "SELECT CAST(SUBSTRING_INDEX(account_number, '-', -1) AS UNSIGNED) AS seq
             FROM subscribers
             WHERE account_number REGEXP ?
             ORDER BY seq ASC"
        );
        $seqStmt->execute(["^{$year}-[0-9]+\$"]);
        $used = array_flip(array_map('intval', $seqStmt->fetchAll(PDO::FETCH_COLUMN)));

        // Build the full gap list (1 → max)
        $maxSeq = empty($used) ? 0 : max(array_keys($used));
        $gaps = [];
        for ($i = 1; $i <= $maxSeq; $i++) {
            if (!isset($used[$i])) {
                $gaps[] = str_pad($i, 4, '0', STR_PAD_LEFT);
            }
        }

        if (empty($gaps)) {
            flashMessage('info', 'No gaps found for ' . $year . ' — nothing to repair.');
            redirect(BASE_URL . '/modules/audit.php');
        }

        // Subscribers with non-standard account numbers (temp YYYY-TXXXXXX or malformed),
        // oldest first so the lowest IDs get the lowest gap numbers.
        $badStmt = db()->prepare(
            "SELECT subscriber_id, account_number
             FROM subscribers
             WHERE account_number NOT REGEXP ?
             ORDER BY subscriber_id ASC"
        );
        $badStmt->execute(["^[0-9]{4}-[0-9]+\$"]);
        $badSubs = $badStmt->fetchAll();

        $pairCount = min(count($gaps), count($badSubs));

        for ($i = 0; $i < $pairCount; $i++) {
            $newNum = $year . '-' . $gaps[$i];
            $subId  = (int)$badSubs[$i]['subscriber_id'];

            // Safety: skip if somehow already taken
            $ck = db()->prepare("SELECT 1 FROM subscribers WHERE account_number = ?");
            $ck->execute([$newNum]);
            if ($ck->fetchColumn()) { $skipped++; continue; }

            db()->prepare("UPDATE subscribers SET account_number = ? WHERE subscriber_id = ?")
               ->execute([$newNum, $subId]);
            $repaired++;
        }

        logActivity('audit', 'repair',
            "Gap repair: {$repaired} non-standard account numbers reassigned to gap slots." .
            ($skipped ? " {$skipped} skipped (collision)." : '')
        );

        $msg = "Filled <strong>{$repaired}</strong> gap(s).";
        if ($skipped) $msg .= " {$skipped} skipped (collision).";
        $remaining = count($gaps) - $repaired - $skipped;
        if ($remaining > 0) $msg .= " <strong>{$remaining}</strong> gaps remain (no more non-standard accounts to assign).";
        flashMessage('success', $msg);

    } catch (Throwable $e) {
        flashMessage('danger', 'Repair failed: ' . $e->getMessage());
    }

    redirect(BASE_URL . '/modules/audit.php');
}

$routerId   = selectedRouterId();
$routerName = selectedRouterName();
$rWhere     = $routerId ? " AND router_id = ?" : "";
$rP         = $routerId ? [$routerId] : [];

// ── 1. Duplicate account numbers ──────────────────────────────
$dupAccStmt = db()->prepare("
    SELECT account_number,
           COUNT(*) AS cnt,
           GROUP_CONCAT(subscriber_id ORDER BY subscriber_id SEPARATOR ',') AS sub_ids,
           GROUP_CONCAT(CONCAT(firstname,' ',lastname) ORDER BY subscriber_id SEPARATOR '|||') AS names,
           GROUP_CONCAT(status ORDER BY subscriber_id SEPARATOR ',') AS statuses
    FROM subscribers
    WHERE 1=1 {$rWhere}
    GROUP BY account_number
    HAVING cnt > 1
    ORDER BY cnt DESC, account_number
");
$dupAccStmt->execute($rP);
$dupAccounts = $dupAccStmt->fetchAll();

// ── 2. Duplicate ppp_username (with full row details) ─────────
$dupUStmt = db()->prepare("
    SELECT ppp_username, COUNT(*) AS cnt
    FROM subscribers
    WHERE ppp_username IS NOT NULL AND ppp_username != '' {$rWhere}
    GROUP BY ppp_username
    HAVING cnt > 1
    ORDER BY cnt DESC, ppp_username
");
$dupUStmt->execute($rP);
$dupUsernames = $dupUStmt->fetchAll();

$dupUsernameDetails = [];
foreach ($dupUsernames as $dup) {
    $stmt = db()->prepare("
        SELECT subscriber_id, account_number, firstname, lastname,
               status, ppp_profile, subscription_end, created_at
        FROM subscribers
        WHERE ppp_username = ? {$rWhere}
        ORDER BY subscriber_id
    ");
    $stmt->execute(array_merge([$dup['ppp_username']], $rP));
    $dupUsernameDetails[$dup['ppp_username']] = $stmt->fetchAll();
}

// ── 3. Account number gap analysis ────────────────────────────
$allAccStmt = db()->prepare(
    "SELECT account_number FROM subscribers WHERE 1=1 {$rWhere} ORDER BY account_number"
);
$allAccStmt->execute($rP);
$allAccNums = $allAccStmt->fetchAll(PDO::FETCH_COLUMN);

$yearMap    = [];   // year => [seq => true]
$tempAccNos = [];   // fallback temp numbers (YYYY-TXXXXXXXX)
$malformed  = [];   // anything else

foreach ($allAccNums as $acc) {
    if (preg_match('/^(\d{4})-(\d+)$/', $acc, $m)) {
        $yearMap[$m[1]][(int)$m[2]] = true;
    } elseif (preg_match('/^(\d{4})-T\d+$/', $acc)) {
        $tempAccNos[] = $acc;
    } else {
        $malformed[] = $acc;
    }
}

$gapsByYear = [];
foreach ($yearMap as $year => $seqs) {
    ksort($seqs);
    $keys = array_keys($seqs);
    $min  = $keys[0];
    $max  = end($keys);
    $gaps = [];
    for ($i = $min; $i <= $max; $i++) {
        if (!isset($seqs[$i])) {
            $gaps[] = $year . '-' . str_pad($i, 4, '0', STR_PAD_LEFT);
        }
    }
    $gapsByYear[$year] = [
        'min'   => $year . '-' . str_pad($min, 4, '0', STR_PAD_LEFT),
        'max'   => $year . '-' . str_pad($max, 4, '0', STR_PAD_LEFT),
        'total' => count($seqs),
        'gaps'  => $gaps,
    ];
}
ksort($gapsByYear);

// ── 4. Active/suspended with no ppp_username ─────────────────
$noPppStmt = db()->prepare("
    SELECT subscriber_id, account_number, firstname, lastname, status, created_at
    FROM subscribers
    WHERE (ppp_username IS NULL OR ppp_username = '')
      AND status IN ('active','suspended') {$rWhere}
    ORDER BY status, account_number
");
$noPppStmt->execute($rP);
$noPppList = $noPppStmt->fetchAll();

// ── 5. Ghost PPP sessions (active on router, not in system) ──
$ghostSessions   = [];
$ghostError      = null;
$ghostRouterName = null;
$ghostAuthType   = null;
$ghostSkipped    = !$routerId;

if ($routerId) {
    require_once BASE_PATH . '/lib/RouterosAPI.php';
    $rStmt = db()->prepare("
        SELECT host, api_port, port, username, password,
               COALESCE(auth_type,'local') AS auth_type, name, um_prefix
        FROM routers WHERE router_id = ?
    ");
    $rStmt->execute([$routerId]);
    $routerRow = $rStmt->fetch();

    if ($routerRow && !empty($routerRow['host'])) {
        $ghostRouterName = $routerRow['name'];
        $ghostAuthType   = $routerRow['auth_type'];
        @set_time_limit(30);
        $api = new RouterosAPI(
            $routerRow['host'],
            (int)($routerRow['api_port'] ?: $routerRow['port']),
            6
        );

        if (!$api->connect($routerRow['username'], decryptData($routerRow['password']))) {
            $ghostError = 'Router offline or unreachable.';
        } else {
            $activeUsernames = []; // lower => true
            $sessionMap      = []; // lower => first raw row seen

            // PPP active sessions
            foreach ($api->query('/ppp/active/print') as $row) {
                $u = strtolower(trim($row['name'] ?? ''));
                if ($u !== '') {
                    $activeUsernames[$u] = true;
                    if (!isset($sessionMap[$u])) $sessionMap[$u] = $row;
                }
            }

            // Hotspot active sessions
            foreach ($api->query('/ip/hotspot/active/print') as $row) {
                $u = strtolower(trim($row['user'] ?? $row['name'] ?? ''));
                if ($u !== '') {
                    $activeUsernames[$u] = true;
                    if (!isset($sessionMap[$u])) $sessionMap[$u] = $row;
                }
            }

            // RADIUS: User Manager active sessions
            if ($routerRow['auth_type'] === 'radius') {
                if (!empty($routerRow['um_prefix'])) {
                    $api->setUmPrefix($routerRow['um_prefix']);
                }
                try {
                    foreach ($api->getUserManagerActiveSessions() as $row) {
                        $u = strtolower(trim(
                            $row['user'] ?? $row['username'] ?? $row['name'] ?? ''
                        ));
                        if ($u !== '') {
                            $activeUsernames[$u] = true;
                            if (!isset($sessionMap[$u])) $sessionMap[$u] = $row;
                        }
                    }
                } catch (Throwable $_) {}
            }

            $api->disconnect();

            // Cross-reference against subscribers table
            if (!empty($activeUsernames)) {
                $allActive = array_keys($activeUsernames);
                $registered = [];
                foreach (array_chunk($allActive, 200) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $rs = db()->prepare(
                        "SELECT LOWER(ppp_username) FROM subscribers WHERE LOWER(ppp_username) IN ({$ph})"
                    );
                    $rs->execute($chunk);
                    foreach ($rs->fetchAll(PDO::FETCH_COLUMN) as $u) {
                        $registered[$u] = true;
                    }
                }

                foreach ($allActive as $u) {
                    if (isset($registered[$u])) continue;
                    $s = $sessionMap[$u];
                    $ghostSessions[] = [
                        'username' => $u,
                        'address'  => $s['address'] ?? $s['framed-ip-address'] ?? $s['user-address'] ?? '—',
                        'mac'      => $s['caller-id'] ?? $s['mac-address'] ?? $s['calling-station-id'] ?? '—',
                        'uptime'   => $s['uptime'] ?? '—',
                    ];
                }
            }
        }
    }
}

// ── Summary counts ────────────────────────────────────────────
$totalGaps    = (int)array_sum(array_map(fn($y) => count($y['gaps']), $gapsByYear));
$totalDupAcc  = count($dupAccounts);
$totalDupUsr  = count($dupUsernames);
$totalNoPpp   = count($noPppList);
$totalTemp    = count($tempAccNos);
$totalMalform = count($malformed);
$totalGhosts  = count($ghostSessions);
$totalIssues  = $totalDupAcc + $totalDupUsr + $totalGaps + $totalNoPpp + $totalTemp + $totalMalform + $totalGhosts;

$pageTitle   = 'Account Audit';
$breadcrumbs = [['label' => 'Account Audit']];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Account Audit</h4>
        <p class="text-muted small mb-0">
            Data integrity check — duplicates, gaps, and missing credentials
            <?php if ($routerName): ?>· <span class="text-primary fw-medium"><?= e($routerName) ?></span><?php endif; ?>
        </p>
    </div>
    <a href="?" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-clockwise me-1"></i>Refresh
    </a>
</div>

<!-- ── KPI Summary ─────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 h-100 <?= $totalDupAcc ? 'bg-danger-subtle' : 'bg-success-subtle' ?>">
            <div class="card-body py-3 px-3 text-center">
                <div class="fs-4 fw-bold <?= $totalDupAcc ? 'text-danger' : 'text-success' ?>"><?= number_format($totalDupAcc) ?></div>
                <div class="small text-muted">Duplicate Acc #</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 h-100 <?= $totalDupUsr ? 'bg-danger-subtle' : 'bg-success-subtle' ?>">
            <div class="card-body py-3 px-3 text-center">
                <div class="fs-4 fw-bold <?= $totalDupUsr ? 'text-danger' : 'text-success' ?>"><?= number_format($totalDupUsr) ?></div>
                <div class="small text-muted">Duplicate Usernames</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 h-100 <?= $totalGaps ? 'bg-warning-subtle' : 'bg-success-subtle' ?>">
            <div class="card-body py-3 px-3 text-center">
                <div class="fs-4 fw-bold <?= $totalGaps ? 'text-warning' : 'text-success' ?>"><?= number_format($totalGaps) ?></div>
                <div class="small text-muted">Sequence Gaps</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 h-100 <?= $totalNoPpp ? 'bg-warning-subtle' : 'bg-success-subtle' ?>">
            <div class="card-body py-3 px-3 text-center">
                <div class="fs-4 fw-bold <?= $totalNoPpp ? 'text-warning' : 'text-success' ?>"><?= number_format($totalNoPpp) ?></div>
                <div class="small text-muted">No PPP Username</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 h-100 <?= $totalTemp ? 'bg-warning-subtle' : 'bg-success-subtle' ?>">
            <div class="card-body py-3 px-3 text-center">
                <div class="fs-4 fw-bold <?= $totalTemp ? 'text-warning' : 'text-success' ?>"><?= number_format($totalTemp) ?></div>
                <div class="small text-muted">Temp Acc #</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card border-0 h-100 <?= $totalMalform ? 'bg-danger-subtle' : 'bg-success-subtle' ?>">
            <div class="card-body py-3 px-3 text-center">
                <div class="fs-4 fw-bold <?= $totalMalform ? 'text-danger' : 'text-success' ?>"><?= number_format($totalMalform) ?></div>
                <div class="small text-muted">Malformed Acc #</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-lg-2">
        <?php $ghostKpiBg = $ghostSkipped ? 'bg-light' : ($ghostError ? 'bg-secondary-subtle' : ($totalGhosts ? 'bg-danger-subtle' : 'bg-success-subtle')); ?>
        <?php $ghostKpiTxt = $ghostSkipped ? 'text-muted' : ($ghostError ? 'text-secondary' : ($totalGhosts ? 'text-danger' : 'text-success')); ?>
        <div class="card border-0 h-100 <?= $ghostKpiBg ?>">
            <div class="card-body py-3 px-3 text-center">
                <div class="fs-4 fw-bold <?= $ghostKpiTxt ?>">
                    <?= $ghostSkipped ? '—' : ($ghostError ? '!' : number_format($totalGhosts)) ?>
                </div>
                <div class="small text-muted">Ghost Sessions</div>
            </div>
        </div>
    </div>
</div>

<?php if ($totalIssues === 0): ?>
<div class="alert alert-success d-flex align-items-center gap-2">
    <i class="bi bi-check-circle-fill fs-5"></i>
    <span>No issues found. All account numbers and PPP usernames are clean<?= $routerName ? ' for router <strong>' . e($routerName) . '</strong>' : '' ?>.</span>
</div>
<?php endif; ?>

<?php if ($totalGaps > 0 || $totalTemp > 0 || $totalMalform > 0): ?>
<!-- ── Gap Repair Tool ──────────────────────────────────────────── -->
<?php
// Count non-standard account numbers that can be reassigned
$badCountStmt = db()->query(
    "SELECT COUNT(*) FROM subscribers WHERE account_number NOT REGEXP '^[0-9]{4}-[0-9]+$'"
);
$totalBadAccounts = (int)$badCountStmt->fetchColumn();
$canRepair = min($totalGaps, $totalBadAccounts);
?>
<div class="card border-warning mb-4">
    <div class="card-header bg-warning-subtle border-warning py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold text-warning-emphasis">
            <i class="bi bi-wrench-adjustable me-2"></i>Gap Repair Tool
        </h6>
        <div class="d-flex gap-2 flex-wrap">
            <span class="badge bg-warning text-dark"><?= number_format($totalGaps) ?> gap<?= $totalGaps !== 1 ? 's' : '' ?> found</span>
            <span class="badge bg-secondary"><?= number_format($totalBadAccounts) ?> non-standard account<?= $totalBadAccounts !== 1 ? 's' : '' ?> to reassign</span>
            <span class="badge <?= $canRepair > 0 ? 'bg-success' : 'bg-secondary' ?>"><?= number_format($canRepair) ?> will be filled</span>
        </div>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-3">
            This tool reassigns <strong>temp and malformed account numbers</strong> to the lowest available gap numbers (e.g. <code>2026-0019</code>, <code>2026-0020</code>&hellip;),
            working oldest subscriber first. Only <code>account_number</code> is changed — PPP usernames and all other data stay untouched.
            New subscribers will continue to fill remaining gaps automatically.
        </p>
        <?php if ($canRepair === 0): ?>
        <div class="alert alert-info mb-0 small py-2">
            <i class="bi bi-info-circle me-1"></i>
            <?= $totalBadAccounts === 0 ? 'No non-standard account numbers to reassign.' : 'No gaps to fill.' ?>
            Remaining gaps will be consumed as new subscribers are added.
        </div>
        <?php else: ?>
        <form method="POST" onsubmit="return confirm('Reassign <?= $canRepair ?> non-standard account number(s) to gap slots?\n\nThis will update account_number only. PPP usernames stay the same.\n\nContinue?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="repair_gaps">
            <button type="submit" class="btn btn-warning fw-semibold">
                <i class="bi bi-wrench-adjustable me-1"></i>Fill <?= number_format($canRepair) ?> Gap<?= $canRepair !== 1 ? 's' : '' ?> Now
            </button>
            <span class="text-muted small ms-2">
                Fills gaps <?= date('Y') ?>-<?= str_pad(1, 4, '0', STR_PAD_LEFT) ?> → <?= date('Y') ?>-<?= str_pad($totalGaps, 4, '0', STR_PAD_LEFT) ?> with temp/malformed accounts
            </span>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>


<!-- ═══════════════════════════════════════════════════════════
     SECTION 1: Duplicate Account Numbers
     ═══════════════════════════════════════════════════════════ -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-files me-2 text-danger"></i>Duplicate Account Numbers
        </h6>
        <span class="badge <?= $totalDupAcc ? 'bg-danger' : 'bg-success' ?>"><?= number_format($totalDupAcc) ?> found</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($dupAccounts)): ?>
        <div class="text-center text-muted py-4 small">
            <i class="bi bi-check-circle text-success d-block fs-3 mb-1"></i>
            No duplicate account numbers.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Account #</th>
                        <th class="text-center">Count</th>
                        <th>Subscriber IDs</th>
                        <th>Names</th>
                        <th class="pe-3">Statuses</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dupAccounts as $dup):
                        $ids      = explode(',', $dup['sub_ids']);
                        $names    = explode('|||', $dup['names']);
                        $statuses = explode(',', $dup['statuses']);
                    ?>
                    <tr>
                        <td class="ps-3 font-monospace fw-semibold text-danger"><?= e($dup['account_number']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-danger"><?= (int)$dup['cnt'] ?></span>
                        </td>
                        <td>
                            <?php foreach ($ids as $sid): ?>
                            <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= (int)$sid ?>"
                               class="badge bg-secondary text-decoration-none me-1" target="_blank">
                                #<?= (int)$sid ?>
                            </a>
                            <?php endforeach; ?>
                        </td>
                        <td class="small">
                            <?php foreach ($names as $i => $n): ?>
                            <div><?= e(trim($n)) ?></div>
                            <?php endforeach; ?>
                        </td>
                        <td class="pe-3">
                            <?php foreach ($statuses as $st): ?>
                            <?= subStatusBadge(trim($st)) ?>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     SECTION 2: Duplicate PPP Usernames
     ═══════════════════════════════════════════════════════════ -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-person-fill-exclamation me-2 text-danger"></i>Duplicate PPP Usernames
        </h6>
        <span class="badge <?= $totalDupUsr ? 'bg-danger' : 'bg-success' ?>"><?= number_format($totalDupUsr) ?> username<?= $totalDupUsr !== 1 ? 's' : '' ?> shared</span>
    </div>
    <div class="card-body <?= empty($dupUsernames) ? '' : 'p-0' ?>">
        <?php if (empty($dupUsernames)): ?>
        <div class="text-center text-muted py-4 small">
            <i class="bi bi-check-circle text-success d-block fs-3 mb-1"></i>
            No duplicate PPP usernames.
        </div>
        <?php else: ?>
        <div class="accordion accordion-flush" id="dupUsernameAccordion">
        <?php foreach ($dupUsernames as $i => $dup):
            $rows    = $dupUsernameDetails[$dup['ppp_username']] ?? [];
            $safeId  = 'dupUsr_' . $i;
        ?>
        <div class="accordion-item border-0 border-bottom">
            <h2 class="accordion-header">
                <button class="accordion-button <?= $i !== 0 ? 'collapsed' : '' ?> py-2 px-3"
                        type="button" data-bs-toggle="collapse"
                        data-bs-target="#<?= $safeId ?>" aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>">
                    <span class="font-monospace fw-semibold text-danger me-3"><?= e($dup['ppp_username']) ?></span>
                    <span class="badge bg-danger me-2"><?= (int)$dup['cnt'] ?> accounts</span>
                    <?php
                        $statSet = array_column($rows, 'status');
                        foreach (array_count_values($statSet) as $st => $n):
                    ?>
                    <span class="me-1"><?= subStatusBadge($st) ?></span>
                    <?php endforeach; ?>
                </button>
            </h2>
            <div id="<?= $safeId ?>" class="accordion-collapse collapse <?= $i === 0 ? 'show' : '' ?>"
                 data-bs-parent="#dupUsernameAccordion">
                <div class="accordion-body p-0">
                    <table class="table table-sm align-middle mb-0" style="font-size:.85rem;">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Account #</th>
                                <th>Subscriber</th>
                                <th>Status</th>
                                <th>Profile</th>
                                <th>Expiry</th>
                                <th class="pe-3">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                            <tr>
                                <td class="ps-3 font-monospace">
                                    <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $row['subscriber_id'] ?>"
                                       class="text-decoration-none fw-semibold" target="_blank">
                                        <?= e($row['account_number']) ?>
                                    </a>
                                </td>
                                <td><?= e($row['firstname'] . ' ' . $row['lastname']) ?></td>
                                <td><?= subStatusBadge($row['status']) ?></td>
                                <td class="font-monospace text-muted small"><?= e($row['ppp_profile'] ?? '—') ?></td>
                                <td class="small <?= ($row['status'] === 'expired' || (isset($row['subscription_end']) && $row['subscription_end'] && strtotime($row['subscription_end']) < time())) ? 'text-danger' : '' ?>">
                                    <?= $row['subscription_end'] ? formatDate($row['subscription_end'], 'M d, Y') : '—' ?>
                                </td>
                                <td class="pe-3 small text-muted"><?= formatDate($row['created_at'], 'M d, Y') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     SECTION 3: Account Number Gap Analysis
     ═══════════════════════════════════════════════════════════ -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-list-ol me-2 text-warning"></i>Account Number Sequence Gaps
        </h6>
        <span class="badge <?= $totalGaps ? 'bg-warning text-dark' : 'bg-success' ?>"><?= number_format($totalGaps) ?> gap<?= $totalGaps !== 1 ? 's' : '' ?> total</span>
    </div>
    <div class="card-body">
        <?php if (empty($gapsByYear)): ?>
        <div class="text-center text-muted py-4 small">
            <i class="bi bi-check-circle text-success d-block fs-3 mb-1"></i>
            No sequence gaps found.
        </div>
        <?php else: ?>
        <?php foreach ($gapsByYear as $year => $info):
            $gapCount  = count($info['gaps']);
            $showLimit = 300;
            $hidden    = max(0, $gapCount - $showLimit);
        ?>
        <div class="mb-4">
            <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
                <span class="fw-semibold fs-6"><?= e($year) ?></span>
                <span class="badge bg-secondary"><?= number_format($info['total']) ?> accounts</span>
                <span class="text-muted small">Range: <strong><?= e($info['min']) ?></strong> – <strong><?= e($info['max']) ?></strong></span>
                <?php if ($gapCount > 0): ?>
                <span class="badge bg-warning text-dark"><?= number_format($gapCount) ?> gap<?= $gapCount !== 1 ? 's' : '' ?></span>
                <?php else: ?>
                <span class="badge bg-success">No gaps</span>
                <?php endif; ?>
            </div>
            <?php if ($gapCount > 0): ?>
            <div style="line-height:2;">
                <?php foreach (array_slice($info['gaps'], 0, $showLimit) as $gap): ?>
                <span class="badge bg-light border text-secondary font-monospace me-1 mb-1"
                      style="font-size:.78rem;"><?= e($gap) ?></span>
                <?php endforeach; ?>
                <?php if ($hidden > 0): ?>
                <span class="badge bg-secondary ms-1">+ <?= number_format($hidden) ?> more…</span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!array_key_last($gapsByYear) !== $year): ?>
        <hr class="my-3">
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     SECTION 4: Active/Suspended Without PPP Username
     ═══════════════════════════════════════════════════════════ -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-person-dash me-2 text-warning"></i>Active / Suspended — Missing PPP Username
        </h6>
        <span class="badge <?= $totalNoPpp ? 'bg-warning text-dark' : 'bg-success' ?>"><?= number_format($totalNoPpp) ?></span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($noPppList)): ?>
        <div class="text-center text-muted py-4 small">
            <i class="bi bi-check-circle text-success d-block fs-3 mb-1"></i>
            All active/suspended subscribers have a PPP username.
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Account #</th>
                        <th>Subscriber</th>
                        <th>Status</th>
                        <th class="pe-3">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($noPppList as $sub): ?>
                    <tr>
                        <td class="ps-3 font-monospace">
                            <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $sub['subscriber_id'] ?>"
                               class="text-decoration-none fw-semibold" target="_blank">
                                <?= e($sub['account_number']) ?>
                            </a>
                        </td>
                        <td class="small fw-medium"><?= e($sub['firstname'] . ' ' . $sub['lastname']) ?></td>
                        <td><?= subStatusBadge($sub['status']) ?></td>
                        <td class="pe-3 small text-muted"><?= formatDate($sub['created_at'], 'M d, Y') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     SECTION 5: Temp & Malformed Account Numbers
     ═══════════════════════════════════════════════════════════ -->
<?php if ($totalTemp > 0 || $totalMalform > 0): ?>
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-exclamation-triangle me-2 text-danger"></i>Temp &amp; Malformed Account Numbers
        </h6>
    </div>
    <div class="card-body">

        <?php if ($totalTemp > 0): ?>
        <div class="mb-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="fw-semibold small text-warning">Temp Numbers</span>
                <span class="badge bg-warning text-dark"><?= number_format($totalTemp) ?></span>
                <span class="text-muted small">— generated by the fallback path (sequence lock failed)</span>
            </div>
            <div style="line-height:2;">
                <?php foreach ($tempAccNos as $t): ?>
                <?php
                    $tStmt = db()->prepare("SELECT subscriber_id, firstname, lastname, status FROM subscribers WHERE account_number = ? LIMIT 1");
                    $tStmt->execute([$t]);
                    $tRow = $tStmt->fetch();
                ?>
                <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $tRow['subscriber_id'] ?? 0 ?>"
                   class="badge bg-warning text-dark border font-monospace text-decoration-none me-1 mb-1"
                   style="font-size:.78rem;" target="_blank">
                    <?= e($t) ?>
                    <?php if ($tRow): ?>
                    <span class="fw-normal">– <?= e($tRow['firstname'] . ' ' . $tRow['lastname']) ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($totalMalform > 0): ?>
        <?php if ($totalTemp > 0): ?><hr class="my-3"><?php endif; ?>
        <div>
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="fw-semibold small text-danger">Malformed Numbers</span>
                <span class="badge bg-danger"><?= number_format($totalMalform) ?></span>
                <span class="text-muted small">— do not match YYYY-NNNN or YYYY-TXXXXXX pattern</span>
            </div>
            <div style="line-height:2;">
                <?php foreach ($malformed as $m): ?>
                <?php
                    $mStmt = db()->prepare("SELECT subscriber_id, firstname, lastname FROM subscribers WHERE account_number = ? LIMIT 1");
                    $mStmt->execute([$m]);
                    $mRow = $mStmt->fetch();
                ?>
                <a href="<?= BASE_URL ?>/modules/subscribers/view/<?= $mRow['subscriber_id'] ?? 0 ?>"
                   class="badge bg-danger-subtle border border-danger text-danger font-monospace text-decoration-none me-1 mb-1"
                   style="font-size:.78rem;" target="_blank">
                    <?= e($m) ?>
                    <?php if ($mRow): ?>
                    <span class="fw-normal">– <?= e($mRow['firstname'] . ' ' . $mRow['lastname']) ?></span>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════
     SECTION 6: Ghost PPP Sessions (active on router, not in system)
     ═══════════════════════════════════════════════════════════ -->
<div class="card border-0 mb-4">
    <div class="card-header bg-transparent border-bottom py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h6 class="mb-0 fw-semibold">
            <i class="bi bi-router me-2 text-danger"></i>Ghost PPP Sessions
            <span class="text-muted fw-normal small ms-1">— active on router but not registered in system</span>
        </h6>
        <div class="d-flex align-items-center gap-2">
            <?php if ($ghostRouterName): ?>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
                <i class="bi bi-hdd-network me-1"></i><?= e($ghostRouterName) ?>
                <?php if ($ghostAuthType === 'radius'): ?>
                <span class="ms-1 opacity-75">· RADIUS</span>
                <?php endif; ?>
            </span>
            <?php endif; ?>
            <span class="badge <?= $ghostSkipped ? 'bg-secondary' : ($ghostError ? 'bg-warning text-dark' : ($totalGhosts ? 'bg-danger' : 'bg-success')) ?>">
                <?= $ghostSkipped ? 'Select a router' : ($ghostError ? 'Offline' : number_format($totalGhosts) . ' found') ?>
            </span>
        </div>
    </div>
    <div class="card-body <?= (!$ghostSkipped && !$ghostError && !empty($ghostSessions)) ? 'p-0' : '' ?>">
        <?php if ($ghostSkipped): ?>
        <div class="text-center text-muted py-4 small">
            <i class="bi bi-hdd-network d-block fs-3 mb-2 text-secondary"></i>
            Select a router first — this check connects live to the router.
        </div>
        <?php elseif ($ghostError): ?>
        <div class="text-center text-muted py-4 small">
            <i class="bi bi-exclamation-triangle d-block fs-3 mb-2 text-warning"></i>
            <?= e($ghostError) ?>
        </div>
        <?php elseif (empty($ghostSessions)): ?>
        <div class="text-center text-muted py-4 small">
            <i class="bi bi-check-circle text-success d-block fs-3 mb-1"></i>
            All active PPP sessions belong to registered subscribers.
        </div>
        <?php else: ?>
        <div class="alert alert-danger border-0 rounded-0 mb-0 small py-2 px-3">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong><?= number_format($totalGhosts) ?> session<?= $totalGhosts !== 1 ? 's' : '' ?></strong>
            found active on the router with no matching subscriber record.
            These may be unauthorized connections or manually created router accounts.
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">PPP Username</th>
                        <th>IP Address</th>
                        <th>MAC / Caller ID</th>
                        <th class="pe-3">Uptime</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ghostSessions as $ghost): ?>
                    <tr>
                        <td class="ps-3 font-monospace fw-semibold text-danger">
                            <?= e($ghost['username']) ?>
                        </td>
                        <td class="font-monospace small"><?= e($ghost['address']) ?></td>
                        <td class="font-monospace small text-muted"><?= e($ghost['mac']) ?></td>
                        <td class="pe-3 small text-muted"><?= e($ghost['uptime']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include BASE_PATH . '/includes/footer.php'; ?>
