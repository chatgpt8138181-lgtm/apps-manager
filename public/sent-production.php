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

        if (($_POST['action'] ?? '') === 'to_ready') {
            revert_app_to_ready((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App moved back to Ready.');
        }

        if (($_POST['action'] ?? '') === 'to_prepare') {
            revert_app_to_prepare((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App moved back to Prepare Production.');
        }

        if (($_POST['action'] ?? '') === 'to_sent') {
            revert_app_to_sent((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App moved back to Sent.');
        }

        if (($_POST['action'] ?? '') === 'update_details') {
            $appId = (int) ($_POST['app_id'] ?? 0);
            update_production_app_details($appId, $_POST);
            redirect_with('sent-production.php?status=' . urlencode($status) . '&app_id=' . $appId, 'success', 'App details updated.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

function render_sent_apps_table(array $apps, string $status): void
{
    ?>
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
            <?php foreach ($apps as $app): ?>
                <tr>
                    <td><?= h($app['name']) ?></td>
                    <td>
                        <?php if (!empty($app['package_name'])): ?>
                            <div class="console-url">
                                <code><?= h($app['package_name']) ?></code>
                                <button class="btn small copy-url" type="button" data-url="<?= h($app['package_name']) ?>">Copy</button>
                            </div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= render_production_badge($app['status']) ?></td>
                    <td><?= h($app['sent_at'] ?? '—') ?></td>
                    <td><?= h($app['live_at'] ?? '—') ?></td>
                    <td class="actions">
                        <a class="btn small" href="sent-production.php?status=<?= h($status) ?>&app_id=<?= (int) $app['id'] ?>">Manage</a>
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
                        <?php if ($app['status'] === 'sent'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="to_ready">
                                <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                <button class="btn small" type="submit">Back to Ready</button>
                            </form>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="to_prepare">
                                <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                <button class="btn small" type="submit">Back to Prepare</button>
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="to_sent">
                                <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                <button class="btn small" type="submit">Back to Sent</button>
                            </form>
                        <?php endif; ?>
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
    <?php
}

$counts = production_status_counts();
$consoles = all_consoles();
$apps = production_apps_by_status($status);
$unassigned = array_values(array_filter($apps, fn($app) => empty($app['console_id'])));
$tabs = [
    'sent' => 'Sent for Production',
    'live' => 'Live',
    'rejected' => 'Rejected',
    'suspended' => 'Suspended',
];

$selectedId = (int) ($_GET['app_id'] ?? 0);
$selected = null;
if ($selectedId > 0) {
    $selected = get_production_app($selectedId);
    if (!$selected || !in_array($selected['status'], ['sent', 'live', 'rejected', 'suspended'], true)) {
        $selected = null;
    }
}

page_start('Sent for Production');
?>
<?php if ($selected): ?>
    <?php render_app_details_panel($selected, $consoles, 'sent-production.php?status=' . urlencode($status)); ?>
    <?php render_checklist_summary_panel($selected); ?>
<?php else: ?>
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

    <?php if (!$apps): ?>
        <p class="empty block">No apps in this list.</p>
    <?php endif; ?>

    <?php foreach ($consoles as $console): ?>
        <?php
        $consoleApps = array_values(array_filter(
            $apps,
            fn($app) => (int) $app['console_id'] === (int) $console['id']
        ));
        if (!$consoleApps) {
            continue;
        }
        ?>
        <div class="app-group" data-group-key="console-<?= (int) $console['id'] ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span><?= h($console['name']) ?> (<?= count($consoleApps) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <?php render_sent_apps_table($consoleApps, $status); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($unassigned): ?>
        <div class="app-group" data-group-key="no-console">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span>No Console (<?= count($unassigned) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <p class="hint">Assign a Play Console from Production &rarr; Manage &rarr; Verify App Details.</p>
                <?php render_sent_apps_table($unassigned, $status); ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<script>
document.querySelectorAll('.copy-url').forEach((button) => {
    button.addEventListener('click', () => {
        navigator.clipboard.writeText(button.dataset.url).then(() => {
            button.textContent = 'Copied!';
            setTimeout(() => { button.textContent = 'Copy'; }, 1500);
        });
    });
});
</script>
<?php page_end(); ?>
