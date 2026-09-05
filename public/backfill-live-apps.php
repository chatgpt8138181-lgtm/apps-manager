<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();
header('Content-Type: text/plain; charset=utf-8');

/*
 * One-time: the apps that were only ever in the loading rotation are live on
 * the store — they were added here by hand, with a name and an icon. This
 * moves them onto the Live stage so they read like every other app, and
 * gives each one a domain URL that no other app already holds.
 */

$moved = db()->exec(
    "UPDATE apps
     SET stage = 'live', live_at = COALESCE(live_at, created_at)
     WHERE stage = 'none'"
);

/* Every URL already in use, so a derived one never lands on top of another. */
$taken = [];
foreach (db()->query('SELECT id, domain_url FROM apps WHERE domain_url IS NOT NULL AND domain_url <> ""')->fetchAll() as $row) {
    $taken[strtolower(trim((string) $row['domain_url']))] = (int) $row['id'];
}

$stmt = db()->query(
    "SELECT a.*, a.app_name AS name, c.app_domain_url AS console_app_domain_url
     FROM apps a JOIN consoles c ON c.id = a.console_id
     WHERE (a.domain_url IS NULL OR a.domain_url = '')
       AND c.app_domain_url IS NOT NULL AND c.app_domain_url <> ''
     ORDER BY a.id ASC"
);

$update = db()->prepare('UPDATE apps SET domain_url = ? WHERE id = ?');
$filled = 0;

foreach ($stmt->fetchAll() as $app) {
    $base = build_app_domain_url($app);
    if ($base === null) {
        continue;
    }

    $url = $base;
    $n = 1;
    while (isset($taken[strtolower($url)])) {
        $url = $base . '_' . (++$n);
    }

    $update->execute([$url, (int) $app['id']]);
    $taken[strtolower($url)] = (int) $app['id'];
    $filled++;
}

$noDomain = (int) db()->query(
    "SELECT COUNT(*) FROM apps WHERE domain_url IS NULL OR domain_url = ''"
)->fetchColumn();

echo "moved to live: {$moved}\n";
echo "domain URLs filled: {$filled}\n";
echo "still without a URL (console has no domain): {$noDomain}\n";
