<?php
declare(strict_types=1);

/*
 * Every app serves a small ads.json from its own folder on the domain.
 * The file is flat: a version, then one ad unit id per placement. What the
 * app is given here is exactly what ships — no keys are added behind the
 * scenes, and the order the keys are written in is the order they keep.
 */

function ads_default_template(): array
{
    return [
        'versionCode' => 1,
        'native' => '',
        'interstitial_logo' => '',
        'interstitial' => '',
        'open' => '',
        'banner' => '',
    ];
}

/* The placements the form offers by name; anything else is a custom key. */
function ads_placement_labels(): array
{
    return [
        'native' => 'Native',
        'interstitial_logo' => 'Interstitial (logo)',
        'interstitial' => 'Interstitial',
        'open' => 'App Open',
        'banner' => 'Banner',
    ];
}

/*
 * A console can keep its own default, so a whole console's apps start from
 * the same file. Consoles without one fall back to the built-in template.
 */
function ads_console_template(?int $consoleId): array
{
    static $cache = [];

    if (!$consoleId) {
        return ads_default_template();
    }
    if (array_key_exists($consoleId, $cache)) {
        return $cache[$consoleId];
    }

    $stmt = db()->prepare('SELECT ads_template FROM consoles WHERE id = ? LIMIT 1');
    $stmt->execute([$consoleId]);
    $raw = trim((string) ($stmt->fetchColumn() ?: ''));

    $parsed = $raw !== '' ? json_decode($raw, true) : null;

    return $cache[$consoleId] = is_array($parsed) ? $parsed : ads_default_template();
}

/* The template this app would start from. */
function ads_template_for(array $app): array
{
    return ads_console_template(isset($app['console_id']) ? (int) $app['console_id'] : null);
}

function save_console_ads_template(int $consoleId, string $raw): void
{
    $raw = trim($raw);

    if ($raw === '') {
        $stmt = db()->prepare('UPDATE consoles SET ads_template = NULL WHERE id = ?');
        $stmt->execute([$consoleId]);
        log_activity('console', $consoleId, 'ads_template_saved', null, 'Back to the built-in template');

        return;
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('This is not valid JSON: ' . json_last_error_msg() . '.');
    }

    $stmt = db()->prepare('UPDATE consoles SET ads_template = ? WHERE id = ?');
    $stmt->execute([ads_encode($parsed), $consoleId]);

    log_activity('console', $consoleId, 'ads_template_saved');
}

/* An app's saved config, or the template it would start from. */
function ads_config_for(array $app): array
{
    $raw = trim((string) ($app['ads_json'] ?? ''));
    if ($raw === '') {
        return ads_template_for($app);
    }

    $parsed = json_decode($raw, true);

    return is_array($parsed) ? $parsed : ads_template_for($app);
}

function ads_encode(array $config): string
{
    $json = json_encode(
        $config,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        throw new RuntimeException('This config cannot be written as JSON.');
    }

    return $json . "\n";
}

/* The file as it will be served, whether or not the app has been saved. */
function ads_file_for(array $app): string
{
    return ads_encode(ads_config_for($app));
}

function save_app_ads(int $appId, array $config): void
{
    /* Encoding first: nothing is stored that cannot be written out again. */
    $json = ads_encode($config);

    $stmt = db()->prepare('UPDATE apps SET ads_json = ?, ads_updated_at = NOW() WHERE id = ?');
    $stmt->execute([$json, $appId]);

    if ($stmt->rowCount() < 1 && !get_production_app($appId)) {
        throw new RuntimeException('App was not found.');
    }

    /* The checklist asks whether this file exists; now it does. */
    mark_checklist_item($appId, 'ads_json', true);

    log_activity('app', $appId, 'ads_saved');
}

/* The raw editor: whatever is typed is kept, once it parses as an object. */
function save_app_ads_raw(int $appId, string $raw): void
{
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException('The ads.json cannot be empty.');
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) {
        throw new RuntimeException('This is not valid JSON: ' . json_last_error_msg() . '.');
    }

    save_app_ads($appId, $parsed);
}

/*
 * Where the file goes. The domain URL already says it: everything after the
 * host is the folder the app serves from.
 */
function ads_folder_path(array $app): ?string
{
    $url = app_domain_url_for($app);
    if ($url === null || $url === '') {
        return null;
    }

    $path = trim((string) parse_url($url, PHP_URL_PATH), '/');

    return $path !== '' ? $path : null;
}

/* Just the app's own folder, without the console's part of the path. */
function ads_folder_name(array $app): ?string
{
    $path = ads_folder_path($app);
    if ($path === null) {
        return null;
    }

    $cut = strrpos($path, '/');

    return $cut === false ? $path : substr($path, $cut + 1);
}

function ads_file_url(array $app): ?string
{
    $url = app_domain_url_for($app);

    return $url !== null && $url !== '' ? rtrim($url, '/') . '/ads.json' : null;
}

/*
 * Build the folders for a set of apps. Returns the files to zip, keyed by
 * the path they take inside it, plus the apps that could not be included.
 */
function ads_build_entries(array $apps): array
{
    $files = [];
    $skipped = [];

    foreach ($apps as $app) {
        if ((string) ($app['status'] ?? $app['stage'] ?? '') === 'none') {
            $skipped[] = [
                'name' => (string) ($app['name'] ?? $app['app_name'] ?? 'Unnamed app'),
                'why' => 'not in the publishing flow, so it has no folder',
            ];
            continue;
        }

        $path = ads_folder_path($app);
        if ($path === null) {
            $skipped[] = [
                'name' => (string) ($app['name'] ?? $app['app_name'] ?? 'Unnamed app'),
                'why' => 'no domain URL, so it has no folder name',
            ];
            continue;
        }

        $files[$path . '/ads.json'] = ads_file_for($app);
    }

    return ['files' => $files, 'skipped' => $skipped];
}

function ads_manifest(array $files, array $skipped): string
{
    $lines = [
        'ads.json folders — App Manager',
        'Generated: ' . date('d M Y H:i'),
        '',
        'Included (' . count($files) . '):',
    ];

    foreach (array_keys($files) as $path) {
        $lines[] = '  ' . $path;
    }

    if ($skipped) {
        $lines[] = '';
        $lines[] = 'Skipped (' . count($skipped) . '):';
        foreach ($skipped as $row) {
            $lines[] = '  ' . $row['name'] . ' — ' . $row['why'];
        }
    }

    return implode("\n", $lines) . "\n";
}

/*
 * Write the files into a zip and hand it to the browser. The archive lives
 * in the system temp directory just long enough to be sent.
 */
function ads_send_zip(array $files, string $filename): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('This server cannot build zip files (the PHP zip extension is missing).');
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ads');
    if ($tmp === false) {
        throw new RuntimeException('Could not create a temporary file for the zip.');
    }

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open the zip for writing.');
    }

    foreach ($files as $path => $contents) {
        $zip->addFromString($path, $contents);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . (string) filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/* A file name that says what is inside without needing to open it. */
function ads_zip_name(string $label): string
{
    $slug = app_slug($label);
    $slug = $slug !== '' ? $slug : 'ads';

    return $slug . '_ads_' . date('Y-m-d') . '.zip';
}
