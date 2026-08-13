<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            add_category((string) ($_POST['name'] ?? ''));
            redirect_with('categories.php', 'success', 'Category added.');
        }
        if ($action === 'delete') {
            delete_category((int) ($_POST['category_id'] ?? 0));
            redirect_with('categories.php', 'success', 'Category deleted.');
        }
    } catch (Throwable $e) {
        redirect_with('categories.php', 'error', $e->getMessage());
    }
}

$categories = all_categories();
$counts = category_counts();

page_start('Console Names');
?>
<section class="form-panel">
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Category Name
            <input type="text" name="name" maxlength="150" required>
        </label>
        <button class="btn primary" type="submit">Add Category</button>
    </form>
</section>

<section class="panel">
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Category</th>
                <th>Total Apps</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($categories as $category): ?>
                <tr>
                    <td><?= h($category['name']) ?></td>
                    <td><?= (int) ($counts[(int) $category['id']] ?? 0) ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this category and its apps?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                            <button class="btn danger" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_end(); ?>
