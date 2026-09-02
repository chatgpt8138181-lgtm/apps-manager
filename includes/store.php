<?php
declare(strict_types=1);

/*
 * Reading a Play Store listing.
 *
 * The store URL is just the package name, so most apps need no link at all.
 * A pasted link is accepted too, and its ?id= gives us the package.
 */

function play_store_url(string $package): string
{
    return 'https://play.google.com/store/apps/details?id=' . rawurlencode($package) . '&hl=en&gl=US';
}

/* Pull the package out of a pasted Play Store link. */
function package_from_store_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }

    $query = parse_url($url, PHP_URL_QUERY) ?: '';
    parse_str($query, $params);
    $id = trim((string) ($params['id'] ?? ''));

    return $id !== '' ? $id : null;
}

function store_fetch(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('This server cannot make outgoing requests.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AppManager/1.0)',
    ]);
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error !== '') {
        throw new RuntimeException('Could not reach the Play Store: ' . $error);
    }
    if ($status === 404) {
        throw new RuntimeException('This app was not found on the Play Store.');
    }
    if ($status !== 200 || $body === '') {
        throw new RuntimeException('The Play Store returned ' . $status . '.');
    }

    return $body;
}

/* Title and icon, as the listing states them. */
function store_listing(string $package): array
{
    $url = play_store_url($package);
    $html = store_fetch($url);

    preg_match('~<meta property="og:title" content="([^"]*)"~i', $html, $title);
    preg_match('~<meta property="og:image" content="([^"]*)"~i', $html, $image);

    $name = html_entity_decode($title[1] ?? '', ENT_QUOTES, 'UTF-8');
    /* Listings end with the store's own suffix; the app's name is the rest. */
    $name = preg_replace('~\s*[-–]\s*Apps on Google Play\s*$~u', '', $name) ?? $name;

    $icon = $image[1] ?? '';
    if ($icon !== '') {
        /* Google sizes its images through the URL; ask for a small square. */
        $icon = preg_replace('~=[sw]\d+(-[a-z0-9]+)*$~i', '=s256', $icon) ?? $icon;
    }

    return [
        'title' => trim($name),
        'icon_url' => $icon,
        'url' => 'https://play.google.com/store/apps/details?id=' . rawurlencode($package),
    ];
}

/* Keep the icon on our own server, so a changed Google URL cannot blank it. */
function store_download_icon(string $iconUrl): string
{
    $body = store_fetch($iconUrl);

    if (strlen($body) > 3 * 1024 * 1024) {
        throw new RuntimeException('The store icon is unexpectedly large.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->buffer($body);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('The store icon is not a supported image.');
    }

    $dir = public_path('uploads/apps');
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (file_put_contents($dir . '/' . $filename, $body) === false) {
        throw new RuntimeException('Could not save the store icon.');
    }
    chmod($dir . '/' . $filename, 0644);

    return 'uploads/apps/' . $filename;
}

/*
 * Read one app's listing and write what it says back onto the app:
 * the store name, a downloaded icon, and when we last looked.
 */
function sync_app_with_store(int $appId): array
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    $package = trim((string) ($app['package_name'] ?? ''));
    $storeUrl = trim((string) ($app['store_url'] ?? ''));

    if ($storeUrl !== '') {
        $package = package_from_store_url($storeUrl) ?? $package;
    }

    if ($package === '') {
        throw new RuntimeException('Add a package name or a Play Store link first.');
    }

    $listing = store_listing($package);

    $iconPath = $app['icon_path'] ?? null;
    if ($listing['icon_url'] !== '') {
        $newIcon = store_download_icon($listing['icon_url']);
        /* The old file is only removed once the new one is safely saved. */
        if (!empty($app['icon_path']) && is_file(public_path((string) $app['icon_path']))) {
            @unlink(public_path((string) $app['icon_path']));
        }
        $iconPath = $newIcon;
    }

    $name = $listing['title'] !== '' ? $listing['title'] : (string) $app['name'];

    $stmt = db()->prepare(
        'UPDATE apps
         SET app_name = ?, package_name = ?, icon_path = ?, store_url = ?,
             store_icon_url = ?, store_title = ?, store_checked_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        $name,
        $package,
        $iconPath,
        $listing['url'],
        $listing['icon_url'],
        $listing['title'],
        $appId,
    ]);

    log_activity('app', $appId, 'store_synced', $name, 'From Play Store');

    return ['name' => $name, 'icon_path' => $iconPath, 'url' => $listing['url']];
}
