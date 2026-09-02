<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            add_console((string) ($_POST['name'] ?? ''), $_POST);
            redirect_with('consoles.php', 'success', 'Console added.');
        }

        if ($action === 'rename') {
            update_console((int) ($_POST['console_id'] ?? 0), (string) ($_POST['name'] ?? ''), $_POST);
            redirect_with('consoles.php', 'success', 'Console updated.');
        }

        if ($action === 'delete') {
            delete_console((int) ($_POST['console_id'] ?? 0));
            redirect_with('consoles.php', 'success', 'Console deleted.');
        }
    } catch (Throwable $e) {
        redirect_with('consoles.php', 'error', $e->getMessage());
    }
}

$consoles = console_overview();
$progress = cycle_progress();

page_start('Play Consoles');
?>
<section class="form-panel add-panel">
    <div class="app-group" data-group-key="add-form">
        <button class="app-group-toggle" type="button" aria-expanded="false">
            <span>+ Add Console</span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="app-group-body">
    <form method="post" class="stacked-form wide">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Console Name
            <input type="text" name="name" maxlength="150" placeholder="Console A" required>
        </label>
        <label>Privacy Policy URL
            <input type="text" name="privacy_policy_url" maxlength="255" placeholder="https://">
        </label>
        <label>App Domain URL
            <input type="text" name="app_domain_url" maxlength="255" placeholder="https://">
        </label>
        <button class="btn primary" type="submit">Add Console</button>
    </form>
        </div>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Play Consoles (<?= count($consoles) ?>)</h2>
                <span class="hint">Each console runs its own cycle; "shown" and "remaining" reset when that console restarts.</span>
    </div>

    <?php if (!$consoles): ?>
        <p class="empty block">No consoles yet. Add your first Play Console above.</p>
    <?php endif; ?>

    <?php foreach ($consoles as $console): ?>
        <?php
        $consoleId = (int) $console['id'];
        $formId = 'console-form-' . $consoleId;
        $urlsSet = !empty($console['privacy_policy_url']) && !empty($console['app_domain_url']);
        ?>
        <div class="app-group" data-group-key="console-<?= $consoleId ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span class="console-head">
                    <span class="console-head-name">
                        <?= h($console['name']) ?>
                        <?= $urlsSet
                            ? '<span class="badge badge-green">URLs set</span>'
                            : '<span class="badge badge-amber">URLs missing</span>' ?>
                    </span>
                    <span class="console-head-meta">
                        <?= (int) $console['live_total'] ?> live
                        &middot; <?= (int) $console['ready_total'] ?> ready
                        &middot; Cycle <?= (int) $console['cycle_no'] ?>
                    </span>
                </span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <div class="console-stats">
                    <span><strong>Cycle <?= (int) $console['cycle_no'] ?></strong></span>
                    <span><strong><?= (int) $console['live_total'] ?></strong> Live Apps</span>
                    <span><strong><?= (int) $console['ready_total'] ?></strong> Ready for Work</span>
                    <span><strong><?= (int) $console['shown_total'] ?></strong> Shown This Cycle</span>
                    <span><strong><?= (int) $console['remaining'] ?></strong> Remaining</span>
                </div>

                <form method="post" class="stacked-form wide" id="<?= h($formId) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="rename">
                    <input type="hidden" name="console_id" value="<?= $consoleId ?>">
                    <label>Console Name
                        <input type="text" name="name" value="<?= h($console['name']) ?>" maxlength="150" required>
                    </label>
                    <label>Privacy Policy URL
                        <span class="input-with-copy">
                            <input type="text" name="privacy_policy_url" value="<?= h($console['privacy_policy_url'] ?? '') ?>" maxlength="255" placeholder="https://">
                            <button class="btn small copy-input" type="button">Copy</button>
                        </span>
                    </label>
                    <label>App Domain URL
                        <span class="input-with-copy">
                            <input type="text" name="app_domain_url" value="<?= h($console['app_domain_url'] ?? '') ?>" maxlength="255" placeholder="https://">
                            <button class="btn small copy-input" type="button">Copy</button>
                        </span>
                    </label>
                </form>

                <div class="inline-actions console-actions">
                    <button class="btn primary" type="submit" form="<?= h($formId) ?>">Save</button>
                    <form method="post" onsubmit="return confirm('Delete this console? Its apps will be unassigned and leave the task pool, and its task history will be removed.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="console_id" value="<?= $consoleId ?>">
                        <button class="btn danger" type="submit">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</section>

<script>
document.querySelectorAll('.copy-input').forEach((button) => {
    button.addEventListener('click', () => {
        const input = button.closest('.input-with-copy').querySelector('input');
        if (!input.value) {
            return;
        }
        navigator.clipboard.writeText(input.value).then(() => {
            button.textContent = 'Copied!';
            setTimeout(() => { button.textContent = 'Copy'; }, 1500);
        });
    });
});
</script>
<?php page_end(); ?>
