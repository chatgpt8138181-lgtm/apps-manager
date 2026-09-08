<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/* Search source for the command palette. */

header('Content-Type: application/json');

$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['results' => []]);
    exit;
}

$like = '%' . $query . '%';
$results = [];

$stmt = db()->prepare(
    "SELECT pa.id, pa.app_name AS name, pa.package_name, pa.stage AS status, c.name AS console_name
     FROM apps pa
     LEFT JOIN consoles c ON c.id = pa.console_id
     WHERE pa.app_name LIKE ? OR pa.package_name LIKE ?
     ORDER BY pa.created_at DESC, pa.id DESC
     LIMIT 8"
);
$stmt->execute([$like, $like]);

foreach ($stmt->fetchAll() as $row) {
    $status = (string) $row['status'];

    $results[] = [
        'group' => 'Apps',
        'title' => (string) $row['name'],
        'sub' => trim(((string) ($row['package_name'] ?? '')) . ' · ' . ucfirst($status)
            . (!empty($row['console_name']) ? ' · ' . $row['console_name'] : ''), ' ·'),
        'url' => 'app.php?id=' . (int) $row['id'],
    ];
}

echo json_encode(['results' => $results]);
