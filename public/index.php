<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';

if (is_logged_in()) {
    header('Location: home.php');
} else {
    header('Location: login.php');
}
exit;
