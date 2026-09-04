<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * Hands over ads.json folders as a zip. The paths inside are the ones the
 * domain URLs name, so the archive drops straight onto the site root.
 *
 * One app comes in by link; a selection arrives as a post from the Apps
 * list; a whole console's live apps come in by link from the Consoles page.
 */

$apps = [];
$label = 'apps';
$back = 'apps.php';
/* Only a whole console carries the console's own folder in the path. */
$withConsolePath = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $apps = apps_by_ids((array) ($_POST['app_ids'] ?? []));
    $label = 'selected apps';
} elseif (($appId = (int) ($_GET['app'] ?? 0)) > 0) {
    $app = get_production_app($appId);
    $apps = $app ? [$app] : [];
    $label = $app ? (string) $app['name'] : 'app';
    $back = 'app.php?id=' . $appId;
} elseif (($consoleId = (int) ($_GET['console'] ?? 0)) > 0) {
    $console = get_console($consoleId);
    $apps = console_live_apps($consoleId);
    $label = $console ? (string) $console['name'] : 'console';
    $back = 'consoles.php';
    $withConsolePath = true;
}

if (!$apps) {
    redirect_with($back, 'error', 'Nothing to download — no apps were found for this.');
}

$built = ads_build_entries($apps, $withConsolePath);

if (!$built['files']) {
    $why = count($built['skipped']) === 1
        ? $built['skipped'][0]['why']
        : 'none of them have a domain URL, so they have no folder names';
    redirect_with($back, 'error', 'Nothing to download — ' . $why . '.');
}

log_activity(
    'app',
    count($apps) === 1 ? (int) $apps[0]['id'] : null,
    'ads_downloaded',
    $label,
    count($built['files']) . ' folder(s)'
);

ads_send_zip($built['files'], ads_zip_name($label));
