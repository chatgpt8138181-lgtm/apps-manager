<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/* Searching and editing an app now happens on the one app list. */
header('Location: apps.php');
exit;
