<?php
declare(strict_types=1);

$appRoot = is_dir(__DIR__ . '/../config') ? dirname(__DIR__) : __DIR__;

require_once $appRoot . '/config/database.php';
require_once $appRoot . '/includes/functions.php';
require_once $appRoot . '/includes/auth.php';
require_once $appRoot . '/includes/csrf.php';
require_once $appRoot . '/includes/workflow.php';

function app_root_path(): string
{
    return is_dir(__DIR__ . '/../config') ? dirname(__DIR__) : __DIR__;
}

function current_page(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php');
}

function asset_version(string $path): int
{
    $root = app_root_path();
    $publicAsset = $root . '/public/' . ltrim($path, '/');
    $directAsset = $root . '/' . ltrim($path, '/');
    $file = is_file($publicAsset) ? $publicAsset : $directAsset;

    return is_file($file) ? (int) filemtime($file) : time();
}

function page_start(string $title): void
{
    $navGroups = [
        'Loading' => [
            'dashboard.php' => 'Dashboard',
            'add-app.php' => 'Add App',
            'search.php' => 'Search/Edit',
            'categories.php' => 'Console Names',
        ],
        'Publishing' => [
            'production.php' => 'Production',
            'sent-production.php' => 'Sent Apps',
            'live-apps.php' => 'Live Apps',
            'consoles.php' => 'Consoles',
            'tasks.php' => 'Daily Tasks',
        ],
    ];
    $flash = flash();
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?= h($title) ?> | App Manager</title>
        <link rel="stylesheet" href="assets/css/style.css?v=<?= asset_version('assets/css/style.css') ?>">
    </head>
    <body>
    <div class="app-shell">
        <aside class="sidebar">
            <div class="sidebar-head">
                <a class="brand" href="dashboard.php">App Manager</a>
                <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="mobile-menu">
                    <span class="menu-icon" aria-hidden="true"></span>
                    <span>Menu</span>
                </button>
            </div>
            <div class="menu-panel" id="mobile-menu">
                <nav>
                    <?php foreach ($navGroups as $group => $items): ?>
                        <?php $isActiveGroup = array_key_exists(current_page(), $items); ?>
                        <div class="nav-group <?= $isActiveGroup ? 'open' : '' ?>">
                            <button class="nav-group-toggle" type="button" aria-expanded="<?= $isActiveGroup ? 'true' : 'false' ?>">
                                <span><?= h($group) ?></span>
                                <span class="nav-chevron" aria-hidden="true"></span>
                            </button>
                            <div class="nav-group-items">
                                <?php foreach ($items as $file => $label): ?>
                                    <a class="<?= current_page() === $file ? 'active' : '' ?>" href="<?= h($file) ?>"><?= h($label) ?></a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <a class="nav-single <?= current_page() === 'admins.php' ? 'active' : '' ?>" href="admins.php">Admins</a>
                </nav>
                <a class="logout" href="logout.php">Logout</a>
            </div>
        </aside>
        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Admin Dashboard</p>
                    <h1><?= h($title) ?></h1>
                </div>
                <span class="user-pill"><?= h($_SESSION['admin_username'] ?? 'Admin') ?></span>
            </header>
            <?php if ($flash): ?>
                <div class="alert <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
            <?php endif; ?>
    <?php
}

function page_end(): void
{
    ?>
        </main>
    </div>
    <script>
    (() => {
        const menuToggle = document.querySelector('.menu-toggle');
        const menuPanel = document.querySelector('.menu-panel');

        if (menuToggle && menuPanel) {
            menuToggle.addEventListener('click', () => {
                const isOpen = menuToggle.getAttribute('aria-expanded') === 'true';
                menuToggle.setAttribute('aria-expanded', String(!isOpen));
                menuPanel.classList.toggle('open', !isOpen);
            });
        }

        document.querySelectorAll('.nav-group-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const group = toggle.closest('.nav-group');
                const isOpen = group.classList.toggle('open');
                toggle.setAttribute('aria-expanded', String(isOpen));
            });
        });
    })();
    </script>
    </body>
    </html>
    <?php
}
