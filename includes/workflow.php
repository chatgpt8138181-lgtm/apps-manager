<?php
declare(strict_types=1);

/*
 * App production workflow + daily task distribution.
 * Statuses: prepare -> sent -> live | rejected | suspended.
 * Only live apps tagged ready_for_work with a console enter daily tasks.
 */

function checklist_items(): array
{
    return [
        'clone_files_rename' => ['label' => 'Clone Files Name Replace', 'description' => 'Cloned project file names replaced'],
        'package_name' => ['label' => 'Package Name Change', 'description' => 'New unique package name set', 'field' => 'package_name', 'placeholder' => 'com.example.app'],
        'application_id' => ['label' => 'Application ID Change', 'description' => 'applicationId updated in Gradle', 'field' => 'application_id', 'placeholder' => 'com.example.app'],
        'app_name_strings' => ['label' => 'App Name Change', 'description' => 'app_name updated in strings.xml'],
        'app_icon' => ['label' => 'App Icon Change', 'description' => 'New launcher icon added'],
        'splash_image' => ['label' => 'Splash Image Change', 'description' => 'New splash image added'],
        'new_data' => ['label' => 'New Data Updated', 'description' => "App's new content/data (JSON, assets, config) updated"],
        'privacy_policy_url' => ['label' => 'Privacy Policy URL Change', 'description' => "Console's privacy policy URL applied", 'console_url' => 'privacy_policy_url'],
        'app_domain_url' => ['label' => 'App Domain URL Change', 'description' => "Console's domain URL applied", 'console_url' => 'app_domain_url'],
        'save_folder' => ['label' => 'Save Folder Change', 'description' => "App's save folder updated"],
        'random_words' => ['label' => 'Random Words Change', 'description' => 'Random words/strings replaced in project'],
        'build_deleted' => ['label' => 'Build/Idea Folder Delete', 'description' => 'Old build/ and .idea/ folders removed'],
        'cache_invalidated' => ['label' => 'Invalidate Cache', 'description' => 'Invalidate Caches / Restart done'],
        'project_rebuilt' => ['label' => 'Compile All Sources/Project Rebuild', 'description' => 'Clean + Rebuild completed successfully'],
    ];
}

function production_statuses(): array
{
    return ['prepare', 'ready', 'sent', 'live', 'rejected', 'suspended'];
}

function render_production_badge(string $status): string
{
    $map = [
        'prepare' => ['badge badge-gray', 'Prepare'],
        'ready' => ['badge badge-amber', 'Ready'],
        'sent' => ['badge badge-blue', 'Sent'],
        'live' => ['badge badge-green', 'Live'],
        'rejected' => ['badge badge-red', 'Rejected'],
        'suspended' => ['badge badge-amber', 'Suspended'],
    ];
    [$class, $label] = $map[$status] ?? ['badge', $status];

    return '<span class="' . $class . '">' . h($label) . '</span>';
}

function workflow_setting(string $key, string $default = ''): string
{
    $stmt = db()->prepare('SELECT setting_value FROM workflow_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    return $value === false ? $default : (string) $value;
}

function set_workflow_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO workflow_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

function cycle_days(): int
{
    return max(1, (int) workflow_setting('cycle_days', '5'));
}

function validate_production_fields(array $data): array
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '' || text_length($name) > 200) {
        throw new RuntimeException('App name must be 1 to 200 characters.');
    }

    $optional = [];
    foreach (['package_name' => 200, 'application_id' => 200, 'privacy_policy_url' => 255, 'app_domain_url' => 255] as $field => $max) {
        $value = trim((string) ($data[$field] ?? ''));
        if (text_length($value) > $max) {
            throw new RuntimeException(str_replace('_', ' ', ucfirst($field)) . " must be {$max} characters or fewer.");
        }
        $optional[$field] = $value === '' ? null : $value;
    }

    return [$name, $optional];
}

