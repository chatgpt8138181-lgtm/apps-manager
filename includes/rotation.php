<?php
declare(strict_types=1);

/*
 * One rotation engine for both modules.
 *
 * Loading rotates a console's Active apps; Daily Tasks rotates a console's
 * Ready-for-Work live apps. The rules are identical: each console walks its
 * own list from a stored position, a day takes the next slice, and the list
 * starts over — one cycle further on — once it runs out.
 *
 * A rotation is described once, here, and the module-facing helpers in
 * functions.php and workflow.php are thin wrappers over these.
 */

function rotation_config(string $kind): array
{
    $configs = [
        'loading' => [
            'table' => 'loading_daily',
            'owner_column' => 'console_id',
            'source_table' => 'apps',
            'source_owner' => 'console_id',
            'source_where' => "loading_status = 'Active'",
            'cycle_key' => 'loading_cycle_c',
            'position_key' => 'loading_pos_c',
            'base_key' => 'loading_cycle_base',
            'owners' => 'all_consoles',
        ],
        'task' => [
            'table' => 'daily_tasks',
            'owner_column' => 'console_id',
            'source_table' => 'apps',
            'source_owner' => 'console_id',
            'source_where' => "stage = 'live' AND ready_for_work = 1",
            'cycle_key' => 'task_cycle_c',
            'position_key' => 'task_pos_c',
            'base_key' => 'task_cycle_base',
            'owners' => 'all_consoles',
        ],
    ];

    if (!isset($configs[$kind])) {
        throw new RuntimeException('Unknown rotation.');
    }

    return $configs[$kind];
}

/* How many apps this owner has in the pool. */
function rotation_total(string $kind, int $ownerId): int
{
    $c = rotation_config($kind);
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM {$c['source_table']}
         WHERE {$c['source_owner']} = ? AND {$c['source_where']}"
    );
    $stmt->execute([$ownerId]);

    return (int) $stmt->fetchColumn();
}

/* How many of them a day takes. */
function rotation_quota(string $kind, int $ownerId): int
{
    if ($kind === 'loading') {
        return loading_apps_per_day();
    }

    $total = rotation_total($kind, $ownerId);
    if ($total === 0) {
        return 0;
    }

    return max(1, (int) ceil($total / cycle_days()));
}

function rotation_cycle(string $kind, int $ownerId): int
{
    $c = rotation_config($kind);

    return max(1, (int) workflow_setting($c['cycle_key'] . $ownerId, '1'));
}

function rotation_set_cycle(string $kind, int $ownerId, int $cycle): void
{
    $c = rotation_config($kind);
    set_workflow_setting($c['cycle_key'] . $ownerId, (string) $cycle);
}

function rotation_base(string $kind): int
{
    $c = rotation_config($kind);

    return max(1, (int) workflow_setting($c['base_key'], '1'));
}

/* Apps this owner already showed in the given cycle. Only used as the
   fallback for installs that predate the stored position. */
function rotation_shown_count(string $kind, int $ownerId, int $cycle): int
{
    $c = rotation_config($kind);
    $stmt = db()->prepare(
        "SELECT COUNT(DISTINCT d.app_id) FROM {$c['table']} d
         JOIN {$c['source_table']} s ON s.id = d.app_id
         WHERE d.{$c['owner_column']} = ? AND d.cycle_no = ? AND s.{$c['source_where']}"
    );
    $stmt->execute([$ownerId, $cycle]);

    return (int) $stmt->fetchColumn();
}

/* Where the rotation starts from on the next generated day. */
function rotation_position(string $kind, int $ownerId): int
{
    $c = rotation_config($kind);
    $stored = workflow_setting($c['position_key'] . $ownerId, '');

    if ($stored === '') {
        return rotation_shown_count($kind, $ownerId, rotation_cycle($kind, $ownerId));
    }

    return max(0, (int) $stored);
}

function rotation_set_position(string $kind, int $ownerId, int $position): void
{
    $c = rotation_config($kind);
    set_workflow_setting($c['position_key'] . $ownerId, (string) max(0, $position));
}

/* Start of the window today's rows were taken from. */
function rotation_today_start(string $kind, int $ownerId): int
{
    $c = rotation_config($kind);
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM {$c['table']} WHERE task_date = ? AND {$c['owner_column']} = ?"
    );
    $stmt->execute([date('Y-m-d'), $ownerId]);
    $position = rotation_position($kind, $ownerId);

    if ((int) $stmt->fetchColumn() < 1) {
        return $position;
    }

    return max(0, $position - max(1, rotation_quota($kind, $ownerId)));
}

/* The cycle number to show: how far the rotation has walked, counted in
   day-sized steps, starting over with each new round. */
function rotation_display_cycle(string $kind, int $ownerId): int
{
    $quota = rotation_quota($kind, $ownerId);
    if ($quota < 1) {
        return 1;
    }

    return intdiv(rotation_today_start($kind, $ownerId), $quota) + 1;
}

