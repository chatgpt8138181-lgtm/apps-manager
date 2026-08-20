<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

$consoles = all_consoles();

page_start('App URLs');
?>
<section class="panel">
    <div class="panel-heading">
        <h2>App URL Names (Console Wise)</h2>
        <span class="hint">Every app name used in domain URLs, per console — duplicates get a number in creation order.</span>
    </div>

    <?php if (!$consoles): ?>
        <p class="empty block">No consoles yet. <a href="consoles.php"><strong>Add a console</strong></a> first.</p>
    <?php endif; ?>

    <?php foreach ($consoles as $console): ?>
        <?php
        $consoleId = (int) $console['id'];
        $baseUrl = $console['app_domain_url'] ?? null;
        $apps = console_app_url_names($consoleId, $baseUrl);
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

                <?php if (!$apps): ?>
                    <p class="empty block">No apps in this console yet.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>App Name</th>
                                <th>URL Name</th>
                                <th>Full URL</th>
                                <th>Status</th>
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
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
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