function validate_console_choice(array $data): ?int
{
    $consoleId = (int) ($data['console_id'] ?? 0);
    if ($consoleId <= 0) {
        return null;
    }

    $stmt = db()->prepare('SELECT id FROM consoles WHERE id = ? LIMIT 1');
    $stmt->execute([$consoleId]);
    if (!$stmt->fetch()) {
        throw new RuntimeException('Console was not found.');
    }

    return $consoleId;
}

function add_production_app(array $data): int
{
    [$name, $optional] = validate_production_fields($data);
    $consoleId = validate_console_choice($data);

    $stmt = db()->prepare(
        'INSERT INTO production_apps (name, package_name, application_id, privacy_policy_url, app_domain_url, console_id)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $optional['package_name'],
        $optional['application_id'],
        $optional['privacy_policy_url'],
        $optional['app_domain_url'],
        $consoleId,
    ]);

    return (int) db()->lastInsertId();
}

function update_production_app_details(int $appId, array $data): void
{
    if (!get_production_app($appId)) {
        throw new RuntimeException('App was not found.');
    }

    [$name, $optional] = validate_production_fields($data);
    $consoleId = validate_console_choice($data);

    $stmt = db()->prepare(
        'UPDATE production_apps
         SET name = ?, package_name = ?, application_id = ?, privacy_policy_url = ?, app_domain_url = ?,
             console_id = ?, ready_for_work = IF(? IS NULL, 0, ready_for_work)
         WHERE id = ?'
    );
    $stmt->execute([
        $name,
        $optional['package_name'],
        $optional['application_id'],
        $optional['privacy_policy_url'],
        $optional['app_domain_url'],
        $consoleId,
        $consoleId,
        $appId,
    ]);
}

function delete_production_app(int $appId): void
{
    $stmt = db()->prepare('DELETE FROM production_apps WHERE id = ?');
    $stmt->execute([$appId]);
}

function get_production_app(int $appId): ?array
{
    $stmt = db()->prepare(
        'SELECT pa.*, c.name AS console_name,
            c.privacy_policy_url AS console_privacy_policy_url,
            c.app_domain_url AS console_app_domain_url,
            (SELECT COUNT(*) FROM production_checklist pc WHERE pc.app_id = pa.id AND pc.is_done = 1) AS checklist_done
         FROM production_apps pa
         LEFT JOIN consoles c ON c.id = pa.console_id
         WHERE pa.id = ?
         LIMIT 1'
    );
    $stmt->execute([$appId]);
    $app = $stmt->fetch();

    return $app ?: null;
}

function production_apps_by_status(string $status): array
{
    $stmt = db()->prepare(
        'SELECT pa.*, c.name AS console_name,
            c.privacy_policy_url AS console_privacy_policy_url,
            c.app_domain_url AS console_app_domain_url,
            (SELECT COUNT(*) FROM production_checklist pc WHERE pc.app_id = pa.id AND pc.is_done = 1) AS checklist_done
         FROM production_apps pa
         LEFT JOIN consoles c ON c.id = pa.console_id
         WHERE pa.status = ?
         ORDER BY pa.created_at ASC, pa.id ASC'
    );
    $stmt->execute([$status]);

    return $stmt->fetchAll();
}

function production_status_counts(): array
{
    $stmt = db()->query('SELECT status, COUNT(*) AS total FROM production_apps GROUP BY status');
    $counts = array_fill_keys(production_statuses(), 0);

    foreach ($stmt->fetchAll() as $row) {
        $counts[$row['status']] = (int) $row['total'];
    }

    return $counts;
}

function checklist_state(int $appId): array
{
    $stmt = db()->prepare('SELECT item_key, is_done FROM production_checklist WHERE app_id = ?');
    $stmt->execute([$appId]);

    $state = [];
    foreach ($stmt->fetchAll() as $row) {
        $state[$row['item_key']] = (int) $row['is_done'] === 1;
    }

    return $state;
}

