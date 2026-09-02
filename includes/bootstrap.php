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

/* Inline, self-hosted icon set for the sidebar. Keys are page files
   plus the group labels that need one. */
function nav_icon(string $key): string
{
    $paths = [
        'production.php' => '<path d="M9 4h6v3H9z"/><path d="M7 4H5v16h14V4h-2"/><path d="m9 13 2 2 4-4"/>',
        'ready-apps.php' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'sent-production.php' => '<path d="M12 19V5"/><path d="m5 12 7-7 7 7"/>',
        'live-apps.php' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a15 15 0 0 1 0 18a15 15 0 0 1 0-18"/>',
        'publish-info.php' => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V5h10"/>',
        'consoles.php' => '<rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><path d="M7 7.5h.01M7 16.5h.01"/>',
        'app-urls.php' => '<path d="M10 13a4 4 0 0 0 5.6.6l2.6-2.6a4 4 0 0 0-5.6-5.6L11 6.9"/><path d="M14 11a4 4 0 0 0-5.6-.6L5.8 13a4 4 0 0 0 5.6 5.6l1.5-1.5"/>',
        'tasks.php' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/><path d="m9 15 2 2 4-4"/>',
        'dashboard.php' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="11" width="7" height="10" rx="1"/><rect x="3" y="15" width="7" height="6" rx="1"/>',
        'active-apps.php' => '<circle cx="12" cy="12" r="9"/><path d="m10 9 5 3-5 3z"/>',
        'add-app.php' => '<rect x="3" y="3" width="18" height="18" rx="3"/><path d="M12 8v8M8 12h8"/>',
        'search.php' => '<circle cx="11" cy="11" r="6"/><path d="m20 20-3.5-3.5"/>',
        'categories.php' => '<path d="M3 12V5a2 2 0 0 1 2-2h7l9 9-9 9z"/><path d="M7.5 7.5h.01"/>',
        'admins.php' => '<circle cx="9" cy="8" r="3"/><path d="M3 20a6 6 0 0 1 12 0"/><path d="M16 11a3 3 0 1 0 0-6"/><path d="M18 20a6 6 0 0 0-3-5.2"/>',
        'App Workflow' => '<path d="M4 7h9"/><path d="m10 4 3 3-3 3"/><path d="M20 17h-9"/><path d="m14 14-3 3 3 3"/>',
    ];

    $path = $paths[$key] ?? '<circle cx="12" cy="12" r="8"/>';

    return '<svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
        . ' stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . $path . '</svg>';
}

/* Counts shown beside the workflow pages, so the sidebar says where
   work is waiting without opening a page. */
function nav_counts(): array
{
    try {
        $counts = production_status_counts();
    } catch (Throwable $e) {
        return [];
    }

    return [
        'production.php' => (int) ($counts['prepare'] ?? 0),
        'ready-apps.php' => (int) ($counts['ready'] ?? 0),
        'sent-production.php' => (int) ($counts['sent'] ?? 0),
        'live-apps.php' => (int) ($counts['live'] ?? 0),
    ];
}

/* Nav entries are either "file.php" => "Label" or "Group" => [ ...entries ],
   so a group can hold a group. */
function nav_items_contain_page(array $items): bool
{
    foreach ($items as $key => $value) {
        if (is_array($value)) {
            if (nav_items_contain_page($value)) {
                return true;
            }
            continue;
        }

        if ($key === current_page()) {
            return true;
        }
    }

    return false;
}

function render_nav_group(string $label, array $items, array $counts = [], bool $nested = false): void
{
    $isOpen = nav_items_contain_page($items);
    ?>
    <div class="nav-group <?= $isOpen ? 'open' : '' ?>">
        <button class="nav-group-toggle" type="button" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>">
            <span class="nav-label">
                <?php if ($nested): ?><?= nav_icon($label) ?><?php endif; ?>
                <?= h($label) ?>
            </span>
            <span class="nav-chevron" aria-hidden="true"></span>
        </button>
        <div class="nav-group-items">
            <?php foreach ($items as $key => $value): ?>
                <?php if (is_array($value)): ?>
                    <?php render_nav_group((string) $key, $value, $counts, true); ?>
                <?php else: ?>
                    <a class="<?= current_page() === $key ? 'active' : '' ?>" href="<?= h((string) $key) ?>">
                        <span class="nav-label"><?= nav_icon((string) $key) ?><?= h((string) $value) ?></span>
                        <?php if (!empty($counts[$key])): ?>
                            <span class="nav-count"><?= (int) $counts[$key] ?></span>
                        <?php endif; ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}

function page_start(string $title): void
{
    $navGroups = [
        'Publishing' => [
            /* The four stages an app moves through, in order. */
            'App Workflow' => [
                'production.php' => 'Production',
                'ready-apps.php' => 'Ready Apps',
                'sent-production.php' => 'Production Apps',
                'live-apps.php' => 'Live Apps',
            ],
            'publish-info.php' => 'Publish Info',
            'consoles.php' => 'Consoles',
            'app-urls.php' => 'App URLs',
            'tasks.php' => 'Daily Tasks',
        ],
        'Loading' => [
            'dashboard.php' => 'Dashboard',
            'active-apps.php' => 'Active Apps',
            'add-app.php' => 'Add App',
            'search.php' => 'Search/Edit',
            'categories.php' => 'Console Names',
        ],
    ];
    $navCounts = nav_counts();
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
                        <?php render_nav_group($group, $items, $navCounts); ?>
                    <?php endforeach; ?>
                    <a class="nav-single <?= current_page() === 'admins.php' ? 'active' : '' ?>" href="admins.php">
                        <span class="nav-label"><?= nav_icon('admins.php') ?>Admins</span>
                    </a>
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
                    /* Close only the siblings at this level, never the parent. */
                    group.parentElement?.querySelectorAll(':scope > .nav-group.open').forEach((other) => {
                        if (other !== group) {
                            other.classList.remove('open');
                            other.querySelector(':scope > .nav-group-toggle')?.setAttribute('aria-expanded', 'false');
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
