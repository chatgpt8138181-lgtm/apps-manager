<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * The IPs used while loading, one month at a time. A new month starts empty;
 * the months before it stay where they are.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $back = 'ip-record.php?' . http_build_query([
        'm' => ip_month((string) ($_POST['return_month'] ?? '')),
        'by' => (string) ($_POST['return_by'] ?? '') === 'app' ? 'app' : 'day',
    ]);

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add') {
            require_can('work');
            $result = add_rotation_ips($_POST);
            $message = $result['added'] . ' IP(s) added.';
            if ($result['bad']) {
                $message .= ' ' . count($result['bad']) . ' line(s) were not an IP: '
                    . implode(', ', array_slice($result['bad'], 0, 3))
                    . (count($result['bad']) > 3 ? '…' : '');
            }
            redirect_with('ip-record.php?m=' . urlencode($result['month']), 'success', $message);
        }

        if ($action === 'add_pool_ip') {
            require_can('settings');
            add_pool_ip($_POST);
            redirect_with($back, 'success', 'IP added to the list.');
        }

        if ($action === 'update_pool_ip') {
            require_can('settings');
            update_pool_ip((int) ($_POST['id'] ?? 0), $_POST);
            redirect_with($back, 'success', 'IP updated.');
        }

        if ($action === 'delete_pool_ip') {
            require_can('settings');
            delete_pool_ip((int) ($_POST['id'] ?? 0));
            redirect_with($back, 'success', 'IP removed from the list.');
        }

        if ($action === 'add_option') {
            require_can('settings');
            $kind = (string) ($_POST['kind'] ?? '');
            add_ip_option($kind, (string) ($_POST['name'] ?? ''));
            redirect_with($back, 'success', 'Added to the list.');
        }

        if ($action === 'delete_option') {
            require_can('settings');
            delete_ip_option((int) ($_POST['id'] ?? 0));
            redirect_with($back, 'success', 'Removed from the list.');
        }

        if ($action === 'delete_month') {
            require_can('settings');
            $wanted = (string) ($_POST['month'] ?? '');
            $removed = delete_ip_month($wanted);
            redirect_with('ip-record.php', 'success', $removed . ' IP(s) removed from ' . ip_month_label($wanted) . '.');
        }

        if ($action === 'delete') {
            require_can('work');
            delete_rotation_ip((int) ($_POST['id'] ?? 0));
            redirect_with($back, 'success', 'IP removed.');
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        redirect_with($back, 'error', $e->getMessage());
    }
}

$month = ip_month((string) ($_GET['m'] ?? ''));
$by = (string) ($_GET['by'] ?? 'day') === 'app' ? 'app' : 'day';
$stats = ip_month_stats($month);
$days = $by === 'day' ? ip_records_by_day($month) : [];
$byApp = $by === 'app' ? ip_apps_by_console($month) : [];
$repeats = ip_month_usage($month);
$consoles = all_consoles();
$apps = all_apps_overview('', 0, '', '');
$pool = ip_pool_all();
$poolUsage = ip_pool_usage();
$optionKinds = ip_option_kinds();
$optionLists = [];
foreach (array_keys($optionKinds) as $kind) {
    $optionLists[$kind] = ip_options($kind);
}

$copyAll = implode("\n", ip_list_for_month($month));
$copyUnique = implode("\n", ip_list_for_month($month, true));
$monthCounts = ip_months_with_counts();

$known = ip_months_with_records();
$previous = ip_month_shift($month, -1);
$next = ip_month_shift($month, 1);
$hasPrevious = (bool) array_filter($known, fn($m) => $m <= $previous);
$hasNext = $next <= date('Y-m');

/* The apps a console holds, so the picker can be grouped by console. */
$appsByConsole = [];
foreach ($apps as $app) {
    $appsByConsole[(int) ($app['console_id'] ?? 0)][] = $app;
}

/*
 * The addresses of one line, each with its own way out. A repeat wears the
 * badge on the address itself, so a long line still reads at a glance.
 */
function ip_chips_cell(array $ips, array $repeats, string $month, string $by): void
{
    ?>
    <div class="ip-chips">
        <?php foreach ($ips as $entry): ?>
            <span class="ip-chip<?= isset($repeats[$entry['name']]) ? ' is-repeat' : '' ?>" title="<?= h($entry['ip']) ?>">
                <code><?= h($entry['name']) ?></code>
                <?php if (isset($repeats[$entry['name']])): ?>
                    <small title="Used <?= (int) $repeats[$entry['name']] ?> times this month"><?= (int) $repeats[$entry['name']] ?>&times;</small>
                <?php endif; ?>
                <?php if (can('work')): ?>
                <form method="post" onsubmit="return confirm('Remove <?= h($entry['name']) ?> from the record?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                    <input type="hidden" name="return_month" value="<?= h($month) ?>">
                    <input type="hidden" name="return_by" value="<?= h($by) ?>">
                    <button type="submit" aria-label="Remove <?= h($entry['name']) ?>">&times;</button>
                </form>
                <?php endif; ?>
            </span>
        <?php endforeach; ?>
    </div>
    <?php
}

