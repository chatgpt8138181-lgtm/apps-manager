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
        'none' => ['badge badge-gray', 'Loading only'],
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
        "INSERT INTO apps (app_name, package_name, application_id, console_id, stage,
                            loading_status, ready_loading_status)
         VALUES (?, ?, ?, ?, 'prepare', 'Inactive', 'Not Ready')"
    );
    $stmt->execute([
        $name,
        $optional['package_name'],
        $optional['application_id'],
        $consoleId,
    ]);

    $newId = (int) db()->lastInsertId();
    ensure_app_domain_url($newId);
    log_activity('app', $newId, 'created', $name);

    return $newId;
}

function update_production_app_details(int $appId, array $data): void
{
    if (!get_production_app($appId)) {
        throw new RuntimeException('App was not found.');
    }

    [$name, $optional] = validate_production_fields($data);
    $consoleId = validate_console_choice($data);

    $stmt = db()->prepare(
        'UPDATE apps
         SET app_name = ?, package_name = ?, application_id = ?,
             console_id = ?, ready_for_work = IF(? IS NULL, 0, ready_for_work)
         WHERE id = ?'
    );
    $stmt->execute([
        $name,
        $optional['package_name'],
        $optional['application_id'],
        $consoleId,
        $consoleId,
        $appId,
    ]);

    ensure_app_domain_url($appId);
    log_activity('app', $appId, 'updated', $name);
}

function delete_production_app(int $appId): void
{
    $app = get_production_app($appId);

    $stmt = db()->prepare('DELETE FROM apps WHERE id = ?');
    $stmt->execute([$appId]);

    if ($app) {
        log_activity('app', $appId, 'deleted', (string) $app['name'], 'Was ' . (string) $app['status']);
    }
}

function get_production_app(int $appId): ?array
{
    $stmt = db()->prepare(
        'SELECT pa.*, pa.app_name AS name, pa.stage AS status, c.name AS console_name,
            c.privacy_policy_url AS console_privacy_policy_url,
            c.app_domain_url AS console_app_domain_url,
            (SELECT COUNT(*) FROM production_checklist pc WHERE pc.app_id = pa.id AND pc.is_done = 1) AS checklist_done
         FROM apps pa
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
        'SELECT pa.*, pa.app_name AS name, pa.stage AS status, c.name AS console_name,
            c.privacy_policy_url AS console_privacy_policy_url,
            c.app_domain_url AS console_app_domain_url,
            (SELECT COUNT(*) FROM production_checklist pc WHERE pc.app_id = pa.id AND pc.is_done = 1) AS checklist_done
         FROM apps pa
         LEFT JOIN consoles c ON c.id = pa.console_id
         WHERE pa.stage = ?
         ORDER BY pa.created_at ASC, pa.id ASC'
    );
    $stmt->execute([$status]);

    return $stmt->fetchAll();
}

