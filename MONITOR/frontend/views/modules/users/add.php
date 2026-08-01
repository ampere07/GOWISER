<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
requireRole(ROLE_SUPERADMIN);

$errors = [];
$routers = routerOptions();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $data = [
        'firstname' => mb_strtoupper(trim($_POST['firstname'] ?? '')),
        'lastname'  => mb_strtoupper(trim($_POST['lastname']  ?? '')),
        'username'  => trim($_POST['username']  ?? ''),
        'email'     => trim($_POST['email']     ?? ''),
        'password'  => $_POST['password']        ?? '',
        'password2' => $_POST['password2']       ?? '',
        'role'      => $_POST['role']            ?? 'user',
        'router_id' => (int)($_POST['router_id'] ?? 0) ?: null,
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    ];

    if (empty($data['firstname']))                           $errors[] = 'First name required.';
    if (empty($data['lastname']))                            $errors[] = 'Last name required.';
    if (empty($data['username']))                            $errors[] = 'Username required.';
    if (empty($data['email']))                               $errors[] = 'Email required.';
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
    if (strlen($data['password']) < 8)                      $errors[] = 'Password must be at least 8 characters.';
    if ($data['password'] !== $data['password2'])           $errors[] = 'Passwords do not match.';
    if (!array_key_exists($data['role'], ROLES))            $errors[] = 'Invalid role selected.';

    // Only superadmin can create superadmin
    if ($data['role'] === ROLE_SUPERADMIN && !hasRole(ROLE_SUPERADMIN)) {
        $errors[] = 'Insufficient permission to create Super Administrator.';
    }
    if ($data['role'] !== ROLE_SUPERADMIN && !$data['router_id']) {
        $errors[] = 'Default router is required for Admin, Cashier, User, and Lineman accounts.';
    }

    // Check unique username/email
    if (empty($errors)) {
        $ck = db()->prepare("SELECT 1 FROM users WHERE username = ? OR email = ?");
        $ck->execute([$data['username'], $data['email']]);
        if ($ck->fetchColumn()) $errors[] = 'Username or email already exists.';
    }
    if (empty($errors)) {
        $ck = db()->prepare("SELECT 1 FROM users WHERE UPPER(TRIM(firstname)) = ? AND UPPER(TRIM(lastname)) = ? LIMIT 1");
        $ck->execute([$data['firstname'], $data['lastname']]);
        if ($ck->fetchColumn()) $errors[] = 'A user with this first and last name already exists.';
    }

    if (empty($errors)) {
        $hash = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        db()->prepare("
            INSERT INTO users (firstname, lastname, username, email, password, role, router_id, is_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([
            $data['firstname'], $data['lastname'], $data['username'],
            $data['email'], $hash, $data['role'], $data['router_id'],
            $data['is_active'], appNow(),
        ]);
        $newUserId = (int)db()->lastInsertId();
        logActivity(
            'users',
            'create',
            "Created system user #{$newUserId}: {$data['firstname']} {$data['lastname']} (@{$data['username']}), email {$data['email']}, role " . (ROLES[$data['role']] ?? $data['role']) . ", default router " . ($data['router_id'] ?: 'none') . ", status " . ($data['is_active'] ? 'active' : 'inactive') . ".",
            null,
            null,
            [
                'user_id' => $newUserId,
                'firstname' => $data['firstname'],
                'lastname' => $data['lastname'],
                'username' => $data['username'],
                'email' => $data['email'],
                'role' => $data['role'],
                'router_id' => $data['router_id'],
                'is_active' => $data['is_active'],
            ]
        );
        flashMessage('success', "User '{$data['username']}' created.");
        redirect(BASE_URL . '/modules/users/');
    }
}

$pageTitle   = 'Add User';
$breadcrumbs = [['label' => 'Users', 'url' => BASE_URL . '/modules/users/'], ['label' => 'Add User']];
include BASE_PATH . '/includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <h4 class="fw-bold mb-0">Add System User</h4>
    <a href="./" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
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
                            <label class="form-label fw-medium" for="addFirstname">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="firstname" id="addFirstname" class="form-control text-uppercase" required
                                   value="<?= e($_POST['firstname'] ?? '') ?>"
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="addLastname">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="lastname" id="addLastname" class="form-control text-uppercase" required
                                   value="<?= e($_POST['lastname'] ?? '') ?>"
                                   autocapitalize="characters" spellcheck="false">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="addUsername">Username <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input type="text" name="username" id="addUsername" class="form-control" required
                                       value="<?= e($_POST['username'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="addEmail">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" id="addEmail" class="form-control" required
                                   value="<?= e($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="addPassword">Password <span class="text-danger">*</span></label>
                            <input type="password" name="password" id="addPassword" class="form-control"
                                   minlength="8" required placeholder="Min. 8 characters">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="addPassword2">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="password2" id="addPassword2" class="form-control"
                                   minlength="8" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="addRole">Role <span class="text-danger">*</span></label>
                            <select name="role" id="addRole" class="form-select">
                                <?php foreach (ROLES as $val => $label): ?>
                                <?php if ($val === ROLE_SUPERADMIN && !hasRole(ROLE_SUPERADMIN)) continue; ?>
                                <option value="<?= $val ?>" <?= ($_POST['role'] ?? 'user') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-medium" for="addRouter">Default Router</label>
                            <select name="router_id" id="addRouter" class="form-select">
                                <option value="">— None / Superadmin —</option>
                                <?php foreach ($routers as $router): ?>
                                <option value="<?= $router['router_id'] ?>" <?= (int)($_POST['router_id'] ?? 0) === (int)$router['router_id'] ? 'selected' : '' ?>>
                                    <?= e($router['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Required for Admin, Cashier, User, and Lineman accounts.</div>
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                       <?= ($_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['is_active'])) ? 'checked' : '' ?>>
                                <label class="form-check-label fw-medium" for="isActive">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-person-plus me-1"></i>Create User
                        </button>
                        <a href="./" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include BASE_PATH . '/includes/footer.php'; ?>
