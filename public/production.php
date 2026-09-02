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

        if ($action === 'bulk') {
            $result = apply_bulk_production_action(
                (string) ($_POST['bulk_action'] ?? ''),
                (array) ($_POST['app_ids'] ?? [])
            );
            redirect_with('production.php', 'success', bulk_result_message($result, 'updated'));
        }

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
            save_checklist($appId, $done, (array) ($_POST['fields'] ?? []));
            redirect_with($return, 'success', 'Checklist saved.');
        }

        if ($action === 'send') {
            send_app_to_production($appId);
            redirect_with('sent-production.php', 'success', 'App sent for production.');
        }

        if ($action === 'ready') {
            mark_app_ready($appId);
            redirect_with('ready-apps.php', 'success', 'App marked Ready for Production.');
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
$consoles = all_consoles();
$listQuery = trim((string) ($_GET['q'] ?? ''));
$listConsole = (int) ($_GET['console'] ?? 0);
$prepareAll = filter_production_apps(production_apps_by_status('prepare'), $listQuery, $listConsole);
$preparePage = paginate($prepareAll);
$prepareApps = $preparePage['rows'];
$selectedId = (int) ($_GET['app_id'] ?? 0);
$selected = null;

if ($selectedId > 0) {
    $selected = get_production_app($selectedId);
    if (!$selected || $selected['status'] !== 'prepare') {
        $selected = null;
    }
}

$selectedState = $selected ? checklist_state((int) $selected['id']) : [];
$selectedTimes = $selected ? checklist_done_times((int) $selected['id']) : [];
$selectedDone = $selected ? (int) $selected['checklist_done'] : 0;
$selectedDomainUrl = $selected ? app_domain_url_for($selected) : null;

page_start('Prepare Production');
?>
<?php if (!$selected): ?>
<section class="form-panel add-panel">
    <div class="app-group" data-group-key="add-form">
        <button class="app-group-toggle" type="button" aria-expanded="false">
            <span>+ Add App to Prepare Production</span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="app-group-body">
    <form method="post" class="stacked-form wide">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>App Name
            <input type="text" name="name" maxlength="200" required>
        </label>
        <label>Play Console
            <select name="console_id">
                <option value="0">No console</option>
                <?php foreach ($consoles as $console): ?>
                    <option value="<?= (int) $console['id'] ?>"><?= h($console['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary" type="submit">Add App</button>
    </form>
        </div>
    </div>
</section>
<?php endif; ?>

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
                <?php
                $fieldName = $item['field'] ?? null;
                $consoleUrlKey = $item['console_url'] ?? null;
                $isDone = !empty($selectedState[$key]);
                $hasField = $fieldName || $consoleUrlKey;
                ?>
                <div class="checklist-item <?= $isDone ? 'done' : '' ?><?= $hasField && $isDone ? ' field-open' : '' ?>">
                    <label class="checklist-item-main">
                        <input type="checkbox" name="items[<?= h($key) ?>]" value="1" <?= $isDone ? 'checked' : '' ?>>
                        <span>
                            <strong><?= h($item['label']) ?></strong>
                            <small><?= h($item['description']) ?></small>
                            <?php if ($isDone && !empty($selectedTimes[$key])): ?>
                                <small class="checklist-done-at">Done <?= h($selectedTimes[$key]) ?></small>
                            <?php endif; ?>
                        </span>
                    </label>
                    <?php if ($fieldName): ?>
                        <div class="checklist-field">
                            <input type="text"
                                   name="fields[<?= h($fieldName) ?>]"
                                   value="<?= h($selected[$fieldName]) ?>"
                                   maxlength="200"
                                   placeholder="<?= h($item['placeholder'] ?? '') ?>">
                        </div>
                    <?php elseif ($consoleUrlKey): ?>
                        <?php
                        $consoleUrl = $consoleUrlKey === 'app_domain_url'
                            ? $selectedDomainUrl
                            : ($selected['console_' . $consoleUrlKey] ?? null);
                        ?>
                        <div class="checklist-field">
                            <?php if (empty($selected['console_id'])): ?>
                                <span class="hint">Assign a Play Console first (Verify App Details below).</span>
                            <?php elseif (!$consoleUrl): ?>
                                <span class="hint">This console has no URL set yet. <a href="consoles.php"><strong>Add it on Consoles</strong></a>.</span>
                            <?php else: ?>
                                <div class="console-url">
                                    <code><?= h($consoleUrl) ?></code>
                                    <button class="btn small copy-url" type="button" data-url="<?= h($consoleUrl) ?>">Copy</button>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="inline-actions">
                <button class="btn primary" type="submit">Save Checklist</button>
            </div>
        </form>

        <div class="inline-actions">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ready">
                <input type="hidden" name="app_id" value="<?= (int) $selected['id'] ?>">
                <button class="btn" type="submit" <?= $selectedDone >= $totalItems ? '' : 'disabled' ?>>
                    Ready for Production
                </button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="app_id" value="<?= (int) $selected['id'] ?>">
                <button class="btn primary" type="submit" <?= $selectedDone >= $totalItems ? '' : 'disabled' ?>>
                    Send for Production
                </button>
            </form>
            <?php if ($selectedDone < $totalItems): ?>
                <span class="hint">Complete all <?= $totalItems ?> checklist items to enable these buttons.</span>
            <?php endif; ?>
        </div>
    </section>

    <section class="form-panel">
        <div class="panel-heading">
            <h2>Verify App Details — <?= h($selected['name']) ?></h2>
            <a class="btn small" href="production.php">Close</a>
        </div>
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
                <label>Privacy Policy URL (from console)
                    <input type="text" value="<?= h($selected['console_privacy_policy_url'] ?? '') ?>" placeholder="Set on Consoles page" readonly>
                </label>
                <label>App Domain URL (from console)
                    <input type="text" value="<?= h($selectedDomainUrl ?? '') ?>" placeholder="Set on Consoles page" readonly>
                </label>
            </div>
            <label>Play Console
                <select name="console_id">
                    <option value="0">No console</option>
                    <?php foreach ($consoles as $console): ?>
                        <option value="<?= (int) $console['id'] ?>" <?= (int) $selected['console_id'] === (int) $console['id'] ? 'selected' : '' ?>>
                            <?= h($console['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="btn primary" type="submit">Save Details</button>
        </form>
    </section>
<?php endif; ?>

<?php if (!$selected): ?>
<?php
function render_prepare_apps_table(array $apps, int $totalItems): void
{
    ?>
    <div class="bulk-form">
    <form method="post" class="bulk-submit" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk">
        <input type="hidden" name="bulk_action" value="">
    </form>
    <?php render_bulk_bar([
        ['value' => 'ready', 'label' => 'Mark Ready'],
        ['value' => 'send', 'label' => 'Send for Production', 'class' => 'primary'],
        ['value' => 'delete', 'label' => 'Delete', 'class' => 'danger',
         'confirm' => 'Delete the selected apps? Their checklists and task history go too.'],
    ]); ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="col-select"><input type="checkbox" class="bulk-all" aria-label="Select all"></th>
                <th>App Name</th>
                <th>Package</th>
                <th>Checklist</th>
                <th>Created</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $app): ?>
                <?php $done = (int) $app['checklist_done']; ?>
                <tr>
                    <td class="col-select"><input type="checkbox" class="bulk-row" value="<?= (int) $app['id'] ?>"></td>
                    <td><?= h($app['name']) ?></td>
                    <td><?= h($app['package_name'] ?? '—') ?></td>
                    <td>
                        <span class="progress-label"><?= $done ?>/<?= $totalItems ?></span>
                        <div class="progress"><span style="width: <?= (int) round($done / $totalItems * 100) ?>%"></span></div>
                    </td>
                    <td><?= h($app['created_at']) ?></td>
                    <td class="actions">
                        <a class="btn small" href="app.php?id=<?= (int) $app['id'] ?>">Open</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="ready">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <button class="btn small primary" type="submit" <?= $done >= $totalItems ? '' : 'disabled' ?>>Ready</button>
                        </form>
                        <div class="action-menu-wrap">
                            <button class="btn small action-menu-btn" type="button" aria-label="More actions">&#8942;</button>
                            <div class="action-menu">
                                <form method="post">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="send">
                                    <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                    <button class="menu-item" type="submit" <?= $done >= $totalItems ? '' : 'disabled' ?>>Send for Production</button>
                                </form>
                                <form method="post" onsubmit="return confirm('Delete this app and its checklist?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                    <button class="menu-item danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    </div>
    <?php
}

$unassignedPrepare = array_values(array_filter($prepareApps, fn($app) => empty($app['console_id'])));
?>
<section class="panel">
    <?php render_list_filters('production.php', $listQuery, $listConsole, $consoles); ?>
    <div class="panel-heading">
        <h2>Prepare Production (<?= (int) $preparePage['total'] ?>)</h2>
        <span class="hint">Send for Production unlocks at <?= $totalItems ?>/<?= $totalItems ?>.</span>
    </div>

    <?php if (!$prepareApps): ?>
        <?php if ($listQuery !== '' || $listConsole > 0): ?>
            <p class="empty block">No app matches this filter.</p>
        <?php else: ?>
            <p class="empty block">No apps in Prepare Production.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($consoles as $console): ?>
        <?php
        $consoleApps = array_values(array_filter(
            $prepareApps,
            fn($app) => (int) $app['console_id'] === (int) $console['id']
        ));
        if (!$consoleApps) {
            continue;
        }
        ?>
        <div class="app-group" data-group-key="console-<?= (int) $console['id'] ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span><?= h($console['name']) ?> (<?= count($consoleApps) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <?php render_prepare_apps_table($consoleApps, $totalItems); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($unassignedPrepare): ?>
        <div class="app-group" data-group-key="no-console">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span>No Console (<?= count($unassignedPrepare) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <p class="hint">Assign a Play Console from Manage &rarr; Verify App Details.</p>
                <?php render_prepare_apps_table($unassignedPrepare, $totalItems); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php render_pager($preparePage, 'production.php', ['q' => $listQuery, 'console' => $listConsole]); ?>
</section>
<?php endif; ?>

<script>
document.querySelectorAll('.checklist-item').forEach((item) => {
    const checkbox = item.querySelector('input[type="checkbox"]');
    if (!checkbox) {
        return;
    }

    const sync = () => {
        item.classList.toggle('done', checkbox.checked);
        if (item.querySelector('.checklist-field')) {
            item.classList.toggle('field-open', checkbox.checked);
        }
    };

    checkbox.addEventListener('change', sync);
    sync();
});

document.querySelectorAll('.copy-url').forEach((button) => {
    button.addEventListener('click', () => {
        navigator.clipboard.writeText(button.dataset.url).then(() => {
            button.textContent = 'Copied!';
            setTimeout(() => { button.textContent = 'Copy'; }, 1500);
        });
    });
});
</script>
<?php page_end(); ?>
