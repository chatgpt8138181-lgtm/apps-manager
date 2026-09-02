<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$query = trim((string) ($_GET['q'] ?? ''));
$categoryId = (int) ($_GET['category_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'search.php?q=' . urlencode((string) ($_POST['return_q'] ?? '')) . '&category_id=' . (int) ($_POST['return_category_id'] ?? 0);

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            update_app((int) ($_POST['app_id'] ?? 0), $_POST);
            redirect_with($return, 'success', 'App updated.');
        }

        if ($action === 'delete') {
            delete_app((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App deleted.');
        }

        if ($action === 'update_all') {
            $apps = $_POST['apps'] ?? [];
            if (!is_array($apps)) {
                throw new RuntimeException('Invalid bulk update.');
            }
            foreach ($apps as $id => $data) {
                if (is_array($data)) {
                    update_app((int) $id, $data);
                }
            }
            redirect_with($return, 'success', 'All visible results updated.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

$categories = all_categories();
$results = search_apps($query, $categoryId);

page_start('Search/Edit');
?>
<section class="form-panel">
    <form method="get" class="search-form" id="search-filter-form">
        <label>Search
            <input type="text" name="q" value="<?= h($query) ?>" placeholder="App name or display ID">
        </label>
        <label>Console
            <select name="category_id" id="search-category-select">
                <option value="0">All Consoles</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                        <?= h($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary" type="submit">Search</button>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Results (<?= count($results) ?>)</h2>
        <?php if (count($results) > 1): ?><span class="hint">Edit rows, then use Update All.</span><?php endif; ?>
    </div>

    <?php if (!$results): ?>
        <p class="empty block">No matching apps found.</p>
    <?php else: ?>
        <?php foreach ($categories as $category): ?>
            <?php
            $categoryResults = array_values(array_filter(
                $results,
                fn($app) => (int) $app['category_id'] === (int) $category['id']
            ));
            if (!$categoryResults) {
                continue;
            }
            ?>
            <div class="app-group" data-group-key="cat-<?= (int) $category['id'] ?>">
                <button class="app-group-toggle" type="button" aria-expanded="false">
                    <span><?= h($category['name']) ?> (<?= count($categoryResults) ?>)</span>
                    <span class="nav-chevron" aria-hidden="true"></span>
                </button>
                <div class="app-group-body">
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="return_q" value="<?= h($query) ?>">
                        <input type="hidden" name="return_category_id" value="<?= (int) $categoryId ?>">
                        <div class="table-wrap">
                            <table>
                                <thead>
                                <tr>
                                    <th>Icon</th>
                                    <th>ID</th>
                                    <th>App Name</th>
                                    <th>Actions</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($categoryResults as $app): ?>
                                    <tr>
                                        <td><img class="app-icon" src="<?= h(app_icon_url($app['icon_path'])) ?>" alt=""></td>
                                        <td><?= (int) $app['display_id'] ?></td>
                                        <td>
                                            <input type="text" name="apps[<?= (int) $app['id'] ?>][app_name]" value="<?= h($app['app_name']) ?>" maxlength="200" required>
                                        </td>
                                        <td class="actions">
                                            <button class="btn small" type="submit"
                                                    name="action"
                                                    value="update"
                                                    onclick="copyRowToSingle(this, <?= (int) $app['id'] ?>)">Update</button>
                                            <button class="btn danger small" type="submit"
                                                    name="action"
                                                    value="delete"
                                                    onclick="return prepareDelete(this, <?= (int) $app['id'] ?>)">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <input type="hidden" name="app_id" class="single-app-id">
                        <input type="hidden" name="app_name" class="single-app-name">
                        <?php if (count($categoryResults) > 1): ?>
                            <div class="bulk-actions">
                                <button class="btn primary" type="submit" name="action" value="update_all">Update All</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

<script>
function copyRowToSingle(button, id) {
    const form = button.closest('form');
    const row = button.closest('tr');
    form.querySelector('.single-app-id').value = id;
    form.querySelector('.single-app-name').value = row.querySelector('[name$="[app_name]"]').value;
}

function prepareDelete(button, id) {
    button.closest('form').querySelector('.single-app-id').value = id;
    return confirm('Delete this app?');
}

document.getElementById('search-category-select').addEventListener('change', () => {
    document.getElementById('search-filter-form').submit();
});
</script>
<?php page_end(); ?>
