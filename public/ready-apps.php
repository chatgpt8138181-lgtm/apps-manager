<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';
        $appId = (int) ($_POST['app_id'] ?? 0);

        if ($action === 'send') {
            send_app_to_production($appId);
            redirect_with('sent-production.php', 'success', 'App sent for production.');
        }

        if ($action === 'delete') {
            delete_production_app($appId);
            redirect_with('ready-apps.php', 'success', 'App deleted.');
        }

        if ($action === 'update_details') {
            update_production_app_details($appId, $_POST);
            redirect_with('ready-apps.php?app_id=' . $appId, 'success', 'App details updated.');
        }

        if ($action === 'to_prepare') {
            revert_app_to_prepare($appId);
            redirect_with('ready-apps.php', 'success', 'App moved back to Prepare Production.');
        }
    } catch (Throwable $e) {
        redirect_with('ready-apps.php', 'error', $e->getMessage());
    }
}

function render_ready_apps_table(array $apps): void
{
    ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>App Name</th>
                <th>Package</th>
                <th>Status</th>
                <th>Created</th>
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
                    <td><?= h($app['created_at']) ?></td>
                    <td class="actions">
                        <a class="btn small" href="ready-apps.php?app_id=<?= (int) $app['id'] ?>">Manage</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="to_prepare">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <button class="btn small" type="submit">Back to Prepare</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <button class="btn small primary" type="submit">Send for Production</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this app? Its checklist will also be removed.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
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

$consoles = all_consoles();
$apps = production_apps_by_status('ready');
$unassigned = array_values(array_filter($apps, fn($app) => empty($app['console_id'])));

$selectedId = (int) ($_GET['app_id'] ?? 0);
$selected = null;
if ($selectedId > 0) {
    $selected = get_production_app($selectedId);
    if (!$selected || $selected['status'] !== 'ready') {
        $selected = null;
    }
}

page_start('Ready for Production');
?>
<?php if ($selected): ?>
    <?php render_app_details_panel($selected, $consoles, 'ready-apps.php'); ?>
    <?php render_checklist_summary_panel($selected); ?>
<?php else: ?>
<section class="panel">
    <div class="panel-heading">
        <h2>Ready for Production (<?= count($apps) ?>)</h2>
        <span class="hint">Checklist-complete apps waiting to be sent. Send each app when its console is ready.</span>
    </div>

    <?php if (!$apps): ?>
        <p class="empty block">No apps are marked Ready for Production. Complete a checklist in Production, then use "Ready for Production".</p>
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
                <?php render_ready_apps_table($consoleApps); ?>
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
                <?php render_ready_apps_table($unassigned); ?>
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
