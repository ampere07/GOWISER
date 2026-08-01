<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($pageTitle ?? 'Dashboard') ?> — <?= e(getSetting('company_name', APP_NAME)) ?></title>

    <!-- Apply saved theme before any CSS renders to prevent flash -->
    <script>(function(){var t=localStorage.getItem('nmTheme')||(window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light');document.documentElement.setAttribute('data-bs-theme',t==='dark'?'dark':'light');document.documentElement.setAttribute('data-theme',t);}());</script>

    <!-- Google Fonts: Inter -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons (self-hosted — no CSP font restriction) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/fonts/bootstrap-icons.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- App CSS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <?= $extraHead ?? '' ?>
</head>
<body>
<?php if (isLoggedIn()): ?>
<div class="wrapper d-flex">
    <?php include BASE_PATH . '/includes/sidebar.php'; ?>

    <div class="main-content flex-grow-1">
        <!-- Top Navbar -->
        <nav class="navbar navbar-expand-lg navbar-dark bg-topbar px-3 sticky-top">
            <button class="btn btn-link text-white sidebar-toggle me-3" id="sidebarToggle">
                <i class="bi bi-list fs-5"></i>
            </button>

            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb" class="d-none d-md-flex me-auto">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item">
                        <a href="<?= BASE_URL ?>/modules/dashboard/" class="text-white-50">
                            <i class="bi bi-house"></i>
                        </a>
                    </li>
                    <?php if (!empty($breadcrumbs)): ?>
                        <?php foreach ($breadcrumbs as $bc): ?>
                            <?php if (!empty($bc['url'])): ?>
                                <li class="breadcrumb-item">
                                    <a href="<?= e($bc['url']) ?>" class="text-white-50"><?= e($bc['label']) ?></a>
                                </li>
                            <?php else: ?>
                                <li class="breadcrumb-item active text-white"><?= e($bc['label']) ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ol>
            </nav>

            <!-- Right side -->
            <?php
                if (!selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
                    $router = defaultRouterForUser(currentUser() ?? []);
                    if ($router) setSelectedRouter((int)$router['router_id'], $router['name']);
                }
                $selRouterId   = selectedRouterId();
                $selRouterName = selectedRouterName();
                $selRouterStatus = null;
                if ($selRouterId) {
                    $selRouterStatus = db()->prepare("SELECT status FROM routers WHERE router_id = ?");
                    $selRouterStatus->execute([$selRouterId]);
                    $selRouterStatus = $selRouterStatus->fetchColumn() ?: 'unknown';
                }
                $dotClass = match($selRouterStatus) {
                    'online'  => 'bg-success',
                    'offline' => 'bg-danger',
                    default   => 'bg-warning',
                };
            ?>
            <div class="d-flex align-items-center gap-2 ms-auto">
                <!-- Router indicator (all roles) -->
                <?php if ($selRouterId): ?>
                <?php if (currentRole() === ROLE_SUPERADMIN): ?>
                <a href="<?= BASE_URL ?>/modules/router_select?switch=1"
                   class="router-indicator d-flex align-items-center gap-2 text-decoration-none"
                   title="Switch router — <?= e(ucfirst($selRouterStatus)) ?>" id="headerRouterLink">
                    <span class="status-dot <?= $dotClass ?>" id="headerRouterDot"></span>
                    <span class="text-white small fw-medium d-none d-md-inline"><?= e($selRouterName) ?></span>
                    <i class="bi bi-arrow-left-right text-white-50" style="font-size:.75rem;"></i>
                </a>
                <?php else: ?>
                <span class="router-indicator d-flex align-items-center gap-2" title="<?= e(ucfirst($selRouterStatus)) ?>">
                    <span class="status-dot <?= $dotClass ?>" id="headerRouterDot"></span>
                    <span class="text-white small fw-medium d-none d-md-inline"><?= e($selRouterName) ?></span>
                </span>
                <?php endif; ?>
                <?php else: ?>
                <?php if (currentRole() === ROLE_SUPERADMIN): ?>
                <a href="<?= BASE_URL ?>/modules/router_select"
                   class="btn btn-sm btn-outline-warning px-2 py-1" style="font-size:.75rem;">
                    <i class="bi bi-router me-1"></i><span class="d-none d-md-inline">Select Router</span>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <!-- Theme toggle -->
                <button class="btn btn-link text-white px-2" id="themeToggle" title="Switch theme" style="font-size:1.1rem;line-height:1;">
                    <i class="bi bi-moon-stars-fill" id="themeIcon"></i>
                </button>

                <!-- Notifications -->
                <div class="dropdown">
                    <button class="btn btn-link text-white position-relative" data-bs-toggle="dropdown"
                            aria-expanded="false" id="notifBtn">
                        <i class="bi bi-bell fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              id="notifBadge" style="display:none;font-size:.6rem;">0</span>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end shadow p-0" style="min-width:300px;max-width:calc(100vw - 1rem);" id="notifDropdown">
                        <div class="px-3 py-2 border-bottom bg-light rounded-top">
                            <span class="fw-semibold small">Notifications</span>
                        </div>
                        <div id="notifList">
                            <div class="text-center py-4 text-muted small">
                                <i class="bi bi-bell-slash d-block fs-4 mb-1"></i>No new notifications
                            </div>
                        </div>
                        <div id="notifFooter" class="border-top px-3 py-2 d-none">
                            <div role="button" style="cursor:pointer;"
                               class="small text-danger text-decoration-none d-flex align-items-center justify-content-between"
                               onclick="window.location.href='<?= BASE_URL ?>/modules/subscribers/?status=expired&_filter=1'">
                                <span><i class="bi bi-people me-1"></i>View all expired</span>
                                <span class="badge bg-danger" id="notifExpiredTotal">0</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Router group chat -->
                <?php if ($selRouterId): ?>
                <button class="btn btn-link text-white position-relative" type="button"
                        data-bs-toggle="modal" data-bs-target="#groupChatModal"
                        id="groupChatBtn" title="Router group chat">
                    <i class="bi bi-chat-dots fs-5"></i>
                </button>
                <?php endif; ?>

                <!-- User menu -->
                <div class="dropdown">
                    <button class="btn btn-link text-white text-decoration-none d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                        <?php $u = currentUser(); ?>
                        <?php if (!empty($u['avatar'])): ?>
                            <img src="<?= e($u['avatar']) ?>" class="rounded-circle" width="32" height="32" alt=""
                                 onerror="this.style.display='none';this.nextElementSibling.style.removeProperty('display');">
                        <?php endif; ?>
                        <div class="avatar-circle bg-primary" <?= !empty($u['avatar']) ? 'style="display:none;"' : '' ?>>
                            <?= strtoupper(substr($u['firstname'] ?? 'U', 0, 1)) ?>
                        </div>
                        <span class="d-none d-md-inline small text-decoration-none">
                            <?= e($u['firstname'] . ' ' . $u['lastname']) ?>
                        </span>
                        <i class="bi bi-chevron-down small"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li class="px-3 py-2 border-bottom">
                            <div class="fw-semibold"><?= e($u['firstname'] . ' ' . $u['lastname']) ?></div>
                            <div class="text-muted small"><?= e(ROLES[$u['role']] ?? ucfirst($u['role'])) ?></div>
                        </li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/users/profile">
                            <i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger logout-confirm" href="<?= BASE_URL ?>/logout">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </nav>

        <!-- Toast container -->
        <div class="toast-container position-fixed bottom-0 end-0 p-3" id="toastContainer" style="z-index:9999;"></div>

        <!-- Flash messages rendered as JSON → picked up by main.js -->
        <?= renderFlash() ?>

        <!-- Page content -->
        <div class="container-fluid px-3 px-md-4 pb-4 pt-3">
<?php endif; ?>
