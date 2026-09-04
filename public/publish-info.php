<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * The sheet for publishing an app: the values a Play Console listing needs,
 * grouped by console so the shared URLs are stated once. A Ready app can be
 * sent from here too, without going back to its own page first.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $return = 'publish-info.php?' . http_build_query(array_filter([
        'status' => (string) ($_POST['return_status'] ?? 'ready'),
        'q' => (string) ($_POST['return_q'] ?? ''),
    ]));

    try {
        if ((string) ($_POST['action'] ?? '') === 'send') {
            send_app_to_production((int) ($_POST['app_id'] ?? 0));
            redirect_with($return, 'success', 'App sent for production.');
        }
    } catch (Throwable $e) {
        redirect_with($return, 'error', $e->getMessage());
    }
}

$tabs = [
    'ready' => 'Ready Apps',
    'sent' => 'Production Apps',
    'live' => 'Live Apps',
];

$view = (string) ($_GET['status'] ?? 'ready');
if (!isset($tabs[$view])) {
    $view = 'ready';
}

$search = trim((string) ($_GET['q'] ?? ''));
$apps = production_apps_by_status($view);

if ($search !== '') {
    $needle = mb_strtolower($search);
    $apps = array_values(array_filter($apps, function (array $app) use ($needle) {
        $haystack = mb_strtolower(($app['name'] ?? '') . ' ' . ($app['package_name'] ?? ''));
        return mb_strpos($haystack, $needle) !== false;
    }));
}

$appsPage = paginate($apps);
$apps = $appsPage['rows'];
$consoles = all_consoles();

function publish_copy_row(string $label, ?string $value, string $emptyHint = 'Not set'): void
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
            <span class="badge badge-red"><?= h($emptyHint) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

function render_publish_apps(array $apps, array $console, string $view, string $search): void
{
    $privacy = $console['privacy_policy_url'] ?? null;

    foreach ($apps as $index => $app) {
        $domainUrl = app_domain_url_for($app);
        $block = "App Name: " . (string) $app['name'] . "\n"
            . "Package: " . (string) ($app['package_name'] ?? '') . "\n"
            . "Privacy Policy: " . (string) ($privacy ?? '') . "\n"
            . "Domain URL: " . (string) ($domainUrl ?? '');
        ?>
        <div class="publish-app">
            <div class="publish-app-head">
                <h4><?= (int) $index + 1 ?>. <?= h($app['name']) ?></h4>
                <div class="inline-actions">
                    <button class="btn small copy-url" type="button" data-url="<?= h($block) ?>">Copy All</button>
                    <?php if (($app['status'] ?? '') === 'ready'): ?>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="send">
                            <input type="hidden" name="app_id" value="<?= (int) $app['id'] ?>">
                            <input type="hidden" name="return_status" value="<?= h($view) ?>">
                            <input type="hidden" name="return_q" value="<?= h($search) ?>">
                            <button class="btn small primary" type="submit">Send for Production</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php publish_copy_row('App Name', $app['name']); ?>
            <?php publish_copy_row('Package Name', $app['package_name'] ?? null, 'Package not set'); ?>
            <?php publish_copy_row('Domain URL', $domainUrl, 'Domain URL not set'); ?>
        </div>
        <?php
    }
}

page_start('Publish Info');
?>
<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a class="<?= $view === $key ? 'active' : '' ?>" href="publish-info.php?status=<?= h($key) ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
</div>

<section class="panel">
    <div class="panel-heading">
        <h2><?= h($tabs[$view]) ?> (<?= (int) $appsPage['total'] ?>)</h2>
        <span class="hint">Everything a store listing needs, ready to copy. Nothing here changes an app.</span>
    </div>

    <form method="get" class="inline-form publish-search">
        <input type="hidden" name="status" value="<?= h($view) ?>">
        <label>Search
            <input type="search" name="q" value="<?= h($search) ?>" placeholder="App name or package">
        </label>
        <button class="btn primary" type="submit">Search</button>
        <?php if ($search !== ''): ?>
            <a class="btn" href="publish-info.php?status=<?= h($view) ?>">Clear</a>
        <?php endif; ?>
    </form>

    <?php if (!$apps): ?>
        <p class="empty block">
            <?= $search !== ''
                ? 'No app matches this search.'
                : 'No apps in this stage yet.' ?>
        </p>
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
                <div class="publish-console-urls">
                    <?php publish_copy_row('Privacy Policy', $console['privacy_policy_url'] ?? null, 'Privacy policy URL missing'); ?>
                    <?php publish_copy_row('Domain Base', $console['app_domain_url'] ?? null, 'Domain URL missing'); ?>
                    <?php if (empty($console['privacy_policy_url']) || empty($console['app_domain_url'])): ?>
                        <p class="hint">Set the missing URL in <a href="consoles.php">Consoles</a>.</p>
                    <?php endif; ?>
                </div>
                <?php render_publish_apps($consoleApps, $console, $view, $search); ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php
    $unassigned = array_values(array_filter($apps, fn($app) => empty($app['console_id'])));
    ?>
    <?php if ($unassigned): ?>
        <div class="app-group" data-group-key="no-console">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span>No Console (<?= count($unassigned) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <p class="hint">Assign a Play Console from Production &rarr; Manage &rarr; Verify App Details to get its URLs.</p>
                <?php render_publish_apps($unassigned, [], $view, $search); ?>
            </div>
        </div>
    <?php endif; ?>
    <?php render_pager($appsPage, 'publish-info.php', ['status' => $view, 'q' => $search]); ?>
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
