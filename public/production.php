<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $appId = (int) ($_POST['app_id'] ?? 0);
    $return = $appId > 0 ? 'production.php?app_id=' . $appId : 'production.php';

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $newId = add_production_app($_POST);
            redirect_with('production.php?app_id=' . $newId, 'success', 'App added to Prepare Production. Complete the checklist.');
        }

        if ($action === 'update_details') {
            update_production_app_details($appId, $_POST);
            redirect_with($return, 'success', 'App details updated.');
        }

        if ($action === 'save_checklist') {
            $done = array_keys((array) ($_POST['items'] ?? []));
            save_checklist($appId, $done);
            redirect_with($return, 'success', 'Checklist saved.');
        }

        if ($action === 'send') {
            send_app_to_production($appId);
            redirect_with('sent-production.php', 'success', 'App sent for production.');
        }

        if ($action === 'delete') {
            delete_production_app($appId);
            redirect_with('production.php', 'success', 'App deleted.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

$items = checklist_items();
$totalItems = count($items);
$prepareApps = production_apps_by_status('prepare');
$selectedId = (int) ($_GET['app_id'] ?? 0);
$selected = null;

if ($selectedId > 0) {
    $selected = get_production_app($selectedId);
    if (!$selected || $selected['status'] !== 'prepare') {
        $selected = null;
    }
}

$selectedState = $selected ? checklist_state((int) $selected['id']) : [];
$selectedDone = $selected ? (int) $selected['checklist_done'] : 0;

page_start('Prepare Production');
?>
<section class="form-panel">
    <h2>Add App to Prepare Production</h2>
    <form method="post" class="stacked-form wide">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>App Name
            <input type="text" name="name" maxlength="200" required>
        </label>
        <div class="form-row">
            <label>Package Name
                <input type="text" name="package_name" maxlength="200" placeholder="com.example.app">
            </label>
            <label>Application ID
                <input type="text" name="application_id" maxlength="200" placeholder="com.example.app">
            </label>
        </div>
        <div class="form-row">
            <label>Privacy Policy URL
                <input type="text" name="privacy_policy_url" maxlength="255" placeholder="https://">
            </label>
            <label>App Domain URL
                <input type="text" name="app_domain_url" maxlength="255" placeholder="https://">
            </label>
        </div>
        <button class="btn primary" type="submit">Add App</button>
    </form>
</section>

<?php if ($selected): ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>Checklist — <?= h($selected['name']) ?> (<?= $selectedDone ?>/<?= $totalItems ?>)</h2>
            <a class="btn small" href="production.php">Close</a>
        </div>
        <span class="progress-label"><?= $selectedDone ?> of <?= $totalItems ?> complete</span>
        <div class="progress"><span style="width: <?= (int) round($selectedDone / $totalItems * 100) ?>%"></span></div>

        <form method="post" class="checklist-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_checklist">
            <input type="hidden" name="app_id" value="<?= (int) $selected['id'] ?>">
            <?php foreach ($items as $key => $item): ?>
                <label class="checklist-item <?= !empty($selectedState[$key]) ? 'done' : '' ?>">
                    <input type="checkbox" name="items[<?= h($key) ?>]" value="1" <?= !empty($selectedState[$key]) ? 'checked' : '' ?>>
                    <span>
                        <strong><?= h($item['label']) ?></strong>
                        <small><?= h($item['description']) ?></small>
                    </span>
                </label>
            <?php endforeach; ?>
            <div class="inline-actions">
                <button class="btn primary" type="submit">Save Checklist</button>
            </div>
        </form>

        <form method="post" class="inline-actions">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send">
            <input type="hidden" name="app_id" value="<?= (int) $selected['id'] ?>">
            <button class="btn primary" type="submit" <?= $selectedDone >= $totalItems ? '' : 'disabled' ?>>
                Send for Production
            </button>
            <?php if ($selectedDone < $totalItems): ?>
                <span class="hint">Complete all <?= $totalItems ?> checklist items to enable this button.</span>
            <?php endif; ?>
        </form>
    </section>

    <section class="form-panel">
        <h2>Edit App Details</h2>
        <form method="post" class="stacked-form wide">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_details">
            <input type="hidden" name="app_id" value="<?= (int) $selected['id'] ?>">
            <label>App Name
                <input type="text" name="name" value="<?= h($selected['name']) ?>" maxlength="200" required>
            </label>
            <div class="form-row">
                <label>Package Name
                    <input type="text" name="package_name" value="<?= h($selected['package_name']) ?>" maxlength="200">
                </label>
                <label>Application ID
                    <input type="text" name="application_id" value="<?= h($selected['application_id']) ?>" maxlength="200">
                </label>
            </div>
            <div class="form-row">
                <label>Privacy Policy URL
                    <input type="text" name="privacy_policy_url" value="<?= h($selected['privacy_policy_url']) ?>" maxlength="255">
                </label>
                <label>App Domain URL
                    <input type="text" name="app_domain_url" value="<?= h($selected['app_domain_url']) ?>" maxlength="255">
                </label>
            </div>
            <button class="btn primary" type="submit">Save Details</button>
        </form>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <h2>Prepare Production (<?= count($prepareApps) ?>)</h2>
        <span class="hint">Send for Production unlocks at <?= $totalItems ?>/<?= $totalItems ?>.</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>App Name</th>
                <th>Package</th>
                <th>Checklist</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$prepareApps): ?>
                <tr><td colspan="5" class="empty">No apps in Prepare Production.</td></tr>
            <?php endif; ?>
            <?php foreach ($prepareApps as $app): ?>
                <?php $done = (int) $app['checklist_done']; ?>
                <tr>
                    <td><?= h($app['name']) ?></td>
                    <td><?= h($app['package_name'] ?? '—') ?></td>
                    <td>
                        <span class="progress-label"><?= $done ?>/<?= $totalItems ?></span>
                        <div class="progress"><span style="width: <?= (int) round($done / $totalItems * 100) ?>%"></span></div>
                    </td>
                    <td><?= h($app['created_at']) ?></td>
                    <td class="actions">
                        <a class="btn small" href="production.php?app_id=<?= (int) $app['id'] ?>">Checklist</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <button class="btn small primary" type="submit" <?= $done >= $totalItems ? '' : 'disabled' ?>>Send</button>
                        </form>
                        <form method="post" onsubmit="return confirm('Delete this app and its checklist?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <button class="btn danger small" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_end(); ?>
