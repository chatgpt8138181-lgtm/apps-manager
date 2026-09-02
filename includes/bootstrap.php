<?php
declare(strict_types=1);

$appRoot = is_dir(__DIR__ . '/../config') ? dirname(__DIR__) : __DIR__;

require_once $appRoot . '/config/database.php';
require_once $appRoot . '/includes/functions.php';
require_once $appRoot . '/includes/auth.php';
require_once $appRoot . '/includes/csrf.php';
require_once $appRoot . '/includes/workflow.php';
require_once $appRoot . '/includes/app-panels.php';

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
            'active-apps.php' => 'Active Apps',
            'add-app.php' => 'Add App',
            'search.php' => 'Search/Edit',
            'categories.php' => 'Console Names',
        ],
        'Publishing' => [
            'production.php' => 'Production',
            'ready-apps.php' => 'Ready Apps',
            'publish-info.php' => 'Publish Info',
            'sent-production.php' => 'Production Apps',
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

        /*
         * Open-group state survives same-page actions (form posts,
         * reloads, tab switches) but resets when arriving from a
         * different page, so every page shift starts fresh.
         */
        let cameFromSamePage = false;
        try {
            const ref = document.referrer ? new URL(document.referrer) : null;
            cameFromSamePage = !!ref && ref.host === location.host && ref.pathname === location.pathname;
        } catch (error) {
            cameFromSamePage = false;
        }

        if (!cameFromSamePage) {
            try {
                Object.keys(sessionStorage)
                    .filter((key) => key.startsWith('openGroups:'))
                    .forEach((key) => sessionStorage.removeItem(key));
            } catch (error) {
                /* storage unavailable */
            }
        }

        let openKeys = new Set();
        try {
            openKeys = new Set(JSON.parse(sessionStorage.getItem(groupStore) || '[]'));
        } catch (error) {
            openKeys = new Set();
        }

        /* Groups can nest (month > day), so one group stays open per level. */
        const restoredScopes = new Set();
        document.querySelectorAll('.app-group').forEach((group) => {
            const key = group.dataset.groupKey || '';
            const scope = group.parentElement;
            if (!key || !openKeys.has(key) || restoredScopes.has(scope)) {
                return;
            }
            restoredScopes.add(scope);
            group.classList.add('open');
            const toggle = group.querySelector(':scope > .app-group-toggle');
            if (toggle) {
                toggle.setAttribute('aria-expanded', 'true');
            }
        });

        const saveOpenKeys = () => {
            try {
                sessionStorage.setItem(groupStore, JSON.stringify([...openKeys]));
            } catch (error) {
                /* storage unavailable */
            }
        };

        const closeActionMenus = () => {
            document.querySelectorAll('.action-menu.open').forEach((menu) => menu.classList.remove('open'));
        };

        document.addEventListener('click', (event) => {
            const menuBtn = event.target.closest('.action-menu-btn');
            if (menuBtn) {
                event.preventDefault();
                const menu = menuBtn.parentElement.querySelector('.action-menu');
                const wasOpen = menu.classList.contains('open');
                closeActionMenus();
                if (!wasOpen) {
                    menu.classList.add('open');

                    /* Keep the menu inside the viewport: flip above the
                       button when there is no room below, and clamp
                       horizontally on narrow screens. */
                    const rect = menuBtn.getBoundingClientRect();
                    const gap = 6;
                    const edge = 8;
                    const menuW = menu.offsetWidth;
                    const menuH = menu.offsetHeight;

                    let top = rect.bottom + gap;
                    if (top + menuH > window.innerHeight - edge) {
                        const above = rect.top - menuH - gap;
                        top = above >= edge ? above : window.innerHeight - menuH - edge;
                    }
                    top = Math.max(edge, Math.min(top, window.innerHeight - menuH - edge));

                    let left = rect.right - menuW;
                    left = Math.min(left, window.innerWidth - menuW - edge);
                    left = Math.max(edge, left);

                    menu.style.top = top + 'px';
                    menu.style.left = left + 'px';
                }
                return;
            }
            if (!event.target.closest('.action-menu')) {
                closeActionMenus();
            }
        });

        window.addEventListener('scroll', closeActionMenus, true);
        window.addEventListener('resize', closeActionMenus);

        document.querySelectorAll('.app-group-toggle').forEach((toggle) => {
            toggle.addEventListener('click', () => {
                const group = toggle.closest('.app-group');
                const isOpen = group.classList.toggle('open');
                toggle.setAttribute('aria-expanded', String(isOpen));

                if (isOpen) {
                    /* Close only the siblings at this level, never the parent. */
                    group.parentElement?.querySelectorAll(':scope > .app-group.open').forEach((other) => {
                        if (other !== group) {
                            other.classList.remove('open');
                            other.querySelector(':scope > .app-group-toggle')?.setAttribute('aria-expanded', 'false');
                        }
                    });
                }

                openKeys.clear();
                let node = isOpen ? group : group.parentElement?.closest('.app-group');
                while (node) {
                    const parentKey = node.dataset.groupKey || '';
                    if (parentKey) {
                        openKeys.add(parentKey);
                    }
                    node = node.parentElement?.closest('.app-group');
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
