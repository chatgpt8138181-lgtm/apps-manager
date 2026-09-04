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

        if ($action === 'update_loading') {
            update_app_statuses(
                $appId,
                (string) ($_POST['loading_status'] ?? ''),
                (string) ($_POST['ready_loading_status'] ?? '')
            );
            redirect_with($self, 'success', 'Loading status updated.');
        }

        if ($action === 'start_publishing') {
            start_publishing($appId);
            redirect_with($self, 'success', 'App added to Prepare Production.');
        }

        if ($action === 'stop_publishing') {
            stop_publishing($appId);
            redirect_with($self, 'success', 'App removed from the publishing flow.');
        }

        if ($action === 'save_domain_url') {
            set_app_domain_url($appId, (string) ($_POST['domain_url'] ?? ''));
            redirect_with($self, 'success', 'Domain URL saved. Its check status is back to pending.');
        }

        if ($action === 'save_ads') {
            $current = get_production_app($appId);
            $existing = $current ? ads_config_for($current) : [];
            $keys = (array) ($_POST['ads_key'] ?? []);
            $values = (array) ($_POST['ads_value'] ?? []);
            $locked = (array) ($_POST['ads_locked'] ?? []);

            $config = [];
            foreach ($keys as $index => $key) {
                $key = trim((string) $key);
                if ($key === '') {
                    continue;
                }
                /* A value the form cannot edit keeps whatever it already had. */
                if (!empty($locked[$index]) && array_key_exists($key, $existing)) {
                    $config[$key] = $existing[$key];
                    continue;
                }
                $value = trim((string) ($values[$index] ?? ''));
                $config[$key] = $key === 'versionCode' ? (int) $value : $value;
            }

            if (!$config) {
                throw new RuntimeException('Add at least one field before saving.');
            }

            save_app_ads($appId, $config);
            redirect_with($self, 'success', 'Ads config saved.');
        }

        if ($action === 'save_ads_raw') {
            save_app_ads_raw($appId, (string) ($_POST['ads_raw'] ?? ''));
            redirect_with($self, 'success', 'Ads config saved.');
        }

        if ($action === 'reset_ads') {
            save_app_ads($appId, ads_default_template());
            redirect_with($self, 'success', 'Ads config reset to the default template.');
        }

        if ($action === 'rebuild_domain_url') {
            $app = get_production_app($appId);
            $built = $app ? build_app_domain_url($app) : null;
            if ($built === null) {
                throw new RuntimeException('Assign a console with a domain URL first.');
            }
            set_app_domain_url($appId, $built);
            redirect_with($self, 'success', 'Domain URL rebuilt from the console domain.');
        }

        if ($action === 'store_sync') {
            $link = trim((string) ($_POST['store_url'] ?? ''));
            if ($link !== '') {
                $save = db()->prepare('UPDATE apps SET store_url = ? WHERE id = ?');
                $save->execute([$link, $appId]);
            }
            $result = sync_app_with_store($appId);
            redirect_with($self, 'success', 'Updated from the Play Store: ' . $result['name'] . '.');
        }

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
$adsConfig = ads_config_for($app);
$adsFile = ads_file_for($app);
$adsLabels = ads_placement_labels();
$adsPath = ads_folder_path($app);
$privacyUrl = $app['console_privacy_policy_url'] ?? null;
$backList = $stageLists[$status] ?? 'production.php';

