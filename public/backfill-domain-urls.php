<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * One-time: write each app's current URL into its own domain_url column,
 * so today's URLs are frozen before anything can rename an app.
 * Safe to run twice — it only fills what is still empty.
 */

header('Content-Type: text/plain');

$stmt = db()->query(
    'SELECT a.*, a.app_name AS name, c.app_domain_url AS console_app_domain_url
     FROM apps a JOIN consoles c ON c.id = a.console_id
     ORDER BY a.console_id ASC, a.created_at ASC, a.id ASC'
);

$update = db()->prepare('UPDATE apps SET domain_url = ? WHERE id = ?');
$filled = 0;
$skipped = 0;
$noBase = 0;

foreach ($stmt->fetchAll() as $app) {
    if (trim((string) ($app['domain_url'] ?? '')) !== '') {
        $skipped++;
        continue;
    }

    $built = build_app_domain_url($app);
    if ($built === null) {
        $noBase++;
        continue;
    }

    $update->execute([$built, (int) $app['id']]);
    $filled++;
}

echo "filled: {$filled}\n";
echo "already set: {$skipped}\n";
echo "no console domain: {$noBase}\n";