function save_checklist(int $appId, array $doneKeys, array $fieldValues = []): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($app['status'] !== 'prepare') {
        throw new RuntimeException('Checklist can only be edited while the app is in Prepare Production.');
    }

    $stmt = db()->prepare(
        'INSERT INTO production_checklist (app_id, item_key, is_done, done_at)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE is_done = VALUES(is_done), done_at = VALUES(done_at)'
    );

    foreach (array_keys(checklist_items()) as $key) {
        $isDone = in_array($key, $doneKeys, true);
        $stmt->execute([$appId, $key, $isDone ? 1 : 0, $isDone ? date('Y-m-d H:i:s') : null]);
    }

    $allowed = ['package_name' => 200, 'application_id' => 200];
    $updates = [];
    $params = [];

    foreach ($allowed as $field => $max) {
        if (!array_key_exists($field, $fieldValues)) {
            continue;
        }

        $value = trim((string) $fieldValues[$field]);
        if (text_length($value) > $max) {
            throw new RuntimeException(ucfirst(str_replace('_', ' ', $field)) . " must be {$max} characters or fewer.");
        }

        $updates[] = $field . ' = ?';
        $params[] = $value === '' ? null : $value;
    }

    if ($updates) {
        $params[] = $appId;
        $update = db()->prepare('UPDATE production_apps SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $update->execute($params);
    }
}

function checklist_is_complete(int $appId): bool
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM production_checklist WHERE app_id = ? AND is_done = 1');
    $stmt->execute([$appId]);

    return (int) $stmt->fetchColumn() >= count(checklist_items());
}

function send_app_to_production(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if (!in_array($app['status'], ['prepare', 'ready'], true)) {
        throw new RuntimeException('Only Prepare or Ready apps can be sent for production.');
    }

    if (!checklist_is_complete($appId)) {
        throw new RuntimeException('Checklist must be 100% complete before sending for production.');
    }

    $stmt = db()->prepare("UPDATE production_apps SET status = 'sent', sent_at = NOW() WHERE id = ?");
    $stmt->execute([$appId]);
}

function mark_app_ready(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($app['status'] !== 'prepare') {
        throw new RuntimeException('Only apps in Prepare Production can be marked Ready.');
    }

    if (!checklist_is_complete($appId)) {
        throw new RuntimeException('Checklist must be 100% complete before marking Ready for Production.');
    }

    $stmt = db()->prepare("UPDATE production_apps SET status = 'ready' WHERE id = ?");
    $stmt->execute([$appId]);
}

/*
 * Reverse actions for mistaken stage moves:
 * ready/sent -> prepare, sent -> ready, live/rejected/suspended -> sent.
 */
function revert_app_to_prepare(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if (!in_array($app['status'], ['ready', 'sent'], true)) {
        throw new RuntimeException('Only Ready or Sent apps can move back to Prepare.');
    }

    $stmt = db()->prepare("UPDATE production_apps SET status = 'prepare', sent_at = NULL WHERE id = ?");
    $stmt->execute([$appId]);
}

function revert_app_to_ready(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($app['status'] !== 'sent') {
        throw new RuntimeException('Only Sent apps can move back to Ready.');
    }

    $stmt = db()->prepare("UPDATE production_apps SET status = 'ready', sent_at = NULL WHERE id = ?");
    $stmt->execute([$appId]);
}

function revert_app_to_sent(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if (!in_array($app['status'], ['live', 'rejected', 'suspended'], true)) {
        throw new RuntimeException('Only Live, Rejected, or Suspended apps can move back to Sent.');
    }

    $stmt = db()->prepare("UPDATE production_apps SET status = 'sent', live_at = NULL, ready_for_work = 0 WHERE id = ?");
    $stmt->execute([$appId]);
}

