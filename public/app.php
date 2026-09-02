<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * One page per app: where it sits in the flow, what a store listing needs,
 * what the checklist says, and every move it can make from here.
 */

$appId = (int) ($_GET['id'] ?? $_POST['app_id'] ?? 0);
$self = 'app.php?id=' . $appId;

$stageLists = [
    'prepare' => 'production.php',
    'ready' => 'ready-apps.php',
    'sent' => 'sent-production.php',
    'live' => 'live-apps.php',
    'rejected' => 'sent-production.php?status=rejected',
    'suspended' => 'sent-production.php?status=suspended',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'update_details') {
            update_production_app_details($appId, $_POST);
            redirect_with($self, 'success', 'App details updated.');
        }

        if ($action === 'ready') {
            mark_app_ready($appId);
            redirect_with($self, 'success', 'App marked Ready for Production.');
        }

        if ($action === 'send') {
            send_app_to_production($appId);
            redirect_with($self, 'success', 'App sent for production.');
        }

        if ($action === 'set_result') {
            $result = (string) ($_POST['result'] ?? '');
            set_production_result($appId, $result);
            redirect_with($self, 'success', 'App marked as ' . ucfirst($result) . '.');
        }

        if ($action === 'to_prepare') {
            revert_app_to_prepare($appId);
            redirect_with($self, 'success', 'App moved back to Prepare Production.');
        }

        if ($action === 'to_ready') {
            revert_app_to_ready($appId);
            redirect_with($self, 'success', 'App moved back to Ready.');
        }

        if ($action === 'to_sent') {
            revert_app_to_sent($appId);
            redirect_with($self, 'success', 'App moved back to Sent.');
        }

        if ($action === 'toggle_ready_for_work') {
            $ready = (int) ($_POST['ready'] ?? 0) === 1;
            set_ready_for_work($appId, $ready);
            redirect_with(
                $self,
                'success',
                $ready ? 'App tagged Ready for Work.' : 'Ready for Work tag removed.',
                ['page' => $self, 'fields' => [
                    'action' => 'toggle_ready_for_work',
                    'app_id' => $appId,
                    'ready' => $ready ? 0 : 1,
                ]]
            );
        }

        if ($action === 'toggle_url_checked') {
            $checked = (int) ($_POST['checked'] ?? 0) === 1;
            set_url_checked($appId, $checked);
            redirect_with($self, 'success', $checked ? 'URL marked as checked.' : 'URL moved back to pending.');
        }

        if ($action === 'delete') {
            $app = get_production_app($appId);
            $back = $app ? ($stageLists[$app['status']] ?? 'production.php') : 'production.php';
            delete_production_app($appId);
            redirect_with($back, 'success', 'App deleted.');
        }
    } catch (Throwable $e) {
        redirect_with($self, 'error', $e->getMessage());
    }
}

$app = $appId > 0 ? get_production_app($appId) : null;

if (!$app) {
    page_start('App');
    ?>
    <section class="panel">
        <p class="empty block">This app was not found. It may have been deleted.</p>
        <div class="inline-actions">
            <a class="btn" href="production.php">Go to Prepare Production</a>
        </div>
    </section>
    <?php
    page_end();
    exit;
}

$status = (string) $app['status'];
$consoles = all_consoles();
$items = checklist_items();
$state = checklist_state($appId);
$doneTimes = checklist_done_times($appId);
$done = (int) $app['checklist_done'];
$total = count($items);
$complete = $done >= $total;
$domainUrl = app_domain_url_for($app);
$privacyUrl = $app['console_privacy_policy_url'] ?? null;
$backList = $stageLists[$status] ?? 'production.php';

$copyBlock = "App Name: " . (string) $app['name'] . "\n"
    . "Package: " . (string) ($app['package_name'] ?? '') . "\n"
    . "Privacy Policy: " . (string) ($privacyUrl ?? '') . "\n"
    . "App URL: " . (string) ($domainUrl ?? '');

