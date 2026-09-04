<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * The first screen of the day: what is due today, and what is waiting
 * on someone. Everything here links to the page that fixes it.
 */

generate_loading_daily();
generate_daily_tasks();

$loadingGroups = todays_loading_apps();
$taskGroups = todays_tasks();

$loadingTotal = 0;
$loadingDone = 0;
foreach ($loadingGroups as $group) {
    foreach ($group['apps'] as $app) {
        $loadingTotal++;
        $loadingDone += (int) $app['is_done'] === 1 ? 1 : 0;
    }
}

$taskTotal = 0;
$taskDone = 0;
foreach ($taskGroups as $group) {
    foreach ($group['tasks'] as $task) {
        $taskTotal++;
        $taskDone += (int) $task['is_done'] === 1 ? 1 : 0;
    }
}

/* Both rotations answer the same question, so the page asks it once. */
$todayByConsole = [];
foreach ($loadingGroups as $consoleId => $group) {
    $todayByConsole[(int) $consoleId] = [
        'name' => $group['name'],
        'loading_total' => count($group['apps']),
        'loading_done' => count(array_filter($group['apps'], fn($a) => (int) $a['is_done'] === 1)),
        'task_total' => 0,
        'task_done' => 0,
    ];
}
foreach ($taskGroups as $consoleId => $group) {
    $consoleId = (int) $consoleId;
    $todayByConsole[$consoleId] ??= [
        'name' => $group['name'],
        'loading_total' => 0,
        'loading_done' => 0,
        'task_total' => 0,
        'task_done' => 0,
    ];
    $todayByConsole[$consoleId]['task_total'] = count($group['tasks']);
    $todayByConsole[$consoleId]['task_done'] =
        count(array_filter($group['tasks'], fn($t) => (int) $t['is_done'] === 1));
}

$counts = production_status_counts();
$items = checklist_items();
$totalItems = count(checklist_required_keys());

$prepareApps = production_apps_by_status('prepare');
$prepareIncomplete = count(array_filter(
    $prepareApps,
    fn($app) => (int) $app['checklist_done'] < $totalItems
));

$consoles = all_consoles();
$consolesMissingUrls = count(array_filter(
    $consoles,
    fn($console) => empty($console['privacy_policy_url']) || empty($console['app_domain_url'])
));

$urlCounts = url_checked_counts();
$liveApps = production_apps_by_status('live');
$untagged = count(array_filter($liveApps, fn($app) => (int) $app['ready_for_work'] !== 1));

$attention = [
    [
        'count' => (int) $counts['ready'],
        'label' => 'Ready apps waiting to be sent',
        'href' => 'apps.php?stage=ready',
        'tone' => 'blue',
    ],
    [
        'count' => $prepareIncomplete,
        'label' => 'Apps with an unfinished checklist',
        'href' => 'apps.php?stage=prepare',
        'tone' => 'amber',
    ],
    [
        'count' => (int) $urlCounts['pending'],
        'label' => 'App URLs still to check',
        'href' => 'apps.php?url=pending',
        'tone' => 'amber',
    ],
    [
        'count' => $consolesMissingUrls,
        'label' => 'Consoles missing a URL',
        'href' => 'consoles.php',
        'tone' => 'red',
    ],
    [
        'count' => $untagged,
        'label' => 'Live apps not tagged Ready for Work',
        'href' => 'apps.php?stage=live',
        'tone' => 'gray',
    ],
];

function home_progress(int $done, int $total): void
{
    $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
    ?>
    <span class="progress-label"><?= $done ?> of <?= $total ?> done</span>
    <div class="progress"><span style="width: <?= $percent ?>%"></span></div>
    <?php
}

page_start('Home');
?>
<section class="panel">
    <div class="panel-heading">
        <h2>Pipeline</h2>
        <span class="hint">Where every published app currently sits.</span>
    </div>
    <div class="stats-grid">
        <a class="stat" href="production.php"><span><?= (int) $counts['prepare'] ?></span><p>Prepare</p></a>
        <a class="stat" href="ready-apps.php"><span><?= (int) $counts['ready'] ?></span><p>Ready</p></a>
        <a class="stat" href="sent-production.php"><span><?= (int) $counts['sent'] ?></span><p>Production</p></a>
        <a class="stat" href="live-apps.php"><span><?= (int) $counts['live'] ?></span><p>Live</p></a>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Today</h2>
        <a class="btn small" href="rotations.php">Open Rotations</a>
    </div>
    <?php if ($loadingTotal === 0 && $taskTotal === 0): ?>
        <p class="empty block">
            Nothing due today. Mark apps Active to start loading, and tag Live apps
            Ready for Work to start the tasks.
        </p>
    <?php else: ?>
        <div class="today-totals">
            <div>
                <span class="today-label">Loading</span>
                <?php home_progress($loadingDone, $loadingTotal); ?>
            </div>
            <div>
                <span class="today-label">Daily Tasks</span>
                <?php home_progress($taskDone, $taskTotal); ?>
            </div>
        </div>
        <ul class="home-list">
            <?php foreach ($todayByConsole as $row): ?>
                <li>
                    <span class="home-list-name"><?= h($row['name']) ?></span>
                    <span class="home-list-meta">
                        Loading <?= $row['loading_done'] ?>/<?= $row['loading_total'] ?>
                        &middot; Tasks <?= $row['task_done'] ?>/<?= $row['task_total'] ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Needs Attention</h2>
        <span class="hint">Each row opens the page that clears it.</span>
    </div>
    <ul class="attention-list">
        <?php foreach ($attention as $row): ?>
            <li class="<?= (int) $row['count'] === 0 ? 'is-clear' : '' ?>">
                <a href="<?= h($row['href']) ?>">
                    <span class="attention-count badge badge-<?= h((int) $row['count'] === 0 ? 'green' : $row['tone']) ?>">
                        <?= (int) $row['count'] ?>
                    </span>
                    <span class="attention-label"><?= h($row['label']) ?></span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
</section>

<?php page_end(); ?>