/* The one list behind the Apps page. */
function all_apps_overview(string $stage, int $consoleId, string $loading, string $search, string $url = ''): array
{
    $where = [];
    $params = [];

    if ($stage !== '') {
        $where[] = 'a.stage = ?';
        $params[] = $stage;
    }
    if ($consoleId > 0) {
        $where[] = 'a.console_id = ?';
        $params[] = $consoleId;
    }
    if ($loading !== '') {
        $where[] = 'a.loading_status = ?';
        $params[] = $loading;
    }
    if ($url === 'pending' || $url === 'checked') {
        /* Only a published app under a console has a URL worth checking. */
        $where[] = "a.url_checked = ? AND a.console_id IS NOT NULL AND a.stage <> 'none'";
        $params[] = $url === 'checked' ? 1 : 0;
    }
    if (trim($search) !== '') {
        $where[] = '(a.app_name LIKE ? OR a.package_name LIKE ?)';
        $like = '%' . trim($search) . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $sql = 'SELECT a.*, c.name AS console_name FROM apps a
            LEFT JOIN consoles c ON c.id = a.console_id';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY c.created_at ASC, c.id ASC, a.created_at ASC, a.id ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

/* Add an app to either side from one form. */
function add_app_record(string $name, int $consoleId, bool $publishing): int
{
    $name = trim($name);
    if ($name === '' || text_length($name) > 200) {
        throw new RuntimeException('App name must be 1 to 200 characters.');
    }

    $stmt = db()->prepare(
        "INSERT INTO apps (app_name, console_id, stage, loading_status, ready_loading_status)
         VALUES (?, ?, ?, ?, 'Not Ready')"
    );
    $stmt->execute([
        $name,
        $consoleId > 0 ? $consoleId : null,
        $publishing ? 'prepare' : 'none',
        $publishing ? 'Inactive' : 'Active',
    ]);

    $newId = (int) db()->lastInsertId();
    ensure_app_domain_url($newId);
    log_activity('app', $newId, 'created', $name, $publishing ? 'Publishing' : 'Loading');

    return $newId;
}

function production_status_counts(): array
{
    $stmt = db()->query("SELECT stage AS status, COUNT(*) AS total FROM apps WHERE stage <> 'none' GROUP BY stage");
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

/* When each item was first ticked, keyed the same way as checklist_state(). */
function checklist_done_times(int $appId): array
{
    $stmt = db()->prepare('SELECT item_key, done_at FROM production_checklist WHERE app_id = ?');
    $stmt->execute([$appId]);

    $times = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!empty($row['done_at'])) {
            $times[$row['item_key']] = (string) $row['done_at'];
        }
    }

    return $times;
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
         ON DUPLICATE KEY UPDATE
            is_done = VALUES(is_done),
            done_at = IF(VALUES(is_done) = 1, IFNULL(done_at, VALUES(done_at)), NULL)'
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
        $update = db()->prepare('UPDATE apps SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $update->execute($params);
    }

    $app = get_production_app($appId);
    log_activity('app', $appId, 'checklist', $app ? (string) $app['name'] : null,
        count($doneKeys) . ' of ' . count(checklist_items()) . ' complete');
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

    $stmt = db()->prepare("UPDATE apps SET stage = 'sent', sent_at = NOW() WHERE id = ?");
    $stmt->execute([$appId]);

    log_activity('app', $appId, 'stage_sent', (string) $app['name']);
}

/* Put a loading-only app into the publishing flow. */
function start_publishing(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($app['status'] !== 'none') {
        throw new RuntimeException('This app is already in the publishing flow.');
    }

    $stmt = db()->prepare("UPDATE apps SET stage = 'prepare' WHERE id = ?");
    $stmt->execute([$appId]);

    log_activity('app', $appId, 'stage_prepare', (string) $app['name'], 'Started publishing');
}

/* Take it back out, leaving the loading side untouched. */
function stop_publishing(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($app['status'] !== 'prepare') {
        throw new RuntimeException('Only an app still in Prepare can leave the publishing flow.');
    }

    $stmt = db()->prepare("UPDATE apps SET stage = 'none' WHERE id = ?");
    $stmt->execute([$appId]);

    log_activity('app', $appId, 'stage_none', (string) $app['name'], 'Removed from publishing');
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

    $stmt = db()->prepare("UPDATE apps SET stage = 'ready' WHERE id = ?");
    $stmt->execute([$appId]);

    log_activity('app', $appId, 'stage_ready', (string) $app['name']);
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

    $stmt = db()->prepare("UPDATE apps SET stage = 'prepare', sent_at = NULL WHERE id = ?");
    $stmt->execute([$appId]);

    log_activity('app', $appId, 'stage_prepare', (string) $app['name'], 'Was ' . (string) $app['status']);
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

    $stmt = db()->prepare("UPDATE apps SET stage = 'ready', sent_at = NULL WHERE id = ?");
    $stmt->execute([$appId]);

    log_activity('app', $appId, 'stage_back_ready', (string) $app['name']);
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

    $stmt = db()->prepare("UPDATE apps SET stage = 'sent', live_at = NULL, ready_for_work = 0 WHERE id = ?");
    $stmt->execute([$appId]);

    log_activity('app', $appId, 'stage_back_sent', (string) $app['name'], 'Was ' . (string) $app['status']);
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
        $stmt = db()->prepare("UPDATE apps SET stage = 'live', live_at = COALESCE(live_at, NOW()) WHERE id = ?");
    } else {
        $stmt = db()->prepare('UPDATE apps SET stage = ? WHERE id = ?');
    }

    $result === 'live' ? $stmt->execute([$appId]) : $stmt->execute([$result, $appId]);

    log_activity('app', $appId, 'stage_' . $result, (string) $app['name']);
}

