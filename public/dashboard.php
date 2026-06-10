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
            <table>
                <thead>
                <tr>
                    <th>ID</th>
                    <th>App Icon</th>
                    <th>App Name</th>
                    <th>Loading</th>
                    <th>Ready Loading</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$categoryApps): ?>
                    <tr><td colspan="5" class="empty">No apps in this category yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($categoryApps as $app): ?>
                    <tr>
                        <td><?= (int) $app['display_id'] ?></td>
                        <td><img class="app-icon" src="<?= h(app_icon_url($app['icon_path'])) ?>" alt=""></td>
                        <td><?= h($app['app_name']) ?></td>
                        <td><?= render_status_badge($app['loading_status']) ?></td>
                        <td><?= render_status_badge($app['ready_loading_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
<?php endforeach; ?>
<?php page_end(); ?>
