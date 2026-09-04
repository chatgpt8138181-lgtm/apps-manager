<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/*
 * Hands over an app's folder as a zip: the same path the domain URL names,
 * with its ads.json inside, ready to drop at the site root.
 */

$appId = (int) ($_GET['app'] ?? 0);
$app = $appId > 0 ? get_production_app($appId) : null;

if (!$app) {
    redirect_with('apps.php', 'error', 'App was not found.');
}

$built = ads_build_entries([$app]);

if (!$built['files']) {
    $why = $built['skipped'][0]['why'] ?? 'it has no folder';
    redirect_with('app.php?id=' . $appId, 'error', 'Nothing to download — ' . $why . '.');
}

log_activity('app', $appId, 'ads_downloaded', (string) $app['name'], array_key_first($built['files']));

ads_send_zip($built['files'], ads_zip_name((string) $app['name']));
