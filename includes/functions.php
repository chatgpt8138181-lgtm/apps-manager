<?php
declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function text_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
}

function text_contains(string $haystack, string $needle): bool
{
    if (function_exists('mb_strpos')) {
        return mb_strpos($haystack, $needle) !== false;
    }

    return strpos($haystack, $needle) !== false;
}

function public_path(string $relative = ''): string
{
    $root = dirname(__DIR__);
    $public = is_dir($root . '/public') ? $root . '/public' : $root;

    return rtrim($public . '/' . ltrim($relative, '/'), '/');
}

/*
 * $undo, when given, is ['page' => 'tasks.php', 'fields' => [...]]: the POST
 * that puts things back. The toast renders it as an Undo button.
 */
function redirect_with(string $url, string $type, string $message, ?array $undo = null): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message, 'undo' => $undo];
    header('Location: ' . $url);
    exit;
}

function flash(): ?array
{
    if (empty($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

function normalize_status(string $status): string
{
    return $status === 'Inactive' ? 'Inactive' : 'Active';
}

function normalize_ready_status(string $status): string
{
    return $status === 'Not Ready' ? 'Not Ready' : 'Ready';
}

function all_categories(): array
{
    $stmt = db()->query('SELECT id, name, created_at FROM categories ORDER BY created_at ASC, id ASC');
    return $stmt->fetchAll();
}

function category_counts(): array
{
    $stmt = db()->query('SELECT category_id, COUNT(*) AS total FROM apps GROUP BY category_id');
    $counts = [];

    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['category_id']] = (int) $row['total'];
    }

    return $counts;
}

function all_admins(): array
{
    $stmt = db()->query('SELECT id, username, created_at FROM admins ORDER BY created_at ASC, id ASC');
    return $stmt->fetchAll();
}

function admin_count(): int
{
    $stmt = db()->query('SELECT COUNT(*) FROM admins');
    return (int) $stmt->fetchColumn();
}

function validate_admin_username(string $username): string
{
    $username = trim($username);

    if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) {
        throw new RuntimeException('Username must be 3 to 100 characters and use letters, numbers, dot, dash, or underscore.');
    }

    return $username;
}

function validate_admin_password(string $password, string $confirm): string
{
    if ($password !== $confirm) {
        throw new RuntimeException('Passwords do not match.');
    }

    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters.');
    }

    return $password;
}

function add_admin_user(string $username, string $password, string $confirm): void
{
    $username = validate_admin_username($username);
    $password = validate_admin_password($password, $confirm);

    $existing = db()->prepare('SELECT id FROM admins WHERE username = ? LIMIT 1');
    $existing->execute([$username]);
    if ($existing->fetch()) {
        throw new RuntimeException('That admin username already exists.');
    }

    $stmt = db()->prepare('INSERT INTO admins (username, password_hash) VALUES (?, ?)');
    $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
}

function update_admin_password(int $adminId, string $password, string $confirm): void
{
    if ($adminId <= 0) {
        throw new RuntimeException('Admin user was not found.');
    }

    $password = validate_admin_password($password, $confirm);

    $stmt = db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $adminId]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Admin user was not found.');
    }
}

function change_own_admin_password(int $adminId, string $currentPassword, string $newPassword, string $confirm): void
{
    $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ? LIMIT 1');
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
        throw new RuntimeException('Current password is incorrect.');
    }

    update_admin_password($adminId, $newPassword, $confirm);
}

function delete_admin_user(int $adminId, int $currentAdminId): void
{
    if ($adminId === $currentAdminId) {
        throw new RuntimeException('You cannot delete your own admin account.');
    }

    if (admin_count() <= 1) {
        throw new RuntimeException('At least one admin account is required.');
    }

    $stmt = db()->prepare('DELETE FROM admins WHERE id = ?');
    $stmt->execute([$adminId]);
}

function add_category(string $name): void
{
    $name = trim($name);
    if ($name === '' || text_length($name) > 150) {
        throw new RuntimeException('Console name must be 1 to 150 characters.');
    }

    $stmt = db()->prepare('INSERT INTO categories (name) VALUES (?)');
    $stmt->execute([$name]);
}

