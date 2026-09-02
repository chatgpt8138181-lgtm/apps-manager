<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/* Search source for the command palette: apps from both modules. */

header('Content-Type: application/json');

$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $query . '%';
$results = [];

$stmt = db()->prepare(
    "SELECT pa.id, pa.name, pa.package_name, pa.status, c.name AS console_name
     FROM production_apps pa
     LEFT JOIN consoles c ON c.id = pa.console_id
     WHERE pa.name LIKE ? OR pa.package_name LIKE ?
     ORDER BY pa.created_at DESC, pa.id DESC
     LIMIT 8"
);
$stmt->execute([$like, $like]);

foreach ($stmt->fetchAll() as $row) {
    $status = (string) $row['status'];

    $results[] = [
        'group' => 'Publishing',
        'title' => (string) $row['name'],
        'sub' => trim(((string) ($row['package_name'] ?? '')) . ' · ' . ucfirst($status)
            . (!empty($row['console_name']) ? ' · ' . $row['console_name'] : ''), ' ·'),
        'url' => 'app.php?id=' . (int) $row['id'],
    ];
}

$stmt = db()->prepare(
    "SELECT a.id, a.app_name, c.name AS category_name
     FROM apps a
     LEFT JOIN categories c ON c.id = a.category_id
     WHERE a.app_name LIKE ?
     ORDER BY a.id DESC
     LIMIT 8"
);
$stmt->execute([$like]);

foreach ($stmt->fetchAll() as $row) {
    $results[] = [
        'group' => 'Loading',
        'title' => (string) $row['app_name'],
        'sub' => '#' . (int) $row['id'] . (!empty($row['category_name']) ? ' · ' . $row['category_name'] : ''),
        'url' => 'search.php?q=' . rawurlencode((string) $row['app_name']),
    ];
}

echo json_encode(['results' => $results]);
