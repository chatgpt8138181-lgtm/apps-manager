<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$status = ($_GET['status'] ?? 'pending') === 'checked' ? 'checked' : 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $return = 'app-urls.php?status=' . urlencode((string) ($_POST['return_status'] ?? 'pending'));

    try {
        $action = $_POST['action'] ?? '';
        $appId = (int) ($_POST['app_id'] ?? 0);

        if ($action === 'mark') {
            set_url_checked($appId, true);
            redirect_with($return, 'success', 'URL marked as checked.');
        }

        if ($action === 'unmark') {
            set_url_checked($appId, false);
            redirect_with($return, 'success', 'URL moved back to pending.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

$consoles = all_consoles();
$counts = url_checked_counts();
$wantChecked = $status === 'checked';
$tabs = ['pending' => 'Pending', 'checked' => 'Checked'];

page_start('App URLs');
?>
<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="<?= $status === $key ? 'active' : '' ?>" href="app-urls.php?status=<?= h($key) ?>">
            <?= h($label) ?> (<?= (int) $counts[$key] ?>)
        </a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <div class="panel-heading">
        <h2><?= h($tabs[$status]) ?> App URLs (<?= (int) $counts[$status] ?>)</h2>
        <span class="hint">
            <?= $wantChecked
                ? 'URLs already checked — use Unmark to move one back to Pending.'
                : 'Every app name used in domain URLs, per console. Mark Checked when a URL is verified.' ?>
        </span>
    </div>

    <?php if (!$consoles): ?>
        <p class="empty block">No consoles yet. <a href="consoles.php"><strong>Add a console</strong></a> first.</p>
    <?php endif; ?>

    <?php $shownAny = false; ?>
    <?php foreach ($consoles as $console): ?>
        <?php
        $consoleId = (int) $console['id'];
        $baseUrl = $console['app_domain_url'] ?? null;
        $apps = array_values(array_filter(
            console_app_url_names($consoleId, $baseUrl),
            fn($app) => ((int) $app['url_checked'] === 1) === $wantChecked
        ));
        if (!$apps) {
            continue;
        }
        $shownAny = true;
        ?>
        <div class="app-group" data-group-key="console-<?= $consoleId ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span>
                    <?= h($console['name']) ?> (<?= count($apps) ?>)
                    <?php if (!$baseUrl): ?>
                        <span class="badge badge-amber">Base URL missing</span>
                    <?php endif; ?>
                </span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <?php if (!$baseUrl): ?>
                    <p class="hint">Set this console's App Domain URL on <a href="consoles.php"><strong>Consoles</strong></a> to see full URLs.</p>
                <?php endif; ?>

                <div class="table-wrap">
                    <table>
                        <thead>
                        <tr>
                            <th>App Name</th>
                            <th>URL Name</th>
                            <th>Full URL</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($apps as $app): ?>
                            <tr>
                                <td><?= h($app['name']) ?></td>
                                <td><code><?= h($app['url_name'] !== '' ? $app['url_name'] : '—') ?></code></td>
                                <td>
                                    <?php if ($app['full_url']): ?>
                                        <div class="console-url">
                                            <code><?= h($app['full_url']) ?></code>
                                            <button class="btn small copy-url" type="button" data-url="<?= h($app['full_url']) ?>">Copy</button>
                                        </div>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?= render_production_badge($app['status']) ?></td>
                                <td>
                                    <form method="post">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="<?= $wantChecked ? 'unmark' : 'mark' ?>">
                                        <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                                        <input type="hidden" name="return_status" value="<?= h($status) ?>">
                                        <button class="btn small <?= $wantChecked ? '' : 'primary' ?>" type="submit">
                                            <?= $wantChecked ? 'Unmark' : 'Mark Checked' ?>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($consoles && !$shownAny): ?>
        <p class="empty block">
            <?= $wantChecked ? 'No checked URLs yet.' : 'No pending URLs — everything is checked.' ?>
            <?php if (!$wantChecked): ?>
                <br><a class="btn small" href="app-urls.php?status=checked">See checked URLs</a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
</section>

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
