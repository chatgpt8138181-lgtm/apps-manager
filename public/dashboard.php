<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        if (($_POST['action'] ?? '') === 'bulk_status') {
            bulk_update_category_status(
                (int) ($_POST['category_id'] ?? 0),
                (string) ($_POST['field'] ?? ''),
                (string) ($_POST['value'] ?? '')
            );
            redirect_with('dashboard.php', 'success', 'Console apps updated.');
        }

        if (($_POST['action'] ?? '') === 'update_group') {
            $apps = $_POST['apps'] ?? [];
            if (!is_array($apps)) {
                throw new RuntimeException('Invalid update.');
            }
            foreach ($apps as $id => $data) {
                if (is_array($data)) {
                    update_app_statuses((int) $id, (string) ($data['loading_status'] ?? ''));
                }
            }
            redirect_with('dashboard.php', 'success', 'Console apps updated.');
        }

        /* The apps someone ticked, set together. */
        if (($_POST['action'] ?? '') === 'bulk_selected') {
            $wanted = (string) ($_POST['bulk_action'] ?? '');
            $status = $wanted === 'set_inactive' ? 'Inactive' : 'Active';
            if ($wanted !== 'set_inactive' && $wanted !== 'set_active') {
                throw new RuntimeException('Unknown action.');
            }
            $changed = bulk_set_loading_status((array) ($_POST['app_ids'] ?? []), $status);
            redirect_with('dashboard.php', 'success', $changed . ' app(s) set ' . $status . '.');
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
    <div class="stat"><span><?= count($categories) ?></span><p>Consoles</p></div>
    <div class="stat"><span><?= count($apps) ?></span><p>Total Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['loading_status'] === 'Active')) ?></span><p>Active Apps</p></div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Apps by Console (<?= count($categories) ?>)</h2>
        <span class="hint">Open a console, tick the apps you want, or change them one by one and Update All.</span>
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
                    <div class="bulk-status-row">
                        <div class="bulk-status-set">
                            <span class="hint">Loading:</span>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="bulk_status">
                                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                                <input type="hidden" name="field" value="loading">
                                <input type="hidden" name="value" value="Active">
                                <button class="btn small primary" type="submit">Active All</button>
                            </form>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="bulk_status">
                                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                                <input type="hidden" name="field" value="loading">
                                <input type="hidden" name="value" value="Inactive">
                                <button class="btn danger small" type="submit">Inactive All</button>
                            </form>
                        </div>
                    </div>

                    <div class="bulk-form">
                        <form method="post" class="bulk-submit" hidden>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="bulk_selected">
                            <input type="hidden" name="bulk_action" value="">
                        </form>
                        <?php render_bulk_bar([
                            ['value' => 'set_active', 'label' => 'Set Active', 'class' => 'primary'],
                            ['value' => 'set_inactive', 'label' => 'Set Inactive', 'class' => 'danger'],
                        ]); ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_group">
                        <div class="table-wrap">
                            <table class="category-table">
                                <colgroup>
                                    <col class="col-select">
                                    <col class="col-id">
                                    <col class="col-icon">
                                    <col class="col-name">
                                    <col class="col-loading">
                                </colgroup>
                                <thead>
                                <tr>
                                    <th class="col-select"><input type="checkbox" class="bulk-all" aria-label="Select all"></th>
                                    <th class="col-id">ID</th>
                                    <th class="col-icon">App Icon</th>
                                    <th class="col-name">App Name</th>
                                    <th class="col-loading">Loading (Active: <?= (int) $activeCount ?>)</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($categoryApps as $app): ?>
                                    <?php $appId = (int) $app['id']; ?>
                                    <tr>
                                        <td class="col-select"><input type="checkbox" class="bulk-row" value="<?= $appId ?>"></td>
                                        <td class="col-id">#<?= (int) $app['id'] ?></td>
                                        <td class="app-icon-cell"><img class="app-icon" src="<?= h(app_icon_url($app['icon_path'])) ?>" alt=""></td>
                                        <td class="col-name"><?= h($app['app_name']) ?></td>
                                        <td class="status-cell">
                                            <select class="status-select <?= $app['loading_status'] === 'Active' ? 'is-green' : 'is-red' ?>" name="apps[<?= $appId ?>][loading_status]">
                                                <option <?= $app['loading_status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                                <option <?= $app['loading_status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
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
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<script>
document.querySelectorAll('.status-select').forEach((select) => {
    select.addEventListener('change', () => {
        const green = select.value === 'Active';
        select.classList.toggle('is-green', green);
        select.classList.toggle('is-red', !green);
    });
});
</script>
<?php page_end(); ?>
