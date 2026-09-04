<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * The ads.json worklist: every live app, console by console, split by
 * whether its folder has been put on the server yet. Pick the ones you are
 * about to upload, take their zip, then move them across.
 */

$views = ['pending' => 'To Create', 'created' => 'Created'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $return = 'ads-config.php?view=' . urlencode((string) ($_POST['return_view'] ?? 'pending'));

    try {
        $action = (string) ($_POST['bulk_action'] ?? '');
        $appIds = (array) ($_POST['app_ids'] ?? []);

        if ($action === 'ads_created' || $action === 'ads_pending') {
            $created = $action === 'ads_created';
            $moved = mark_ads_created($appIds, $created);
            redirect_with(
                $return,
                'success',
                $moved . ' app(s) moved to ' . ($created ? 'Created' : 'To Create') . '.'
            );
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

$view = (string) ($_GET['view'] ?? 'pending');
if (!isset($views[$view])) {
    $view = 'pending';
}

$apps = ads_live_apps($view === 'created');
$counts = [
    'pending' => count($view === 'pending' ? $apps : ads_live_apps(false)),
    'created' => count($view === 'created' ? $apps : ads_live_apps(true)),
];

/* Console by console, the way every other list here is grouped. */
$groups = [];
foreach ($apps as $app) {
    $key = (int) $app['console_id'];
    $groups[$key]['name'] = $app['console_name'] ?? 'No console';
    $groups[$key]['apps'][] = $app;
}

page_start('Ads Config');
?>
<div class="tabs">
    <?php foreach ($views as $key => $label): ?>
        <a class="<?= $view === $key ? 'active' : '' ?>" href="ads-config.php?view=<?= h($key) ?>">
            <?= h($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <div class="panel-heading">
        <h2><?= h($views[$view]) ?> (<?= count($apps) ?>)</h2>
        <span class="hint">
            A selection downloads as <code>app/ads.json</code>.
            A console's own button gives the whole path, <code>console/app/ads.json</code>.
        </span>
    </div>

    <?php if (!$apps): ?>
        <p class="empty block">
            <?= $view === 'pending'
                ? 'Nothing waiting — every live app has its ads.json on the server.'
                : 'Nothing here yet. Mark apps as created once their folders are uploaded.' ?>
        </p>
    <?php endif; ?>

    <?php foreach ($groups as $consoleId => $group): ?>
        <div class="app-group" data-group-key="ads-console-<?= (int) $consoleId ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span class="console-head">
                    <span class="console-head-name"><?= h($group['name']) ?> (<?= count($group['apps']) ?>)</span>
                </span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <div class="inline-actions ads-console-actions">
                    <a class="btn" href="ads-download.php?console=<?= (int) $consoleId ?>">
                        Download all (ZIP)
                    </a>
                    <span class="hint">Every live app of this console, with the console folder in front.</span>
                </div>

                <div class="bulk-form">
                    <form method="post" action="ads-download.php" class="bulk-download" hidden>
                        <?= csrf_field() ?>
                    </form>
                    <form method="post" class="bulk-submit" hidden>
                        <?= csrf_field() ?>
                        <input type="hidden" name="bulk_action" value="">
                        <input type="hidden" name="return_view" value="<?= h($view) ?>">
                    </form>

                    <?php render_bulk_bar($view === 'pending'
                        ? [
                            ['value' => 'ads_zip', 'label' => 'Download ZIP', 'class' => 'primary', 'download' => true],
                            ['value' => 'ads_created', 'label' => 'Json Created'],
                        ]
                        : [
                            ['value' => 'ads_zip', 'label' => 'Download ZIP', 'class' => 'primary', 'download' => true],
                            ['value' => 'ads_pending', 'label' => 'Back to To Create'],
                        ]); ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th class="col-select"><input type="checkbox" class="bulk-all" aria-label="Select all"></th>
                                <th>App</th>
                                <th>Folder</th>
                                <th>ads.json</th>
                                <th>Actions</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($group['apps'] as $app): ?>
                                <?php $folder = ads_folder_name($app); ?>
                                <tr>
                                    <td class="col-select">
                                        <input type="checkbox" class="bulk-row" value="<?= (int) $app['id'] ?>"
                                               <?= $folder === null ? 'data-no-url="1"' : '' ?>>
                                    </td>
                                    <td>
                                        <span class="cell-with-icon">
                                            <img class="app-icon" src="<?= h(app_icon_url($app['icon_path'] ?? null)) ?>" alt="">
                                            <?= h($app['name']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($folder !== null): ?>
                                            <code><?= h($folder) ?>/ads.json</code>
                                        <?php else: ?>
                                            <span class="badge badge-red">No domain URL</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($app['ads_updated_at'])): ?>
                                            <span class="badge badge-green">Saved</span>
                                        <?php else: ?>
                                            <span class="badge badge-gray">Template</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions">
                                        <a class="btn small" href="app.php?id=<?= (int) $app['id'] ?>">Open</a>
                                        <?php if ($folder !== null): ?>
                                            <a class="btn small" href="ads-download.php?app=<?= (int) $app['id'] ?>">ZIP</a>
                                        <?php endif; ?>
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
</section>
<?php page_end(); ?>
