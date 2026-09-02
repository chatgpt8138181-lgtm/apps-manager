<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$view = in_array($_GET['view'] ?? '', ['all', 'history'], true) ? $_GET['view'] : 'today';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'active-apps.php' . ($view !== 'today' ? '?view=' . urlencode($view) : '');

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

        if ($action === 'cycle_step') {
            $direction = (string) ($_POST['direction'] ?? 'restart');
            $categoryId = (int) ($_POST['category_id'] ?? 0);
            shift_category_cycle($categoryId, $direction);
            $messages = [
                'next' => 'Console moved to the next cycle.',
                'previous' => 'Console moved back to the previous cycle.',
                'restart' => 'Console restarted from its first app on Cycle 1.',
            ];
            $opposite = ['next' => 'previous', 'previous' => 'next'];
            $undo = isset($opposite[$direction])
                ? ['page' => 'active-apps.php', 'fields' => [
                    'action' => 'cycle_step',
                    'direction' => $opposite[$direction],
                    'category_id' => $categoryId,
                ]]
                : null;
            redirect_with('active-apps.php', 'success', $messages[$direction] ?? $messages['restart'], $undo);
        }

        if ($action === 'new_cycle') {
            start_new_loading_cycle();
            redirect_with('active-apps.php', 'success', 'Rotation restarted from the first app of every console.');
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
$allActivePage = paginate($allActive);
$allActiveRows = $allActivePage['rows'];
$historyGroups = $view === 'history' ? loading_history() : [];

page_start($view === 'all' ? 'All Active Apps' : ($view === 'history' ? 'Loading History' : 'Active Apps'));
?>
<div class="tabs">
    <a class="<?= $view === 'today' ? 'active' : '' ?>" href="active-apps.php">Today's Apps</a>
    <a class="<?= $view === 'all' ? 'active' : '' ?>" href="active-apps.php?view=all">All Active</a>
    <a class="<?= $view === 'history' ? 'active' : '' ?>" href="active-apps.php?view=history">History</a>
</div>

<?php if ($view === 'today'): ?>
    <section class="stats-grid">
        <div class="stat"><span><?= (int) $progress['apps_per_day'] ?></span><p>Apps per Day</p></div>
        <div class="stat"><span><?= (int) $progress['eligible'] ?></span><p>Active Apps</p></div>
        <div class="stat"><span><?= (int) $progress['shown'] ?></span><p>Shown This Cycle</p></div>
        <div class="stat"><span><?= (int) $progress['remaining'] ?></span><p>Remaining</p></div>
    </section>

    <section class="form-panel">
        <div class="panel-heading">
            <h2>Rotation Settings</h2>
            <span class="hint">Each console runs its own cycle.</span>
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
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="new_cycle">
                <button class="btn" type="submit">Restart All Consoles</button>
            </form>
        </div>
        <p class="hint">Every day each console shows the next <?= (int) $progress['apps_per_day'] ?> active app(s). Apps never repeat within a cycle. When a console has shown all of its apps it starts again from its first app automatically, and Restart All Consoles does that for every console right away.</p>
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
                    Set apps to Active on the Dashboard to start the rotation.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php foreach ($todayGroups as $categoryId => $group): ?>
            <?php $doneCount = count(array_filter($group['apps'], fn($app) => (int) $app['is_done'] === 1)); ?>
            <div class="app-group" data-group-key="cat-<?= (int) $categoryId ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span><?= h($group['name']) ?> (<?= $doneCount ?>/<?= count($group['apps']) ?> done) &middot; Cycle <?= display_category_cycle((int) $categoryId) ?></span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                    <div class="inline-actions bulk-status-row">
                        <span class="hint">This console:</span>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cycle_step">
                            <input type="hidden" name="direction" value="previous">
                            <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">
                            <button class="btn small" type="submit">&laquo; Previous Cycle</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cycle_step">
                            <input type="hidden" name="direction" value="restart">
                            <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">
                            <button class="btn small" type="submit">Restart</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cycle_step">
                            <input type="hidden" name="direction" value="next">
                            <input type="hidden" name="category_id" value="<?= (int) $categoryId ?>">
                            <button class="btn small" type="submit">Next Cycle &raquo;</button>
                        </form>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>App Icon</th>
                                <th>App Name</th>
                                <th>Ready Loading</th>
                                <th>Status</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['apps'] as $task): ?>
                                <?php $isDone = (int) $task['is_done'] === 1; ?>
                                <tr>
                                    <td><img class="app-icon" src="<?= h(app_icon_url($task['icon_path'])) ?>" alt=""></td>
                                    <td>
                                        <span class="cell-title">
                                            <?= h($task['app_name']) ?>
                                            <span class="cell-sub">#<?= (int) $task['app_id'] ?></span>
                                        </span>
                                    </td>
                                    <td><?= render_status_badge($task['ready_loading_status']) ?></td>
                                    <td>
                                        <form method="post">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle_done">
                                            <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                            <label class="done-toggle">
                                                <input type="checkbox" <?= $isDone ? 'checked' : '' ?> onchange="this.closest('form').submit()">
                                                <?= $isDone
                                                    ? '<span class="badge badge-green">Done</span>'
                                                    : '<span class="badge badge-amber">Pending</span>' ?>
                                            </label>
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
<?php elseif ($view === 'all'): ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>All Active Apps (<?= (int) $allActivePage['total'] ?>)</h2>
            <span class="hint">Every Active app, console wise.</span>
        </div>

        <?php if (!$allActive): ?>
            <p class="empty block">No active apps found.</p>
        <?php endif; ?>

        <?php foreach ($categories as $category): ?>
            <?php
            $categoryApps = array_values(array_filter(
                $allActiveRows,
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
                                    <td>#<?= (int) $app['id'] ?></td>
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
        <?php render_pager($allActivePage, 'active-apps.php', ['view' => 'all']); ?>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>Loading History</h2>
            <span class="hint">Month-wise record of every rotated app, open a month to see its days.</span>
        </div>

        <?php if (!$historyGroups): ?>
            <p class="empty block">No history yet.</p>
        <?php endif; ?>

        <?php foreach ($historyGroups as $month): ?>
            <div class="app-group" data-group-key="month-<?= h((string) $month['key']) ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span><?= h($month['label']) ?> (<?= (int) $month['done'] ?>/<?= (int) $month['total'] ?> done)</span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                    <?php foreach ($month['days'] as $day): ?>
                        <div class="app-group" data-group-key="date-<?= h((string) $day['date']) ?>">
                            <button class="app-group-toggle" type="button" aria-expanded="false">
                                <span><?= h($day['label']) ?> (<?= (int) $day['done'] ?>/<?= (int) $day['total'] ?> done)</span>
                                <span class="nav-chevron" aria-hidden="true"></span>
                            </button>
                            <div class="app-group-body">
                                <div class="table-wrap">
                                    <table>
                                        <thead>
                                        <tr>
                                            <th>Console</th>
                                            <th>App Icon</th>
                                            <th>App Name</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($day['rows'] as $task): ?>
                                            <tr>
                                                <td><?= h($task['category_name']) ?></td>
                                                <td><img class="app-icon" src="<?= h(app_icon_url($task['icon_path'])) ?>" alt=""></td>
                                                <td>
                                                    <span class="cell-title">
                                                        <?= h($task['app_name']) ?>
                                                        <span class="cell-sub">#<?= (int) $task['app_id'] ?></span>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?= (int) $task['is_done'] === 1
                                                        ? '<span class="badge badge-green">Done</span>'
                                                        : '<span class="badge badge-amber">Pending</span>' ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php page_end(); ?>
