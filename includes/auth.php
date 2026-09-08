<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function is_logged_in(): bool
{
    return !empty($_SESSION['admin_logged_in']);
}

/* The pages a viewer may open: the rotation side, and nothing else. */
function viewer_pages(): array
{
    return ['rotations.php', 'dashboard.php', 'ip-record.php', 'logout.php', 'palette.php'];
}

/* Where a role starts, and where it is sent back to when it overreaches. */
function role_home(): string
{
    return current_role() === 'viewer' ? 'rotations.php' : 'home.php';
}

/* Whether this role may open a page at all. */
function can_open_page(string $page): bool
{
    if ($page === 'admins.php') {
        return can('users');
    }

    /* The IP list and its pickers are setup, not day-to-day work. */
    if ($page === 'ip-management.php') {
        return can('settings');
    }

    if (current_role() === 'viewer') {
        return in_array($page, viewer_pages(), true);
    }

    return true;
}

function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }

    $page = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!can_open_page($page)) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'Your account does not have access to that page.'];
        header('Location: ' . role_home());
        exit;
    }
}

function login_admin(string $username, string $password): bool
{
    $stmt = db()->prepare('SELECT id, username, role, password_hash FROM admins WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role'] = (string) ($admin['role'] ?? 'operator');

    try {
        $stamp = db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?');
        $stamp->execute([(int) $admin['id']]);
    } catch (Throwable $e) {
        /* A missing stamp is never worth refusing a sign-in for. */
    }

    return true;
}

/*
 * What a role may do.
 *
 * 'work'     the day's work: rotations, IPs, checklists, ads, stage moves
 * 'create'   bringing something new into the system
 * 'settings' the shape of things: console URLs, rotation settings, IP lists
 * 'delete'   taking something out for good
 * 'users'    the Admins page
 *
 * A viewer holds none of these: the rotation pages, read, and nothing else.
 */
function role_abilities(): array
{
    return [
        'admin' => ['work', 'create', 'settings', 'delete', 'users'],
        'manager' => ['work', 'create', 'settings'],
        'operator' => ['work'],
        'viewer' => [],
    ];
}

function role_labels(): array
{
    return [
        'admin' => 'Admin',
        'manager' => 'Manager',
        'operator' => 'Operator',
        'viewer' => 'Viewer',
    ];
}

function role_descriptions(): array
{
    return [
        'admin' => 'Everything, including these accounts.',
        'manager' => 'Everything about apps, consoles and rotations. Cannot delete or manage accounts.',
        'operator' => 'The day\'s work. Cannot add, change settings or delete.',
        'viewer' => 'Rotations only, and nothing to change.',
    ];
}

function current_role(): string
{
    $role = (string) ($_SESSION['admin_role'] ?? '');
    if (array_key_exists($role, role_abilities())) {
        return $role;
    }

    /* A session opened before roles existed: read the account's own role
       rather than assuming the smallest one. */
    $adminId = (int) ($_SESSION['admin_id'] ?? 0);
    if ($adminId > 0) {
        try {
            $stmt = db()->prepare('SELECT role FROM admins WHERE id = ? LIMIT 1');
            $stmt->execute([$adminId]);
            $found = (string) ($stmt->fetchColumn() ?: '');
            if (array_key_exists($found, role_abilities())) {
                return $_SESSION['admin_role'] = $found;
            }
        } catch (Throwable $e) {
            /* Fall through to the safe answer below. */
        }
    }

    return 'operator';
}

/* Whether the person signed in may do this kind of thing. */
function can(string $ability): bool
{
    return in_array($ability, role_abilities()[current_role()] ?? [], true);
}

/* The same question, answered by refusing rather than by hiding. */
function require_can(string $ability): void
{
    if (!can($ability)) {
        throw new RuntimeException('Your account does not have access to that.');
    }
}

function logout_admin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
}
