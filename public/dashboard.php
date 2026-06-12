<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$categories = all_categories();
$counts = category_counts();
$apps = sorted_apps();
$selectedCategoryParam = (string) ($_GET['category_id'] ?? '');
$showAllSummary = $selectedCategoryParam === 'all';
$selectedCategoryId = $showAllSummary ? 0 : (int) $selectedCategoryParam;
$selectedCategory = $showAllSummary ? null : ($categories[0] ?? null);

if (!$showAllSummary) {
    foreach ($categories as $category) {
        if ((int) $category['id'] === $selectedCategoryId) {
            $selectedCategory = $category;
            break;
        }
    }
}

$selectedCategoryId = $selectedCategory ? (int) $selectedCategory['id'] : 0;
$categoryApps = $selectedCategoryId > 0 ? sorted_apps($selectedCategoryId) : [];
$activeCount = count(array_filter($categoryApps, fn($app) => $app['loading_status'] === 'Active'));
$readyCount = count(array_filter($categoryApps, fn($app) => $app['ready_loading_status'] === 'Ready'));

page_start('Dashboard');
?>
<section class="stats-grid">
    <div class="stat"><span><?= count($categories) ?></span><p>Categories</p></div>
    <div class="stat"><span><?= count($apps) ?></span><p>Total Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['loading_status'] === 'Active')) ?></span><p>Active Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['ready_loading_status'] === 'Ready')) ?></span><p>Ready Apps</p></div>
</section>

<section class="form-panel dashboard-filter">
    <form method="get" class="inline-form">
        <label>Categories
            <select name="category_id" onchange="this.form.submit()">
                <option value="all" <?= $showAllSummary ? 'selected' : '' ?>>All Categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= !$showAllSummary && $selectedCategoryId === (int) $category['id'] ? 'selected' : '' ?>>
                        <?= h($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary" type="submit">Show</button>
    </form>
</section>

<?php if ($showAllSummary): ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>All Categories</h2>
        </div>
        <div class="category-summary-grid">
            <?php foreach ($categories as $category): ?>
                <a class="category-summary" href="dashboard.php?category_id=<?= (int) $category['id'] ?>">
                    <span><?= h($category['name']) ?></span>
                    <strong><?= (int) ($counts[(int) $category['id']] ?? 0) ?></strong>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php elseif (!$selectedCategory): ?>
    <section class="panel">
        <p class="empty block">No categories found.</p>
    </section>
<?php else: ?>
    <section class="panel">
        <div class="panel-heading">
            <h2><?= h($selectedCategory['name']) ?> (<?= (int) ($counts[$selectedCategoryId] ?? 0) ?>)</h2>
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
                    <th class="col-loading">Loading (Active: <?= (int) $activeCount ?>)</th>
                    <th class="col-ready">Ready Loading (Ready: <?= (int) $readyCount ?>)</th>
                </tr>
                </thead>
                <tbody>
                <?php if (!$categoryApps): ?>
                    <tr><td colspan="5" class="empty">No apps found in this category.</td></tr>
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
<?php endif; ?>
<?php page_end(); ?>
