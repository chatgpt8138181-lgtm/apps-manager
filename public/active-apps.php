<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$view = ($_GET['view'] ?? '') === 'all' ? 'all' : 'today';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'active-apps.php' . ($view === 'all' ? '?view=all' : '');

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_done') {
            toggle_loading_done((int) ($_POST['task_id'] ?? 0));
            redirect_with($return, 'success', 'App status updated.');
        }

        if ($action === 'save_settings') {
            update_loading_apps_per_day((int) ($_POST['apps_per_day'] ?? 0));
            redirect_with('active-apps.php', 'success', 'Settings saved. New quota applies from the next generated day.');
        }

        if ($action === 'new_cycle') {
            start_new_loading_cycle();
            redirect_with('active-apps.php', 'success', 'New cycle started. All active apps are eligible again.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

if ($view === 'today') {
    $generated = generate_loading_daily();
}

$progress = loading_cycle_progress();
$todayGroups = $view === 'today' ? todays_loading_apps() : [];
$todayTotal = 0;
foreach ($todayGroups as $group) {
    $todayTotal += count($group['apps']);
}

$categories = all_categories();
$allActive = $view === 'all'
    ? array_values(array_filter(sorted_apps(), fn($app) => $app['loading_status'] === 'Active'))
    : [];

page_start($view === 'all' ? 'All Active Apps' : 'Active Apps');
?>
<div class="tabs">
    <a class="<?= $view === 'today' ? 'active' : '' ?>" href="active-apps.php">Today's Apps</a>
    <a class="<?= $view === 'all' ? 'active' : '' ?>" href="active-apps.php?view=all">All Active</a>
</div>

<?php if ($view === 'today'): ?>
    <section class="stats-grid cycle-grid">
        <div class="stat"><span><?= (int) $progress['cycle_no'] ?></span><p>Current Cycle</p></div>
        <div class="stat"><span><?= (int) $progress['apps_per_day'] ?></span><p>Apps per Day</p></div>
        <div class="stat"><span><?= (int) $progress['eligible'] ?></span><p>Active Apps</p></div>
        <div class="stat"><span><?= (int) $progress['shown'] ?></span><p>Shown This Cycle</p></div>
        <div class="stat"><span><?= (int) $progress['remaining'] ?></span><p>Remaining</p></div>
    </section>

    <section class="form-panel">
        <div class="panel-heading">
            <h2>Rotation Settings</h2>
            <?php if ($progress['complete']): ?>
                <span class="badge badge-green">Cycle Complete</span>
            <?php endif; ?>
        </div>
        <div class="inline-actions cycle-controls">
            <form method="post" class="inline-form cycle-days-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <label>Apps per Day (per console)
                    <input type="number" name="apps_per_day" value="<?= (int) $progress['apps_per_day'] ?>" min="1" max="100" required>
                </label>
                <button class="btn primary" type="submit">Save</button>
            </form>
            <?php if ($progress['complete']): ?>
                <form method="post" onsubmit="return confirm('Start a new cycle? All active apps become eligible again.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="new_cycle">
                    <button class="btn primary" type="submit">Start New Cycle</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="hint">Every day each console shows the next <?= (int) $progress['apps_per_day'] ?> active app(s). Apps never repeat within a cycle.</p>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <h2>Today's Apps — <?= h(date('d M Y')) ?> (<?= (int) $todayTotal ?> apps)</h2>
            <?php if (!empty($generated)): ?>
                <span class="hint">Generated <?= (int) $generated ?> app(s) for today.</span>
            <?php endif; ?>
        </div>

        <?php if (!$todayGroups): ?>
            <p class="empty block">
                No apps for today.
                <?php if ($progress['eligible'] === 0): ?>
                    Set apps to Active in Search/Edit to start the rotation.
                <?php elseif ($progress['complete']): ?>
                    The cycle is complete — start a new cycle to continue.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php foreach ($todayGroups as $categoryId => $group): ?>
            <?php $doneCount = count(array_filter($group['apps'], fn($app) => (int) $app['is_done'] === 1)); ?>
            <div class="app-group" data-group-key="cat-<?= (int) $categoryId ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span><?= h($group['name']) ?> (<?= $doneCount ?>/<?= count($group['apps']) ?> done)</span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>App Icon</th>
                                <th>App Name</th>
                                <th>Ready Loading</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['apps'] as $task): ?>
                                <?php $isDone = (int) $task['is_done'] === 1; ?>
                                <tr>
                                    <td><img class="app-icon" src="<?= h(app_icon_url($task['icon_path'])) ?>" alt=""></td>
                                    <td><?= h($task['app_name']) ?></td>
                                    <td><?= render_status_badge($task['ready_loading_status']) ?></td>
                                    <td>
                                        <?= $isDone
                                            ? '<span class="badge badge-green">Done</span>'
                                            : '<span class="badge badge-amber">Pending</span>' ?>
                                    </td>
                                    <td>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_done">
                                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                            <button class="btn small <?= $isDone ? '' : 'primary' ?>" type="submit">
                                                <?= $isDone ? 'Mark Pending' : 'Mark Done' ?>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>All Active Apps (<?= count($allActive) ?>)</h2>
            <span class="hint">Every Active app, console wise.</span>
        </div>

        <?php if (!$allActive): ?>
            <p class="empty block">No active apps found.</p>
        <?php endif; ?>

        <?php foreach ($categories as $category): ?>
            <?php
            $categoryApps = array_values(array_filter(
                $allActive,
                fn($app) => (int) $app['category_id'] === (int) $category['id']
            ));
            if (!$categoryApps) {
                continue;
            }
            ?>
            <div class="app-group" data-group-key="cat-<?= (int) $category['id'] ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span><?= h($category['name']) ?> (<?= count($categoryApps) ?>)</span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>ID</th>
                                <th>App Icon</th>
                                <th>App Name</th>
                                <th>Ready Loading</th>
                                <th>Created</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($categoryApps as $app): ?>
                                <tr>
                                    <td><?= (int) $app['display_id'] ?></td>
                                    <td><img class="app-icon" src="<?= h(app_icon_url($app['icon_path'])) ?>" alt=""></td>
                                    <td><?= h($app['app_name']) ?></td>
                                    <td><?= render_status_badge($app['ready_loading_status']) ?></td>
                                    <td><?= h($app['created_at']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php page_end(); ?>
