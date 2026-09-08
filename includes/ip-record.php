<?php
declare(strict_types=1);

/*
 * The IPs used while loading apps.
 *
 * An entry belongs to a day and, usually, to the app it was used on — the
 * console comes with the app. A month stands on its own: the page opens on
 * the current one, and the months before it stay where they are.
 */

/* The month a page is showing, as YYYY-MM. Anything odd falls back to now. */
function ip_month(string $wanted = ''): string
{
    $wanted = trim($wanted);

    return preg_match('/^\d{4}-\d{2}$/', $wanted) === 1 ? $wanted : date('Y-m');
}

function ip_month_label(string $month): string
{
    $time = strtotime($month . '-01') ?: time();

    return date('F Y', $time);
}

function ip_month_shift(string $month, int $months): string
{
    $time = strtotime($month . '-01 ' . ($months >= 0 ? '+' : '') . $months . ' months');

    return date('Y-m', $time ?: time());
}

/* The months that hold something, newest first — so a page knows where to go. */
function ip_months_with_records(): array
{
    try {
        $stmt = db()->query(
            "SELECT DISTINCT DATE_FORMAT(used_on, '%Y-%m') AS m FROM rotation_ips ORDER BY m DESC"
        );

        return array_column($stmt->fetchAll(), 'm');
    } catch (Throwable $e) {
        return [];
    }
}

function ip_month_stats(string $month): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total, COUNT(DISTINCT COALESCE(ip_id, id)) AS unique_ips,
                COUNT(DISTINCT used_on) AS days
         FROM rotation_ips WHERE DATE_FORMAT(used_on, '%Y-%m') = ?"
    );
    $stmt->execute([$month]);
    $row = $stmt->fetch() ?: [];

    return [
        'total' => (int) ($row['total'] ?? 0),
        'unique_ips' => (int) ($row['unique_ips'] ?? 0),
        'days' => (int) ($row['days'] ?? 0),
    ];
}

/* One month's entries, newest day first, each day in the order they arrived. */
function ip_records_by_day(string $month): array
{
    $stmt = db()->prepare(
        "SELECT r.*, COALESCE(p.name, r.ip) AS ip_name, COALESCE(p.ip, r.ip) AS ip,
                a.app_name, c.name AS console_name
         FROM rotation_ips r
         LEFT JOIN ip_pool p ON p.id = r.ip_id
         LEFT JOIN apps a ON a.id = r.app_id
         LEFT JOIN consoles c ON c.id = r.console_id
         WHERE DATE_FORMAT(r.used_on, '%Y-%m') = ?
         ORDER BY r.used_on DESC, r.id ASC"
    );
    $stmt->execute([$month]);

    $days = [];
    foreach ($stmt->fetchAll() as $row) {
        $date = (string) $row['used_on'];
        $days[$date]['label'] = date('d M Y', strtotime($date) ?: time());
        $days[$date]['rows'][] = $row;
    }

    return $days;
}

/*
 * The same month seen the other way: every console with its apps, and each
 * app with the IPs it was given. Apps that were given none are still listed,
 * so a console shows its whole set rather than only the busy ones.
 */
