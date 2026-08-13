<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$status = (string) ($_GET['status'] ?? 'sent');
if (!in_array($status, ['sent', 'live', 'rejected', 'suspended'], true)) {
    $status = 'sent';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'sent-production.php?status=' . urlencode((string) ($_POST['return_status'] ?? 'sent'));

    try {
        if (($_POST['action'] ?? '') === 'set_result') {
            $result = (string) ($_POST['result'] ?? '');
            set_production_result((int) ($_POST['app_id'] ?? 0), $result);
            redirect_with($return, 'success', 'App marked as ' . ucfirst($result) . '.');
        }

        if (($_POST['action'] ?? '') === 'delete') {
            delete_production_app((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App deleted.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

$counts = production_status_counts();
$apps = production_apps_by_status($status);
$tabs = [
    'sent' => 'Sent for Production',
    'live' => 'Live',
    'rejected' => 'Rejected',
    'suspended' => 'Suspended',
];

page_start('Sent for Production');
?>
<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="<?= $status === $key ? 'active' : '' ?>" href="sent-production.php?status=<?= h($key) ?>">
            <?= h($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <div class="panel-heading">
        <h2><?= h($tabs[$status]) ?> (<?= count($apps) ?>)</h2>
        <?php if ($status === 'sent'): ?>
            <span class="hint">Set the Play Console review result for each app.</span>
        <?php elseif ($status === 'live'): ?>
            <a class="btn small" href="live-apps.php">Manage in Live Apps</a>
        <?php endif; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>App Name</th>
                <th>Package</th>
                <th>Status</th>
                <th>Sent At</th>
                <th>Live At</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$apps): ?>
                <tr><td colspan="6" class="empty">No apps in this list.</td></tr>
            <?php endif; ?>
            <?php foreach ($apps as $app): ?>
                <tr>
                    <td><?= h($app['name']) ?></td>
                    <td><?= h($app['package_name'] ?? '—') ?></td>
                    <td><?= render_production_badge($app['status']) ?></td>
                    <td><?= h($app['sent_at'] ?? '—') ?></td>
                    <td><?= h($app['live_at'] ?? '—') ?></td>
                    <td class="actions">
                        <?php foreach (['live' => 'Mark Live', 'rejected' => 'Reject', 'suspended' => 'Suspend'] as $result => $label): ?>
                            <?php if ($app['status'] === $result) continue; ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_result">
                                <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="result" value="<?= h($result) ?>">
                                <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                <button class="btn small <?= $result === 'live' ? 'primary' : ($result === 'rejected' ? 'danger' : '') ?>" type="submit">
                                    <?= h($label) ?>
                                </button>
                            </form>
                        <?php endforeach; ?>
                        <form method="post" onsubmit="return confirm('Delete this app? Its checklist and task history will also be removed.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <input type="hidden" name="return_status" value="<?= h($status) ?>">
                            <button class="btn danger small" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_end(); ?>
