<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$categories = all_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $iconPath = upload_icon($_FILES['icon'] ?? []);
        add_app($_POST, $iconPath);
        redirect_with('add-app.php', 'success', 'App added successfully.');
    } catch (Throwable $e) {
        redirect_with('add-app.php', 'error', $e->getMessage());
    }
}

page_start('Add App');
?>
<section class="form-panel">
    <form method="post" enctype="multipart/form-data" class="stacked-form wide">
        <?= csrf_field() ?>
        <label>App Name
            <input type="text" name="app_name" maxlength="200" required>
        </label>
        <label>Console
            <select name="category_id" required>
                <option value="">Choose console</option>
                <?php foreach ($categories as $category): ?>
                    <option value="<?= (int) $category['id'] ?>"><?= h($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="form-row">
            <label>Loading
                <select name="loading_status">
                    <option>Active</option>
                    <option>Inactive</option>
                </select>
            </label>
            <label>Ready Loading
                <select name="ready_loading_status">
                    <option>Ready</option>
                    <option>Not Ready</option>
                </select>
            </label>
        </div>
        <label>App Icon
            <input type="file" name="icon" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
            <small>JPG, PNG, or WEBP. Maximum 2MB.</small>
        </label>
        <button class="btn primary" type="submit">Add App</button>
    </form>
</section>
<?php page_end(); ?>
