<?php
$root = is_file(__DIR__ . '/../includes/bootstrap.php') ? dirname(__DIR__) : __DIR__;
require_once $root . '/includes/bootstrap.php';
require_login();

/* Both rotations now share one page. */
$view = ($_GET['view'] ?? '') === 'history' ? '?view=history' : '';
header('Location: rotations.php' . $view);
exit;
