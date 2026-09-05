<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * The loading rotation: what each console loads today, and the record of
 * every day before this one.
 */

$view = in_array($_GET['view'] ?? '', ['history'], true) ? 'history' : 'today';
$self = 'rotations.php' . ($view === 'history' ? '?view=history' : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'toggle_done') {
            rotation_toggle_done('loading', (int) ($_POST['row_id'] ?? 0));
            redirect_with($self, 'success', 'Loading status updated.');
        }

        if ($action === 'save_settings') {
            update_loading_apps_per_day((int) ($_POST['apps_per_day'] ?? 0));
            redirect_with('rotations.php', 'success', 'Settings saved.');
        }

        if ($action === 'cycle_step') {
            $direction = (string) ($_POST['direction'] ?? 'restart');
            $consoleId = (int) ($_POST['console_id'] ?? 0);
            rotation_shift('loading', $consoleId, $direction);
            $messages = [
                'next' => 'Console moved to the next cycle.',
                'previous' => 'Console moved back to the previous cycle.',
                'restart' => 'Console restarted from its first app on Cycle 1.',
            ];
            $opposite = ['next' => 'previous', 'previous' => 'next'];
            $undo = isset($opposite[$direction])
                ? ['page' => 'rotations.php', 'fields' => [
                    'action' => 'cycle_step',
                    'direction' => $opposite[$direction],
                    'console_id' => $consoleId,
                ]]
                : null;
            redirect_with('rotations.php', 'success', $messages[$direction] ?? $messages['restart'], $undo);
        }

        if ($action === 'restart_all') {
            rotation_restart_all('loading');
            redirect_with('rotations.php', 'success', 'Every console restarted from its first app.');
        }
    } catch (Throwable $e) {
        redirect_with($self, 'error', $e->getMessage());
    }
}

if ($view === 'today') {
    $generatedLoading = rotation_generate('loading');
}

$loadingProgress = loading_cycle_progress();
$loadingGroups = $view === 'today' ? todays_loading_apps() : [];
$consoles = all_consoles();

$loadingHistory = $view === 'history' ? loading_history() : [];

/* One row of controls, used by both rotations. */
function rotation_controls(string $kind, int $consoleId): void
{
    foreach ([
        ['previous', '&laquo; Previous Cycle'],
        ['restart', 'Restart'],
        ['next', 'Next Cycle &raquo;'],
    ] as [$direction, $label]) {
        ?>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cycle_step">
            <input type="hidden" name="kind" value="<?= h($kind) ?>">
            <input type="hidden" name="direction" value="<?= h($direction) ?>">
            <input type="hidden" name="console_id" value="<?= $consoleId ?>">
            <button class="btn small" type="submit"><?= $label ?></button>
        </form>
        <?php
    }
}

function rotation_done_toggle(string $kind, array $row): void
{
    $isDone = (int) $row['is_done'] === 1;
    ?>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_done">
        <input type="hidden" name="kind" value="<?= h($kind) ?>">
        <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">
        <label class="done-toggle">
            <input type="checkbox" <?= $isDone ? 'checked' : '' ?> onchange="this.closest('form').submit()">
            <?= $isDone
                ? '<span class="badge badge-green">Done</span>'
                : '<span class="badge badge-amber">Pending</span>' ?>
        </label>
    </form>
    <?php
}

page_start($view === 'history' ? 'Rotation History' : 'Rotations');
?>
<div class="tabs">
    <a class="<?= $view === 'today' ? 'active' : '' ?>" href="rotations.php">Today</a>
    <a class="<?= $view === 'history' ? 'active' : '' ?>" href="rotations.php?view=history">History</a>
</div>

