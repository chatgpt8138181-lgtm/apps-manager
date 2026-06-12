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
                (string) ($_POST['password_confirm'] ?? '')
            );
            redirect_with('admins.php', 'success', 'Admin user added.');
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
            <button class="btn primary" type="submit">Add Admin</button>
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
    <div class="panel-heading">
        <h2>Admin Users (<?= count($admins) ?>)</h2>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Username</th>
                <th>Created</th>
                <th>Reset Password</th>
                <th>Delete</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($admins as $admin): ?>
                <?php $adminId = (int) $admin['id']; ?>
                <tr>
                    <td>
                        <?= h($admin['username']) ?>
                        <?php if ($adminId === $currentAdminId): ?>
                            <span class="user-pill inline-pill">Current</span>
                        <?php endif; ?>
                    </td>
                    <td><?= h($admin['created_at']) ?></td>
                    <td>
                        <?php if ($adminId !== $currentAdminId): ?>
                            <form method="post" class="inline-reset-form">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <input type="password" name="new_password" placeholder="New password" autocomplete="new-password" required>
                                <input type="password" name="new_password_confirm" placeholder="Confirm" autocomplete="new-password" required>
                                <button class="btn small" type="submit">Reset</button>
                            </form>
                        <?php else: ?>
                            <span class="hint">Use Change My Password</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($adminId !== $currentAdminId): ?>
                            <form method="post" onsubmit="return confirm('Delete this admin user?');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_admin">
                                <input type="hidden" name="admin_id" value="<?= $adminId ?>">
                                <button class="btn danger small" type="submit">Delete</button>
                            </form>
                        <?php else: ?>
                            <span class="hint">Protected</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_end(); ?>