function set_production_result(int $appId, string $result): void
{
    if (!in_array($result, ['live', 'rejected', 'suspended'], true)) {
        throw new RuntimeException('Invalid production result.');
    }

    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($app['status'] === 'prepare') {
        throw new RuntimeException('This app has not been sent for production yet.');
    }

    if ($result === 'live') {
        $stmt = db()->prepare("UPDATE production_apps SET status = 'live', live_at = COALESCE(live_at, NOW()) WHERE id = ?");
    } else {
        $stmt = db()->prepare('UPDATE production_apps SET status = ? WHERE id = ?');
    }

    $result === 'live' ? $stmt->execute([$appId]) : $stmt->execute([$result, $appId]);
}

function assign_console(int $appId, int $consoleId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($consoleId <= 0) {
        $stmt = db()->prepare('UPDATE production_apps SET console_id = NULL, ready_for_work = 0 WHERE id = ?');
        $stmt->execute([$appId]);
        return;
    }

    $check = db()->prepare('SELECT id FROM consoles WHERE id = ? LIMIT 1');
    $check->execute([$consoleId]);
    if (!$check->fetch()) {
        throw new RuntimeException('Console was not found.');
    }

    $stmt = db()->prepare('UPDATE production_apps SET console_id = ? WHERE id = ?');
    $stmt->execute([$consoleId, $appId]);
}

function set_ready_for_work(int $appId, bool $ready): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($ready) {
        if ($app['status'] !== 'live') {
            throw new RuntimeException('Only Live apps can be tagged Ready for Work.');
        }
        if (empty($app['console_id'])) {
            throw new RuntimeException('Assign a Play Console before tagging Ready for Work.');
        }
    }

    $stmt = db()->prepare('UPDATE production_apps SET ready_for_work = ? WHERE id = ?');
    $stmt->execute([$ready ? 1 : 0, $appId]);
}

function app_slug(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);

    return trim($slug, '_');
}

/*
 * Console domain URL is a base; each app gets base/app_name_slug.
 * Duplicate app names in the same console get a number suffix in
 * creation order: car_wallpaper, car_wallpaper1, car_wallpaper2.
 */
function app_domain_url_for(array $app): ?string
{
    $base = $app['console_app_domain_url'] ?? null;
    if (!$base || empty($app['console_id'])) {
        return null;
    }

    $slug = app_slug((string) $app['name']);
    if ($slug === '') {
        return rtrim((string) $base, '/');
    }

    $stmt = db()->prepare('SELECT name FROM production_apps WHERE console_id = ? AND id < ? ORDER BY id ASC');
    $stmt->execute([(int) $app['console_id'], (int) $app['id']]);

    $earlier = 0;
    foreach ($stmt->fetchAll() as $row) {
        if (app_slug((string) $row['name']) === $slug) {
            $earlier++;
        }
    }

    return rtrim((string) $base, '/') . '/' . $slug . ($earlier > 0 ? $earlier : '');
}

/*
 * All apps of a console with their generated URL names, in creation
 * order — the same numbering app_domain_url_for() produces.
 */
function console_app_url_names(int $consoleId, ?string $baseUrl): array
{
    $stmt = db()->prepare('SELECT id, name, status, url_checked FROM production_apps WHERE console_id = ? ORDER BY id ASC');
    $stmt->execute([$consoleId]);
    $rows = $stmt->fetchAll();

    $counts = [];
    foreach ($rows as &$row) {
        $slug = app_slug((string) $row['name']);
        $seen = $counts[$slug] ?? 0;
        $counts[$slug] = $seen + 1;

        $urlName = $slug === '' ? '' : $slug . ($seen > 0 ? $seen : '');
        $row['url_name'] = $urlName;
        $row['full_url'] = ($baseUrl && $urlName !== '') ? rtrim($baseUrl, '/') . '/' . $urlName : null;
    }
    unset($row);

    return $rows;
}

function set_url_checked(int $appId, bool $checked): void
{
    $stmt = db()->prepare('UPDATE production_apps SET url_checked = ? WHERE id = ?');
    $stmt->execute([$checked ? 1 : 0, $appId]);

    if ($stmt->rowCount() < 1 && !get_production_app($appId)) {
        throw new RuntimeException('App was not found.');
    }
}