function ip_apps_by_console(string $month): array
{
    /* The apps a console loads, plus any app this month's record touched. */
    $stmt = db()->prepare(
        "SELECT a.id, a.app_name, a.console_id, c.name AS console_name
         FROM apps a
         JOIN consoles c ON c.id = a.console_id
         WHERE a.stage = 'live'
            OR a.id IN (SELECT app_id FROM rotation_ips
                        WHERE app_id IS NOT NULL AND DATE_FORMAT(used_on, '%Y-%m') = ?)
         ORDER BY c.created_at ASC, c.id ASC, a.created_at ASC, a.id ASC"
    );
    $stmt->execute([$month]);

    $consoles = [];
    foreach ($stmt->fetchAll() as $app) {
        $consoleId = (int) $app['console_id'];
        $consoles[$consoleId]['name'] = $app['console_name'];
        $consoles[$consoleId]['total'] = 0;
        $consoles[$consoleId]['apps'][(int) $app['id']] = [
            'id' => (int) $app['id'],
            'name' => (string) $app['app_name'],
            'rows' => [],
        ];
    }

    $rows = db()->prepare(
        "SELECT r.*, COALESCE(p.name, r.ip) AS ip_name, COALESCE(p.ip, r.ip) AS ip
         FROM rotation_ips r
         LEFT JOIN ip_pool p ON p.id = r.ip_id
         WHERE DATE_FORMAT(r.used_on, '%Y-%m') = ?
         ORDER BY r.used_on DESC, r.id DESC"
    );
    $rows->execute([$month]);

    $loose = [];
    foreach ($rows->fetchAll() as $row) {
        $appId = (int) ($row['app_id'] ?? 0);
        $consoleId = (int) ($row['console_id'] ?? 0);

        if ($appId > 0 && isset($consoles[$consoleId]['apps'][$appId])) {
            $consoles[$consoleId]['apps'][$appId]['rows'][] = $row;
            $consoles[$consoleId]['total']++;
            continue;
        }

        /* Recorded against the console alone, or against nothing at all. */
        if ($consoleId > 0 && isset($consoles[$consoleId])) {
            $consoles[$consoleId]['apps'][0] ??= ['id' => 0, 'name' => 'No app', 'rows' => []];
            $consoles[$consoleId]['apps'][0]['rows'][] = $row;
            $consoles[$consoleId]['total']++;
            continue;
        }

        $loose[] = $row;
    }

    if ($loose) {
        $consoles[0] = [
            'name' => 'No console',
            'total' => count($loose),
            'apps' => [0 => ['id' => 0, 'name' => 'No app', 'rows' => $loose]],
        ];
    }

    return $consoles;
}

/*
 * Rows that share everything but the address read better as one line with
 * the addresses together, rather than one line each.
 */
function ip_group_rows(array $rows, array $by): array
{
    $groups = [];

    foreach ($rows as $row) {
        $key = '';
        foreach ($by as $field) {
            $key .= '|' . (string) ($row[$field] ?? '');
        }

        if (!isset($groups[$key])) {
            $groups[$key] = $row;
            $groups[$key]['ips'] = [];
        }

        $groups[$key]['ips'][] = [
            'id' => (int) $row['id'],
            'ip' => (string) $row['ip'],
            'name' => (string) ($row['ip_name'] ?? $row['ip']),
        ];
    }

    return array_values($groups);
}

/* How often each IP turns up in the month, so a repeat can be shown as one. */
function ip_month_usage(string $month): array
{
    $stmt = db()->prepare(
        "SELECT COALESCE(p.name, r.ip) AS ip_name, COUNT(*) AS times
         FROM rotation_ips r
         LEFT JOIN ip_pool p ON p.id = r.ip_id
         WHERE DATE_FORMAT(r.used_on, '%Y-%m') = ?
         GROUP BY ip_name HAVING times > 1"
    );
    $stmt->execute([$month]);

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(string) $row['ip_name']] = (int) $row['times'];
    }

    return $counts;
}

/*
 * The list of IPs. Each one has a name, and the name is what a person reads
 * wherever the IP turns up. Where it comes from belongs to the IP, not to
 * each use of it.
 */
function ip_pool_all(): array
{
    try {
        $stmt = db()->query('SELECT * FROM ip_pool ORDER BY name ASC');

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function ip_pool_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM ip_pool WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/* How often each IP in the list has been used, so a list row can say so. */
function ip_pool_usage(): array
{
    try {
        $stmt = db()->query(
            'SELECT ip_id, COUNT(*) AS total FROM rotation_ips
             WHERE ip_id IS NOT NULL GROUP BY ip_id'
        );

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['ip_id']] = (int) $row['total'];
        }

        return $counts;
    } catch (Throwable $e) {
        return [];
    }
}

function validate_pool_fields(array $data): array
{
    $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 100);
    $ip = trim((string) ($data['ip'] ?? ''));

    if ($name === '') {
        throw new RuntimeException('Give this IP a name.');
    }
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        throw new RuntimeException('"' . $ip . '" is not an IP address.');
    }

    return [
        'name' => $name,
        'ip' => $ip,
        'provider' => mb_substr(trim((string) ($data['provider'] ?? '')), 0, 100) ?: null,
        'country' => mb_substr(trim((string) ($data['country'] ?? '')), 0, 60) ?: null,
        'city' => mb_substr(trim((string) ($data['city'] ?? '')), 0, 100) ?: null,
        'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 255) ?: null,
    ];
}

