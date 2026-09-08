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
        "SELECT COUNT(*) AS total, COUNT(DISTINCT ip) AS unique_ips, COUNT(DISTINCT used_on) AS days
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
        "SELECT r.*, a.app_name, c.name AS console_name
         FROM rotation_ips r
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

/* How often each IP turns up in the month, so a repeat can be shown as one. */
function ip_month_usage(string $month): array
{
    $stmt = db()->prepare(
        "SELECT ip, COUNT(*) AS times FROM rotation_ips
         WHERE DATE_FORMAT(used_on, '%Y-%m') = ?
         GROUP BY ip HAVING times > 1"
    );
    $stmt->execute([$month]);

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(string) $row['ip']] = (int) $row['times'];
    }

    return $counts;
}

/* What has been typed before, offered back as suggestions. */
function ip_known_values(string $column): array
{
    if (!in_array($column, ['provider', 'country'], true)) {
        return [];
    }

    try {
        $stmt = db()->query(
            "SELECT DISTINCT {$column} AS v FROM rotation_ips
             WHERE {$column} IS NOT NULL AND {$column} <> '' ORDER BY v ASC LIMIT 100"
        );

        return array_column($stmt->fetchAll(), 'v');
    } catch (Throwable $e) {
        return [];
    }
}

/*
 * Add one or many IPs at once: the box takes one per line, and every line
 * that is not an address is reported back rather than quietly dropped.
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

    $provider = mb_substr(trim((string) ($data['provider'] ?? '')), 0, 100);
    $country = mb_substr(trim((string) ($data['country'] ?? '')), 0, 60);
    $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 255);

    $lines = preg_split('/[\r\n,]+/', (string) ($data['ips'] ?? '')) ?: [];
    $added = 0;
    $bad = [];

    $insert = db()->prepare(
        'INSERT INTO rotation_ips (used_on, console_id, app_id, ip, provider, country, note)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($lines as $line) {
        $ip = trim($line);
        if ($ip === '') {
            continue;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $bad[] = $ip;
            continue;
        }

        $insert->execute([
            $date,
            $consoleId > 0 ? $consoleId : null,
            $appId > 0 ? $appId : null,
            $ip,
            $provider !== '' ? $provider : null,
            $country !== '' ? $country : null,
            $note !== '' ? $note : null,
        ]);
        $added++;
    }

    if ($added === 0 && !$bad) {
        throw new RuntimeException('Add at least one IP.');
    }

    return ['added' => $added, 'bad' => $bad, 'month' => date('Y-m', strtotime($date))];
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

function delete_rotation_ip(int $id): void
{
    $stmt = db()->prepare('DELETE FROM rotation_ips WHERE id = ?');
    $stmt->execute([$id]);
}