function url_checked_counts(): array
{
    $stmt = db()->query(
        'SELECT url_checked, COUNT(*) AS total FROM production_apps
         WHERE console_id IS NOT NULL
         GROUP BY url_checked'
    );

    $counts = ['pending' => 0, 'checked' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(int) $row['url_checked'] === 1 ? 'checked' : 'pending'] = (int) $row['total'];
    }

    return $counts;
}

function all_consoles(): array
{
    $stmt = db()->query('SELECT id, name, privacy_policy_url, app_domain_url, created_at FROM consoles ORDER BY created_at ASC, id ASC');

    return $stmt->fetchAll();
}

function validate_console_urls(array $data): array
{
    $urls = [];

    foreach (['privacy_policy_url', 'app_domain_url'] as $field) {
        $value = trim((string) ($data[$field] ?? ''));
        if (text_length($value) > 255) {
            throw new RuntimeException(ucfirst(str_replace('_', ' ', $field)) . ' must be 255 characters or fewer.');
        }
        $urls[$field] = $value === '' ? null : $value;
    }

    return $urls;
}

function add_console(string $name, array $data = []): void
{
    $name = trim($name);
    if ($name === '' || text_length($name) > 150) {
        throw new RuntimeException('Console name must be 1 to 150 characters.');
    }

    $urls = validate_console_urls($data);

    $stmt = db()->prepare('INSERT INTO consoles (name, privacy_policy_url, app_domain_url) VALUES (?, ?, ?)');
    $stmt->execute([$name, $urls['privacy_policy_url'], $urls['app_domain_url']]);
}

function update_console(int $consoleId, string $name, array $data = []): void
{
    $name = trim($name);
    if ($name === '' || text_length($name) > 150) {
        throw new RuntimeException('Console name must be 1 to 150 characters.');
    }

    $check = db()->prepare('SELECT id FROM consoles WHERE id = ? LIMIT 1');
    $check->execute([$consoleId]);
    if (!$check->fetch()) {
        throw new RuntimeException('Console was not found.');
    }

    $existing = db()->prepare('SELECT id FROM consoles WHERE name = ? AND id != ? LIMIT 1');
    $existing->execute([$name, $consoleId]);
    if ($existing->fetch()) {
        throw new RuntimeException('That console name already exists.');
    }

    $urls = validate_console_urls($data);

    $stmt = db()->prepare('UPDATE consoles SET name = ?, privacy_policy_url = ?, app_domain_url = ? WHERE id = ?');
    $stmt->execute([$name, $urls['privacy_policy_url'], $urls['app_domain_url'], $consoleId]);
}

function delete_console(int $consoleId): void
{
    $stmt = db()->prepare('DELETE FROM consoles WHERE id = ?');
    $stmt->execute([$consoleId]);
}

function console_overview(): array
{
    $stmt = db()->query(
        "SELECT c.id, c.name, c.privacy_policy_url, c.app_domain_url, c.created_at,
            (SELECT COUNT(*) FROM production_apps pa
             WHERE pa.console_id = c.id AND pa.status = 'live') AS live_total,
            (SELECT COUNT(*) FROM production_apps pa
             WHERE pa.console_id = c.id AND pa.status = 'live' AND pa.ready_for_work = 1) AS ready_total
         FROM consoles c
         ORDER BY c.created_at ASC, c.id ASC"
    );

    $consoles = $stmt->fetchAll();
    foreach ($consoles as &$console) {
        $consoleId = (int) $console['id'];
        $cycle = console_cycle($consoleId);
        $shown = min((int) $console['ready_total'], console_task_shown_count($consoleId, $cycle));

        $console['cycle_no'] = display_console_cycle($cycle);
        $console['shown_total'] = $shown;
        $console['remaining'] = max(0, (int) $console['ready_total'] - $shown);
    }
    unset($console);

    return $consoles;
}

