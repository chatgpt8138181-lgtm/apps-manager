<?php
declare(strict_types=1);

/*
 * Shared read/edit panels for a production app, used by the
 * Ready Apps, Sent Apps, and Live Apps manage views.
 */

/* Where this app sits in the four-stage flow. */
/* Long lists are shown a page at a time, so the browser never has to
   draw hundreds of rows at once. */
function paginate(array $rows, int $perPage = 50): array
{
    $total = count($rows);
    $perPage = max(10, $perPage);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($pages, (int) ($_GET['page'] ?? 1)));

    return [
        'rows' => array_slice($rows, ($page - 1) * $perPage, $perPage),
        'page' => $page,
        'pages' => $pages,
        'total' => $total,
        'per_page' => $perPage,
        'from' => $total === 0 ? 0 : ($page - 1) * $perPage + 1,
        'to' => min($total, $page * $perPage),
    ];
}

function render_pager(array $info, string $page, array $params = []): void
{
    if ((int) $info['pages'] < 2) {
        return;
    }

    $link = function (int $target) use ($page, $params): string {
        $params['page'] = $target;

        return $page . '?' . http_build_query($params);
    };
    ?>
    <nav class="pager" aria-label="Pages">
        <span class="pager-count">
            <?= (int) $info['from'] ?>&ndash;<?= (int) $info['to'] ?> of <?= (int) $info['total'] ?>
        </span>
        <span class="pager-links">
            <?php if ((int) $info['page'] > 1): ?>
                <a class="btn small" href="<?= h($link((int) $info['page'] - 1)) ?>">&laquo; Previous</a>
            <?php endif; ?>
            <span class="pager-position">Page <?= (int) $info['page'] ?> of <?= (int) $info['pages'] ?></span>
            <?php if ((int) $info['page'] < (int) $info['pages']): ?>
                <a class="btn small" href="<?= h($link((int) $info['page'] + 1)) ?>">Next &raquo;</a>
            <?php endif; ?>
        </span>
    </nav>
    <?php
}

/* Narrow a list by free text (name or package) and by console. */
function filter_production_apps(array $apps, string $query, int $consoleId): array
{
    $needle = mb_strtolower(trim($query));

    return array_values(array_filter($apps, function (array $app) use ($needle, $consoleId) {
        if ($consoleId > 0 && (int) ($app['console_id'] ?? 0) !== $consoleId) {
            return false;
        }
        if ($needle === '') {
            return true;
        }

        $haystack = mb_strtolower(($app['name'] ?? '') . ' ' . ($app['package_name'] ?? ''));

        return mb_strpos($haystack, $needle) !== false;
    }));
}

/* The search + console bar every publishing list carries. */
function render_list_filters(
    string $page,
    string $query,
    int $consoleId,
    array $consoles,
    array $hidden = []
): void {
    $active = $query !== '' || $consoleId > 0;
    ?>
    <form method="get" action="<?= h($page) ?>" class="list-filters">
        <?php foreach ($hidden as $name => $value): ?>
            <input type="hidden" name="<?= h((string) $name) ?>" value="<?= h((string) $value) ?>">
        <?php endforeach; ?>
        <label>Search
            <input type="search" name="q" value="<?= h($query) ?>" placeholder="App name or package">
        </label>
        <label>Console
            <select name="console">
                <option value="0">All consoles</option>
                <?php foreach ($consoles as $console): ?>
                    <option value="<?= (int) $console['id'] ?>" <?= $consoleId === (int) $console['id'] ? 'selected' : '' ?>>
                        <?= h($console['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn primary" type="submit">Filter</button>
        <?php if ($active): ?>
            <a class="btn" href="<?= h($page . ($hidden ? '?' . http_build_query($hidden) : '')) ?>">Clear</a>
        <?php endif; ?>
    </form>
    <?php
}

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

/* The bar that appears once rows are ticked. $actions is a list of
   ['value' => ..., 'label' => ..., 'class' => ..., 'confirm' => ...]. */
function render_bulk_bar(array $actions): void
{
    ?>
    <div class="bulk-bar" hidden>
        <span class="bulk-count"><strong class="bulk-number">0</strong> selected</span>
        <div class="bulk-bar-actions">
            <?php foreach ($actions as $action): ?>
                <button class="btn small <?= h($action['class'] ?? '') ?>"
                        type="button"
                        data-bulk-action="<?= h($action['value']) ?>"
                        <?= !empty($action['confirm'])
                            ? 'data-confirm="' . h($action['confirm']) . '"'
                            : '' ?>>
                    <?= h($action['label']) ?>
                </button>
            <?php endforeach; ?>
            <button class="btn small bulk-clear" type="button">Clear</button>
        </div>
    </div>
    <?php
}

/* Where this comment sits, the checklist panel follows. */
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
