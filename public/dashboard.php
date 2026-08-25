<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$categories = all_categories();
$counts = category_counts();
$apps = sorted_apps();

page_start('Dashboard');
?>
<section class="stats-grid">
    <div class="stat"><span><?= count($categories) ?></span><p>Categories</p></div>
    <div class="stat"><span><?= count($apps) ?></span><p>Total Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['loading_status'] === 'Active')) ?></span><p>Active Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['ready_loading_status'] === 'Ready')) ?></span><p>Ready Apps</p></div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Apps by Console (<?= count($categories) ?>)</h2>
        <span class="hint">Open a console to see its apps.</span>
    </div>

    <?php if (!$categories): ?>
        <p class="empty block">No categories found.</p>
    <?php endif; ?>

    <?php foreach ($categories as $category): ?>
        <?php
        $categoryId = (int) $category['id'];
        $categoryApps = array_values(array_filter(
            $apps,
            fn($app) => (int) $app['category_id'] === $categoryId
        ));
        $activeCount = count(array_filter($categoryApps, fn($app) => $app['loading_status'] === 'Active'));
        $readyCount = count(array_filter($categoryApps, fn($app) => $app['ready_loading_status'] === 'Ready'));
        ?>
        <div class="app-group" data-group-key="cat-<?= $categoryId ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span><?= h($category['name']) ?> (<?= (int) ($counts[$categoryId] ?? 0) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <div class="table-wrap">
                    <table class="category-table">
                        <colgroup>
                            <col class="col-id">
                            <col class="col-icon">
                            <col class="col-name">
                            <col class="col-loading">
                            <col class="col-ready">
                        </colgroup>
                        <thead>
                        <tr>
                            <th class="col-id">ID</th>
                            <th class="col-icon">App Icon</th>
                            <th class="col-name">App Name</th>
                            <th class="col-loading">Loading (Active: <?= (int) $activeCount ?>)</th>
                            <th class="col-ready">Ready Loading (Ready: <?= (int) $readyCount ?>)</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!$categoryApps): ?>
                            <tr><td colspan="5" class="empty">No apps found in this console.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($categoryApps as $app): ?>
                            <tr>
                                <td class="col-id"><?= (int) $app['display_id'] ?></td>
                                <td class="app-icon-cell"><img class="app-icon" src="<?= h(app_icon_url($app['icon_path'])) ?>" alt=""></td>
                                <td class="col-name"><?= h($app['app_name']) ?></td>
                                <td class="status-cell"><?= render_status_badge($app['loading_status']) ?></td>
                                <td class="ready-cell"><?= render_status_badge($app['ready_loading_status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php page_end(); ?>
