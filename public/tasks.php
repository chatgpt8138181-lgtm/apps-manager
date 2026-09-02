<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$view = ($_GET['view'] ?? '') === 'history' ? 'history' : 'today';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'tasks.php' . ($view === 'history' ? '?view=history' : '');

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'toggle_done') {
            toggle_task_done((int) ($_POST['task_id'] ?? 0));
            redirect_with($return, 'success', 'Task status updated.');
        }

        if ($action === 'save_settings') {
            update_cycle_days((int) ($_POST['cycle_days'] ?? 0));
            redirect_with('tasks.php', 'success', 'Cycle settings saved. New quotas apply from the next generated day.');
        }

        if ($action === 'new_cycle') {
            start_new_cycle();
            redirect_with('tasks.php', 'success', 'Every console restarted from its first app.');
        }

        if ($action === 'cycle_step') {
            $direction = (string) ($_POST['direction'] ?? 'restart');
            shift_console_cycle((int) ($_POST['console_id'] ?? 0), $direction);
            $messages = [
                'next' => 'Console moved to the next cycle.',
                'previous' => 'Console moved back to the previous cycle.',
                'restart' => 'Console restarted from its first app on Cycle 1.',
            ];
            redirect_with('tasks.php', 'success', $messages[$direction] ?? $messages['restart']);
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

if ($view === 'today') {
    $generated = generate_daily_tasks();
}

$progress = cycle_progress();
$todayGroups = $view === 'today' ? todays_tasks() : [];
$historyGroups = $view === 'history' ? task_history() : [];
$todayTotal = 0;
foreach ($todayGroups as $group) {
    $todayTotal += count($group['tasks']);
}

page_start($view === 'history' ? 'Task History' : "Today's Task");
?>
<div class="tabs">
    <a class="<?= $view === 'today' ? 'active' : '' ?>" href="tasks.php">Today's Task</a>
    <a class="<?= $view === 'history' ? 'active' : '' ?>" href="tasks.php?view=history">Task History</a>
</div>

<?php if ($view === 'today'): ?>
    <section class="stats-grid cycle-grid">
        <div class="stat"><span><?= (int) $progress['cycle_days'] ?></span><p>Cycle Days</p></div>
        <div class="stat"><span><?= (int) $progress['eligible'] ?></span><p>Eligible Apps</p></div>
        <div class="stat"><span><?= (int) $progress['shown'] ?></span><p>Shown This Cycle</p></div>
        <div class="stat"><span><?= (int) $progress['remaining'] ?></span><p>Remaining</p></div>
    </section>

    <section class="form-panel">
        <div class="panel-heading">
            <h2>Cycle Settings</h2>
            <span class="hint">Each console runs its own cycle.</span>
        </div>
        <div class="inline-actions cycle-controls">
            <form method="post" class="inline-form cycle-days-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">
                <label>Days per Cycle
                    <input type="number" name="cycle_days" value="<?= (int) $progress['cycle_days'] ?>" min="1" max="365" required>
                </label>
                <button class="btn primary" type="submit">Save</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="new_cycle">
                <button class="btn" type="submit">Restart All Consoles</button>
            </form>
        </div>
        <p class="hint">Daily quota per console = ceil(console's Ready for Work apps &divide; cycle days). When a console has shown all of its apps it starts again from its first app automatically.</p>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <h2>Today's Task — <?= h(date('d M Y')) ?> (<?= (int) $todayTotal ?> apps)</h2>
            <?php if (!empty($generated)): ?>
                <span class="hint">Generated <?= (int) $generated ?> task(s) for today.</span>
            <?php endif; ?>
        </div>

        <?php if (!$todayGroups): ?>
            <p class="empty block">
                No tasks for today.
                <?php if ($progress['eligible'] === 0): ?>
                    Tag Live apps as Ready for Work and assign consoles to start the task system.
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php foreach ($todayGroups as $consoleId => $group): ?>
            <?php
            $tasks = $group['tasks'];
            $doneCount = count(array_filter($tasks, fn($task) => (int) $task['is_done'] === 1));
            ?>
            <div class="app-group" data-group-key="console-<?= (int) $consoleId ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span><?= h($group['name']) ?> (<?= $doneCount ?>/<?= count($tasks) ?> done) &middot; Cycle <?= display_console_cycle((int) $consoleId) ?></span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                    <div class="inline-actions bulk-status-row">
                        <span class="hint">This console:</span>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cycle_step">
                            <input type="hidden" name="direction" value="previous">
                            <input type="hidden" name="console_id" value="<?= (int) $consoleId ?>">
                            <button class="btn small" type="submit">&laquo; Previous Cycle</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cycle_step">
                            <input type="hidden" name="direction" value="restart">
                            <input type="hidden" name="console_id" value="<?= (int) $consoleId ?>">
                            <button class="btn small" type="submit">Restart</button>
                        </form>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="cycle_step">
                            <input type="hidden" name="direction" value="next">
                            <input type="hidden" name="console_id" value="<?= (int) $consoleId ?>">
                            <button class="btn small" type="submit">Next Cycle &raquo;</button>
                        </form>
                    </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>App Name</th>
                            <th>Package</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($tasks as $task): ?>
                            <?php $isDone = (int) $task['is_done'] === 1; ?>
                            <tr>
                                <td><?= h($task['app_name']) ?></td>
                                <td><?= h($task['package_name'] ?? '—') ?></td>
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
            <h2>Task History</h2>
            <span class="hint">Month-wise record of every assigned app, open a month to see its days.</span>
        </div>

        <?php if (!$historyGroups): ?>
            <p class="empty block">No task history yet.</p>
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
                                            <th>App Name</th>
                                            <th>Status</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($day['rows'] as $task): ?>
                                            <tr>
                                                <td><?= h($task['console_name']) ?></td>
                                                <td><?= h($task['app_name']) ?></td>
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
