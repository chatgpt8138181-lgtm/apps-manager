<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/* Each app's URL now lives on the app's own page; the list is a filter. */
header('Location: apps.php?url=pending');
exit;