/*
 * Each console runs its own task cycle: once a console has shown all of
 * its Ready for Work apps it starts again from its first app, without
 * waiting for the other consoles.
 */
function console_cycle(int $consoleId): int
{
    return max(1, (int) workflow_setting('task_cycle_c' . $consoleId, '1'));
}

/*
 * Stored cycle numbers only ever grow so past rows stay valid, while the
 * number shown to the user counts from the last "Restart All Consoles".
 */
function task_cycle_base(): int
{
    return max(1, (int) workflow_setting('task_cycle_base', '1'));
}

function display_console_cycle(int $storedCycle): int
{
    return max(1, $storedCycle - task_cycle_base() + 1);
}

function set_console_cycle(int $consoleId, int $cycle): void
{
    set_workflow_setting('task_cycle_c' . $consoleId, (string) $cycle);
}

function console_ready_count(int $consoleId): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM production_apps
         WHERE console_id = ? AND status = 'live' AND ready_for_work = 1"
    );
    $stmt->execute([$consoleId]);

    return (int) $stmt->fetchColumn();
}

function console_task_shown_count(int $consoleId, int $cycle): int
{
    $stmt = db()->prepare(
        "SELECT COUNT(DISTINCT dt.app_id) FROM daily_tasks dt
         JOIN production_apps pa ON pa.id = dt.app_id
         WHERE dt.console_id = ? AND dt.cycle_no = ?
           AND pa.status = 'live' AND pa.ready_for_work = 1"
    );
    $stmt->execute([$consoleId, $cycle]);

    return (int) $stmt->fetchColumn();
}

function cycle_progress(): array
{
    $eligible = 0;
    $shown = 0;

    foreach (all_consoles() as $console) {
        $consoleId = (int) $console['id'];
        $total = console_ready_count($consoleId);
        if ($total === 0) {
            continue;
        }

        $eligible += $total;
        $shown += min($total, console_task_shown_count($consoleId, console_cycle($consoleId)));
    }

    return [
        'cycle_days' => cycle_days(),
        'eligible' => $eligible,
        'shown' => $shown,
        'remaining' => max(0, $eligible - $shown),
    ];
}

function generate_daily_tasks(): int
{
    $today = date('Y-m-d');
    $days = cycle_days();
    $inserted = 0;

    $done = db()->prepare('SELECT COUNT(*) FROM daily_tasks WHERE task_date = ? AND console_id = ?');
    $insert = db()->prepare(
        'INSERT IGNORE INTO daily_tasks (task_date, app_id, console_id, cycle_no) VALUES (?, ?, ?, ?)'
    );

    foreach (all_consoles() as $console) {
        $consoleId = (int) $console['id'];

        $done->execute([$today, $consoleId]);
        if ((int) $done->fetchColumn() > 0) {
            continue;
        }

        $total = console_ready_count($consoleId);
        if ($total === 0) {
            continue;
        }

        $cycle = console_cycle($consoleId);

        /* This console finished its list, so start it over. */
        if (console_task_shown_count($consoleId, $cycle) >= $total) {
            $cycle++;
            set_console_cycle($consoleId, $cycle);
        }

        $quota = (int) ceil($total / $days);

        $pick = db()->prepare(
            "SELECT id FROM production_apps
             WHERE console_id = ? AND status = 'live' AND ready_for_work = 1
               AND id NOT IN (
                   SELECT app_id FROM daily_tasks WHERE console_id = ? AND cycle_no = ?
               )
             ORDER BY created_at ASC, id ASC
             LIMIT " . $quota
        );
        $pick->execute([$consoleId, $consoleId, $cycle]);

        foreach ($pick->fetchAll() as $row) {
            $insert->execute([$today, (int) $row['id'], $consoleId, $cycle]);
            $inserted++;
        }
    }

    return $inserted;
}

