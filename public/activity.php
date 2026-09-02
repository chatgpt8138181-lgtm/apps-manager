<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/* A running record of what changed, newest first. */

$entity = (string) ($_GET['entity'] ?? '');
if (!in_array($entity, ['app', 'console'], true)) {
    $entity = '';
}
$search = trim((string) ($_GET['q'] ?? ''));
$rows = recent_activity(200, $entity, $search);
$grouped = [];

foreach ($rows as $row) {
    $day = substr((string) $row['created_at'], 0, 10);
    $grouped[$day][] = $row;
}

page_start('Activity');
?>
<section class="panel">
    <form method="get" action="activity.php" class="list-filters">
        <label>Search
            <input type="search" name="q" value="<?= h($search) ?>" placeholder="App, console, or who">
        </label>
        <label>Type
            <select name="entity">
                <option value="">Everything</option>
                <option value="app" <?= $entity === 'app' ? 'selected' : '' ?>>Apps</option>
                <option value="console" <?= $entity === 'console' ? 'selected' : '' ?>>Consoles</option>
            </select>
        </label>
        <button class="btn primary" type="submit">Filter</button>
        <?php if ($entity !== '' || $search !== ''): ?>
            <a class="btn" href="activity.php">Clear</a>
        <?php endif; ?>
    </form>

    <div class="panel-heading">
        <h2>Activity (<?= count($rows) ?>)</h2>
        <span class="hint">The last 200 changes, newest first.</span>
    </div>

    <?php if (!$rows): ?>
        <p class="empty block">
            <?= $entity !== '' || $search !== ''
                ? 'Nothing matches this filter.'
                : 'Nothing recorded yet. Changes appear here as they happen.' ?>
        </p>
    <?php endif; ?>

    <?php foreach ($grouped as $day => $dayRows): ?>
        <div class="app-group" data-group-key="day-<?= h($day) ?>">
            <button class="app-group-toggle" type="button" aria-expanded="false">
                <span><?= h(date('d M Y', strtotime($day))) ?> (<?= count($dayRows) ?>)</span>
                <span class="nav-chevron" aria-hidden="true"></span>
            </button>
            <div class="app-group-body">
                <ul class="activity-list">
                    <?php foreach ($dayRows as $row): ?>
                        <?php $link = activity_link($row); ?>
                        <li>
                            <span class="activity-time"><?= h(substr((string) $row['created_at'], 11, 5)) ?></span>
                            <span class="badge badge-gray"><?= h(activity_entity_label((string) $row['entity'])) ?></span>
                            <span class="activity-what">
                                <strong><?= h(activity_label((string) $row['action'])) ?></strong>
                                <?php if (!empty($row['entity_name'])): ?>
                                    &mdash;
                                    <?php if ($link): ?>
                                        <a href="<?= h($link) ?>"><?= h($row['entity_name']) ?></a>
                                    <?php else: ?>
                                        <?= h($row['entity_name']) ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php if (!empty($row['detail'])): ?>
                                    <small><?= h($row['detail']) ?></small>
                                <?php endif; ?>
                            </span>
                            <span class="activity-who"><?= h($row['admin_name'] ?? '—') ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endforeach; ?>
</section>
<?php page_end(); ?>
