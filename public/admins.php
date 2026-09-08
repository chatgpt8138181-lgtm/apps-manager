<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add_admin') {
            add_admin_user(
                (string) ($_POST['username'] ?? ''),
                (string) ($_POST['password'] ?? ''),
                (string) ($_POST['password_confirm'] ?? ''),
                (string) ($_POST['role'] ?? 'operator')
            );
            redirect_with('admins.php', 'success', 'Account added.');
        }

        if ($action === 'set_role') {
            set_admin_role(
                (int) ($_POST['admin_id'] ?? 0),
                (string) ($_POST['role'] ?? ''),
                $currentAdminId
            );
            redirect_with('admins.php', 'success', 'Role changed.');
        }

        if ($action === 'change_own_password') {
            change_own_admin_password(
                $currentAdminId,
                (string) ($_POST['current_password'] ?? ''),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirm'] ?? '')
            );
            redirect_with('admins.php', 'success', 'Your password was changed.');
        }

        if ($action === 'reset_password') {
            update_admin_password(
                (int) ($_POST['admin_id'] ?? 0),
                (string) ($_POST['new_password'] ?? ''),
                (string) ($_POST['new_password_confirm'] ?? '')
            );
            redirect_with('admins.php', 'success', 'Admin password was reset.');
        }

        if ($action === 'delete_admin') {
            delete_admin_user((int) ($_POST['admin_id'] ?? 0), $currentAdminId);
            redirect_with('admins.php', 'success', 'Admin user deleted.');
        }
    } catch (Throwable $e) {
        redirect_with('admins.php', 'error', $e->getMessage());
    }
}

$admins = all_admins();
$roles = role_labels();
$roleNotes = role_descriptions();

/* A timestamp reads as a day, with the time quietly under it. */
function admin_when(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '<span class="cell-sub">Never</span>';
    }

    $time = strtotime($value);
    if ($time === false) {
        return h($value);
    }

    return '<span class="cell-title">' . h(date('d M Y', $time))
        . '<span class="cell-sub">' . h(date('H:i', $time)) . '</span></span>';
}

page_start('Admins');
?>
<section class="admin-grid">
    <div class="form-panel">
        <h2>Add Admin User</h2>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_admin">
            <label>Username
                <input type="text" name="username" maxlength="100" autocomplete="off" required>
            </label>
            <label>Password
                <input type="password" name="password" autocomplete="new-password" required>
            </label>
            <label>Confirm Password
                <input type="password" name="password_confirm" autocomplete="new-password" required>
            </label>
            <label>Role
                <select name="role" id="new-admin-role">
                    <?php foreach ($roles as $key => $label): ?>
                        <option value="<?= h((string) $key) ?>" <?= $key === 'operator' ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="hint role-note" id="new-admin-role-note"><?= h($roleNotes['operator']) ?></p>
            <button class="btn primary" type="submit">Add Account</button>
        </form>
    </div>

    <div class="form-panel">
        <h2>Change My Password</h2>
        <form method="post" class="stacked-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_own_password">
            <label>Current Password
                <input type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label>New Password
                <input type="password" name="new_password" autocomplete="new-password" required>
            </label>
            <label>Confirm New Password
                <input type="password" name="new_password_confirm" autocomplete="new-password" required>
            </label>
            <button class="btn primary" type="submit">Change Password</button>
        </form>
    </div>
</section>

<section class="panel">
    <div class="app-group" data-group-key="role-guide">
        <button class="app-group-toggle" type="button" aria-expanded="false">
            <span>What each role may do</span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="app-group-body">
            <ul class="role-guide">
                <?php foreach ($roles as $key => $label): ?>
                    <li>
                        <span class="badge badge-<?= $key === 'admin' ? 'blue' : ($key === 'viewer' ? 'gray' : 'green') ?>"><?= h($label) ?></span>
                        <span><?= h($roleNotes[$key] ?? '') ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Accounts (<?= count($admins) ?>)</h2>
        <span class="hint">A role says what an account may do. At least one Admin must remain.</span>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th class="col-user">Username</th>
                <th class="col-role">Role</th>
                <th class="col-when">Last login</th>
                <th class="col-when">Created</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <?php $adminId = (int) $admin['id']; ?>
                <tr>
                    <td class="col-user">
                        <span class="cell-title">
                            <?= h($admin['username']) ?>
                            <?php if ($adminId === $currentAdminId): ?>
                                <span class="cell-sub">This is you</span>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td class="col-role">
                        <?php if ($adminId === $currentAdminId): ?>
                            <span class="cell-title">
                                <span class="badge badge-blue"><?= h($roles[$admin['role']] ?? $admin['role']) ?></span>
                                <span class="cell-sub">Cannot change your own</span>
                            </span>
                        <?php else: ?>
                            <form method="post" class="role-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_role">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <select name="role" onchange="this.closest('form').submit()">
                                    <?php foreach ($roles as $key => $label): ?>
                                        <option value="<?= h((string) $key) ?>" <?= $admin['role'] === $key ? 'selected' : '' ?>>
                                            <?= h($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                        <?php endif; ?>
                    </td>
                    <td class="col-when"><?= admin_when($admin['last_login_at'] ?? null) ?></td>
                    <td class="col-when"><?= admin_when($admin['created_at'] ?? null) ?></td>
                    <td class="actions admin-actions">
                        <?php if ($adminId !== $currentAdminId): ?>
                            <form method="post" class="inline-reset-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <input type="password" name="new_password" placeholder="New password" autocomplete="new-password" required>
                                <input type="password" name="new_password_confirm" placeholder="Confirm" autocomplete="new-password" required>
                                <button class="btn small" type="submit">Reset</button>
                            </form>
                            <form method="post" onsubmit="return confirm('Delete this account?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_admin">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <button class="btn danger small" type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="hint">Use Change My Password above. This account cannot delete itself.</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<script>
/* The note under the picker says what the chosen role means. */
(() => {
    const notes = <?= json_encode($roleNotes, JSON_UNESCAPED_UNICODE) ?>;
    const select = document.getElementById('new-admin-role');
    const note = document.getElementById('new-admin-role-note');

    select?.addEventListener('change', () => {
        note.textContent = notes[select.value] || '';
    });
})();
</script>
<?php page_end(); ?>