<?php if ($view === 'today'): ?>
    <section class="stats-grid">
        <div class="stat"><span><?= (int) $loadingProgress['shown'] ?>/<?= (int) $loadingProgress['eligible'] ?></span><p>Loading this cycle</p></div>
        <div class="stat"><span><?= (int) $loadingProgress['remaining'] ?></span><p>Loading remaining</p></div>
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
                <label>Loading apps per day
                    <input type="number" name="apps_per_day" value="<?= (int) $loadingProgress['apps_per_day'] ?>" min="1" max="100" required>
                </label>
                <button class="btn primary" type="submit">Save</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="restart_all">
                <input type="hidden" name="kind" value="loading">
                <button class="btn" type="submit">Restart all loading</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <h2>Today &mdash; <?= h(date('d M Y')) ?></h2>
            <span class="hint">
                <?= (int) ($generatedLoading ?? 0) > 0
                    ? 'Generated ' . (int) $generatedLoading . ' row(s) for today.'
                    : 'One slice for each console.' ?>
            </span>
        </div>

        <?php if (!$loadingGroups): ?>
            <p class="empty block">
                Nothing scheduled for today.
                <br><a class="btn small" href="apps.php?loading=Active">Set apps Active for loading</a>
            </p>
        <?php endif; ?>

        <?php foreach ($consoles as $console): ?>
            <?php
            $consoleId = (int) $console['id'];
            $loadingRows = $loadingGroups[$consoleId]['apps'] ?? [];
            if (!$loadingRows) {
                continue;
            }
            $loadingDone = count(array_filter($loadingRows, fn($r) => (int) $r['is_done'] === 1));
            ?>
            <div class="app-group" data-group-key="console-<?= $consoleId ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span class="console-head">
                        <span class="console-head-name"><?= h($console['name']) ?></span>
                        <span class="console-head-meta">
                            Loading <?= $loadingDone ?>/<?= count($loadingRows) ?>
                            &middot; Cycle <?= rotation_display_cycle('loading', $consoleId) ?>
                        </span>
                    </span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                        <div class="inline-actions bulk-status-row">
                            <span class="hint">This console:</span>
                            <?php rotation_controls('loading', $consoleId); ?>
                        </div>
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>App Icon</th>
                                    <th>App Name</th>
                                    <th>Status</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($loadingRows as $row): ?>
                                    <tr>
                                        <td><img class="app-icon" src="<?= h(app_icon_url($row['icon_path'])) ?>" alt=""></td>
                                        <td>
                                            <span class="cell-title">
                                                <a href="app.php?id=<?= (int) $row['app_id'] ?>"><?= h($row['app_name']) ?></a>
                                                <span class="cell-sub">#<?= (int) $row['app_id'] ?></span>
                                            </span>
                                        </td>
                                        <td><?php rotation_done_toggle('loading', $row); ?></td>
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
    <?php
    /* The record, month by month. */
    $months = $loadingHistory;
    krsort($months);
    ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>Rotation History</h2>
            <span class="hint">Month by month, newest first.</span>
        </div>

        <?php if (!$months): ?>
            <p class="empty block">Nothing recorded yet.</p>
        <?php endif; ?>

        <?php foreach ($months as $monthKey => $month): ?>
            <?php
            $monthRows = 0;
            $monthDone = 0;
            foreach ($month['days'] as $day) {
                foreach ($day['rows'] as $row) {
                    $monthRows++;
                    $monthDone += (int) $row['is_done'] === 1 ? 1 : 0;
                }
            }
            krsort($month['days']);
            ?>
            <div class="app-group" data-group-key="month-<?= h((string) $monthKey) ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span><?= h($month['label']) ?> (<?= $monthDone ?>/<?= $monthRows ?> done)</span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                    <?php foreach ($month['days'] as $date => $day): ?>
                        <?php $dayDone = count(array_filter($day['rows'], fn($r) => (int) $r['is_done'] === 1)); ?>
                        <div class="app-group" data-group-key="day-<?= h((string) $date) ?>">
                            <button class="app-group-toggle" type="button" aria-expanded="false">
                                <span><?= h($day['label']) ?> (<?= $dayDone ?>/<?= count($day['rows']) ?> done)</span>
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
                                        <?php foreach ($day['rows'] as $row): ?>
                                            <tr>
                                                <td><?= h($row['category_name'] ?? $row['console_name'] ?? '—') ?></td>
                                                <td><?= h($row['app_name']) ?></td>
                                                <td>
                                                    <?= (int) $row['is_done'] === 1
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