function assign_console(int $appId, int $consoleId): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    if ($consoleId <= 0) {
        $stmt = db()->prepare('UPDATE apps SET console_id = NULL, ready_for_work = 0 WHERE id = ?');
        $stmt->execute([$appId]);
        return;
    }

    $check = db()->prepare('SELECT id FROM consoles WHERE id = ? LIMIT 1');
    $check->execute([$consoleId]);
    if (!$check->fetch()) {
        throw new RuntimeException('Console was not found.');
    }

    $stmt = db()->prepare('UPDATE apps SET console_id = ? WHERE id = ?');
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

    $stmt = db()->prepare('UPDATE apps SET ready_for_work = ? WHERE id = ?');
    $stmt->execute([$ready ? 1 : 0, $appId]);

    log_activity('app', $appId, $ready ? 'tagged_ready' : 'untagged_ready', (string) $app['name']);
}

/* Apply one stage move to many apps at once. Returns how many moved and
   the first problem, so a page can report both. */
function apply_bulk_production_action(string $action, array $appIds): array
{
    $moves = [
        'ready' => 'mark_app_ready',
        'send' => 'send_app_to_production',
        'to_prepare' => 'revert_app_to_prepare',
        'to_ready' => 'revert_app_to_ready',
        'to_sent' => 'revert_app_to_sent',
        'delete' => 'delete_production_app',
    ];
    $results = ['live', 'rejected', 'suspended'];

    $done = 0;
    $failed = 0;
    $firstError = null;

    foreach ($appIds as $rawId) {
        $appId = (int) $rawId;
        if ($appId <= 0) {
            continue;
        }

        try {
            if (isset($moves[$action])) {
                $moves[$action]($appId);
            } elseif (in_array($action, $results, true)) {
                set_production_result($appId, $action);
            } elseif ($action === 'tag_ready' || $action === 'untag_ready') {
                set_ready_for_work($appId, $action === 'tag_ready');
            } elseif ($action === 'store_sync') {
                sync_app_with_store($appId);
            } else {
                throw new RuntimeException('Unknown bulk action.');
            }
            $done++;
        } catch (Throwable $e) {
            $failed++;
            $firstError = $firstError ?? $e->getMessage();
        }
    }

    if ($done === 0 && $firstError !== null) {
        throw new RuntimeException($firstError);
    }

    return ['done' => $done, 'failed' => $failed, 'error' => $firstError];
}

