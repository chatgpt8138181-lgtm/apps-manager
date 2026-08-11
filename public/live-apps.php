<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';
        $appId = (int) ($_POST['app_id'] ?? 0);

        if ($action === 'assign_console') {
            assign_console($appId, (int) ($_POST['console_id'] ?? 0));
            redirect_with('live-apps.php', 'success', 'Console assignment saved.');
        }

        if ($action === 'toggle_ready') {
            $ready = (int) ($_POST['ready'] ?? 0) === 1;
            set_ready_for_work($appId, $ready);
            redirect_with('live-apps.php', 'success', $ready ? 'App tagged Ready for Work.' : 'Ready for Work tag removed.');
        }
    } catch (Throwable $e) {
        redirect_with('live-apps.php', 'error', $e->getMessage());
    }
}

$consoles = all_consoles();
$apps = production_apps_by_status('live');
$readyCount = count(array_filter($apps, fn($app) => (int) $app['ready_for_work'] === 1));

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
        <p class="empty block">No Play Consoles yet. <a href="consoles.php"><strong>Add a console</strong></a> first, then assign live apps to it.</p>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <h2>Live Apps (<?= count($apps) ?>)</h2>
        <span class="hint">Assign a console, then tag Ready for Work to enter the daily task system.</span>
    </div>
    <div class="table-wrap">
        <table class="edit-table">
            <thead>
            <tr>
                <th>App Name</th>
                <th>Package</th>
                <th>Live At</th>
                <th>Play Console</th>
                <th>Ready for Work</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$apps): ?>
                <tr><td colspan="6" class="empty">No live apps yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($apps as $app): ?>
                <?php $isReady = (int) $app['ready_for_work'] === 1; ?>
                <tr>
                    <td><?= h($app['name']) ?></td>
                    <td><?= h($app['package_name'] ?? '—') ?></td>
                    <td><?= h($app['live_at'] ?? '—') ?></td>
                    <td>
                        <form method="post" class="console-assign-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="assign_console">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <select name="console_id">
                                <option value="0">No console</option>
                                <?php foreach ($consoles as $console): ?>
                                    <option value="<?= (int) $console['id'] ?>" <?= (int) $app['console_id'] === (int) $console['id'] ? 'selected' : '' ?>>
                                        <?= h($console['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn small" type="submit">Save</button>
                        </form>
                    </td>
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
                            <button class="btn small <?= $isReady ? 'danger' : 'primary' ?>" type="submit">
                                <?= $isReady ? 'Remove Tag' : 'Tag Ready' ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_end(); ?>
