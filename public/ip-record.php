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
    $back = 'ip-record.php?m=' . urlencode(ip_month((string) ($_POST['return_month'] ?? '')));

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add') {
            $result = add_rotation_ips($_POST);
            $message = $result['added'] . ' IP(s) added.';
            if ($result['bad']) {
                $message .= ' ' . count($result['bad']) . ' line(s) were not an IP: '
                    . implode(', ', array_slice($result['bad'], 0, 3))
                    . (count($result['bad']) > 3 ? '…' : '');
            }
            redirect_with('ip-record.php?m=' . urlencode($result['month']), 'success', $message);
        }

        if ($action === 'delete') {
            delete_rotation_ip((int) ($_POST['id'] ?? 0));
            redirect_with($back, 'success', 'IP removed.');
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        redirect_with($back, 'error', $e->getMessage());
    }
}

$month = ip_month((string) ($_GET['m'] ?? ''));
$stats = ip_month_stats($month);
$days = ip_records_by_day($month);
$repeats = ip_month_usage($month);
$consoles = all_consoles();
$apps = all_apps_overview('', 0, '', '');
$providers = ip_known_values('provider');
$countries = ip_known_values('country');

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

page_start('IP Record');
?>
<section class="panel">
    <div class="panel-heading">
        <h2><?= h(ip_month_label($month)) ?></h2>
        <div class="inline-actions">
            <?php if ($hasPrevious): ?>
                <a class="btn small" href="ip-record.php?m=<?= h($previous) ?>">&laquo; <?= h(ip_month_label($previous)) ?></a>
            <?php endif; ?>
            <?php if ($month !== date('Y-m')): ?>
                <a class="btn small" href="ip-record.php">This month</a>
            <?php endif; ?>
            <?php if ($hasNext && $next <= date('Y-m')): ?>
                <a class="btn small" href="ip-record.php?m=<?= h($next) ?>"><?= h(ip_month_label($next)) ?> &raquo;</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats-grid">
        <div class="stat"><span><?= (int) $stats['total'] ?></span><p>IPs this month</p></div>
        <div class="stat"><span><?= (int) $stats['unique_ips'] ?></span><p>Different IPs</p></div>
        <div class="stat"><span><?= (int) $stats['days'] ?></span><p>Days recorded</p></div>
    </div>
</section>

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

                <label>IPs <small>(one per line)</small>
                    <textarea name="ips" rows="5" spellcheck="false" placeholder="192.0.2.10&#10;198.51.100.7" required></textarea>
                </label>

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
                    <label>Provider
                        <input type="text" name="provider" maxlength="100" list="ip-providers" placeholder="Proxy or VPN provider">
                    </label>
                </div>

                <div class="form-row">
                    <label>Country
                        <input type="text" name="country" maxlength="60" list="ip-countries" placeholder="Country">
                    </label>
                    <label>Note
                        <input type="text" name="note" maxlength="255" placeholder="Anything worth remembering">
                    </label>
                </div>

                <datalist id="ip-providers">
                    <?php foreach ($providers as $value): ?><option value="<?= h($value) ?>"></option><?php endforeach; ?>
                </datalist>
                <datalist id="ip-countries">
                    <?php foreach ($countries as $value): ?><option value="<?= h($value) ?>"></option><?php endforeach; ?>
                </datalist>

                <button class="btn primary" type="submit">Add IPs</button>
            </form>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Day by day</h2>
        <span class="hint">Newest first. A new month starts empty; this one stays here.</span>
    </div>

    <?php if (!$days): ?>
        <p class="empty block">Nothing recorded for <?= h(ip_month_label($month)) ?> yet.</p>
    <?php endif; ?>

    <?php foreach ($days as $date => $day): ?>
        <?php $dayPage = paginate_group($day['rows'], 'd' . str_replace('-', '', $date)); ?>
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
                            <th>IP</th>
                            <th>App</th>
                            <th>Provider</th>
                            <th>Country</th>
                            <th>Note</th>
                            <th>Actions</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($dayPage['rows'] as $row): ?>
                            <tr>
                                <td>
                                    <span class="cell-title">
                                        <code><?= h($row['ip']) ?></code>
                                        <?php if (isset($repeats[$row['ip']])): ?>
                                            <span class="badge badge-amber">Used <?= (int) $repeats[$row['ip']] ?>&times; this month</span>
                                        <?php endif; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($row['app_name'])): ?>
                                        <span class="cell-title">
                                            <a href="app.php?id=<?= (int) $row['app_id'] ?>"><?= h($row['app_name']) ?></a>
                                            <span class="cell-sub"><?= h($row['console_name'] ?? '') ?></span>
                                        </span>
                                    <?php elseif (!empty($row['console_name'])): ?>
                                        <span class="cell-sub"><?= h($row['console_name']) ?></span>
                                    <?php else: ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td><?= $row['provider'] !== null && $row['provider'] !== '' ? h($row['provider']) : '&mdash;' ?></td>
                                <td><?= $row['country'] !== null && $row['country'] !== '' ? h($row['country']) : '&mdash;' ?></td>
                                <td><?= $row['note'] !== null && $row['note'] !== '' ? h($row['note']) : '&mdash;' ?></td>
                                <td class="actions">
                                    <form method="post" onsubmit="return confirm('Remove this IP from the record?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="return_month" value="<?= h($month) ?>">
                                        <button class="btn small danger" type="submit">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php render_group_pager($dayPage, 'ip-record.php', ['m' => $month]); ?>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php page_end(); ?>