$copyBlock = "App Name: " . (string) $app['name'] . "\n"
    . "Package: " . (string) ($app['package_name'] ?? '') . "\n"
    . "Privacy Policy: " . (string) ($privacyUrl ?? '') . "\n"
    . "Domain URL: " . (string) ($domainUrl ?? '');

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
        <h2 class="app-title">
            <img class="app-icon" src="<?= h(app_icon_url($app['icon_path'] ?? null)) ?>" alt="">
            <?= h($app['name']) ?>
        </h2>
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
        <?php elseif ($status === 'none'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start_publishing">
                <input type="hidden" name="app_id" value="<?= $appId ?>">
                <button class="btn primary" type="submit">Start publishing</button>
            </form>
            <span class="hint">This app is only in the loading rotation so far.</span>
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
                <?php if ($status === 'prepare'): ?>
                    <form method="post">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="stop_publishing">
                        <input type="hidden" name="app_id" value="<?= $appId ?>">
                        <button class="menu-item" type="submit">Remove from publishing</button>
                    </form>
                <?php endif; ?>
                <?php if (!in_array($status, ['prepare', 'ready', 'none'], true)): ?>
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

<section class="form-panel">
    <div class="panel-heading">
        <h2>Loading</h2>
        <a class="btn small" href="rotations.php">Rotations</a>
    </div>
    <form method="post" class="inline-form loading-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_loading">
        <input type="hidden" name="app_id" value="<?= $appId ?>">
        <label>Loading
            <select name="loading_status">
                <option <?= $app['loading_status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                <option <?= $app['loading_status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </label>
        <label>Ready to Load
            <select name="ready_loading_status">
                <option <?= $app['ready_loading_status'] === 'Ready' ? 'selected' : '' ?>>Ready</option>
                <option <?= $app['ready_loading_status'] === 'Not Ready' ? 'selected' : '' ?>>Not Ready</option>
            </select>
        </label>
        <button class="btn primary" type="submit">Save</button>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Store Listing</h2>
        <div class="inline-actions">
            <?php if (!empty($app['store_url'])): ?>
                <a class="btn small" href="<?= h($app['store_url']) ?>" target="_blank" rel="noopener">Open on Play Store</a>
            <?php endif; ?>
            <button class="btn small copy-url" type="button" data-url="<?= h($copyBlock) ?>">Copy All</button>
        </div>
    </div>

    <form method="post" class="inline-form store-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="store_sync">
        <input type="hidden" name="app_id" value="<?= $appId ?>">
        <label>Play Store link <small>(optional &mdash; the package is enough)</small>
            <input type="text" name="store_url" value="<?= h($app['store_url'] ?? '') ?>"
                   placeholder="https://play.google.com/store/apps/details?id=...">
        </label>
        <button class="btn" type="submit">Fetch from Play Store</button>
    </form>
    <?php if (!empty($app['store_checked_at'])): ?>
        <p class="hint">Last read from the store: <?= h($app['store_checked_at']) ?></p>
    <?php endif; ?>
    <?php app_fact_row('App Name', $app['name']); ?>
    <?php app_fact_row('Package Name', $app['package_name'] ?? null, 'Package not set'); ?>
    <?php app_fact_row('Application ID', $app['application_id'] ?? null, 'Not set'); ?>
    <?php app_fact_row('Privacy Policy', $privacyUrl, 'Console URL missing'); ?>
    <div class="publish-row">
        <span class="publish-label">Domain URL</span>
        <div class="console-url">
            <?php if (!empty($domainUrl)): ?>
                <code><?= h($domainUrl) ?></code>
                <button class="btn small copy-url" type="button" data-url="<?= h($domainUrl) ?>">Copy</button>
                <button class="btn small domain-url-toggle" type="button">Edit</button>
            <?php else: ?>
                <span class="badge badge-gray">Not set</span>
                <button class="btn small domain-url-toggle" type="button">Set URL</button>
            <?php endif; ?>
        </div>
        <form method="post" class="domain-url-form" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_domain_url">
            <input type="hidden" name="app_id" value="<?= $appId ?>">
            <input type="text" name="domain_url" value="<?= h($app['domain_url'] ?? '') ?>"
                   maxlength="255" placeholder="https://">
            <button class="btn small primary" type="submit">Save</button>
            <button class="btn small domain-url-cancel" type="button">Cancel</button>
        </form>
    </div>
    <p class="hint domain-url-hint">
        <?php if (!empty($app['console_app_domain_url'])): ?>
            Console domain: <code><?= h($app['console_app_domain_url']) ?></code>
            <?php $suggested = build_app_domain_url($app); ?>
            <?php if ($suggested !== null && $suggested !== trim((string) ($app['domain_url'] ?? ''))): ?>
                &middot; from the app's current name it would be <code><?= h($suggested) ?></code>
                <form method="post" class="inline-post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rebuild_domain_url">
                    <input type="hidden" name="app_id" value="<?= $appId ?>">
                    <button class="btn small" type="submit">Use that</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            Assign a console with a domain URL to fill this automatically.
        <?php endif; ?>
    </p>
    <?php if (empty($app['console_id'])): ?>
        <p class="hint">Assign a Play Console below to fill the console URLs.</p>
    <?php endif; ?>
</section>

<section class="panel ads-panel">
    <div class="panel-heading">
        <h2>Ads Config</h2>
        <div class="inline-actions">
            <?php if ($adsPath !== null): ?>
                <a class="btn small primary" href="ads-download.php?app=<?= $appId ?>">Download folder (ZIP)</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($adsPath !== null): ?>
        <p class="hint ads-target">
            Folder <code><?= h($adsPath) ?>/</code>
            &middot; served at <code><?= h((string) ads_file_url($app)) ?></code>
        </p>
    <?php else: ?>
        <p class="hint ads-target">
            Set a Domain URL above to give this app a folder. The file can be written now either way.
        </p>
    <?php endif; ?>

    <div class="tabs ads-tabs">
        <button type="button" class="active" data-ads-tab="fields">Fields</button>
        <button type="button" data-ads-tab="raw">Raw JSON</button>
    </div>

    <form method="post" class="ads-form" data-ads-panel="fields">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_ads">
        <input type="hidden" name="app_id" value="<?= $appId ?>">

        <div class="ads-rows">
            <?php $rowIndex = 0; ?>
            <?php foreach ($adsConfig as $key => $value): ?>
                <?php
                $known = isset($adsLabels[$key]) || $key === 'versionCode';
                $editable = is_scalar($value) || $value === null;
                ?>
                <div class="ads-row<?= $known ? '' : ' is-custom' ?>">
                    <?php if ($known): ?>
                        <span class="ads-key"><?= h($adsLabels[$key] ?? 'Version code') ?></span>
                        <input type="hidden" name="ads_key[<?= $rowIndex ?>]" value="<?= h((string) $key) ?>">
                    <?php else: ?>
                        <input class="ads-key-input" type="text" name="ads_key[<?= $rowIndex ?>]"
                               value="<?= h((string) $key) ?>" maxlength="80" aria-label="Field name">
                    <?php endif; ?>

                    <?php if (!$editable): ?>
                        <input type="text" value="<?= h(json_encode($value, JSON_UNESCAPED_SLASHES)) ?>" disabled>
                        <input type="hidden" name="ads_locked[<?= $rowIndex ?>]" value="1">
                        <span class="hint">Edit in Raw JSON</span>
                    <?php elseif ($key === 'versionCode'): ?>
                        <input type="number" name="ads_value[<?= $rowIndex ?>]" value="<?= (int) $value ?>" min="0" step="1">
                    <?php else: ?>
                        <input type="text" name="ads_value[<?= $rowIndex ?>]" value="<?= h((string) $value) ?>"
                               placeholder="ca-app-pub-…" spellcheck="false">
                    <?php endif; ?>

                    <?php if (!$known): ?>
                        <button class="btn small ads-remove" type="button" aria-label="Remove field">Remove</button>
                    <?php endif; ?>
                </div>
                <?php $rowIndex++; ?>
            <?php endforeach; ?>
        </div>

        <div class="inline-actions ads-actions" data-next-index="<?= $rowIndex ?>">
            <button class="btn primary" type="submit">Save</button>
            <button class="btn ads-add" type="button">+ Add field</button>
        </div>
    </form>

    <form method="post" class="ads-raw-form" data-ads-panel="raw" hidden>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_ads_raw">
        <input type="hidden" name="app_id" value="<?= $appId ?>">
        <label class="ads-raw-label">ads.json
            <textarea name="ads_raw" rows="12" spellcheck="false"><?= h($adsFile) ?></textarea>
        </label>
        <p class="hint">Whatever is written here is exactly what the file will hold.</p>
        <div class="inline-actions">
            <button class="btn primary" type="submit">Save</button>
            <button class="btn copy-url" type="button" data-url="<?= h($adsFile) ?>">Copy</button>
        </div>
    </form>

    <div class="hint ads-foot">
        <?php if (!empty($app['ads_updated_at'])): ?>
            Saved <?= h((string) $app['ads_updated_at']) ?>.
        <?php else: ?>
            Not saved yet — this is the default template.
        <?php endif; ?>
        <form method="post" class="inline-post"
              onsubmit="return confirm('Replace this app\'s ads.json with the default template?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reset_ads">
            <input type="hidden" name="app_id" value="<?= $appId ?>">
            <button class="btn small" type="submit">Reset to template</button>
        </form>
    </div>
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

<?php if ($status !== 'none'): ?>
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
<?php endif; ?>

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
/* The ads config is one file, shown either as fields or as itself. */
document.querySelectorAll('[data-ads-tab]').forEach((tab) => {
    tab.addEventListener('click', () => {
        const panel = tab.closest('.ads-panel');
        const wanted = tab.dataset.adsTab;

        panel.querySelectorAll('[data-ads-tab]').forEach((other) => {
            other.classList.toggle('active', other === tab);
        });
        panel.querySelectorAll('[data-ads-panel]').forEach((form) => {
            form.hidden = form.dataset.adsPanel !== wanted;
        });
    });
});

document.querySelectorAll('.ads-add').forEach((button) => {
    const actions = button.closest('.ads-actions');
    const rows = button.closest('form').querySelector('.ads-rows');

    button.addEventListener('click', () => {
        const index = Number(actions.dataset.nextIndex);
        actions.dataset.nextIndex = String(index + 1);

        const row = document.createElement('div');
        row.className = 'ads-row is-custom';
        row.innerHTML = '<input class="ads-key-input" type="text" name="ads_key[' + index + ']"'
            + ' maxlength="80" placeholder="Field name" aria-label="Field name">'
            + '<input type="text" name="ads_value[' + index + ']" placeholder="Value" spellcheck="false">'
            + '<button class="btn small ads-remove" type="button">Remove</button>';
        rows.appendChild(row);
        row.querySelector('input').focus();
    });
});

document.addEventListener('click', (event) => {
    const remove = event.target.closest('.ads-remove');
    if (remove) {
        remove.closest('.ads-row').remove();
    }
});

/* The domain URL reads like the rows above it until you ask to change it. */
document.querySelectorAll('.domain-url-toggle').forEach((button) => {
    const row = button.closest('.publish-row');
    const form = row.querySelector('.domain-url-form');
    const view = row.querySelector('.console-url');

    button.addEventListener('click', () => {
        view.hidden = true;
        form.hidden = false;
        form.querySelector('input[name="domain_url"]').focus();
    });

    form.querySelector('.domain-url-cancel').addEventListener('click', () => {
        form.hidden = true;
        view.hidden = false;
    });
});

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
