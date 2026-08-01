        </div><!-- /.container-fluid -->
    </div><!-- /.main-content -->
</div><!-- /.wrapper -->

<?php
if (isLoggedIn() && !selectedRouterId() && currentRole() !== ROLE_SUPERADMIN) {
    $router = defaultRouterForUser(currentUser() ?? []);
    if ($router) setSelectedRouter((int)$router['router_id'], $router['name']);
}
?>
<?php if (isLoggedIn() && selectedRouterId()): ?>
<!-- Router group chat modal -->
<div class="modal fade" id="groupChatModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle flex-shrink-0" style="width:36px;height:36px;">
                        <i class="bi bi-chat-dots text-primary"></i>
                    </div>
                    <div class="min-w-0">
                        <h5 class="modal-title fw-semibold mb-0">Group Chat</h5>
                        <div class="small text-muted text-truncate">
                            <i class="bi bi-router me-1"></i><?= e(selectedRouterName()) ?><span class="mx-1">·</span><span id="groupChatCount">Today</span>
                        </div>
                    </div>
                </div>
                <div class="d-flex align-items-center gap-1 ms-auto">
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="groupChatReload" title="Reload latest 50">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                    <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div id="groupChatMessages" class="nm-chat-list" aria-live="polite">
                    <div class="nm-chat-state">
                        <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
                        <span>Loading messages...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer nm-chat-footer">
                <form id="groupChatForm" class="w-100">
                    <div class="nm-chat-compose">
                        <textarea id="groupChatInput" class="form-control"
                                  rows="1" maxlength="1000" autocomplete="off"
                                  placeholder="Type a message..."></textarea>
                        <button class="btn btn-primary nm-chat-send" type="submit" title="Send message">
                            <i class="bi bi-send"></i>
                        </button>
                    </div>
                    <div class="nm-chat-compose-meta">
                        <span id="groupChatChars">0/1000</span>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- jQuery -->
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<!-- Bootstrap 5 Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<!-- JS globals (must be defined before main.js executes) -->
<script>
window.CSRF_TOKEN        = '<?= csrfToken() ?>';
window.CSRF_TOKEN_NAME   = '_csrf_token';
window.BASE_URL          = '<?= BASE_URL ?>';
window.CURRENT_ROLE      = '<?= currentRole() ?>';
window.CURRENT_USER_ID   = <?= (int)currentUserId() ?>;
window.CURRENCY_SYMBOL   = '<?= e(getSetting('currency_symbol', '₱')) ?>';
window.COMPANY_NAME      = '<?= e(getSetting('company_name', APP_NAME)) ?>';
</script>
<!-- Keep CSRF token in sync after each AJAX call that rotates it -->
<script>
(function () {
    const _fetch = window.fetch;
    window.fetch = function (...args) {
        return _fetch.apply(this, args).then(function (resp) {
            const clone = resp.clone();
            const ct = resp.headers.get('content-type') || '';
            if (ct.includes('application/json')) {
                clone.json().then(function (d) {
                    if (d && d.csrf_token) window.CSRF_TOKEN = d.csrf_token;
                }).catch(() => {});
            }
            return resp;
        });
    };
})();
</script>
<!-- App JS -->
<script src="<?= BASE_URL ?>/assets/js/main.js?v=20260621-chatui2"></script>

<?= $extraScripts ?? '' ?>
</body>
</html>