function bulk_result_message(array $result, string $verb): string
{
    $message = $result['done'] . ' app(s) ' . $verb . '.';
    if ($result['failed'] > 0) {
        $message .= ' ' . $result['failed'] . ' skipped: ' . (string) $result['error'];
    }

    return $message;
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
/*
 * An app's domain URL is stored on the app. It is only ever built from the
 * console base and the app's name when there is nothing stored yet, which
 * means renaming an app — or reading its name off the Play Store — cannot
 * move a URL that is already in use.
 */
function app_domain_url_for(array $app): ?string
{
    $stored = trim((string) ($app['domain_url'] ?? ''));
    if ($stored !== '') {
        return $stored;
    }

    return build_app_domain_url($app);
}

/* What the URL would be, from the console base and the app's name. */
function build_app_domain_url(array $app): ?string
{
    $base = $app['console_app_domain_url'] ?? null;
    if (!$base || empty($app['console_id'])) {
        return null;
    }

    $slug = app_slug((string) $app['name']);
    if ($slug === '') {
        return rtrim((string) $base, '/');
    }

    $stmt = db()->prepare(
        "SELECT app_name AS name FROM apps
         WHERE console_id = ? AND stage <> 'none' AND id < ? ORDER BY id ASC"
    );
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
/* Store a URL the person typed. A changed URL has not been checked yet. */
function set_app_domain_url(int $appId, string $url): void
{
    $app = get_production_app($appId);
    if (!$app) {
        throw new RuntimeException('App was not found.');
    }

    $url = trim($url);
    if ($url !== '') {
        if (text_length($url) > 255) {
            throw new RuntimeException('Domain URL must be 255 characters or fewer.');
        }
        if (!preg_match('~^https?://~i', $url)) {
            throw new RuntimeException('Domain URL must start with http:// or https://.');
        }
    }

    $current = trim((string) ($app['domain_url'] ?? ''));
    if ($current === $url) {
        return;
    }

    $stmt = db()->prepare('UPDATE apps SET domain_url = ?, url_checked = 0 WHERE id = ?');
    $stmt->execute([$url === '' ? null : $url, $appId]);

    log_activity('app', $appId, 'domain_url_changed', (string) $app['name'], $url !== '' ? $url : 'cleared');
}

/* Fill the URL in once, when the app first has a console to belong to. */
function ensure_app_domain_url(int $appId): void
{
    $app = get_production_app($appId);
    if (!$app || trim((string) ($app['domain_url'] ?? '')) !== '') {
        return;
    }

    $built = build_app_domain_url($app);
    if ($built === null) {
        return;
    }

    $stmt = db()->prepare('UPDATE apps SET domain_url = ? WHERE id = ?');
    $stmt->execute([$built, $appId]);
}

/* Rebuild a whole console's URLs on its current domain base. */
function rebuild_console_domain_urls(int $consoleId): array
{
    $stmt = db()->prepare(
        'SELECT a.*, a.app_name AS name, a.stage AS status, c.app_domain_url AS console_app_domain_url
         FROM apps a JOIN consoles c ON c.id = a.console_id
         WHERE a.console_id = ?
         ORDER BY a.created_at ASC, a.id ASC'
    );
    $stmt->execute([$consoleId]);
    $apps = $stmt->fetchAll();

    $update = db()->prepare('UPDATE apps SET domain_url = ?, url_checked = 0 WHERE id = ?');
    $changed = 0;
    $wasChecked = 0;

    foreach ($apps as $app) {
        $built = build_app_domain_url($app);
        if ($built === null || $built === trim((string) ($app['domain_url'] ?? ''))) {
            continue;
        }

        $update->execute([$built, (int) $app['id']]);
        $changed++;
        $wasChecked += (int) $app['url_checked'] === 1 ? 1 : 0;
    }

    if ($changed > 0) {
        log_activity('console', $consoleId, 'urls_rebuilt', null, $changed . ' app URL(s) rebuilt');
    }

    return ['changed' => $changed, 'was_checked' => $wasChecked, 'total' => count($apps)];
}

function console_app_url_names(int $consoleId, ?string $baseUrl): array
{
    $stmt = db()->prepare(
        "SELECT id, app_name AS name, stage AS status, url_checked, domain_url FROM apps
         WHERE console_id = ? AND stage <> 'none' ORDER BY id ASC"
    );
    $stmt->execute([$consoleId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$row) {
        $stored = trim((string) ($row['domain_url'] ?? ''));
        $row['full_url'] = $stored !== '' ? $stored : null;
        /* The last segment, shown on its own so the list stays readable. */
        $path = trim((string) parse_url($stored, PHP_URL_PATH), '/');
        $row['url_name'] = $path !== '' ? substr($path, strrpos($path, '/') === false ? 0 : strrpos($path, '/') + 1) : '';
        $row['off_base'] = $stored !== '' && $baseUrl
            && strpos($stored, rtrim((string) $baseUrl, '/') . '/') !== 0;
    }
    unset($row);

    return $rows;
}

function set_url_checked(int $appId, bool $checked): void
{
    $stmt = db()->prepare('UPDATE apps SET url_checked = ? WHERE id = ?');
    $stmt->execute([$checked ? 1 : 0, $appId]);

    if ($stmt->rowCount() < 1 && !get_production_app($appId)) {
        throw new RuntimeException('App was not found.');
    }

    log_activity('app', $appId, $checked ? 'url_checked' : 'url_pending');
}

function url_checked_counts(): array
{
    $stmt = db()->query(
        "SELECT url_checked, COUNT(*) AS total FROM apps
         WHERE console_id IS NOT NULL AND stage <> 'none'
         GROUP BY url_checked"
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

    log_activity('console', (int) db()->lastInsertId(), 'created', $name);
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

    log_activity('console', $consoleId, 'updated', $name);
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
            (SELECT COUNT(*) FROM apps pa
             WHERE pa.console_id = c.id AND pa.stage = 'live') AS live_total,
            (SELECT COUNT(*) FROM apps pa
             WHERE pa.console_id = c.id AND pa.stage = 'live' AND pa.ready_for_work = 1) AS ready_total,
            (SELECT COUNT(*) FROM apps a WHERE a.console_id = c.id) AS loading_total,
            (SELECT COUNT(*) FROM apps a
             WHERE a.console_id = c.id AND a.loading_status = 'Active') AS loading_active
         FROM consoles c
         ORDER BY c.created_at ASC, c.id ASC"
    );

    $consoles = $stmt->fetchAll();
    foreach ($consoles as &$console) {
        $consoleId = (int) $console['id'];
        /* Shown counts the walked position, the same measure the page stats use. */
        $shown = min((int) $console['ready_total'], console_position($consoleId));

        $console['cycle_no'] = display_console_cycle($consoleId);
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

/*
 * Stored cycle numbers only ever grow so past rows stay valid, while the
 * number shown to the user counts from the last "Restart All Consoles".
 */

/* The cycle number a console is on: how far its rotation has walked,
   counted in day-sized steps and starting over with each new round. */




/* Quota is per console, so the window size can differ per console. */

/* Where the console's rotation currently sits: the list position that the
   next generated day starts from. Older installs fall back to what the
   current cycle already showed, so nothing jumps back to the first app. */


/* Start of the window that today's tasks were taken from. */



/* Task rotation: thin names over the shared engine in rotation.php. */
function console_cycle(int $consoleId): int
{
    return rotation_cycle('task', $consoleId);
}

function set_console_cycle(int $consoleId, int $cycle): void
{
    rotation_set_cycle('task', $consoleId, $cycle);
}

function task_cycle_base(): int
{
    return rotation_base('task');
}

function display_console_cycle(int $consoleId): int
{
    return rotation_display_cycle('task', $consoleId);
}

function console_ready_count(int $consoleId): int
{
    return rotation_total('task', $consoleId);
}

function console_task_quota(int $consoleId): int
{
    return rotation_quota('task', $consoleId);
}

function console_position(int $consoleId): int
{
    return rotation_position('task', $consoleId);
}

function set_console_position(int $consoleId, int $position): void
{
    rotation_set_position('task', $consoleId, $position);
}

function console_today_start(int $consoleId): int
{
    return rotation_today_start('task', $consoleId);
}

function cycle_progress(): array
{
    return ['cycle_days' => cycle_days()] + rotation_progress('task');
}

function generate_daily_tasks(): int
{
    return rotation_generate('task');
}

function toggle_task_done(int $taskId): void
{
    rotation_toggle_done('task', $taskId);
}

function start_new_cycle(): void
{
    rotation_restart_all('task');
}

function shift_console_cycle(int $consoleId, string $direction): void
{
    rotation_shift('task', $consoleId, $direction);
}

function restart_console_cycle(int $consoleId): void
{
    rotation_shift('task', $consoleId, 'restart');
}

function todays_tasks(): array
{
    $stmt = db()->prepare(
        "SELECT dt.id, dt.is_done, dt.cycle_no, pa.app_name, pa.package_name,
                c.id AS console_id, c.name AS console_name
         FROM daily_tasks dt
         JOIN apps pa ON pa.id = dt.app_id
         JOIN consoles c ON c.id = dt.console_id
         WHERE dt.task_date = ? AND pa.stage = 'live' AND pa.ready_for_work = 1
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
        'SELECT dt.task_date, dt.is_done, dt.cycle_no, pa.app_name, c.name AS console_name
         FROM daily_tasks dt
         JOIN apps pa ON pa.id = dt.app_id
         JOIN consoles c ON c.id = dt.console_id
         ORDER BY dt.task_date DESC, c.created_at ASC, c.id ASC, dt.id ASC'
    );

    return group_history_by_month($stmt->fetchAll());
}


function update_cycle_days(int $days): void
{
    if ($days < 1 || $days > 365) {
        throw new RuntimeException('Cycle days must be between 1 and 365.');
    }

    set_workflow_setting('cycle_days', (string) $days);
}

/* Manual restart: every console starts again from its first app today. */

/*
 * Move one console between rounds. 'restart' replays the current cycle,
 * 'next' and 'previous' step the cycle number; either way that console
 * starts again from its first app.
 */

