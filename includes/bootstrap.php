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
            'ready-apps.php' => 'Ready Apps',
            'sent-production.php' => 'Sent Apps',
            'live-apps.php' => 'Live Apps',
            'consoles.php' => 'Consoles',
            'app-urls.php' => 'App URLs',
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
        document.querySelectorAll('.app-group-body, .nav-group-items, .checklist-field').forEach((body) => {
            const inner = document.createElement('div');
            inner.className = 'collapse-inner';
            while (body.firstChild) {
                inner.appendChild(body.firstChild);
            }
            body.appendChild(inner);
        });

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

                if (isOpen) {
                    document.querySelectorAll('.nav-group.open').forEach((other) => {
                        if (other !== group) {
                            other.classList.remove('open');
                            other.querySelector('.nav-group-toggle')?.setAttribute('aria-expanded', 'false');
                        }
                    });
                }
            });
        });

        const groupStore = 'openGroups:' + location.pathname + location.search;
        let openKeys = new Set();
        try {
            openKeys = new Set(JSON.parse(sessionStorage.getItem(groupStore) || '[]'));
        } catch (error) {
            openKeys = new Set();
        }

        let restoredOne = false;
        document.querySelectorAll('.app-group').forEach((group) => {
            const key = group.dataset.groupKey || '';
            if (!restoredOne && key && openKeys.has(key)) {
                group.classList.add('open');
                const toggle = group.querySelector('.app-group-toggle');
                if (toggle) {
                    toggle.setAttribute('aria-expanded', 'true');
                }
                restoredOne = true;
            }
        });

        const saveOpenKeys = () => {
            try {
                sessionStorage.setItem(groupStore, JSON.stringify([...openKeys]));
            } catch (error) {
                /* storage unavailable */
            }
        };

        document.querySelectorAll('.app-group-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const group = toggle.closest('.app-group');
                const isOpen = group.classList.toggle('open');
                toggle.setAttribute('aria-expanded', String(isOpen));

                if (isOpen) {
                    document.querySelectorAll('.app-group.open').forEach((other) => {
                        if (other !== group) {
                            other.classList.remove('open');
                            other.querySelector('.app-group-toggle')?.setAttribute('aria-expanded', 'false');
                        }
                    });
                }

                openKeys.clear();
                const key = group.dataset.groupKey || '';
                if (isOpen && key) {
                    openKeys.add(key);
                }
                saveOpenKeys();
            });
        });
    })();
    </script>
    </body>
    </html>
    <?php
}