function todays_tasks(): array
{
    $stmt = db()->prepare(
        "SELECT dt.id, dt.is_done, dt.cycle_no, pa.name AS app_name, pa.package_name,
                c.id AS console_id, c.name AS console_name
         FROM daily_tasks dt
         JOIN production_apps pa ON pa.id = dt.app_id
         JOIN consoles c ON c.id = dt.console_id
         WHERE dt.task_date = ? AND pa.status = 'live' AND pa.ready_for_work = 1
         ORDER BY c.created_at ASC, c.id ASC, dt.id ASC"
    );
    $stmt->execute([date('Y-m-d')]);

    $grouped = [];
    foreach ($stmt->fetchAll() as $task) {
        $consoleId = (int) $task['console_id'];
        $grouped[$consoleId]['name'] = $task['console_name'];
        $grouped[$consoleId]['cycle_no'] = (int) $task['cycle_no'];
        $grouped[$consoleId]['tasks'][] = $task;
    }

    return $grouped;
}

function task_history(): array
{
    $stmt = db()->query(
        'SELECT dt.task_date, dt.is_done, dt.cycle_no, pa.name AS app_name, c.name AS console_name
         FROM daily_tasks dt
         JOIN production_apps pa ON pa.id = dt.app_id
         JOIN consoles c ON c.id = dt.console_id
         ORDER BY dt.task_date DESC, c.created_at ASC, c.id ASC, dt.id ASC'
    );

    return group_history_by_month($stmt->fetchAll());
}

function toggle_task_done(int $taskId): void
{
    $stmt = db()->prepare('UPDATE daily_tasks SET is_done = 1 - is_done WHERE id = ?');
    $stmt->execute([$taskId]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Task was not found.');
    }
}

function update_cycle_days(int $days): void
{
    if ($days < 1 || $days > 365) {
        throw new RuntimeException('Cycle days must be between 1 and 365.');
    }

    set_workflow_setting('cycle_days', (string) $days);
}

/* Manual restart: every console starts again from its first app today. */
function start_new_cycle(): void
{
    $stmt = db()->prepare('DELETE FROM daily_tasks WHERE task_date = ?');
    $stmt->execute([date('Y-m-d')]);

    $consoles = all_consoles();

    $next = (int) db()->query('SELECT COALESCE(MAX(cycle_no), 0) FROM daily_tasks')->fetchColumn();
    foreach ($consoles as $console) {
        $next = max($next, console_cycle((int) $console['id']));
    }
    $next++;

    /* Same cycle for everyone, and counting starts over at Cycle 1. */
    foreach ($consoles as $console) {
        set_console_cycle((int) $console['id'], $next);
    }
    set_workflow_setting('task_cycle_base', (string) $next);
}

/*
 * Move one console between rounds. 'restart' replays the current cycle,
 * 'next' and 'previous' step the cycle number; either way that console
 * starts again from its first app.
 */
function shift_console_cycle(int $consoleId, string $direction): void
{
    if ($consoleId <= 0) {
        throw new RuntimeException('Console was not found.');
    }

    $current = console_cycle($consoleId);
    $target = $current;

    if ($direction === 'next') {
        $target = $current + 1;
    } elseif ($direction === 'previous') {
        $target = $current - 1;
    } elseif ($direction !== 'restart') {
        throw new RuntimeException('Invalid cycle action.');
    }

    if ($target < task_cycle_base()) {
        throw new RuntimeException('This console is already on Cycle 1.');
    }

    $today = db()->prepare('DELETE FROM daily_tasks WHERE task_date = ? AND console_id = ?');
    $today->execute([date('Y-m-d'), $consoleId]);

    $round = db()->prepare('DELETE FROM daily_tasks WHERE console_id = ? AND cycle_no = ?');
    $round->execute([$consoleId, $target]);

    set_console_cycle($consoleId, $target);
}

function restart_console_cycle(int $consoleId): void
{
    shift_console_cycle($consoleId, 'restart');
}