function add_pool_ip(array $data): void
{
    $f = validate_pool_fields($data);

    $clash = db()->prepare('SELECT name, ip FROM ip_pool WHERE name = ? OR ip = ? LIMIT 1');
    $clash->execute([$f['name'], $f['ip']]);
    if ($found = $clash->fetch()) {
        throw new RuntimeException($found['ip'] === $f['ip']
            ? 'That IP is already on the list, as "' . $found['name'] . '".'
            : 'The name "' . $f['name'] . '" is already taken.');
    }

    $stmt = db()->prepare(
        'INSERT INTO ip_pool (name, ip, provider, country, city, note) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$f['name'], $f['ip'], $f['provider'], $f['country'], $f['city'], $f['note']]);
}

function update_pool_ip(int $id, array $data): void
{
    $f = validate_pool_fields($data);

    $clash = db()->prepare('SELECT name, ip FROM ip_pool WHERE (name = ? OR ip = ?) AND id <> ? LIMIT 1');
    $clash->execute([$f['name'], $f['ip'], $id]);
    if ($found = $clash->fetch()) {
        throw new RuntimeException($found['ip'] === $f['ip']
            ? 'That IP is already on the list, as "' . $found['name'] . '".'
            : 'The name "' . $f['name'] . '" is already taken.');
    }

    $stmt = db()->prepare(
        'UPDATE ip_pool SET name = ?, ip = ?, provider = ?, country = ?, city = ?, note = ? WHERE id = ?'
    );
    $stmt->execute([$f['name'], $f['ip'], $f['provider'], $f['country'], $f['city'], $f['note'], $id]);
}

/* An IP that has been used somewhere stays, so no record loses its name. */
function delete_pool_ip(int $id): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM rotation_ips WHERE ip_id = ?');
    $stmt->execute([$id]);
    $used = (int) $stmt->fetchColumn();

    if ($used > 0) {
        throw new RuntimeException(
            'This IP is used ' . $used . ' time(s) in the record. Remove those entries first.'
        );
    }

    $delete = db()->prepare('DELETE FROM ip_pool WHERE id = ?');
    $delete->execute([$id]);
}

/* The lists that provider, country and city are picked from. */
function ip_option_kinds(): array
{
    return ['provider' => 'Providers', 'country' => 'Countries', 'city' => 'Cities'];
}

function ip_options(string $kind): array
{
    if (!array_key_exists($kind, ip_option_kinds())) {
        return [];
    }

    try {
        $stmt = db()->prepare('SELECT id, name FROM ip_options WHERE kind = ? ORDER BY name ASC');
        $stmt->execute([$kind]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function add_ip_option(string $kind, string $name): void
{
    if (!array_key_exists($kind, ip_option_kinds())) {
        throw new RuntimeException('Unknown list.');
    }

    $name = mb_substr(trim($name), 0, 100);
    if ($name === '') {
        throw new RuntimeException('Give it a name first.');
    }

    $stmt = db()->prepare('INSERT IGNORE INTO ip_options (kind, name) VALUES (?, ?)');
    $stmt->execute([$kind, $name]);

    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('"' . $name . '" is already on that list.');
    }
}

/*
 * Removing a name takes it off the list for next time. Entries already
 * recorded keep what they were given.
 */
function delete_ip_option(int $id): void
{
    $stmt = db()->prepare('DELETE FROM ip_options WHERE id = ?');
    $stmt->execute([$id]);
}

/*
 * Recording use: one day, one app, and the IPs picked from the list. What
 * each IP is and where it comes from already lives with the IP itself.
 */
function add_rotation_ips(array $data): array
{
    $date = trim((string) ($data['used_on'] ?? ''));
    if ($date === '' || !strtotime($date)) {
        throw new RuntimeException('Pick the day these IPs were used on.');
    }
    $date = date('Y-m-d', strtotime($date));

    $appId = (int) ($data['app_id'] ?? 0);
    $consoleId = (int) ($data['console_id'] ?? 0);

    /* The app carries its own console, so that is the one that gets stored. */
    if ($appId > 0) {
        $stmt = db()->prepare('SELECT console_id FROM apps WHERE id = ? LIMIT 1');
        $stmt->execute([$appId]);
        $found = $stmt->fetch();
        if (!$found) {
            throw new RuntimeException('That app was not found.');
        }
        $consoleId = (int) ($found['console_id'] ?? 0);
    }

    $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($data['ip_ids'] ?? [])))));

    if (!$ids) {
        throw new RuntimeException('Pick at least one IP from the list.');
    }

    $insert = db()->prepare(
        'INSERT INTO rotation_ips (used_on, console_id, app_id, ip_id, ip, provider, country, city, note)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $added = 0;
    foreach ($ids as $id) {
        $pool = ip_pool_get($id);
        if (!$pool) {
            continue;
        }

        /* The plain columns keep a copy, so an old row still reads on its own. */
        $insert->execute([
            $date,
            $consoleId > 0 ? $consoleId : null,
            $appId > 0 ? $appId : null,
            (int) $pool['id'],
            (string) $pool['ip'],
            $pool['provider'],
            $pool['country'],
            $pool['city'],
            $note !== '' ? $note : null,
        ]);
        $added++;
    }

    if ($added === 0) {
        throw new RuntimeException('None of those IPs are on the list any more.');
    }

    return ['added' => $added, 'bad' => [], 'month' => date('Y-m', strtotime($date))];
}

