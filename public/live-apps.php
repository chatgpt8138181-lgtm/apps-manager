<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';
        $appId = (int) ($_POST['app_id'] ?? 0);

        if ($action === 'bulk') {
            $result = apply_bulk_production_action(
                (string) ($_POST['bulk_action'] ?? ''),
                (array) ($_POST['app_ids'] ?? [])
            );
            redirect_with('live-apps.php', 'success', bulk_result_message($result, 'updated'));
        }

        if ($action === 'toggle_ready') {
            $ready = (int) ($_POST['ready'] ?? 0) === 1;
            set_ready_for_work($appId, $ready);
            redirect_with(
                'live-apps.php',
                'success',
                $ready ? 'App tagged Ready for Work.' : 'Ready for Work tag removed.',
                ['page' => 'live-apps.php', 'fields' => [
                    'action' => 'toggle_ready',
                    'app_id' => $appId,
                    'ready' => $ready ? 0 : 1,
                ]]
            );
        }

        if ($action === 'delete') {
            delete_production_app($appId);
            redirect_with('live-apps.php', 'success', 'App deleted.');
        }

        if ($action === 'update_details') {
            update_production_app_details($appId, $_POST);
            redirect_with('live-apps.php?app_id=' . $appId, 'success', 'App details updated.');
        }

        if ($action === 'to_sent') {
            revert_app_to_sent($appId);
            redirect_with('live-apps.php', 'success', 'App moved back to Production Apps.');
        }
    } catch (Throwable $e) {
        redirect_with('live-apps.php', 'error', $e->getMessage());
    }
}

function render_live_apps_table(array $apps): void
{
    ?>
    <div class="bulk-form">
    <form method="post" class="bulk-submit" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk">
        <input type="hidden" name="bulk_action" value="">
    </form>
    <?php render_bulk_bar([
        ['value' => 'tag_ready', 'label' => 'Tag Ready for Work', 'class' => 'primary'],
        ['value' => 'untag_ready', 'label' => 'Remove Tag'],
        ['value' => 'to_sent', 'label' => 'Back to Production Apps'],
    ]); ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="col-select"><input type="checkbox" class="bulk-all" aria-label="Select all"></th>
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
                    <td class="col-select"><input type="checkbox" class="bulk-row" value="<?= (int) $app['id'] ?>"></td>
                    <td>
                        <span class="cell-with-icon">
                            <img class="app-icon" src="<?= h(app_icon_url($app['icon_path'] ?? null)) ?>" alt="">
                            <?= h($app['name']) ?>
                        </span>
                    </td>
                    <td><?= h($app['package_name'] ?? '—') ?></td>
                    <td><?= h($app['live_at'] ?? '—') ?></td>
                    <td>
                        <?= $isReady
                            ? '<span class="badge badge-green">Ready for Work</span>'
                            : '<span class="badge badge-gray">Not Tagged</span>' ?>
                    </td>
                    <td class="actions">
                        <a class="btn small" href="app.php?id=<?= (int) $app['id'] ?>">Open</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_ready">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <input type="hidden" name="ready" value="<?= $isReady ? 0 : 1 ?>">
                            <button class="btn small <?= $isReady ? '' : 'primary' ?>" type="submit">
                                <?= $isReady ? 'Remove Tag' : 'Tag Ready' ?>
                            </button>
                        </form>
                        <div class="action-menu-wrap">
                            <button class="btn small action-menu-btn" type="button" aria-label="More actions">&#8942;</button>
                            <div class="action-menu">
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="to_sent">
                                    <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                    <button class="menu-item" type="submit">Back to Production Apps</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Delete this app? Its checklist and task history will also be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                    <button class="menu-item danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
    <?php
}

$consoles = all_consoles();
$listQuery = trim((string) ($_GET['q'] ?? ''));
$listConsole = (int) ($_GET['console'] ?? 0);
/* Stats describe every live app; the list below follows the filters. */
$allLive = production_apps_by_status('live');
$livePage = paginate(filter_production_apps($allLive, $listQuery, $listConsole));
$apps = $livePage['rows'];
$readyCount = count(array_filter($allLive, fn($app) => (int) $app['ready_for_work'] === 1));
$unassigned = array_values(array_filter($apps, fn($app) => empty($app['console_id'])));

$selectedId = (int) ($_GET['app_id'] ?? 0);
$selected = null;
if ($selectedId > 0) {
    $selected = get_production_app($selectedId);
    if (!$selected || $selected['status'] !== 'live') {
        $selected = null;
    }
}

page_start('Live Apps');
?>
<?php if ($selected): ?>
    <?php render_app_details_panel($selected, $consoles, 'live-apps.php'); ?>
    <?php render_checklist_summary_panel($selected); ?>
<?php else: ?>
<section class="stats-grid">
    <div class="stat"><span><?= count($allLive) ?></span><p>Live Apps</p></div>
    <div class="stat"><span><?= $readyCount ?></span><p>Ready for Work</p></div>
    <div class="stat"><span><?= count($allLive) - $readyCount ?></span><p>Not Tagged</p></div>
    <div class="stat"><span><?= count($consoles) ?></span><p>Play Consoles</p></div>
</section>

<?php if (!$consoles): ?>
    <section class="panel">
        <p class="empty block">No Play Consoles yet. <a href="consoles.php"><strong>Add a console</strong></a> first, then assign apps to it from Production.</p>
    </section>
<?php endif; ?>

<section class="panel">
    <?php render_list_filters('live-apps.php', $listQuery, $listConsole, $consoles); ?>
    <div class="panel-heading">
        <h2>Live Apps (<?= (int) $livePage['total'] ?>)</h2>
        <span class="hint">Console is set in Production (Manage). Tag Ready for Work to enter the daily task system.</span>
    </div>

    <?php if (!$apps): ?>
        <?php if ($listQuery !== '' || $listConsole > 0): ?>
            <p class="empty block">No app matches this filter.</p>
        <?php else: ?>
            <p class="empty block">No live apps yet.<br><a class="btn small" href="sent-production.php">See apps waiting for review</a></p>
        <?php endif; ?>
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

    <?php render_pager($livePage, 'live-apps.php', ['q' => $listQuery, 'console' => $listConsole]); ?>
</section>
<?php endif; ?>
<?php page_end(); ?>
