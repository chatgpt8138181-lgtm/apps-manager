<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

function render_search_results(array $results, string $query, int $categoryId): void
{
    ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>Results (<?= count($results) ?>)</h2>
            <?php if (count($results) > 1): ?><span class="hint">Edit rows, then use Update All.</span><?php endif; ?>
        </div>

        <?php if (!$results): ?>
            <p class="empty block">No matching apps found.</p>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="return_q" value="<?= h($query) ?>">
                <input type="hidden" name="return_category_id" value="<?= (int) $categoryId ?>">
                <div class="table-wrap">
                    <table class="edit-table">
                        <thead>
                        <tr>
                            <th>Icon</th>
                            <th>ID</th>
                            <th>App Name</th>
                            <th>Category</th>
                            <th>Loading</th>
                            <th>Ready Loading</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results as $app): ?>
                            <tr>
                                <td><img class="app-icon" src="<?= h(app_icon_url($app['icon_path'])) ?>" alt=""></td>
                                <td><?= (int) $app['display_id'] ?></td>
                                <td>
                                    <input type="text" name="apps[<?= (int) $app['id'] ?>][app_name]" value="<?= h($app['app_name']) ?>" maxlength="200" required>
                                </td>
                                <td><?= h($app['category_name']) ?></td>
                                <td>
                                    <select name="apps[<?= (int) $app['id'] ?>][loading_status]">
                                        <option <?= $app['loading_status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                                        <option <?= $app['loading_status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                                    </select>
                                </td>
                                <td>
                                    <select name="apps[<?= (int) $app['id'] ?>][ready_loading_status]">
                                        <option <?= $app['ready_loading_status'] === 'Ready' ? 'selected' : '' ?>>Ready</option>
                                        <option <?= $app['ready_loading_status'] === 'Not Ready' ? 'selected' : '' ?>>Not Ready</option>
                                    </select>
                                </td>
                                <td class="actions">
                                    <button class="btn small" type="submit"
                                            formaction="search.php"
                                            formmethod="post"
                                            name="action"
                                            value="update"
                                            onclick="copyRowToSingle(this, <?= (int) $app['id'] ?>)">Update</button>
                                    <button class="btn danger small" type="submit"
                                            formaction="search.php"
                                            formmethod="post"
                                            name="action"
                                            value="delete"
                                            onclick="return prepareDelete(this, <?= (int) $app['id'] ?>)">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <input type="hidden" name="app_id" id="single_app_id">
                <input type="hidden" name="app_name" id="single_app_name">
                <input type="hidden" name="loading_status" id="single_loading_status">
                <input type="hidden" name="ready_loading_status" id="single_ready_status">
                <?php if (count($results) > 1): ?>
                    <div class="bulk-actions">
                        <button class="btn primary" type="submit" name="action" value="update_all">Update All</button>
                    </div>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>
    <?php
}

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

if (($_GET['partial'] ?? '') === 'results') {
    render_search_results($results, $query, $categoryId);
    exit;
}

page_start('Search/Edit');
?>
<section class="form-panel">
    <form method="get" class="search-form" id="search-filter-form">
        <label>Search
            <input type="text" name="q" value="<?= h($query) ?>" placeholder="App name or display ID">
        </label>
        <label>Category
            <select name="category_id" id="search-category-select">
                <option value="0">All Categories</option>
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

<div id="search-results" class="async-results" aria-live="polite">
    <?php render_search_results($results, $query, $categoryId); ?>
</div>

<script>
function copyRowToSingle(button, id) {
    const row = button.closest('tr');
    document.getElementById('single_app_id').value = id;
    document.getElementById('single_app_name').value = row.querySelector('[name$="[app_name]"]').value;
    document.getElementById('single_loading_status').value = row.querySelector('[name$="[loading_status]"]').value;
    document.getElementById('single_ready_status').value = row.querySelector('[name$="[ready_loading_status]"]').value;
}

function prepareDelete(button, id) {
    document.getElementById('single_app_id').value = id;
    return confirm('Delete this app?');
}

(() => {
    const form = document.getElementById('search-filter-form');
    const categorySelect = document.getElementById('search-category-select');
    const results = document.getElementById('search-results');

    if (!form || !categorySelect || !results || !window.fetch || !window.history) {
        return;
    }

    const loadResults = async () => {
        const params = new URLSearchParams(new FormData(form));
        const pageUrl = `search.php?${params.toString()}`;
        params.set('partial', 'results');

        results.classList.add('loading');
        results.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(`search.php?${params.toString()}`, {
                headers: {'X-Requested-With': 'fetch'}
            });

            if (!response.ok) {
                throw new Error('Search request failed.');
            }

            results.innerHTML = await response.text();
            window.history.pushState({}, '', pageUrl);
        } catch (error) {
            window.location.href = pageUrl;
        } finally {
            results.classList.remove('loading');
            results.setAttribute('aria-busy', 'false');
        }
    };

    categorySelect.addEventListener('change', loadResults);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        loadResults();
    });
})();
</script>
<?php page_end(); ?>