function delete_category(int $id): void
{
    $icons = db()->prepare('SELECT icon_path FROM apps WHERE category_id = ? AND icon_path IS NOT NULL');
    $icons->execute([$id]);
    $iconPaths = $icons->fetchAll();

    $stmt = db()->prepare('DELETE FROM categories WHERE id = ?');
    $stmt->execute([$id]);

    foreach ($iconPaths as $app) {
        $path = public_path($app['icon_path']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function upload_icon(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Icon upload failed.');
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Icon must be 2MB or smaller.');
    }

    $tmp = $file['tmp_name'] ?? '';
    if (!is_uploaded_file($tmp)) {
        throw new RuntimeException('Invalid upload.');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Only JPG, PNG, and WEBP icons are allowed.');
    }

    $uploadDir = public_path('uploads/apps');
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    $target = $uploadDir . '/' . $filename;

    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('Could not save icon.');
    }

    chmod($target, 0644);
    return 'uploads/apps/' . $filename;
}

function add_app(array $data, ?string $iconPath): void
{
    $name = trim((string) ($data['app_name'] ?? ''));
    $categoryId = (int) ($data['category_id'] ?? 0);

    if ($name === '' || text_length($name) > 200) {
        throw new RuntimeException('App name must be 1 to 200 characters.');
    }

    if ($categoryId <= 0) {
        throw new RuntimeException('Please choose a console.');
    }

    $stmt = db()->prepare(
        'INSERT INTO apps (category_id, app_name, loading_status, ready_loading_status, icon_path)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $categoryId,
        $name,
        normalize_status((string) ($data['loading_status'] ?? 'Active')),
        normalize_ready_status((string) ($data['ready_loading_status'] ?? 'Ready')),
        $iconPath,
    ]);
}

function sorted_apps(?int $categoryId = null): array
{
    $sql = 'SELECT apps.*, categories.name AS category_name
            FROM apps
            JOIN categories ON categories.id = apps.category_id';
    $params = [];

    if ($categoryId !== null) {
        $sql .= ' WHERE apps.category_id = ?';
        $params[] = $categoryId;
    }

    $sql .= " ORDER BY categories.created_at ASC, categories.id ASC,
              CASE apps.loading_status WHEN 'Active' THEN 0 ELSE 1 END ASC,
              apps.created_at ASC, apps.id ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return assign_display_ids($stmt->fetchAll());
}

function assign_display_ids(array $apps): array
{
    $displayIds = [];

    foreach ($apps as &$app) {
        $categoryId = (int) $app['category_id'];
        $displayIds[$categoryId] = ($displayIds[$categoryId] ?? 0) + 1;
        $app['display_id'] = $displayIds[$categoryId];
    }
    unset($app);

    return $apps;
}

function search_apps(string $query, int $categoryId): array
{
    $query = trim($query);
    $all = sorted_apps($categoryId > 0 ? $categoryId : null);

    if ($query === '') {
        return $all;
    }

    $isNumeric = ctype_digit($query);
    $needle = text_lower($query);

    return array_values(array_filter($all, static function (array $app) use ($isNumeric, $query, $needle): bool {
        if ($isNumeric && (string) $app['display_id'] === $query) {
            return true;
        }

        return text_contains(text_lower($app['app_name']), $needle);
    }));
}