function delete_rotation_ip(int $id): void
{
    $stmt = db()->prepare('DELETE FROM rotation_ips WHERE id = ?');
    $stmt->execute([$id]);
}

/* How many IPs each app has on one day, for the rotation list to show. */
function ip_counts_for_date(string $date): array
{
    try {
        $stmt = db()->prepare(
            'SELECT app_id, COUNT(*) AS total FROM rotation_ips
             WHERE used_on = ? AND app_id IS NOT NULL GROUP BY app_id'
        );
        $stmt->execute([$date]);

        $counts = [];
        foreach ($stmt->fetchAll() as $row) {
            $counts[(int) $row['app_id']] = (int) $row['total'];
        }

        return $counts;
    } catch (Throwable $e) {
        return [];
    }
}

/* Which IPs an app already has on one day, so the picker can say so. */
function ip_ids_for_date(string $date): array
{
    try {
        $stmt = db()->prepare(
            'SELECT app_id, ip_id, COUNT(*) AS total FROM rotation_ips
             WHERE used_on = ? AND app_id IS NOT NULL AND ip_id IS NOT NULL
             GROUP BY app_id, ip_id'
        );
        $stmt->execute([$date]);

        $found = [];
        foreach ($stmt->fetchAll() as $row) {
            $found[(int) $row['app_id']][(int) $row['ip_id']] = (int) $row['total'];
        }

        return $found;
    } catch (Throwable $e) {
        return [];
    }
}

/* The month's addresses as plain lines, for handing to something else. */
function ip_list_for_month(string $month, bool $uniqueOnly = false): array
{
    $sql = $uniqueOnly
        ? "SELECT DISTINCT COALESCE(p.ip, r.ip) AS ip FROM rotation_ips r
           LEFT JOIN ip_pool p ON p.id = r.ip_id
           WHERE DATE_FORMAT(r.used_on, '%Y-%m') = ? ORDER BY ip ASC"
        : "SELECT COALESCE(p.ip, r.ip) AS ip FROM rotation_ips r
           LEFT JOIN ip_pool p ON p.id = r.ip_id
           WHERE DATE_FORMAT(r.used_on, '%Y-%m') = ? ORDER BY r.used_on ASC, r.id ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute([$month]);

    return array_column($stmt->fetchAll(), 'ip');
}

/* Every month that holds something, with how much, newest first. */
function ip_months_with_counts(): array
{
    try {
        $stmt = db()->query(
            "SELECT DATE_FORMAT(used_on, '%Y-%m') AS month, COUNT(*) AS total
             FROM rotation_ips GROUP BY month ORDER BY month DESC"
        );

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* Clear one whole month. Only ever runs because someone asked for it. */
function delete_ip_month(string $month): int
{
    if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
        throw new RuntimeException('That is not a month.');
    }
    if ($month === date('Y-m')) {
        throw new RuntimeException('This month is still in use. Pick an earlier one.');
    }

    $stmt = db()->prepare("DELETE FROM rotation_ips WHERE DATE_FORMAT(used_on, '%Y-%m') = ?");
    $stmt->execute([$month]);

    return $stmt->rowCount();
}

/* One picker, built from the list it belongs to. */
function ip_option_select(string $kind, array $options, string $chosen = '', string $form = ''): void
{
    ?>
    <select name="<?= h($kind) ?>" aria-label="<?= h(ucfirst($kind)) ?>"<?= $form !== '' ? ' form="' . h($form) . '"' : '' ?>>
        <option value=""><?= h(ucfirst($kind)) ?></option>
        <?php foreach ($options as $option): ?>
            <option value="<?= h($option['name']) ?>" <?= $chosen === $option['name'] ? 'selected' : '' ?>>
                <?= h($option['name']) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
}
