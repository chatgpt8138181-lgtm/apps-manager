<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            add_console((string) ($_POST['name'] ?? ''));
            redirect_with('consoles.php', 'success', 'Console added.');
        }

        if ($action === 'rename') {
            rename_console((int) ($_POST['console_id'] ?? 0), (string) ($_POST['name'] ?? ''));
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
<section class="form-panel">
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <label>Console Name
            <input type="text" name="name" maxlength="150" placeholder="Console A" required>
        </label>
        <button class="btn primary" type="submit">Add Console</button>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <h2>Play Consoles (<?= count($consoles) ?>)</h2>
        <span class="hint">Cycle <?= (int) $progress['cycle_no'] ?> — "shown" and "remaining" reset when a new cycle starts.</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>Console</th>
                <th>Live Apps</th>
                <th>Ready for Work</th>
                <th>Shown This Cycle</th>
                <th>Remaining</th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$consoles): ?>
                <tr><td colspan="6" class="empty">No consoles yet. Add your first Play Console above.</td></tr>
            <?php endif; ?>
            <?php foreach ($consoles as $console): ?>
                <tr>
                    <td>
                        <form method="post" class="console-assign-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="console_id" value="<?= (int) $console['id'] ?>">
                            <input type="text" name="name" value="<?= h($console['name']) ?>" maxlength="150" required>
                            <button class="btn small" type="submit">Save</button>
                        </form>
                    </td>
                    <td><?= (int) $console['live_total'] ?></td>
                    <td><?= (int) $console['ready_total'] ?></td>
                    <td><?= (int) $console['shown_total'] ?></td>
                    <td><?= (int) $console['remaining'] ?></td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this console? Its apps will be unassigned and leave the task pool, and its task history will be removed.');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="console_id" value="<?= (int) $console['id'] ?>">
                            <button class="btn danger small" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_end(); ?>
