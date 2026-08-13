<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';
        $appId = (int) ($_POST['app_id'] ?? 0);

        if ($action === 'toggle_ready') {
            $ready = (int) ($_POST['ready'] ?? 0) === 1;
            set_ready_for_work($appId, $ready);
            redirect_with('live-apps.php', 'success', $ready ? 'App tagged Ready for Work.' : 'Ready for Work tag removed.');
        }

        if ($action === 'delete') {
            delete_production_app($appId);
            redirect_with('live-apps.php', 'success', 'App deleted.');
        }
    } catch (Throwable $e) {
        redirect_with('live-apps.php', 'error', $e->getMessage());
    }
}

function render_live_apps_table(array $apps): void
{
    ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>App Name</th>
                <th>Package</th>
                <th>Live At</th>
                <th>Ready for Work</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $app): ?>
                <?php $isReady = (int) $app['ready_for_work'] === 1; ?>
                <tr>
                    <td><?= h($app['name']) ?></td>
                    <td><?= h($app['package_name'] ?? '—') ?></td>
                    <td><?= h($app['live_at'] ?? '—') ?></td>
                    <td>
                        <?= $isReady
                            ? '<span class="badge badge-green">Ready for Work</span>'
                            : '<span class="badge badge-gray">Not Tagged</span>' ?>
                    </td>
                    <td class="actions">
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_ready">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <input type="hidden" name="ready" value="<?= $isReady ? 0 : 1 ?>">
                            <button class="btn small <?= $isReady ? '' : 'primary' ?>" type="submit">
                                <?= $isReady ? 'Remove Tag' : 'Tag Ready' ?>
                            </button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this app? Its checklist and task history will also be removed.');">
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
$apps = production_apps_by_status('live');
$readyCount = count(array_filter($apps, fn($app) => (int) $app['ready_for_work'] === 1));
$unassigned = array_values(array_filter($apps, fn($app) => empty($app['console_id'])));

page_start('Live Apps');
?>
<section class="stats-grid">
    <div class="stat"><span><?= count($apps) ?></span><p>Live Apps</p></div>
    <div class="stat"><span><?= $readyCount ?></span><p>Ready for Work</p></div>
    <div class="stat"><span><?= count($apps) - $readyCount ?></span><p>Not Tagged</p></div>
    <div class="stat"><span><?= count($consoles) ?></span><p>Play Consoles</p></div>
</section>

<?php if (!$consoles): ?>
    <section class="panel">
        <p class="empty block">No Play Consoles yet. <a href="consoles.php"><strong>Add a console</strong></a> first, then assign apps to it from Production.</p>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <h2>Live Apps (<?= count($apps) ?>)</h2>
        <span class="hint">Console is set in Production (Manage). Tag Ready for Work to enter the daily task system.</span>
    </div>

    <?php if (!$apps): ?>
        <p class="empty block">No live apps yet.</p>
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
                <?php render_live_apps_table($consoleApps); ?>
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
                <p class="hint">Assign a Play Console from Production &rarr; Manage &rarr; Verify App Details to make these apps taggable.</p>
                <?php render_live_apps_table($unassigned); ?>
            </div>
        </div>
    <?php endif; ?>
</section>
<?php page_end(); ?>
