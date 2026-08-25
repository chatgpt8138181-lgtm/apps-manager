<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (($_POST['action'] ?? '') === 'update_group') {
            $apps = $_POST['apps'] ?? [];
            if (!is_array($apps)) {
                throw new RuntimeException('Invalid update.');
            }
            foreach ($apps as $id => $data) {
                if (is_array($data)) {
                    update_app_statuses(
                        (int) $id,
                        (string) ($data['loading_status'] ?? ''),
                        (string) ($data['ready_loading_status'] ?? '')
                    );
                }
            }
            redirect_with('dashboard.php', 'success', 'Console apps updated.');
        }
    } catch (Throwable $e) {
        redirect_with('dashboard.php', 'error', $e->getMessage());
    }
}

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
        <span class="hint">Open a console, change Loading / Ready Loading, then Update All.</span>
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
                <?php if (!$categoryApps): ?>
                    <p class="empty block">No apps found in this console.</p>
                <?php else: ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_group">
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
                                <?php foreach ($categoryApps as $app): ?>
                                    <?php $appId = (int) $app['id']; ?>
                                    <tr>
                                        <td class="col-id"><?= (int) $app['display_id'] ?></td>
                                        <td class="app-icon-cell"><img class="app-icon" src="<?= h(app_icon_url($app['icon_path'])) ?>" alt=""></td>
                                        <td class="col-name"><?= h($app['app_name']) ?></td>
                                        <td class="status-cell">
                                            <select class="status-select <?= $app['loading_status'] === 'Active' ? 'is-green' : 'is-red' ?>" name="apps[<?= $appId ?>][loading_status]">
                                                <option <?= $app['loading_status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                                <option <?= $app['loading_status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                            </select>
                                        </td>
                                        <td class="ready-cell">
                                            <select class="status-select <?= $app['ready_loading_status'] === 'Ready' ? 'is-green' : 'is-red' ?>" name="apps[<?= $appId ?>][ready_loading_status]">
                                                <option <?= $app['ready_loading_status'] === 'Ready' ? 'selected' : '' ?>>Ready</option>
                                                <option <?= $app['ready_loading_status'] === 'Not Ready' ? 'selected' : '' ?>>Not Ready</option>
                                            </select>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="bulk-actions">
                            <button class="btn primary" type="submit">Update All</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<script>
document.querySelectorAll('.status-select').forEach((select) => {
    select.addEventListener('change', () => {
        const green = select.value === 'Active' || select.value === 'Ready';
        select.classList.toggle('is-green', green);
        select.classList.toggle('is-red', !green);
    });
});
</script>
<?php page_end(); ?>
