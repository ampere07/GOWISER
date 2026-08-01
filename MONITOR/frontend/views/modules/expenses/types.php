<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_ADMIN, ROLE_SUPERADMIN);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/settings/?tab=expense_types');
}

$errors  = [];
$success = '';

// ── Add new type ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_type'])) {
    verifyCsrf();
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '') ?: null;
    if ($name === '') {
        $errors[] = 'Type name is required.';
    } else {
        $ck = db()->prepare("SELECT 1 FROM expense_types WHERE name = ?");
        $ck->execute([$name]);
        if ($ck->fetchColumn()) {
            $errors[] = 'An expense type with this name already exists.';
        } else {
            db()->prepare("INSERT INTO expense_types (name, description) VALUES (?,?)")
                ->execute([$name, $desc]);
            logActivity('expenses', 'type_create', "Created expense type: {$name}");
            flashMessage('success', "Type '{$name}' added.");
            redirect(BASE_URL . '/modules/expenses/types');
        }
    }
}

// ── Toggle active ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_type'])) {
    verifyCsrf();
    $tid = (int)($_POST['type_id'] ?? 0);
    if ($tid) {
        $row = db()->prepare("SELECT is_active FROM expense_types WHERE type_id = ?");
        $row->execute([$tid]);
        $row = $row->fetch();
        if ($row) {
            $new = $row['is_active'] ? 0 : 1;
            db()->prepare("UPDATE expense_types SET is_active = ? WHERE type_id = ?")
                ->execute([$new, $tid]);
            flashMessage('success', 'Type status updated.');
        }
    }
    redirect(BASE_URL . '/modules/expenses/types');
}

// ── Edit type (inline POST) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_type'])) {
    verifyCsrf();
    $tid  = (int)($_POST['type_id']     ?? 0);
    $name = trim($_POST['edit_name']    ?? '');
    $desc = trim($_POST['edit_desc']    ?? '') ?: null;
    if (!$tid || $name === '') {
        flashMessage('danger', 'Name is required.');
    } else {
        $ck = db()->prepare("SELECT 1 FROM expense_types WHERE name = ? AND type_id != ?");
        $ck->execute([$name, $tid]);
        if ($ck->fetchColumn()) {
            flashMessage('danger', 'Name already in use by another type.');
        } else {
            db()->prepare("UPDATE expense_types SET name=?, description=? WHERE type_id=?")
                ->execute([$name, $desc, $tid]);
            logActivity('expenses', 'type_update', "Updated expense type ID {$tid}: {$name}");
            flashMessage('success', 'Type updated.');
        }
    }
    redirect(BASE_URL . '/modules/expenses/types');
}

// ── Delete type ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_type'])) {
    requireMinRole(ROLE_SUPERADMIN);
    verifyCsrf();
    $tid = (int)($_POST['type_id'] ?? 0);
    if ($tid) {
        $used = db()->prepare("SELECT COUNT(*) FROM expenses WHERE expense_type_id = ?");
        $used->execute([$tid]);
        if ((int)$used->fetchColumn() > 0) {
            flashMessage('danger', 'Cannot delete: this type is used by existing expenses. Deactivate it instead.');
        } else {
            db()->prepare("DELETE FROM expense_types WHERE type_id = ?")->execute([$tid]);
            logActivity('expenses', 'type_delete', "Deleted expense type ID {$tid}");
            flashMessage('success', 'Type deleted.');
        }
    }
    redirect(BASE_URL . '/modules/expenses/types');
}

