<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$categories = all_categories();
$counts = category_counts();
$apps = sorted_apps();
$appsByCategory = [];

foreach ($apps as $app) {
    $appsByCategory[(int) $app['category_id']][] = $app;
}

page_start('Dashboard');
?>
<section class="stats-grid">
    <div class="stat"><span><?= count($categories) ?></span><p>Categories</p></div>
    <div class="stat"><span><?= count($apps) ?></span><p>Total Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['loading_status'] === 'Active')) ?></span><p>Active Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['ready_loading_status'] === 'Ready')) ?></span><p>Ready Apps</p></div>
</section>

<?php foreach ($categories as $category): ?>
    <?php $categoryApps = $appsByCategory[(int) $category['id']] ?? []; ?>
    <section class="panel">
        <div class="panel-heading">
            <h2><?= h($category['name']) ?> (<?= (int) ($counts[(int) $category['id']] ?? 0) ?>)</h2>
        </div>
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
                    <th class="col-loading">Loading</th>
                    <th class="col-ready">Ready Loading</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$categoryApps): ?>
                    <tr><td colspan="5" class="empty">No apps in this category yet.</td></tr>
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
    </section>
<?php endforeach; ?>
<?php page_end(); ?>
