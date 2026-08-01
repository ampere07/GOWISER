<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_SUPERADMIN);

$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM users WHERE user_id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { flashMessage('danger', 'User not found.'); redirect(BASE_URL . '/modules/users/'); }

// Non-superadmin cannot edit superadmin
if ($user['role'] === ROLE_SUPERADMIN && !hasRole(ROLE_SUPERADMIN)) {
    flashMessage('danger', 'Insufficient permissions.'); redirect(BASE_URL . '/modules/users/');
}

$errors = [];
$routers = routerOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'firstname' => mb_strtoupper(trim($_POST['firstname'] ?? '')),
        'lastname'  => mb_strtoupper(trim($_POST['lastname']  ?? '')),
        'email'     => trim($_POST['email']     ?? ''),
        'role'      => $_POST['role']            ?? $user['role'],
        'router_id' => (int)($_POST['router_id'] ?? 0) ?: null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];
    $newPass  = $_POST['new_password']   ?? '';
    $newPass2 = $_POST['new_password2']  ?? '';

    if (empty($data['firstname']))                           $errors[] = 'First name required.';
    if (empty($data['lastname']))                            $errors[] = 'Last name required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
    if ($newPass && strlen($newPass) < 8)                   $errors[] = 'Password must be at least 8 characters.';
    if ($newPass && $newPass !== $newPass2)                  $errors[] = 'Passwords do not match.';
    if (!array_key_exists($data['role'], ROLES))            $errors[] = 'Invalid role selected.';

    if ($data['role'] === ROLE_SUPERADMIN && !hasRole(ROLE_SUPERADMIN)) {
        $errors[] = 'Cannot assign Super Administrator role.';
    }
    if ($data['role'] !== ROLE_SUPERADMIN && !$data['router_id']) {
        $errors[] = 'Default router is required for Admin, Cashier, User, and Lineman accounts.';
    }

    // Check email uniqueness (exclude current user)
    if (empty($errors)) {
        $ck = db()->prepare("SELECT 1 FROM users WHERE email = ? AND user_id != ?");
        $ck->execute([$data['email'], $id]);
        if ($ck->fetchColumn()) $errors[] = 'Email already in use.';
    }
    if (empty($errors)) {
        $ck = db()->prepare("
            SELECT 1 FROM users
            WHERE UPPER(TRIM(firstname)) = ?
              AND UPPER(TRIM(lastname)) = ?
              AND user_id != ?
            LIMIT 1
        ");
        $ck->execute([$data['firstname'], $data['lastname'], $id]);
        if ($ck->fetchColumn()) $errors[] = 'A user with this first and last name already exists.';
    }

    if (empty($errors)) {
        $hash = $newPass ? password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]) : $user['password'];

        db()->prepare("
            UPDATE users SET firstname=?,lastname=?,email=?,role=?,router_id=?,is_active=?,password=?,updated_at=?
            WHERE user_id=?
        ")->execute([
            $data['firstname'], $data['lastname'], $data['email'],
            $data['role'], $data['router_id'], $data['is_active'], $hash, appNow(), $id,
        ]);
        logActivity(
            'users',
            'update',
            "Updated system user #{$id}: @{$user['username']}. Name {$user['firstname']} {$user['lastname']} -> {$data['firstname']} {$data['lastname']}; email {$user['email']} -> {$data['email']}; role " . (ROLES[$user['role']] ?? $user['role']) . " -> " . (ROLES[$data['role']] ?? $data['role']) . "; router " . ($user['router_id'] ?: 'none') . " -> " . ($data['router_id'] ?: 'none') . "; status " . ($user['is_active'] ? 'active' : 'inactive') . " -> " . ($data['is_active'] ? 'active' : 'inactive') . ($newPass ? '; password was reset.' : '.'),
            null,
            [
                'user_id' => $id,
                'firstname' => $user['firstname'],
                'lastname' => $user['lastname'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'router_id' => $user['router_id'],
                'is_active' => $user['is_active'],
                'password_reset' => false,
            ],
            [
                'user_id' => $id,
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'username' => $user['username'],
                'email' => $data['email'],
                'role' => $data['role'],
                'router_id' => $data['router_id'],
                'is_active' => $data['is_active'],
                'password_reset' => (bool)$newPass,
            ]
        );
        flashMessage('success', 'User updated.');
        redirect(BASE_URL . '/modules/users/');
    }
}

$pageTitle   = 'Edit User';
$breadcrumbs = [['label' => 'Users', 'url' => BASE_URL . '/modules/users/'], ['label' => 'Edit User']];
include BASE_PATH . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Edit User — <?= e($user['firstname'] . ' ' . $user['lastname']) ?></h4>
    <a href="<?= BASE_URL ?>/modules/users/" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>
<?= inlineToasts($errors) ?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0">
            <div class="card-body">
                <form method="POST" novalidate>
                    <?= csrfField() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editFirstname">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="firstname" id="editFirstname" class="form-control text-uppercase" required
                                   value="<?= e($_POST['firstname'] ?? $user['firstname']) ?>"
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editLastname">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="lastname" id="editLastname" class="form-control text-uppercase" required
                                   value="<?= e($_POST['lastname'] ?? $user['lastname']) ?>"
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editUsername">Username</label>
                            <input type="text" id="editUsername" class="form-control" value="<?= e($user['username']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editEmail">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="editEmail" class="form-control" required
                                   value="<?= e($_POST['email'] ?? $user['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editNewPw">New Password
                                <small class="text-muted fw-normal">(blank = keep current)</small>
                            </label>
                            <input type="password" name="new_password" id="editNewPw" class="form-control"
                                   placeholder="Minimum 8 characters">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editNewPw2">Confirm New Password</label>
                            <input type="password" name="new_password2" id="editNewPw2" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRole">Role</label>
                            <select name="role" id="editRole" class="form-select">
                                <?php foreach (ROLES as $val => $label): ?>
                                <?php if ($val === ROLE_SUPERADMIN && !hasRole(ROLE_SUPERADMIN)) continue; ?>
                                <option value="<?= $val ?>" <?= ($_POST['role'] ?? $user['role']) === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="editRouter">Default Router</label>
                            <select name="router_id" id="editRouter" class="form-select">
                                <option value="">— None / Superadmin —</option>
                                <?php foreach ($routers as $router): ?>
                                <?php $selectedRouter = (int)($_POST['router_id'] ?? ($user['router_id'] ?? 0)); ?>
                                <option value="<?= $router['router_id'] ?>" <?= $selectedRouter === (int)$router['router_id'] ? 'selected' : '' ?>>
                                    <?= e($router['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Required for Admin, Cashier, User, and Lineman accounts.</div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                       <?= ($_SERVER['REQUEST_METHOD'] !== 'POST' ? $user['is_active'] : isset($_POST['is_active'])) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Save Changes</button>
                        <a href="<?= BASE_URL ?>/modules/users/" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include BASE_PATH . '/includes/footer.php'; ?>