function update_app(int $appId, array $data): void
{
    $name = trim((string) ($data['app_name'] ?? ''));
    if ($appId <= 0 || $name === '' || text_length($name) > 200) {
        throw new RuntimeException('Invalid app update.');
    }

    if (!isset($data['loading_status']) || !isset($data['ready_loading_status'])) {
        $current = db()->prepare('SELECT loading_status, ready_loading_status FROM apps WHERE id = ? LIMIT 1');
        $current->execute([$appId]);
        $row = $current->fetch();
        if (!$row) {
            throw new RuntimeException('App was not found.');
        }
        $data['loading_status'] = $data['loading_status'] ?? $row['loading_status'];
        $data['ready_loading_status'] = $data['ready_loading_status'] ?? $row['ready_loading_status'];
    }

    $stmt = db()->prepare(
        'UPDATE apps
         SET app_name = ?, loading_status = ?, ready_loading_status = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->execute([
        $name,
        normalize_status((string) $data['loading_status']),
        normalize_ready_status((string) $data['ready_loading_status']),
        $appId,
    ]);
}

function bulk_update_category_status(int $categoryId, string $field, string $value): void
{
    if ($categoryId <= 0) {
        throw new RuntimeException('Console was not found.');
    }

    if ($field === 'loading') {
        $stmt = db()->prepare('UPDATE apps SET loading_status = ?, updated_at = NOW() WHERE category_id = ?');
        $stmt->execute([normalize_status($value), $categoryId]);
    } elseif ($field === 'ready') {
        $stmt = db()->prepare('UPDATE apps SET ready_loading_status = ?, updated_at = NOW() WHERE category_id = ?');
        $stmt->execute([normalize_ready_status($value), $categoryId]);
    } else {
        throw new RuntimeException('Invalid bulk update.');
    }
}

function update_app_statuses(int $appId, string $loading, string $ready): void
{
    $stmt = db()->prepare(
        'UPDATE apps SET loading_status = ?, ready_loading_status = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->execute([normalize_status($loading), normalize_ready_status($ready), $appId]);
}

function delete_app(int $appId): void
{
    $stmt = db()->prepare('SELECT icon_path FROM apps WHERE id = ?');
    $stmt->execute([$appId]);
    $app = $stmt->fetch();

    $delete = db()->prepare('DELETE FROM apps WHERE id = ?');
    $delete->execute([$appId]);

    if ($app && !empty($app['icon_path'])) {
        $path = public_path($app['icon_path']);
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function app_icon_url(?string $path): string
{
    return $path ?: 'assets/css/default-icon.svg';
}

/*
 * Daily rotation for the Loading module: every day each console
 * (category) shows N active apps, no repeats within a cycle.
 */
function loading_apps_per_day(): int
{
    return max(1, (int) workflow_setting('loading_apps_per_day', '2'));
}

/*
 * Each console runs its own cycle: when a console has shown all of its
 * active apps, that console starts again from its first app without
 * waiting for the other consoles.
 */
function category_cycle(int $categoryId): int
{
    return max(1, (int) workflow_setting('loading_cycle_c' . $categoryId, '1'));
}

/*
 * Stored cycle numbers only ever grow so past rows stay valid, while the
 * number shown to the user counts from the last "Restart All Consoles".
 */
function loading_cycle_base(): int
{
    return max(1, (int) workflow_setting('loading_cycle_base', '1'));
}

/* The cycle number a console is on: how far its rotation has walked,
   counted in day-sized steps and starting over with each new round. */
function display_category_cycle(int $categoryId): int
{
    return intdiv(category_today_start($categoryId), loading_apps_per_day()) + 1;
}

function set_category_cycle(int $categoryId, int $cycle): void
{
    set_workflow_setting('loading_cycle_c' . $categoryId, (string) $cycle);
}

function category_active_count(int $categoryId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM apps WHERE category_id = ? AND loading_status = 'Active'");
    $stmt->execute([$categoryId]);

    return (int) $stmt->fetchColumn();
}

function category_shown_count(int $categoryId, int $cycle): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(DISTINCT ld.app_id) FROM loading_daily ld
         JOIN apps a ON a.id = ld.app_id
         WHERE ld.category_id = ? AND ld.cycle_no = ? AND a.loading_status = 'Active'"
    );
    $stmt->execute([$categoryId, $cycle]);

    return (int) $stmt->fetchColumn();
}

function update_loading_apps_per_day(int $count): void
{
    if ($count < 1 || $count > 100) {
        throw new RuntimeException('Apps per day must be between 1 and 100.');
    }

    set_workflow_setting('loading_apps_per_day', (string) $count);
}

/* Manual restart: every console starts again from its first app today. */
function start_new_loading_cycle(): void
{
    $stmt = db()->prepare('DELETE FROM loading_daily WHERE task_date = ?');
    $stmt->execute([date('Y-m-d')]);

    $categories = all_categories();

    $next = (int) db()->query('SELECT COALESCE(MAX(cycle_no), 0) FROM loading_daily')->fetchColumn();
    foreach ($categories as $category) {
        $next = max($next, category_cycle((int) $category['id']));
    }
    $next++;

    /* Same cycle for everyone, and counting starts over at Cycle 1. */
    foreach ($categories as $category) {
        set_category_cycle((int) $category['id'], $next);
        set_category_position((int) $category['id'], 0);
    }
    set_workflow_setting('loading_cycle_base', (string) $next);
}

/*
 * Move one console between rounds. 'restart' replays the current cycle,
 * 'next' and 'previous' step the cycle number; either way that console
 * starts again from its first app.
 */
function shift_category_cycle(int $categoryId, string $direction): void
{
    if ($categoryId <= 0) {
        throw new RuntimeException('Console was not found.');
    }

    $quota = loading_apps_per_day();
    $total = category_active_count($categoryId);
    $cycle = category_cycle($categoryId);
    $start = category_today_start($categoryId);
    $target = $start;

    if ($direction === 'restart') {
        /* A restart puts the console back on its first app and Cycle 1. */
        $cycle = loading_cycle_base();
        $target = 0;
    } elseif ($direction === 'next') {
        $target = $start + $quota;
        if ($total > 0 && $target >= $total) {
            $cycle++;
            $target = 0;
        }
    } elseif ($direction === 'previous') {
        $target = $start - $quota;
        if ($target < 0) {
            $cycle--;
            if ($cycle < loading_cycle_base()) {
                throw new RuntimeException('This console is already on the first apps of Cycle 1.');
            }
            $target = $total > 0 ? intdiv(max(0, $total - 1), $quota) * $quota : 0;
        }
    } else {
        throw new RuntimeException('Invalid cycle action.');
    }

    $today = db()->prepare('DELETE FROM loading_daily WHERE task_date = ? AND category_id = ?');
    $today->execute([date('Y-m-d'), $categoryId]);

    set_category_cycle($categoryId, $cycle);
    set_category_position($categoryId, $target);
}

function restart_category_cycle(int $categoryId): void
{
    shift_category_cycle($categoryId, 'restart');
}

/* Where the console's rotation currently sits: the list position that the
   next generated day starts from. Older installs fall back to what the
   current cycle already showed, so nothing jumps back to the first app. */
function category_position(int $categoryId): int
{
    $stored = workflow_setting('loading_pos_c' . $categoryId, '');
    if ($stored === '') {
        return category_shown_count($categoryId, category_cycle($categoryId));
    }

    return max(0, (int) $stored);
}

function set_category_position(int $categoryId, int $position): void
{
    set_workflow_setting('loading_pos_c' . $categoryId, (string) max(0, $position));
}

/* Start of the window that today's apps were taken from. */
function category_today_start(int $categoryId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM loading_daily WHERE task_date = ? AND category_id = ?');
    $stmt->execute([date('Y-m-d'), $categoryId]);
    $position = category_position($categoryId);

    if ((int) $stmt->fetchColumn() < 1) {
        return $position;
    }

    return max(0, $position - loading_apps_per_day());
}

function loading_cycle_progress(): array
{
    $eligible = 0;
    $shown = 0;

    foreach (all_categories() as $category) {
        $categoryId = (int) $category['id'];
        $total = category_active_count($categoryId);
        if ($total === 0) {
            continue;
        }

        $eligible += $total;
        $shown += min($total, category_position($categoryId));
    }

    return [
        'apps_per_day' => loading_apps_per_day(),
        'eligible' => $eligible,
        'shown' => $shown,
        'remaining' => max(0, $eligible - $shown),
    ];
}

function generate_loading_daily(): int
{
    $today = date('Y-m-d');
    $quota = loading_apps_per_day();
    $inserted = 0;

    $done = db()->prepare('SELECT COUNT(*) FROM loading_daily WHERE task_date = ? AND category_id = ?');
    $insert = db()->prepare(
        'INSERT IGNORE INTO loading_daily (task_date, app_id, category_id, cycle_no) VALUES (?, ?, ?, ?)'
    );

    foreach (all_categories() as $category) {
        $categoryId = (int) $category['id'];

        $done->execute([$today, $categoryId]);
        if ((int) $done->fetchColumn() > 0) {
            continue;
        }

        $total = category_active_count($categoryId);
        if ($total === 0) {
            continue;
        }

        $cycle = category_cycle($categoryId);
        $position = category_position($categoryId);

        /* This console finished its list, so start it over. */
        if ($position >= $total) {
            $cycle++;
            set_category_cycle($categoryId, $cycle);
            $position = 0;
        }

        $pick = db()->prepare(
            "SELECT id FROM apps
             WHERE category_id = ? AND loading_status = 'Active'
             ORDER BY created_at ASC, id ASC
             LIMIT " . $quota . " OFFSET " . $position
        );
        $pick->execute([$categoryId]);

        foreach ($pick->fetchAll() as $row) {
            $insert->execute([$today, (int) $row['id'], $categoryId, $cycle]);
            $inserted++;
        }

        set_category_position($categoryId, $position + $quota);
    }

    return $inserted;
}

function todays_loading_apps(): array
{
    $stmt = db()->prepare(
        "SELECT ld.id, ld.is_done, ld.cycle_no, c.id AS category_id, c.name AS category_name,
                a.id AS app_id, a.app_name, a.icon_path, a.ready_loading_status
         FROM loading_daily ld
         JOIN apps a ON a.id = ld.app_id
         JOIN categories c ON c.id = ld.category_id
         WHERE ld.task_date = ? AND a.loading_status = 'Active'
         ORDER BY c.created_at ASC, c.id ASC, ld.id ASC"
    );
    $stmt->execute([date('Y-m-d')]);

    $grouped = [];
    foreach ($stmt->fetchAll() as $row) {
        $categoryId = (int) $row['category_id'];
        $grouped[$categoryId]['name'] = $row['category_name'];
        $grouped[$categoryId]['cycle_no'] = (int) $row['cycle_no'];
        $grouped[$categoryId]['apps'][] = $row;
    }

    return $grouped;
}

function loading_history(): array
{
    $stmt = db()->query(
        'SELECT ld.task_date, ld.is_done, ld.cycle_no, a.id AS app_id, a.app_name, a.icon_path,
                c.name AS category_name
         FROM loading_daily ld
         JOIN apps a ON a.id = ld.app_id
         JOIN categories c ON c.id = ld.category_id
         ORDER BY ld.task_date DESC, c.created_at ASC, c.id ASC, ld.id ASC'
    );

    return group_history_by_month($stmt->fetchAll());
}

/* History is grouped month-wise, and each month keeps its own day list. */
function group_history_by_month(array $rows): array
{
    $months = [];
    foreach ($rows as $row) {
        $date = (string) $row['task_date'];
        $monthKey = substr($date, 0, 7);
        $done = (int) $row['is_done'] === 1;

        if (!isset($months[$monthKey])) {
            $months[$monthKey] = [
                'key' => $monthKey,
                'label' => date('F Y', strtotime($date)),
                'total' => 0,
                'done' => 0,
                'days' => [],
            ];
        }
        if (!isset($months[$monthKey]['days'][$date])) {
            $months[$monthKey]['days'][$date] = [
                'date' => $date,
                'label' => date('d M Y', strtotime($date)),
                'total' => 0,
                'done' => 0,
                'rows' => [],
            ];
        }

        $months[$monthKey]['total']++;
        $months[$monthKey]['days'][$date]['total']++;
        if ($done) {
            $months[$monthKey]['done']++;
            $months[$monthKey]['days'][$date]['done']++;
        }
        $months[$monthKey]['days'][$date]['rows'][] = $row;
    }

    return $months;
}

function toggle_loading_done(int $taskId): void
{
    $stmt = db()->prepare('UPDATE loading_daily SET is_done = 1 - is_done WHERE id = ?');
    $stmt->execute([$taskId]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Task was not found.');
    }
}

function render_status_badge(string $status): string
{
    $class = $status === 'Active' || $status === 'Ready' ? 'badge badge-green' : 'badge badge-red';
    return '<span class="' . $class . '">' . h($status) . '</span>';
}
