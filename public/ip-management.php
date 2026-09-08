<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();
require_can('settings');

/*
 * The IPs themselves: the list every record and rotation reads from, and the
 * short lists behind the Provider, Country and City pickers.
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $back = 'ip-management.php';

    try {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'add_pool_ip') {
            add_pool_ip($_POST);
            redirect_with($back, 'success', 'IP added to the list.');
        }

        if ($action === 'update_pool_ip') {
            update_pool_ip((int) ($_POST['id'] ?? 0), $_POST);
            redirect_with($back, 'success', 'IP updated.');
        }

        if ($action === 'delete_pool_ip') {
            delete_pool_ip((int) ($_POST['id'] ?? 0));
            redirect_with($back, 'success', 'IP removed from the list.');
        }

        if ($action === 'add_option') {
            add_ip_option((string) ($_POST['kind'] ?? ''), (string) ($_POST['name'] ?? ''));
            redirect_with($back, 'success', 'Added to the list.');
        }

        if ($action === 'delete_option') {
            delete_ip_option((int) ($_POST['id'] ?? 0));
            redirect_with($back, 'success', 'Removed from the list.');
        }

        throw new RuntimeException('Unknown action.');
    } catch (Throwable $e) {
        redirect_with($back, 'error', $e->getMessage());
    }
}

$pool = ip_pool_all();
$poolUsage = ip_pool_usage();
$optionKinds = ip_option_kinds();
$optionLists = [];
foreach (array_keys($optionKinds) as $kind) {
    $optionLists[$kind] = ip_options($kind);
}

/* One row of the list: the same shape whether it is being added or edited. */
function pool_row_fields(array $entry, string $formId, array $optionLists): void
{
    ?>
    <td data-label="Name">
        <input type="text" form="<?= h($formId) ?>" name="name" value="<?= h((string) ($entry['name'] ?? '')) ?>"
               maxlength="100" placeholder="What you call it" aria-label="Name" required>
    </td>
    <td data-label="IP">
        <input type="text" form="<?= h($formId) ?>" name="ip" value="<?= h((string) ($entry['ip'] ?? '')) ?>"
               maxlength="45" placeholder="192.0.2.10" spellcheck="false" aria-label="IP" required>
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
    <td data-label="Note">
        <input type="text" form="<?= h($formId) ?>" name="note" value="<?= h((string) ($entry['note'] ?? '')) ?>"
               maxlength="255" placeholder="Anything worth remembering" aria-label="Note">
    </td>
    <?php
}

page_start('IP Management');
?>
<section class="panel">
    <div class="panel-heading">
        <h2>IP list (<?= count($pool) ?>)</h2>
    </div>
    <p class="hint">
        Every IP you work with, each under a name. The name is what shows on the
        rotation and in the record; the address stays here.
    </p>

    <div class="table-wrap">
        <table class="pool-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>IP</th>
                <th>Provider</th>
                <th>Country</th>
                <th>City</th>
                <th>Note</th>
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
                    <?php pool_row_fields($entry, $formId, $optionLists); ?>
                    <td data-label="Used">
                        <span class="badge badge-<?= $used > 0 ? 'blue' : 'gray' ?>"><?= $used ?></span>
                    </td>
                    <td class="actions" data-label="Actions">
                        <button class="btn small primary" type="submit" form="<?= $formId ?>" name="action" value="update_pool_ip">Save</button>
                        <button class="btn small danger" type="submit" form="<?= $formId ?>" name="action" value="delete_pool_ip"
                                onclick="return confirm('Remove <?= h($entry['name']) ?> from the list?');">Delete</button>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php /* The new one waits in the same shape as the rest. */ ?>
            <tr class="pool-row pool-new" id="pool-new-row" hidden>
                <?php pool_row_fields([], 'pool-new', $optionLists); ?>
                <td data-label="Used">
                    <span class="badge badge-gray">0</span>
                </td>
                <td class="actions" data-label="Actions">
                    <button class="btn small primary" type="submit" form="pool-new" name="action" value="add_pool_ip">Add</button>
                    <button class="btn small" type="button" id="pool-new-cancel">Cancel</button>
                </td>
            </tr>
            </tbody>
        </table>
    </div>

    <?php if (!$pool): ?>
        <p class="empty block" id="pool-empty">No IPs on the list yet.</p>
    <?php endif; ?>

    <div class="pool-add-bar">
        <button class="btn primary" type="button" id="pool-new-open">+ Add IP</button>
    </div>

    <?php /* The forms live outside the table; each row's fields point at their own. */ ?>
    <form method="post" id="pool-new" hidden><?= csrf_field() ?></form>
    <?php foreach ($pool as $entry): ?>
        <?php /* Which button was pressed carries the action, so no hidden one competes with it. */ ?>
        <form method="post" id="pool-<?= (int) $entry['id'] ?>" hidden>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $entry['id'] ?>">
        </form>
    <?php endforeach; ?>
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

<script>
(() => {
    const row = document.getElementById('pool-new-row');
    const open = document.getElementById('pool-new-open');
    const cancel = document.getElementById('pool-new-cancel');
    const empty = document.getElementById('pool-empty');
    if (!row || !open || !cancel) {
        return;
    }

    const show = (wanted) => {
        row.hidden = !wanted;
        open.hidden = wanted;
        if (empty) {
            empty.hidden = wanted;
        }
    };

    open.addEventListener('click', () => {
        show(true);
        row.querySelector('input[name="name"]').focus();
    });

    cancel.addEventListener('click', () => {
        row.querySelectorAll('input').forEach((field) => { field.value = ''; });
        row.querySelectorAll('select').forEach((field) => { field.selectedIndex = 0; });
        show(false);
    });
})();
</script>
<?php page_end(); ?>
