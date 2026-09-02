<?php
declare(strict_types=1);

/*
 * Shared read/edit panels for a production app, used by the
 * Ready Apps, Sent Apps, and Live Apps manage views.
 */

/* Where this app sits in the four-stage flow. */
function render_workflow_stepper(array $app): void
{
    $steps = [
        'prepare' => 'Prepare',
        'ready' => 'Ready',
        'sent' => 'Sent',
        'live' => 'Live',
    ];
    $order = array_keys($steps);
    $status = (string) ($app['status'] ?? 'prepare');
    $current = array_search($status, $order, true);
    $offTrack = $current === false;
    ?>
    <ol class="stepper<?= $offTrack ? ' stepper-off' : '' ?>">
        <?php foreach ($order as $index => $key): ?>
            <?php
            $state = 'todo';
            if (!$offTrack) {
                if ($index < $current) {
                    $state = 'done';
                } elseif ($index === $current) {
                    $state = 'current';
                }
            }
            ?>
            <li class="step step-<?= $state ?>">
                <span class="step-dot"><?= $state === 'done' ? '&check;' : (int) $index + 1 ?></span>
                <span class="step-name"><?= h($steps[$key]) ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php if ($offTrack): ?>
        <p class="hint">This app is <?= h($status) ?>, so it sits outside the normal flow.</p>
    <?php endif; ?>
    <?php
}

function render_app_details_panel(array $app, array $consoles, string $backUrl): void
{
    $domainUrl = app_domain_url_for($app);
    ?>
    <section class="form-panel">
        <div class="panel-heading">
            <h2>App Details — <?= h($app['name']) ?></h2>
            <a class="btn small" href="<?= h($backUrl) ?>">Close</a>
        </div>

        <?php render_workflow_stepper($app); ?>

        <p class="hint app-detail-meta">
            Status: <?= render_production_badge($app['status']) ?>
            <?php if ((int) ($app['ready_for_work'] ?? 0) === 1): ?>
                <span class="badge badge-green">Ready for Work</span>
            <?php endif; ?>
            &middot; Created: <?= h($app['created_at']) ?>
            <?php if (!empty($app['sent_at'])): ?>&middot; Sent: <?= h($app['sent_at']) ?><?php endif; ?>
            <?php if (!empty($app['live_at'])): ?>&middot; Live: <?= h($app['live_at']) ?><?php endif; ?>
        </p>

        <form method="post" class="stacked-form wide">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_details">
            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
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
            <div class="form-row">
                <label>Privacy Policy URL (from console)
                    <input type="text" value="<?= h($app['console_privacy_policy_url'] ?? '') ?>" placeholder="Set on Consoles page" readonly>
                </label>
                <label>App Domain URL (from console)
                    <input type="text" value="<?= h($domainUrl ?? '') ?>" placeholder="Set on Consoles page" readonly>
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
    <?php
}

function render_checklist_summary_panel(array $app): void
{
    $items = checklist_items();
    $state = checklist_state((int) $app['id']);
    $done = (int) ($app['checklist_done'] ?? 0);
    $total = count($items);
    ?>
    <section class="panel">
        <div class="panel-heading">
            <h2>Checklist — <?= h($app['name']) ?> (<?= $done ?>/<?= $total ?>)</h2>
            <span class="hint">Checklist is completed during Prepare Production.</span>
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
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}
