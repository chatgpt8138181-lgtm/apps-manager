<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$status = (string) ($_GET['status'] ?? 'sent');
if ($status === 'live') {
    /* Live apps have their own page, so send the reader there. */
    header('Location: live-apps.php');
    exit;
}
if (!in_array($status, ['sent', 'rejected', 'suspended'], true)) {
    $status = 'sent';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'sent-production.php?status=' . urlencode((string) ($_POST['return_status'] ?? 'sent'));

    try {
        if (($_POST['action'] ?? '') === 'bulk') {
            $bulkAction = (string) ($_POST['bulk_action'] ?? '');
            $result = apply_bulk_production_action($bulkAction, (array) ($_POST['app_ids'] ?? []));
            $to = $bulkAction === 'live' ? 'live-apps.php' : $return;
            redirect_with($to, 'success', bulk_result_message($result, 'updated'));
        }

        if (($_POST['action'] ?? '') === 'set_result') {
            $result = (string) ($_POST['result'] ?? '');
            set_production_result((int) ($_POST['app_id'] ?? 0), $result);
            $to = $result === 'live' ? 'live-apps.php' : $return;
            redirect_with($to, 'success', 'App marked as ' . ucfirst($result) . '.');
        }

        if (($_POST['action'] ?? '') === 'delete') {
            delete_production_app((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App deleted.');
        }

        if (($_POST['action'] ?? '') === 'to_ready') {
            revert_app_to_ready((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App moved back to Ready.');
        }

        if (($_POST['action'] ?? '') === 'to_prepare') {
            revert_app_to_prepare((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App moved back to Prepare Production.');
        }

        if (($_POST['action'] ?? '') === 'to_sent') {
            revert_app_to_sent((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App moved back to Production Apps.');
        }

        if (($_POST['action'] ?? '') === 'update_details') {
            $appId = (int) ($_POST['app_id'] ?? 0);
            update_production_app_details($appId, $_POST);
            redirect_with('sent-production.php?status=' . urlencode($status) . '&app_id=' . $appId, 'success', 'App details updated.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

function render_sent_apps_table(array $apps, string $status): void
{
    $bulk = $status === 'sent'
        ? [
            ['value' => 'live', 'label' => 'Mark Live', 'class' => 'primary'],
            ['value' => 'rejected', 'label' => 'Reject'],
            ['value' => 'suspended', 'label' => 'Suspend'],
            ['value' => 'to_ready', 'label' => 'Back to Ready'],
        ]
        : [
            ['value' => 'live', 'label' => 'Mark Live', 'class' => 'primary'],
            ['value' => 'to_sent', 'label' => 'Back to Production Apps'],
        ];
    ?>
    <div class="bulk-form">
    <form method="post" class="bulk-submit" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk">
        <input type="hidden" name="bulk_action" value="">
        <input type="hidden" name="return_status" value="<?= h($status) ?>">
    </form>
    <?php render_bulk_bar($bulk); ?>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th class="col-select"><input type="checkbox" class="bulk-all" aria-label="Select all"></th>
                <th>App Name</th>
                <th>Package</th>
                <th>Status</th>
                <th>Sent At</th>
                <th>Live At</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($apps as $app): ?>
                <tr>
                    <td class="col-select"><input type="checkbox" class="bulk-row" value="<?= (int) $app['id'] ?>"></td>
                    <td><?= h($app['name']) ?></td>
                    <td>
                        <?php if (!empty($app['package_name'])): ?>
                            <div class="console-url">
                                <code><?= h($app['package_name']) ?></code>
                                <button class="btn small copy-url" type="button" data-url="<?= h($app['package_name']) ?>">Copy</button>
                            </div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?= render_production_badge($app['status']) ?></td>
                    <td><?= h($app['sent_at'] ?? '—') ?></td>
                    <td><?= h($app['live_at'] ?? '—') ?></td>
                    <td class="actions">
                        <a class="btn small" href="app.php?id=<?= (int) $app['id'] ?>">Open</a>
                        <?php if ($app['status'] === 'sent'): ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="set_result">
                                <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                <input type="hidden" name="result" value="live">
                                <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                <button class="btn small primary" type="submit">Mark Live</button>
                            </form>
                        <?php endif; ?>
                        <div class="action-menu-wrap">
                            <button class="btn small action-menu-btn" type="button" aria-label="More actions">&#8942;</button>
                            <div class="action-menu">
                                <?php foreach (['live' => 'Mark Live', 'rejected' => 'Reject', 'suspended' => 'Suspend'] as $result => $label): ?>
                                    <?php if ($app['status'] === $result) continue; ?>
                                    <?php if ($app['status'] === 'sent' && $result === 'live') continue; ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="set_result">
                                        <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                        <input type="hidden" name="result" value="<?= h($result) ?>">
                                        <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                        <button class="menu-item" type="submit"><?= h($label) ?></button>
                                    </form>
                                <?php endforeach; ?>
                                <?php if ($app['status'] === 'sent'): ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="to_ready">
                                        <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                        <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                        <button class="menu-item" type="submit">Back to Ready</button>
                                    </form>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="to_prepare">
                                        <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                        <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                        <button class="menu-item" type="submit">Back to Prepare</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="to_sent">
                                        <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                        <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                        <button class="menu-item" type="submit">Back to Production Apps</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Delete this app? Its checklist and task history will also be removed.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                    <input type="hidden" name="return_status" value="<?= h($status) ?>">
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

$counts = production_status_counts();
$consoles = all_consoles();
$listQuery = trim((string) ($_GET['q'] ?? ''));
$listConsole = (int) ($_GET['console'] ?? 0);
$sentPage = paginate(filter_production_apps(production_apps_by_status($status), $listQuery, $listConsole));
$apps = $sentPage['rows'];
$unassigned = array_values(array_filter($apps, fn($app) => empty($app['console_id'])));
$tabs = [
    'sent' => 'Sent for Production',
    'rejected' => 'Rejected',
    'suspended' => 'Suspended',
];

$selectedId = (int) ($_GET['app_id'] ?? 0);
$selected = null;
if ($selectedId > 0) {
    $selected = get_production_app($selectedId);
    if ($selected && $selected['status'] === 'live') {
        header('Location: live-apps.php?app_id=' . $selectedId);
        exit;
    }
    if (!$selected || !in_array($selected['status'], ['sent', 'rejected', 'suspended'], true)) {
        $selected = null;
    }
}

page_start('Production Apps');
?>
<?php if ($selected): ?>
    <?php render_app_details_panel($selected, $consoles, 'sent-production.php?status=' . urlencode($status)); ?>
    <?php render_checklist_summary_panel($selected); ?>
<?php else: ?>
<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="<?= $status === $key ? 'active' : '' ?>" href="sent-production.php?status=<?= h($key) ?>">
            <?= h($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
    <a class="tab-link" href="live-apps.php">Live (<?= (int) $counts['live'] ?>) &rarr;</a>
</div>

<section class="panel">
    <?php render_list_filters('sent-production.php', $listQuery, $listConsole, $consoles, ['status' => $status]); ?>
    <div class="panel-heading">
        <h2><?= h($tabs[$status]) ?> (<?= (int) $sentPage['total'] ?>)</h2>
        <?php if ($status === 'sent'): ?>
            <span class="hint">Set the Play Console review result. Marking an app Live moves it to Live Apps.</span>
        <?php endif; ?>
    </div>

    <?php if (!$apps): ?>
        <?php if ($listQuery !== '' || $listConsole > 0): ?>
            <p class="empty block">No app matches this filter.</p>
        <?php else: ?>
            <p class="empty block">No apps in this list.</p>
        <?php endif; ?>
    <?php endif; ?>

    <?php foreach ($consoles as $console): ?>
        <?php
        $consoleApps = array_values(array_filter(
            $apps,
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
                <?php render_sent_apps_table($consoleApps, $status); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($unassigned): ?>
        <div class="app-group" data-group-key="no-console">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span>No Console (<?= count($unassigned) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <p class="hint">Assign a Play Console from Production &rarr; Manage &rarr; Verify App Details.</p>
                <?php render_sent_apps_table($unassigned, $status); ?>
            </div>
        </div>
    <?php endif; ?>

    <?php render_pager($sentPage, 'sent-production.php', ['status' => $status, 'q' => $listQuery, 'console' => $listConsole]); ?>
</section>
<?php endif; ?>

<script>
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