function rotation_progress(string $kind): array
{
    $owners = (rotation_config($kind)['owners'])();
    $eligible = 0;
    $shown = 0;

    foreach ($owners as $owner) {
        $ownerId = (int) $owner['id'];
        $total = rotation_total($kind, $ownerId);
        if ($total === 0) {
            continue;
        }

        $eligible += $total;
        $shown += min($total, rotation_position($kind, $ownerId));
    }

    return [
        'eligible' => $eligible,
        'shown' => $shown,
        'remaining' => max(0, $eligible - $shown),
    ];
}

/* Today's slice for every owner that has not been generated yet. */
function rotation_generate(string $kind): int
{
    $c = rotation_config($kind);
    $today = date('Y-m-d');
    $inserted = 0;

    $done = db()->prepare(
        "SELECT COUNT(*) FROM {$c['table']} WHERE task_date = ? AND {$c['owner_column']} = ?"
    );
    $insert = db()->prepare(
        "INSERT IGNORE INTO {$c['table']} (task_date, app_id, {$c['owner_column']}, cycle_no)
         VALUES (?, ?, ?, ?)"
    );

    foreach ((rotation_config($kind)['owners'])() as $owner) {
        $ownerId = (int) $owner['id'];

        $done->execute([$today, $ownerId]);
        if ((int) $done->fetchColumn() > 0) {
            continue;
        }

        $total = rotation_total($kind, $ownerId);
        if ($total === 0) {
            continue;
        }

        $quota = max(1, rotation_quota($kind, $ownerId));
        $cycle = rotation_cycle($kind, $ownerId);
        $position = rotation_position($kind, $ownerId);

        /* This owner finished its list, so start it over. */
        if ($position >= $total) {
            $cycle++;
            rotation_set_cycle($kind, $ownerId, $cycle);
            $position = 0;
        }

        $pick = db()->prepare(
            "SELECT id FROM {$c['source_table']}
             WHERE {$c['source_owner']} = ? AND {$c['source_where']}
             ORDER BY created_at ASC, id ASC
             LIMIT " . $quota . " OFFSET " . $position
        );
        $pick->execute([$ownerId]);

        foreach ($pick->fetchAll() as $row) {
            $insert->execute([$today, (int) $row['id'], $ownerId, $cycle]);
            $inserted++;
        }

        rotation_set_position($kind, $ownerId, $position + $quota);
    }

    return $inserted;
}

/* Move one owner's rotation: back to its first app, or one window either way. */
function rotation_shift(string $kind, int $ownerId, string $direction): void
{
    if ($ownerId <= 0) {
        throw new RuntimeException('Console was not found.');
    }

    $c = rotation_config($kind);
    $quota = max(1, rotation_quota($kind, $ownerId));
    $total = rotation_total($kind, $ownerId);
    $cycle = rotation_cycle($kind, $ownerId);
    $start = rotation_today_start($kind, $ownerId);
    $target = $start;

    if ($direction === 'restart') {
        /* A restart puts the console back on its first app and Cycle 1. */
        $cycle = rotation_base($kind);
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
            if ($cycle < rotation_base($kind)) {
                throw new RuntimeException('This console is already on the first apps of Cycle 1.');
            }
            $target = $total > 0 ? intdiv(max(0, $total - 1), $quota) * $quota : 0;
        }
    } else {
        throw new RuntimeException('Invalid cycle action.');
    }

    $stmt = db()->prepare(
        "DELETE FROM {$c['table']} WHERE task_date = ? AND {$c['owner_column']} = ?"
    );
    $stmt->execute([date('Y-m-d'), $ownerId]);

    rotation_set_cycle($kind, $ownerId, $cycle);
    rotation_set_position($kind, $ownerId, $target);
}

/* Every owner starts again from its first app, all on one shared cycle. */
function rotation_restart_all(string $kind): void
{
    $c = rotation_config($kind);

    $stmt = db()->prepare("DELETE FROM {$c['table']} WHERE task_date = ?");
    $stmt->execute([date('Y-m-d')]);

    $owners = ($c['owners'])();

    $next = (int) db()->query("SELECT COALESCE(MAX(cycle_no), 0) FROM {$c['table']}")->fetchColumn();
    foreach ($owners as $owner) {
        $next = max($next, rotation_cycle($kind, (int) $owner['id']));
    }
    $next++;

    foreach ($owners as $owner) {
        rotation_set_cycle($kind, (int) $owner['id'], $next);
        rotation_set_position($kind, (int) $owner['id'], 0);
    }
    set_workflow_setting($c['base_key'], (string) $next);
}

function rotation_toggle_done(string $kind, int $rowId): void
{
    $c = rotation_config($kind);
    $stmt = db()->prepare("UPDATE {$c['table']} SET is_done = 1 - is_done WHERE id = ?");
    $stmt->execute([$rowId]);

    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Task was not found.');
    }
}