$types = db()->query("SELECT et.*, (SELECT COUNT(*) FROM expenses e WHERE e.expense_type_id = et.type_id) AS usage_count
                      FROM expense_types et ORDER BY et.name")->fetchAll();

$pageTitle   = 'Expense Types';
$breadcrumbs = [
    ['label' => 'Expenses', 'url' => BASE_URL . '/modules/expenses/'],
    ['label' => 'Expense Types'],
];
include BASE_PATH . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0">Expense Types</h4>
        <p class="text-muted small mb-0"><?= count($types) ?> type<?= count($types) !== 1 ? 's' : '' ?> configured</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/expenses/" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Expenses
    </a>
</div>

<?= inlineToasts($errors) ?>

<div class="row g-4">

    <!-- Add Type Form -->
    <div class="col-lg-4">
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3">
                <i class="bi bi-plus-circle me-1 text-primary"></i>Add Expense Type
            </div>
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="newTypeName">Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="newTypeName" class="form-control" required
                               placeholder="e.g. Office Supplies, Utilities"
                               value="<?= e($_POST['name'] ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium" for="newTypeDesc">Description</label>
                        <input type="text" name="description" id="newTypeDesc" class="form-control"
                               placeholder="Optional description"
                               value="<?= e($_POST['description'] ?? '') ?>">
                    </div>
                    <button type="submit" name="add_type" class="btn btn-primary w-100">
                        <i class="bi bi-plus-lg me-1"></i>Add Type
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Types List -->
    <div class="col-lg-8">
        <div class="card border-0">
            <div class="card-header bg-transparent border-bottom fw-semibold py-3 d-flex justify-content-between align-items-center">
                <span><i class="bi bi-tags me-1"></i>All Types</span>
                <span class="badge bg-secondary"><?= count($types) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($types)): ?>
                <div class="text-center text-muted py-5">
                    <i class="bi bi-tags fs-2 d-block mb-2"></i>No expense types yet.
                </div>
                <?php else: ?>
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Description</th>
                            <th class="text-center">Used</th>
                            <th class="text-center">Status</th>
                            <th class="pe-3 text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($types as $t): ?>
                        <tr id="type-row-<?= $t['type_id'] ?>">
                            <!-- View mode -->
                            <td class="ps-3 fw-medium small" id="td-name-<?= $t['type_id'] ?>"><?= e($t['name']) ?></td>
                            <td class="text-muted small" id="td-desc-<?= $t['type_id'] ?>"><?= $t['description'] ? e($t['description']) : '—' ?></td>
                            <td class="text-center">
                                <span class="badge bg-secondary-subtle text-secondary border"><?= $t['usage_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($t['is_active']): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="pe-3 text-end text-nowrap">
                                <div class="d-flex gap-1 justify-content-end">
                                <!-- Edit inline -->
                                <button type="button" class="btn btn-sm btn-outline-secondary"
                                        onclick="editTypeRow(<?= $t['type_id'] ?>, <?= json_encode($t['name']) ?>, <?= json_encode($t['description'] ?? '') ?>)"
                                        title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <!-- Toggle -->
                                <form method="POST" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="type_id" value="<?= $t['type_id'] ?>">
                                    <button name="toggle_type"
                                            class="btn btn-sm <?= $t['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                                            title="<?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <i class="bi <?= $t['is_active'] ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                                    </button>
                                </form>
                                <?php if (hasRole(ROLE_SUPERADMIN) && $t['usage_count'] == 0): ?>
                                <button type="button" class="btn btn-sm btn-outline-danger btn-delete-type"
                                        title="Delete"
                                        data-id="<?= $t['type_id'] ?>"
                                        data-name="<?= e(htmlspecialchars($t['name'], ENT_QUOTES)) ?>">
                                    <i class="bi bi-trash3"></i>
                                </button>
                                <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Inline Edit Modal -->
<div class="modal fade" id="editTypeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="type_id" id="editTypeId">
                <div class="modal-header">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center bg-primary-subtle" style="width:36px;height:36px;flex-shrink:0;">
                            <i class="bi bi-pencil-square text-primary"></i>
                        </div>
                        <h5 class="modal-title fw-semibold mb-0">Edit Expense Type</h5>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Name <span class="text-danger">*</span></label>
                        <input type="text" name="edit_name" id="editTypeName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-medium">Description</label>
                        <input type="text" name="edit_desc" id="editTypeDesc" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="edit_type" class="btn btn-primary fw-semibold">
                        <i class="bi bi-check-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden delete form -->
<form method="POST" id="deleteTypeForm" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="type_id" id="deleteTypeId">
    <button name="delete_type" type="submit"></button>
</form>

<?php
$extraScripts = <<<'JS'
<script>
function editTypeRow(id, name, desc) {
    document.getElementById('editTypeId').value   = id;
    document.getElementById('editTypeName').value = name;
    document.getElementById('editTypeDesc').value = desc;
    new bootstrap.Modal(document.getElementById('editTypeModal')).show();
}

document.querySelectorAll('.btn-delete-type').forEach(btn => {
    btn.addEventListener('click', function () {
        const id   = this.dataset.id;
        const name = this.dataset.name;
        Swal.fire({
            title: 'Delete Expense Type?',
            html: `<div class="text-start">
                <div class="mb-3 p-2 bg-light rounded small fw-medium">${name}</div>
                <div class="alert alert-danger py-2 small mb-0">
                    This action <strong>cannot be undone</strong>.
                </div>
            </div>`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-trash3 me-1"></i>Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#dc3545',
            reverseButtons: true,
        }).then(result => {
            if (!result.isConfirmed) return;
            document.getElementById('deleteTypeId').value = id;
            document.getElementById('deleteTypeForm').submit();
        });
    });
});
</script>
JS;
include BASE_PATH . '/includes/footer.php';
?>
