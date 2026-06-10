<?php
declare(strict_types=1);

/*
 * Update these values after creating your cPanel MySQL database.
 * Keep this file outside public_html when your host allows it.
 */
define('DB_HOST', 'localhost');
define('DB_NAME', 'app_manager');
define('DB_USER', 'app_manager_user');
define('DB_PASS', 'change_this_password');
define('DB_CHARSET', 'utf8mb4');

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    return $pdo;
}