/* One picker, built from the list it belongs to. */
function ip_option_select(string $kind, array $options, string $chosen = '', string $form = ''): void
{
    ?>
    <select name="<?= h($kind) ?>" aria-label="<?= h(ucfirst($kind)) ?>"<?= $form !== '' ? ' form="' . h($form) . '"' : '' ?>>
        <option value=""><?= h(ucfirst($kind)) ?></option>
        <?php foreach ($options as $option): ?>
            <option value="<?= h($option['name']) ?>" <?= $chosen === $option['name'] ? 'selected' : '' ?>>
                <?= h($option['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}

page_start('IP Record');
?>
<section class="panel">
    <div class="panel-heading">
        <h2><?= h(ip_month_label($month)) ?></h2>
        <div class="inline-actions">
            <?php if ($stats['total'] > 0): ?>
                <button class="btn small copy-ips" type="button" data-ips="<?= h($copyAll) ?>">
                    Copy all (<?= (int) $stats['total'] ?>)
                </button>
                <?php if ($stats['unique_ips'] < $stats['total']): ?>
                    <button class="btn small copy-ips" type="button" data-ips="<?= h($copyUnique) ?>">
                        Copy unique (<?= (int) $stats['unique_ips'] ?>)
                    </button>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($hasPrevious): ?>
                <a class="btn small" href="ip-record.php?m=<?= h($previous) ?>&amp;by=<?= h($by) ?>">&laquo; <?= h(ip_month_label($previous)) ?></a>
            <?php endif; ?>
            <?php if ($month !== date('Y-m')): ?>
                <a class="btn small" href="ip-record.php?by=<?= h($by) ?>">This month</a>
            <?php endif; ?>
            <?php if ($hasNext && $next <= date('Y-m')): ?>
                <a class="btn small" href="ip-record.php?m=<?= h($next) ?>&amp;by=<?= h($by) ?>"><?= h(ip_month_label($next)) ?> &raquo;</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat"><span><?= (int) $stats['total'] ?></span><p>IPs this month</p></div>
        <div class="stat"><span><?= (int) $stats['unique_ips'] ?></span><p>Different IPs</p></div>
        <div class="stat"><span><?= (int) $stats['days'] ?></span><p>Days recorded</p></div>
    </div>
</section>

<?php if (can('work')): ?>
<section class="form-panel add-panel">
    <div class="app-group" data-group-key="add-ips">
        <button class="app-group-toggle" type="button" aria-expanded="false">
            <span>+ Add IPs</span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="app-group-body">
            <form method="post" class="stacked-form wide">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="return_month" value="<?= h($month) ?>">
                <input type="hidden" name="return_by" value="<?= h($by) ?>">

                <label>IPs <small>(pick one or more)</small>
                    <select name="ip_ids[]" multiple size="<?= max(4, min(10, count($pool))) ?>" required>
                        <?php foreach ($pool as $entry): ?>
                            <option value="<?= (int) $entry['id'] ?>"><?= h($entry['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if (!$pool): ?>
                    <p class="hint">The IP list is empty. Add IPs to it below first.</p>
                <?php else: ?>
                    <p class="hint">Hold Ctrl (or Cmd) to pick several.</p>
                <?php endif; ?>

                <div class="form-row">
                    <label>Used on
                        <input type="date" name="used_on" value="<?= h(date('Y-m-d')) ?>" required>
                    </label>
                    <label>App <small>(the console comes with it)</small>
                        <select name="app_id">
                            <option value="0">No app</option>
                            <?php foreach ($consoles as $console): ?>
                                <?php $list = $appsByConsole[(int) $console['id']] ?? []; ?>
                                <?php if (!$list) { continue; } ?>
                                <optgroup label="<?= h($console['name']) ?>">
                                    <?php foreach ($list as $app): ?>
                                        <option value="<?= (int) $app['id'] ?>"><?= h($app['app_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <label>Console <small>(only when no app is picked)</small>
                        <select name="console_id">
                            <option value="0">No console</option>
                            <?php foreach ($consoles as $console): ?>
                                <option value="<?= (int) $console['id'] ?>"><?= h($console['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>Note
                        <input type="text" name="note" maxlength="255" placeholder="Anything worth remembering">
                    </label>
                </div>

                <button class="btn primary" type="submit">Add IPs</button>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <h2><?= $by === 'app' ? 'App by app' : 'Day by day' ?></h2>
        <span class="hint">
            <?= $by === 'app'
                ? 'Every app with the IPs it was given this month.'
                : 'Newest first. A new month starts empty; this one stays here.' ?>
        </span>
    </div>

    <div class="tabs">
        <a class="<?= $by === 'day' ? 'active' : '' ?>" href="ip-record.php?m=<?= h($month) ?>&amp;by=day">Day by day</a>
        <a class="<?= $by === 'app' ? 'active' : '' ?>" href="ip-record.php?m=<?= h($month) ?>&amp;by=app">App by app</a>
    </div>

    <?php if (!$days && !$byApp): ?>
        <p class="empty block">Nothing recorded for <?= h(ip_month_label($month)) ?> yet.</p>
    <?php endif; ?>

    <?php foreach ($byApp as $consoleId => $console): ?>
        <div class="app-group" id="c<?= (int) $consoleId ?>" data-group-key="ip-console-<?= (int) $consoleId ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span class="console-head">
                    <span class="console-head-name"><?= h($console['name']) ?></span>
                    <span class="console-head-meta">
                        <?= count($console['apps']) ?> app<?= count($console['apps']) === 1 ? '' : 's' ?>
                        &middot; <?= (int) $console['total'] ?> IP<?= (int) $console['total'] === 1 ? '' : 's' ?>
                    </span>
                </span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <?php foreach ($console['apps'] as $app): ?>
                    <div class="app-group" id="a<?= (int) $app['id'] ?>" data-group-key="ip-app-<?= (int) $app['id'] ?>">
                        <button class="app-group-toggle" type="button" aria-expanded="false">
                            <span class="console-head">
                                <span class="console-head-name">
                                    <?= h($app['name']) ?>
                                    <?php if ($app['id'] > 0): ?>#<?= (int) $app['id'] ?><?php endif; ?>
                                </span>
                                <span class="console-head-meta">
                                    <?= count($app['rows']) ?> IP<?= count($app['rows']) === 1 ? '' : 's' ?>
                                </span>
                            </span>
                            <span class="nav-chevron" aria-hidden="true"></span>
                        </button>
                        <div class="app-group-body">
                            <?php if ($app['id'] > 0): ?>
                                <div class="inline-actions">
                                    <a class="btn small" href="app.php?id=<?= (int) $app['id'] ?>">Open app</a>
                                </div>
                            <?php endif; ?>

                            <?php $appLines = ip_group_rows($app['rows'], ['used_on', 'provider', 'country', 'city', 'note']); ?>
                            <?php $appPage = paginate_group($appLines, 'a' . (int) $app['id']); ?>
                            <?php if (!$app['rows']): ?>
                                <p class="empty block">No IPs for this app in <?= h(ip_month_label($month)) ?>.</p>
                            <?php else: ?>
                                <div class="table-wrap">
                                    <table>
                                        <thead>
                                        <tr>
                                            <th>Used on</th>
                                            <th>IPs</th>
                                            <th>Provider</th>
                                            <th>Country</th>
                                            <th>City</th>
                                            <th>Note</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($appPage['rows'] as $line): ?>
                                            <tr>
                                                <td class="col-when">
                                                    <?= h(date('d M Y', strtotime((string) $line['used_on']) ?: time())) ?>
                                                    <span class="cell-sub"><?= count($line['ips']) ?> IP<?= count($line['ips']) === 1 ? '' : 's' ?></span>
                                                </td>
                                                <td><?php ip_chips_cell($line['ips'], $repeats, $month, $by); ?></td>
                                                <td><?= $line['provider'] !== null && $line['provider'] !== '' ? h($line['provider']) : '&mdash;' ?></td>
                                                <td><?= $line['country'] !== null && $line['country'] !== '' ? h($line['country']) : '&mdash;' ?></td>
                                                <td><?= !empty($line['city']) ? h($line['city']) : '&mdash;' ?></td>
                                                <td><?= $line['note'] !== null && $line['note'] !== '' ? h($line['note']) : '&mdash;' ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php render_group_pager($appPage, 'ip-record.php', ['m' => $month, 'by' => $by]); ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($days as $date => $day): ?>
        <?php $dayLines = ip_group_rows($day['rows'], ['app_id', 'console_id', 'provider', 'country', 'city', 'note']); ?>
        <?php $dayPage = paginate_group($dayLines, 'd' . str_replace('-', '', $date)); ?>
        <div class="app-group" id="d<?= h(str_replace('-', '', $date)) ?>" data-group-key="ip-day-<?= h($date) ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span><?= h($day['label']) ?> (<?= count($day['rows']) ?> IPs)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>App</th>
                            <th>IPs</th>
                            <th>Provider</th>
                            <th>Country</th>
                            <th>City</th>
                            <th>Note</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dayPage['rows'] as $line): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($line['app_name'])): ?>
                                        <span class="cell-title">
                                            <a href="app.php?id=<?= (int) $line['app_id'] ?>"><?= h($line['app_name']) ?></a>
                                            <span class="cell-sub">
                                                <?= h($line['console_name'] ?? '') ?>
                                                &middot; <?= count($line['ips']) ?> IP<?= count($line['ips']) === 1 ? '' : 's' ?>
                                            </span>
                                        </span>
                                    <?php elseif (!empty($line['console_name'])): ?>
                                        <span class="cell-sub"><?= h($line['console_name']) ?></span>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><?php ip_chips_cell($line['ips'], $repeats, $month, $by); ?></td>
                                <td><?= $line['provider'] !== null && $line['provider'] !== '' ? h($line['provider']) : '&mdash;' ?></td>
                                <td><?= $line['country'] !== null && $line['country'] !== '' ? h($line['country']) : '&mdash;' ?></td>
                                <td><?= !empty($line['city']) ? h($line['city']) : '&mdash;' ?></td>
                                <td><?= $line['note'] !== null && $line['note'] !== '' ? h($line['note']) : '&mdash;' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php render_group_pager($dayPage, 'ip-record.php', ['m' => $month, 'by' => $by]); ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php if (can('settings')): ?>
<section class="panel">
    <div class="app-group" data-group-key="ip-pool">
        <button class="app-group-toggle" type="button" aria-expanded="false">
            <span>IP list (<?= count($pool) ?>)</span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="app-group-body">
            <p class="hint">
                Every IP you work with, each under a name. The name is what shows
                on the rotation and in this record; the address stays here.
            </p>

            <form method="post" class="stacked-form wide pool-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add_pool_ip">
                <input type="hidden" name="return_month" value="<?= h($month) ?>">
                <input type="hidden" name="return_by" value="<?= h($by) ?>">
                <div class="form-row">
                    <label>Name
                        <input type="text" name="name" maxlength="100" placeholder="What you call it" required>
                    </label>
                    <label>IP
                        <input type="text" name="ip" maxlength="45" placeholder="192.0.2.10" spellcheck="false" required>
                    </label>
                </div>
                <div class="form-row">
                    <label>Provider
                        <?php ip_option_select('provider', $optionLists['provider']); ?>
                    </label>
                    <label>Country
                        <?php ip_option_select('country', $optionLists['country']); ?>
                    </label>
                </div>
                <div class="form-row">
                    <label>City
                        <?php ip_option_select('city', $optionLists['city']); ?>
                    </label>
                    <label>Note
                        <input type="text" name="note" maxlength="255" placeholder="Anything worth remembering">
                    </label>
                </div>
                <button class="btn primary" type="submit">Add IP</button>
            </form>

            <?php if (!$pool): ?>
                <p class="empty block">No IPs on the list yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="pool-table">
                        <thead>
                        <tr>
                            <th>Name</th>
                            <th>IP</th>
                            <th>Provider</th>
                            <th>Country</th>
                            <th>City</th>
                            <th>Used</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pool as $entry): ?>
                            <?php
                            $used = (int) ($poolUsage[(int) $entry['id']] ?? 0);
                            $formId = 'pool-' . (int) $entry['id'];
                            ?>
                            <tr class="pool-row">
                                <td data-label="Name">
                                    <input type="text" form="<?= $formId ?>" name="name" value="<?= h($entry['name']) ?>" maxlength="100" aria-label="Name" required>
                                </td>
                                <td data-label="IP">
                                    <input type="text" form="<?= $formId ?>" name="ip" value="<?= h($entry['ip']) ?>" maxlength="45" aria-label="IP" spellcheck="false" required>
                                </td>
                                <td data-label="Provider">
                                    <?php ip_option_select('provider', $optionLists['provider'], (string) ($entry['provider'] ?? ''), $formId); ?>
                                </td>
                                <td data-label="Country">
                                    <?php ip_option_select('country', $optionLists['country'], (string) ($entry['country'] ?? ''), $formId); ?>
                                </td>
                                <td data-label="City">
                                    <?php ip_option_select('city', $optionLists['city'], (string) ($entry['city'] ?? ''), $formId); ?>
                                </td>
                                <td data-label="Used">
                                    <span class="badge badge-<?= $used > 0 ? 'blue' : 'gray' ?>"><?= $used ?></span>
                                </td>
                                <td class="actions" data-label="Actions">
                                    <button class="btn small primary" type="submit" form="<?= $formId ?>">Save</button>
                                    <button class="btn small danger" type="submit" form="<?= $formId ?>" name="action" value="delete_pool_ip"
                                            onclick="return confirm('Remove <?= h($entry['name']) ?> from the list?');">Delete</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php /* The forms live outside the table; each row's fields point at their own. */ ?>
                <?php foreach ($pool as $entry): ?>
                    <form method="post" id="pool-<?= (int) $entry['id'] ?>" hidden>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update_pool_ip">
                        <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
                        <input type="hidden" name="return_month" value="<?= h($month) ?>">
                        <input type="hidden" name="return_by" value="<?= h($by) ?>">
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="panel">
    <div class="app-group" data-group-key="ip-lists">
        <button class="app-group-toggle" type="button" aria-expanded="false">
            <span>Manage lists</span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="app-group-body">
            <p class="hint">
                What the Provider, Country and City pickers offer. Removing a name only
                takes it off the list &mdash; IPs already recorded keep what they were given.
            </p>
            <div class="option-lists">
                <?php foreach ($optionKinds as $kind => $title): ?>
                    <div class="option-list">
                        <h3 class="rotation-title"><?= h($title) ?> (<?= count($optionLists[$kind]) ?>)</h3>
                        <form method="post" class="option-add">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_option">
                            <input type="hidden" name="kind" value="<?= h($kind) ?>">
                            <input type="hidden" name="return_month" value="<?= h($month) ?>">
                            <input type="hidden" name="return_by" value="<?= h($by) ?>">
                            <input type="text" name="name" maxlength="100" placeholder="Add a name" required>
                            <button class="btn small primary" type="submit">Add</button>
                        </form>
                        <?php if (!$optionLists[$kind]): ?>
                            <p class="empty block">Nothing on this list yet.</p>
                        <?php else: ?>
                            <ul class="option-items">
                                <?php foreach ($optionLists[$kind] as $option): ?>
                                    <li>
                                        <span><?= h($option['name']) ?></span>
                                        <form method="post"
                                              onsubmit="return confirm('Take &quot;<?= h($option['name']) ?>&quot; off the list?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_option">
                                            <input type="hidden" name="id" value="<?= (int) $option['id'] ?>">
                                            <input type="hidden" name="return_month" value="<?= h($month) ?>">
                            <input type="hidden" name="return_by" value="<?= h($by) ?>">
                                            <button class="btn small" type="submit" aria-label="Remove">&times;</button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php
/* Months other than this one, so an old record can be cleared on purpose. */
$earlier = array_values(array_filter($monthCounts, fn($row) => (string) $row['month'] !== date('Y-m')));
?>
<?php if ($earlier && can('settings')): ?>
    <section class="panel">
        <div class="app-group" data-group-key="ip-earlier-months">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span>Earlier months (<?= count($earlier) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <p class="hint">
                    Nothing here is removed on its own. A month goes only when you say so.
                </p>
                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>Month</th>
                            <th>IPs</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($earlier as $row): ?>
                            <tr>
                                <td><?= h(ip_month_label((string) $row['month'])) ?></td>
                                <td><?= (int) $row['total'] ?></td>
                                <td class="actions">
                                    <a class="btn small" href="ip-record.php?m=<?= h((string) $row['month']) ?>&amp;by=<?= h($by) ?>">Open</a>
                                    <form method="post"
                                          onsubmit="return confirm('Delete all <?= (int) $row['total'] ?> IP(s) from <?= h(ip_month_label((string) $row['month'])) ?>? This cannot be undone.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_month">
                                        <input type="hidden" name="month" value="<?= h((string) $row['month']) ?>">
                                        <input type="hidden" name="return_month" value="<?= h($month) ?>">
                            <input type="hidden" name="return_by" value="<?= h($by) ?>">
                                        <button class="btn small danger" type="submit">Delete month</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
<?php endif; ?>

<script>
document.querySelectorAll('.copy-ips').forEach((button) => {
    button.addEventListener('click', () => {
        navigator.clipboard.writeText(button.dataset.ips).then(() => {
            const label = button.textContent;
            button.textContent = 'Copied!';
            setTimeout(() => { button.textContent = label; }, 1500);
        });
    });
});
</script>
<?php page_end(); ?>