function app_fact_row(string $label, ?string $value, string $empty = 'Not set'): void
{
    ?>
    <div class="publish-row">
        <span class="publish-label"><?= h($label) ?></span>
        <?php if ($value !== null && $value !== ''): ?>
            <div class="console-url">
                <code><?= h($value) ?></code>
                <button class="btn small copy-url" type="button" data-url="<?= h($value) ?>">Copy</button>
            </div>
        <?php else: ?>
            <span class="badge badge-gray"><?= h($empty) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

page_start($app['name']);
?>
<section class="panel">
    <div class="panel-heading">
        <h2><?= h($app['name']) ?></h2>
        <a class="btn small" href="<?= h($backList) ?>">Back to list</a>
    </div>

    <?php render_workflow_stepper($app); ?>

    <p class="hint app-detail-meta">
        <?= render_production_badge($status) ?>
        <?php if ((int) ($app['ready_for_work'] ?? 0) === 1): ?>
            <span class="badge badge-green">Ready for Work</span>
        <?php endif; ?>
        <?php if ((int) ($app['url_checked'] ?? 0) === 1): ?>
            <span class="badge badge-blue">URL checked</span>
        <?php endif; ?>
        &middot; #<?= $appId ?>
        &middot; Console: <?= h($app['console_name'] ?? 'Not assigned') ?>
        &middot; Created: <?= h($app['created_at']) ?>
        <?php if (!empty($app['sent_at'])): ?>&middot; Sent: <?= h($app['sent_at']) ?><?php endif; ?>
        <?php if (!empty($app['live_at'])): ?>&middot; Live: <?= h($app['live_at']) ?><?php endif; ?>
        <?php if (!empty($app['updated_at'])): ?>&middot; Updated: <?= h($app['updated_at']) ?><?php endif; ?>
    </p>

    <div class="inline-actions app-stage-actions">
        <?php if ($status === 'prepare'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="ready">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn" type="submit" <?= $complete ? '' : 'disabled' ?>>Mark Ready</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn primary" type="submit" <?= $complete ? '' : 'disabled' ?>>Send for Production</button>
            </form>
            <a class="btn small" href="production.php?app_id=<?= $appId ?>">Edit checklist</a>
            <?php if (!$complete): ?>
                <span class="hint">Complete all <?= $total ?> checklist items to move this app on.</span>
            <?php endif; ?>
        <?php elseif ($status === 'ready'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn primary" type="submit">Send for Production</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="to_prepare">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn" type="submit">Back to Prepare</button>
            </form>
        <?php elseif ($status === 'sent'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_result">
                <input type="hidden" name="result" value="live">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn primary" type="submit">Mark Live</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_result">
                <input type="hidden" name="result" value="rejected">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn" type="submit">Reject</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_result">
                <input type="hidden" name="result" value="suspended">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn" type="submit">Suspend</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="to_ready">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn" type="submit">Back to Ready</button>
            </form>
        <?php elseif ($status === 'live'): ?>
            <?php $tagged = (int) ($app['ready_for_work'] ?? 0) === 1; ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_ready_for_work">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <input type="hidden" name="ready" value="<?= $tagged ? 0 : 1 ?>">
                <button class="btn <?= $tagged ? '' : 'primary' ?>" type="submit">
                    <?= $tagged ? 'Remove Ready for Work' : 'Tag Ready for Work' ?>
                </button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="to_sent">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn" type="submit">Back to Sent</button>
            </form>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="set_result">
                <input type="hidden" name="result" value="live">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn primary" type="submit">Mark Live</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="to_sent">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn" type="submit">Back to Sent</button>
            </form>
        <?php endif; ?>

        <div class="action-menu-wrap">
            <button class="btn small action-menu-btn" type="button" aria-label="More actions">&#8942;</button>
            <div class="action-menu">
                <?php if (!in_array($status, ['prepare', 'ready'], true)): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="to_prepare">
                        <input type="hidden" name="app_id" value="<?= $appId ?>">
                        <button class="menu-item" type="submit">Back to Prepare</button>
                    </form>
                <?php endif; ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_url_checked">
                    <input type="hidden" name="app_id" value="<?= $appId ?>">
                    <input type="hidden" name="checked" value="<?= (int) ($app['url_checked'] ?? 0) === 1 ? 0 : 1 ?>">
                    <button class="menu-item" type="submit">
                        <?= (int) ($app['url_checked'] ?? 0) === 1 ? 'Mark URL pending' : 'Mark URL checked' ?>
                    </button>
                </form>
                <form method="post" onsubmit="return confirm('Delete this app? Its checklist and task history will also be removed.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="app_id" value="<?= $appId ?>">
                    <button class="menu-item danger" type="submit">Delete</button>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Store Listing</h2>
        <button class="btn small copy-url" type="button" data-url="<?= h($copyBlock) ?>">Copy All</button>
    </div>
    <?php app_fact_row('App Name', $app['name']); ?>
    <?php app_fact_row('Package Name', $app['package_name'] ?? null, 'Package not set'); ?>
    <?php app_fact_row('Application ID', $app['application_id'] ?? null, 'Not set'); ?>
    <?php app_fact_row('Privacy Policy', $privacyUrl, 'Console URL missing'); ?>
    <?php app_fact_row('App URL', $domainUrl, 'Console domain URL missing'); ?>
    <?php if (empty($app['console_id'])): ?>
        <p class="hint">Assign a Play Console below to fill the console URLs.</p>
    <?php endif; ?>
</section>

<section class="form-panel">
    <div class="panel-heading">
        <h2>Edit Details</h2>
    </div>
    <form method="post" class="stacked-form wide">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_details">
        <input type="hidden" name="app_id" value="<?= $appId ?>">
        <label>App Name
            <input type="text" name="name" value="<?= h($app['name']) ?>" maxlength="200" required>
        </label>
        <div class="form-row">
            <label>Package Name
                <input type="text" name="package_name" value="<?= h($app['package_name'] ?? '') ?>" maxlength="200">
            </label>
            <label>Application ID
                <input type="text" name="application_id" value="<?= h($app['application_id'] ?? '') ?>" maxlength="200">
            </label>
        </div>
        <label>Play Console
            <select name="console_id">
                <option value="0">No console</option>
                <?php foreach ($consoles as $console): ?>
                    <option value="<?= (int) $console['id'] ?>" <?= (int) ($app['console_id'] ?? 0) === (int) $console['id'] ? 'selected' : '' ?>>
                        <?= h($console['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary" type="submit">Save Details</button>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Checklist (<?= $done ?>/<?= $total ?>)</h2>
        <?php if ($status === 'prepare'): ?>
            <a class="btn small" href="production.php?app_id=<?= $appId ?>">Edit checklist</a>
        <?php else: ?>
            <span class="hint">Completed during Prepare Production.</span>
        <?php endif; ?>
    </div>
    <span class="progress-label"><?= $done ?> of <?= $total ?> complete</span>
    <div class="progress"><span style="width: <?= (int) round($done / max(1, $total) * 100) ?>%"></span></div>

    <div class="checklist-form">
        <?php foreach ($items as $key => $item): ?>
            <?php $isDone = !empty($state[$key]); ?>
            <div class="checklist-item <?= $isDone ? 'done' : '' ?>">
                <div class="checklist-item-main">
                    <?= $isDone
                        ? '<span class="badge badge-green">Done</span>'
                        : '<span class="badge badge-gray">Pending</span>' ?>
                    <span>
                        <strong><?= h($item['label']) ?></strong>
                        <small><?= h($item['description']) ?></small>
                        <?php if ($isDone && !empty($doneTimes[$key])): ?>
                            <small class="checklist-done-at">Done <?= h($doneTimes[$key]) ?></small>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Activity</h2>
        <a class="btn small" href="activity.php">All activity</a>
    </div>
    <?php $history = activity_for('app', $appId, 20); ?>
    <?php if (!$history): ?>
        <p class="empty block">Nothing recorded for this app yet.</p>
    <?php else: ?>
        <ul class="activity-list">
            <?php foreach ($history as $row): ?>
                <li>
                    <span class="activity-time"><?= h((string) $row['created_at']) ?></span>
                    <span class="activity-what">
                        <strong><?= h(activity_label((string) $row['action'])) ?></strong>
                        <?php if (!empty($row['detail'])): ?><small><?= h($row['detail']) ?></small><?php endif; ?>
                    </span>
                    <span class="activity-who"><?= h($row['admin_name'] ?? '—') ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<script>
document.querySelectorAll('.copy-url').forEach((button) => {
    button.addEventListener('click', () => {
        navigator.clipboard.writeText(button.dataset.url).then(() => {
            const label = button.textContent;
            button.textContent = 'Copied!';
            setTimeout(() => { button.textContent = label; }, 1500);
        });
    });
});
</script>
<?php page_end(); ?>
