<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/* Temporary probe: can this host reach the Play Store, and can we read
   an app's icon out of the page? Removed once the answer is known. */

header('Content-Type: text/plain');

$package = (string) ($_GET['package'] ?? 'com.whatsapp');
$url = 'https://play.google.com/store/apps/details?id=' . rawurlencode($package) . '&hl=en&gl=US';

echo "curl: " . (function_exists('curl_init') ? 'yes' : 'no') . "\n";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'yes' : 'no') . "\n";
echo "url: {$url}\n\n";

$html = '';
$status = 0;
$error = '';

if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AppManager/1.0)',
    ]);
    $html = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
}

echo "http status: {$status}\n";
echo "curl error: " . ($error !== '' ? $error : 'none') . "\n";
echo "bytes: " . strlen($html) . "\n\n";

if ($html !== '') {
    preg_match('~<meta property="og:image" content="([^"]+)"~i', $html, $img);
    preg_match('~<meta property="og:title" content="([^"]+)"~i', $html, $title);
    echo "og:title: " . ($title[1] ?? 'not found') . "\n";
    echo "og:image: " . ($img[1] ?? 'not found') . "\n";
}
