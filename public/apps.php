<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * Every app, both sides of the house, in one list. The old stage pages are
 * this list with a filter already set.
 */

$stages = [
    '' => 'All stages',
    'none' => 'Loading only',
    'prepare' => 'Prepare',
    'ready' => 'Ready',
    'sent' => 'Sent',
    'live' => 'Live',
    'rejected' => 'Rejected',
    'suspended' => 'Suspended',
];

$loadingFilters = [
    '' => 'Any loading state',
    'Active' => 'Active',
    'Inactive' => 'Inactive',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'apps.php?' . http_build_query(array_filter([
        'stage' => (string) ($_POST['return_stage'] ?? ''),
        'console' => (int) ($_POST['return_console'] ?? 0),
        'loading' => (string) ($_POST['return_loading'] ?? ''),
        'q' => (string) ($_POST['return_q'] ?? ''),
    ]));

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $consoleId = (int) ($_POST['console_id'] ?? 0);
            $newId = add_app_record($name, $consoleId, ($_POST['track'] ?? 'loading') === 'publishing');
            redirect_with('app.php?id=' . $newId, 'success', 'App added.');
        }

        if ($action === 'bulk') {
            $result = apply_bulk_production_action(
                (string) ($_POST['bulk_action'] ?? ''),
                (array) ($_POST['app_ids'] ?? [])
            );
            redirect_with($return, 'success', bulk_result_message($result, 'updated'));
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

$stage = (string) ($_GET['stage'] ?? '');
if (!array_key_exists($stage, $stages)) {
    $stage = '';
}
$loading = (string) ($_GET['loading'] ?? '');
if (!array_key_exists($loading, $loadingFilters)) {
    $loading = '';
}
$listQuery = trim((string) ($_GET['q'] ?? ''));
$listConsole = (int) ($_GET['console'] ?? 0);

$consoles = all_consoles();
$all = all_apps_overview($stage, $listConsole, $loading, $listQuery);
$page = paginate($all);
$rows = $page['rows'];

$filterState = array_filter([
    'stage' => $stage,
    'console' => $listConsole,
    'loading' => $loading,
    'q' => $listQuery,
]);

page_start('Apps');
?>
<section class="form-panel add-panel">
    <div class="app-group" data-group-key="add-form">
        <button class="app-group-toggle" type="button" aria-expanded="false">
            <span>+ Add App</span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="app-group-body">
            <form method="post" class="stacked-form wide">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <label>App Name
                    <input type="text" name="name" maxlength="200" required>
                </label>
                <div class="form-row">
                    <label>Console
                        <select name="console_id">
                            <option value="0">No console</option>
                            <?php foreach ($consoles as $console): ?>
                                <option value="<?= (int) $console['id'] ?>"><?= h($console['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Track
                        <select name="track">
                            <option value="loading">Loading only</option>
                            <option value="publishing">Start in Prepare Production</option>
                        </select>
                    </label>
                </div>
                <button class="btn primary" type="submit">Add App</button>
            </form>
        </div>
    </div>
</section>

<section class="panel">
    <form method="get" action="apps.php" class="list-filters">
        <label>Search
            <input type="search" name="q" value="<?= h($listQuery) ?>" placeholder="App name or package">
        </label>
        <label>Console
            <select name="console">
                <option value="0">All consoles</option>
                <?php foreach ($consoles as $console): ?>
                    <option value="<?= (int) $console['id'] ?>" <?= $listConsole === (int) $console['id'] ? 'selected' : '' ?>>
                        <?= h($console['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Stage
            <select name="stage">
                <?php foreach ($stages as $key => $label): ?>
                    <option value="<?= h((string) $key) ?>" <?= $stage === (string) $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Loading
            <select name="loading">
                <?php foreach ($loadingFilters as $key => $label): ?>
                    <option value="<?= h((string) $key) ?>" <?= $loading === (string) $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary" type="submit">Filter</button>
        <?php if ($filterState): ?>
            <a class="btn" href="apps.php">Clear</a>
        <?php endif; ?>
    </form>

    <div class="panel-heading">
        <h2>Apps (<?= (int) $page['total'] ?>)</h2>
        <span class="hint">One row per app. Open it to work on either side.</span>
    </div>

    <?php if (!$rows): ?>
        <p class="empty block">
            <?= $filterState ? 'No app matches this filter.' : 'No apps yet.' ?>
        </p>
    <?php endif; ?>

    <?php
    /* The page's rows, grouped the way every other list groups them. */
    $groups = [];
    foreach ($rows as $row) {
        $key = (int) ($row['console_id'] ?? 0);
        $groups[$key]['name'] = $row['console_name'] ?? 'No console';
        $groups[$key]['apps'][] = $row;
    }
    ?>

    <?php foreach ($groups as $groupId => $group): ?>
        <div class="app-group" data-group-key="console-<?= (int) $groupId ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span class="console-head">
                    <span class="console-head-name"><?= h($group['name']) ?> (<?= count($group['apps']) ?>)</span>
                    <?php
                    $liveHere = count(array_filter($group['apps'], fn($a) => $a['stage'] === 'live'));
                    $activeHere = count(array_filter($group['apps'], fn($a) => $a['loading_status'] === 'Active'));
                    ?>
                    <span class="console-head-meta">
                        <?= $activeHere ?> active &middot; <?= $liveHere ?> live
                    </span>
                </span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <div class="bulk-form">
                    <form method="post" class="bulk-submit" hidden>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="bulk">
                        <input type="hidden" name="bulk_action" value="">
                        <input type="hidden" name="return_stage" value="<?= h($stage) ?>">
                        <input type="hidden" name="return_console" value="<?= (int) $listConsole ?>">
                        <input type="hidden" name="return_loading" value="<?= h($loading) ?>">
                        <input type="hidden" name="return_q" value="<?= h($listQuery) ?>">
                    </form>
                    <?php render_bulk_bar([
                        ['value' => 'ready', 'label' => 'Mark Ready'],
                        ['value' => 'send', 'label' => 'Send for Production', 'class' => 'primary'],
                        ['value' => 'live', 'label' => 'Mark Live'],
                        ['value' => 'tag_ready', 'label' => 'Tag Ready for Work'],
                        ['value' => 'untag_ready', 'label' => 'Remove Tag'],
                    ]); ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th class="col-select"><input type="checkbox" class="bulk-all" aria-label="Select all"></th>
                                <th>App</th>
                                <th>Stage</th>
                                <th>Loading</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['apps'] as $app): ?>
                                <tr>
                                    <td class="col-select"><input type="checkbox" class="bulk-row" value="<?= (int) $app['id'] ?>"></td>
                                    <td>
                                        <span class="cell-title">
                                            <?= h($app['app_name']) ?>
                                            <span class="cell-sub">
                                                #<?= (int) $app['id'] ?>
                                                <?php if (!empty($app['package_name'])): ?>
                                                    &middot; <?= h($app['package_name']) ?>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </td>
                                    <td><?= render_production_badge((string) $app['stage']) ?></td>
                                    <td>
                                        <?= render_status_badge((string) $app['loading_status']) ?>
                                        <?php if ((int) $app['ready_for_work'] === 1): ?>
                                            <span class="badge badge-green">Ready for Work</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                        <a class="btn small" href="app.php?id=<?= (int) $app['id'] ?>">Open</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php render_pager($page, 'apps.php', [
        'stage' => $stage,
        'console' => $listConsole,
        'loading' => $loading,
        'q' => $listQuery,
    ]); ?>
</section>
<?php page_end(); ?>
