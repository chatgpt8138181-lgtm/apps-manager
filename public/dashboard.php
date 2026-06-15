<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

function selected_dashboard_category(array $categories, string $categoryParam): array
{
    $showAll = $categoryParam === 'all';
    $selectedCategory = $showAll ? null : ($categories[0] ?? null);
    $selectedCategoryId = $showAll ? 0 : (int) $categoryParam;

    if (!$showAll) {
        foreach ($categories as $category) {
            if ((int) $category['id'] === $selectedCategoryId) {
                $selectedCategory = $category;
                break;
            }
        }
    }

    return [$showAll, $selectedCategory];
}

function render_category_table(array $category, array $counts): void
{
    $categoryId = (int) $category['id'];
    $categoryApps = sorted_apps($categoryId);
    $activeCount = count(array_filter($categoryApps, fn($app) => $app['loading_status'] === 'Active'));
    $readyCount = count(array_filter($categoryApps, fn($app) => $app['ready_loading_status'] === 'Ready'));
    ?>
    <section class="panel">
        <div class="panel-heading">
            <h2><?= h($category['name']) ?> (<?= (int) ($counts[$categoryId] ?? 0) ?>)</h2>
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
    <?php
}

function render_dashboard_category_content(array $categories, array $counts, bool $showAll, ?array $selectedCategory): void
{
    if (!$categories) {
        ?>
        <section class="panel">
            <p class="empty block">No categories found.</p>
        </section>
        <?php
        return;
    }

    if ($showAll) {
        ?>
        <div class="category-panels">
            <?php foreach ($categories as $category): ?>
                <?php render_category_table($category, $counts); ?>
            <?php endforeach; ?>
        </div>
        <?php
        return;
    }

    if (!$selectedCategory) {
        ?>
        <section class="panel">
            <p class="empty block">No categories found.</p>
        </section>
        <?php
        return;
    }

    render_category_table($selectedCategory, $counts);
}

$categories = all_categories();
$counts = category_counts();
$apps = sorted_apps();
$selectedCategoryParam = (string) ($_GET['category_id'] ?? '');
[$showAll, $selectedCategory] = selected_dashboard_category($categories, $selectedCategoryParam);
$selectedCategoryId = $selectedCategory ? (int) $selectedCategory['id'] : 0;

if (($_GET['partial'] ?? '') === 'category') {
    render_dashboard_category_content($categories, $counts, $showAll, $selectedCategory);
    exit;
}

page_start('Dashboard');
?>
<section class="stats-grid">
    <div class="stat"><span><?= count($categories) ?></span><p>Categories</p></div>
    <div class="stat"><span><?= count($apps) ?></span><p>Total Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['loading_status'] === 'Active')) ?></span><p>Active Apps</p></div>
    <div class="stat"><span><?= count(array_filter($apps, fn($app) => $app['ready_loading_status'] === 'Ready')) ?></span><p>Ready Apps</p></div>
</section>

<section class="dashboard-filter">
    <form method="get" class="inline-form" id="dashboard-category-form">
        <label>Categories
            <select name="category_id" id="dashboard-category-select">
                <option value="all" <?= $showAll ? 'selected' : '' ?>>All Categories</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>" <?= !$showAll && $selectedCategoryId === (int) $category['id'] ? 'selected' : '' ?>>
                        <?= h($category['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    </form>
</section>

<div id="category-results" class="category-results" aria-live="polite">
    <?php render_dashboard_category_content($categories, $counts, $showAll, $selectedCategory); ?>
</div>

<script>
(() => {
    const form = document.getElementById('dashboard-category-form');
    const select = document.getElementById('dashboard-category-select');
    const results = document.getElementById('category-results');

    if (!form || !select || !results || !window.fetch || !window.history) {
        return;
    }

    const loadCategory = async () => {
        const params = new URLSearchParams(new FormData(form));
        const pageUrl = `dashboard.php?${params.toString()}`;
        params.set('partial', 'category');

        results.classList.add('loading');
        results.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(`dashboard.php?${params.toString()}`, {
                headers: {'X-Requested-With': 'fetch'}
            });

            if (!response.ok) {
                throw new Error('Category request failed.');
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

    select.addEventListener('change', loadCategory);
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        loadCategory();
    });
})();
</script>
<?php page_end(); ?>
