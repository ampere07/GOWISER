<?php
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$role = currentRole();
$selRouterId   = selectedRouterId();
$selRouterName = selectedRouterName();
$canViewPayments = in_array($role, MODULE_ACCESS['payments'] ?? [], true);
$canViewExpenses = in_array($role, MODULE_ACCESS['expenses'] ?? [], true);

// Sidebar count badges
$_sbRouterWhere = $selRouterId ? ' AND router_id = ?' : '';
$_sbRouterParam = $selRouterId ? [$selRouterId] : [];

$_s = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE status NOT IN (?, ?) $_sbRouterWhere");
$_s->execute(array_merge([SUB_STATUS_PENDING, SUB_STATUS_ARCHIVED], $_sbRouterParam));
$sbCountSubscribers = (int)$_s->fetchColumn();

$_s = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE status = ? $_sbRouterWhere");
$_s->execute(array_merge([SUB_STATUS_PENDING], $_sbRouterParam));
$sbCountApplications = (int)$_s->fetchColumn();

$_s = db()->prepare("SELECT COUNT(*) FROM subscribers WHERE status = ? $_sbRouterWhere");
$_s->execute(array_merge([SUB_STATUS_ARCHIVED], $_sbRouterParam));
$sbCountArchived = (int)$_s->fetchColumn();

$_s = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE DATE(payment_date) = ? AND status = 'paid'" . $_sbRouterWhere);
$_s->execute(array_merge([appToday()], $_sbRouterParam));
$sbPaymentsToday = number_format((float)$_s->fetchColumn(), 2);

$_s = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE DATE(expense_date) = ?" . expensePeriodTypeSql('daily', '') . $_sbRouterWhere);
$_s->execute(array_merge([appToday()], $_sbRouterParam));
$sbExpensesToday = number_format((float)$_s->fetchColumn(), 2);

// Job order counts (scoped to current user for lineman)
$sbJobPending = 0;
$sbJobOngoing = 0;
if (hasRole(ROLE_LINEMAN, ROLE_ADMIN, ROLE_SUPERADMIN)) {
    try {
        $_joWhere  = ($role === ROLE_LINEMAN) ? ' AND i.user_id = ?' : '';
        $_joParams = ($role === ROLE_LINEMAN) ? [currentUserId()] : [];
        $_joRouterWhere = $selRouterId ? ' AND s.router_id = ?' : '';
        $_joRouterParam = $selRouterId ? [$selRouterId] : [];
        $_s = db()->prepare("SELECT COUNT(*) FROM installations i JOIN subscribers s ON s.subscriber_id = i.subscriber_id WHERE i.status = 'Pending'" . $_joWhere . $_joRouterWhere);
        $_s->execute(array_merge($_joParams, $_joRouterParam));
        $sbJobPending = (int)$_s->fetchColumn();
        $_s = db()->prepare("SELECT COUNT(*) FROM installations i JOIN subscribers s ON s.subscriber_id = i.subscriber_id WHERE i.status = 'Ongoing'" . $_joWhere . $_joRouterWhere);
        $_s->execute(array_merge($_joParams, $_joRouterParam));
        $sbJobOngoing = (int)$_s->fetchColumn();
    } catch (Throwable) {}
}
unset($_s);

function sidebarLink(string $icon, string $label, string $url, string $currentPath, ?string $match = null, string $badge = ''): string {
    $active    = str_contains($currentPath, $match ?? $url) ? 'active' : '';
    $base      = BASE_URL;
    $badgeHtml = $badge !== '' ? '<span class="sb-count-badge">' . $badge . '</span>' : '';
    return '<li class="nav-item">
              <a class="nav-link ' . $active . '" href="' . $base . $url . '" title="' . htmlspecialchars($label, ENT_QUOTES) . '">
                <span class="nav-icon-wrap"><i class="bi bi-' . $icon . ' nav-icon"></i></span>
                <span class="nav-label">' . htmlspecialchars($label, ENT_QUOTES) . '</span>
                ' . $badgeHtml . '
              </a>
            </li>';
}
?>
<aside class="sidebar" id="sidebar">

    <!-- ── Brand ──────────────────────────────────────── -->
    <div class="sb-brand">
        <div class="sb-brand-icon">
            <i class="bi bi-router-fill"></i>
        </div>
        <div class="sb-brand-text">
            <div class="sb-app-name"><?= e(getSetting('company_name', APP_NAME)) ?></div>
        </div>
    </div>

    <!-- ── Live router mini-status ───────────────────── -->
    <div class="sb-status-panel sidebar-brand-text">
        <div class="sb-status-header">
            <span>ROUTER STATUS</span>
            <button id="refreshRouters" class="sb-refresh-btn" title="Refresh">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        </div>
        <div id="sidebarRouterStatus" class="sb-status-body"
             data-router-id="<?= (int)$selRouterId ?>"
             data-router-name="<?= e($selRouterName) ?>">
            <span class="sb-status-loading">
                <i class="bi bi-circle-half spinning"></i> Loading…
            </span>
        </div>
    </div>

    <!-- ── Navigation ────────────────────────────────── -->
    <nav class="sb-nav">
        <ul class="nav flex-column px-2">

            <?php if (in_array($role, MODULE_ACCESS['dashboard'])): ?>
            <li class="sb-section-label">DASHBOARDS</li>
            <?= sidebarLink('speedometer2', 'Dashboard 1', '/modules/dashboard/', $currentPath, '/modules/dashboard/index') ?>
            <?php if (in_array($role, MODULE_ACCESS['dashboard2'])): ?>
            <?= sidebarLink('router-fill', 'Dashboard 2', '/modules/dashboard/mikrotik', $currentPath) ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array($role, MODULE_ACCESS['subscribers'])): ?>
            <li class="sb-section-label">SUBSCRIBERS</li>
            <?php
            $onSubsPending  = str_contains($currentPath, '/modules/subscribers/pending');
            $onSubsArchived = str_contains($currentPath, '/modules/subscribers/archived');
            $subMainActive  = (!$onSubsPending && !$onSubsArchived && str_contains($currentPath, '/modules/subscribers/')) ? 'active' : '';
            $base = BASE_URL;
            ?>
            <li class="nav-item">
                <a class="nav-link <?= $subMainActive ?>" href="<?= $base ?>/modules/subscribers/" title="Subscribers">
                    <span class="nav-icon-wrap"><i class="bi bi-people-fill nav-icon"></i></span>
                    <span class="nav-label">Subscribers</span>
                    <?php if ($sbCountSubscribers > 0): ?><span class="sb-count-badge"><?= number_format($sbCountSubscribers) ?></span><?php endif; ?>
                </a>
            </li>
            <?php if (!isReadOnlyUser()): ?>
            <li class="nav-item">
                <a class="nav-link <?= $onSubsPending ? 'active' : '' ?>" href="<?= $base ?>/modules/subscribers/pending" title="Pending">
                    <span class="nav-icon-wrap"><i class="bi bi-hourglass-split nav-icon"></i></span>
                    <span class="nav-label">Application</span>
                    <?php if ($sbCountApplications > 0): ?><span class="sb-count-badge"><?= number_format($sbCountApplications) ?></span><?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $onSubsArchived ? 'active' : '' ?>" href="<?= $base ?>/modules/subscribers/archived" title="Archived">
                    <span class="nav-icon-wrap"><i class="bi bi-archive-fill nav-icon"></i></span>
                    <span class="nav-label">Archived</span>
                    <?php if ($sbCountArchived > 0): ?><span class="sb-count-badge"><?= number_format($sbCountArchived) ?></span><?php endif; ?>
                </a>
            </li>
            <?php endif; ?>
            <?php if (in_array($role, MODULE_ACCESS['plans'])): ?>
            <?= sidebarLink('grid-fill', 'Plans', '/modules/plans/', $currentPath) ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (hasRole(ROLE_LINEMAN, ROLE_ADMIN, ROLE_SUPERADMIN)): ?>
            <?php
            $onInstallations = str_contains($currentPath, '/modules/installations');
            $instQsStatus    = $_GET['status'] ?? '';
            ?>
            <li class="sb-section-label">INSTALLATIONS</li>
            <li class="nav-item">
                <a class="nav-link"
                   data-bs-toggle="collapse" href="#instCollapse" role="button"
                   aria-expanded="<?= $onInstallations ? 'true' : 'false' ?>"
                   aria-controls="instCollapse">
                    <span class="nav-icon-wrap"><i class="bi bi-clipboard2-check nav-icon"></i></span>
                    <span class="nav-label">Installations</span>
                </a>
                <div class="collapse <?= $onInstallations ? 'show' : '' ?>" id="instCollapse">
                    <ul class="nav flex-column" style="padding-left:1.5rem;">
                        <?php
                        $instPending  = $onInstallations && $instQsStatus === 'Pending';
                        $instOngoing  = $onInstallations && $instQsStatus === 'Ongoing';
                        $instApproved = $onInstallations && $instQsStatus === 'Approved';
                        $base = BASE_URL;
                        ?>
                        <li class="nav-item">
                            <a class="nav-link <?= $instPending ? 'active' : '' ?>"
                               href="<?= $base ?>/modules/installations/?status=Pending">
                                <span class="nav-label">Pending</span>
                                <?php if ($sbJobPending > 0): ?>
                                <span class="sb-count-badge"><?= $sbJobPending ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $instOngoing ? 'active' : '' ?>"
                               href="<?= $base ?>/modules/installations/?status=Ongoing">
                                <span class="nav-label">Ongoing</span>
                                <?php if ($sbJobOngoing > 0): ?>
                                <span class="sb-count-badge"><?= $sbJobOngoing ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link <?= $instApproved ? 'active' : '' ?>"
                               href="<?= $base ?>/modules/installations/?status=Approved">
                                <span class="nav-label">Approved</span>
                            </a>
                        </li>
                    </ul>
                </div>
            </li>
            <?php endif; ?>

            <?php if ($canViewPayments || $canViewExpenses): ?>
            <li class="sb-section-label">BILLING</li>
            <?php if ($canViewPayments): ?>
            <?= sidebarLink('cash-coin', 'Payments', '/modules/payments/', $currentPath, null, $sbPaymentsToday) ?>
            <?php endif; ?>
            <?php if ($canViewExpenses): ?>
            <?= sidebarLink('receipt-cutoff', 'Expenses', '/modules/expenses/', $currentPath, null, $sbExpensesToday) ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array($role, MODULE_ACCESS['routers']) || in_array($role, MODULE_ACCESS['bandwidth']) || in_array($role, MODULE_ACCESS['map'])): ?>
            <li class="sb-section-label">NETWORK</li>
            <?php if (in_array($role, MODULE_ACCESS['routers'])): ?>
            <?= sidebarLink('router', 'Routers', '/modules/routers/', $currentPath) ?>
            <?php endif; ?>
            <?php if (in_array($role, MODULE_ACCESS['bandwidth'])): ?>
            <?= sidebarLink('activity', 'Bandwidth', '/modules/bandwidth/', $currentPath) ?>
            <?php endif; ?>
            <?php if (in_array($role, MODULE_ACCESS['map'])): ?>
            <?= sidebarLink('map-fill', 'Map', '/modules/map/', $currentPath) ?>
            <?php endif; ?>
            <?php endif; ?>

            <?php if (in_array($role, MODULE_ACCESS['reports'])): ?>
            <li class="sb-section-label">REPORTS</li>
            <?= sidebarLink('bar-chart-line-fill', 'Reports', '/modules/reports/', $currentPath) ?>
            <?php endif; ?>

            <?php if (in_array($role, MODULE_ACCESS['users']) || in_array($role, MODULE_ACCESS['logs']) || in_array($role, MODULE_ACCESS['notifications']) || in_array($role, MODULE_ACCESS['settings']) || in_array($role, MODULE_ACCESS['cronjobs'] ?? [])): ?>
            <li class="sb-section-label">SETTINGS</li>
            <?php if (in_array($role, MODULE_ACCESS['users'])): ?>
            <?= sidebarLink('person-gear', 'Users', '/modules/users/', $currentPath) ?>
            <?php endif; ?>
            <?php if (in_array($role, MODULE_ACCESS['logs'])): ?>
            <?= sidebarLink('journal-text', 'Activity Log', '/modules/logs/', $currentPath) ?>
            <?php endif; ?>
            <?php if (in_array($role, MODULE_ACCESS['notifications'])): ?>
            <?= sidebarLink('envelope-fill', 'Notifications', '/modules/notifications/', $currentPath) ?>
            <?php endif; ?>
            <?php if (in_array($role, MODULE_ACCESS['settings'])): ?>
            <?= sidebarLink('gear-fill', 'Settings', '/modules/settings/', $currentPath) ?>
            <?php endif; ?>
            <?php if (in_array($role, MODULE_ACCESS['cronjobs'] ?? [])): ?>
            <?= sidebarLink('clock-history', 'Cron Jobs', '/modules/cronjobs/', $currentPath) ?>
            <?php endif; ?>
            <?php endif; ?>

        </ul>
    </nav>


</aside>
