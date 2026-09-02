<?php
declare(strict_types=1);

/*
 * A plain record of who changed what, so a surprise can be traced back.
 * Logging never breaks the action it describes: if the table is missing
 * or the insert fails, the write is skipped silently.
 */

function log_activity(
    string $entity,
    ?int $entityId,
    string $action,
    ?string $entityName = null,
    ?string $detail = null
): void {
    try {
        $stmt = db()->prepare(
            'INSERT INTO activity_log
                (admin_id, admin_name, entity, entity_id, entity_name, action, detail)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null,
            $_SESSION['admin_username'] ?? null,
            $entity,
            $entityId,
            $entityName !== null ? mb_substr($entityName, 0, 200) : null,
            $action,
            $detail !== null ? mb_substr($detail, 0, 255) : null,
        ]);
    } catch (Throwable $e) {
        /* An audit line is never worth failing the action for. */
    }
}

function activity_for(string $entity, int $entityId, int $limit = 20): array
{
    try {
        $limit = max(1, min(100, $limit));
        $stmt = db()->prepare(
            'SELECT * FROM activity_log
             WHERE entity = ? AND entity_id = ?
             ORDER BY id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([$entity, $entityId]);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function recent_activity(int $limit = 100, string $entity = '', string $search = ''): array
{
    try {
        $limit = max(1, min(500, $limit));
        $where = [];
        $params = [];

        if ($entity !== '') {
            $where[] = 'entity = ?';
            $params[] = $entity;
        }
        if (trim($search) !== '') {
            $where[] = '(entity_name LIKE ? OR action LIKE ? OR admin_name LIKE ?)';
            $like = '%' . trim($search) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $sql = 'SELECT * FROM activity_log';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

/* Turn a stored action key into something a person reads. */
function activity_label(string $action): string
{
    $labels = [
        'created' => 'Created',
        'updated' => 'Details updated',
        'deleted' => 'Deleted',
        'checklist' => 'Checklist saved',
        'stage_ready' => 'Moved to Ready',
        'stage_sent' => 'Sent for production',
        'stage_live' => 'Marked Live',
        'stage_rejected' => 'Marked Rejected',
        'stage_suspended' => 'Marked Suspended',
        'stage_prepare' => 'Moved back to Prepare',
        'stage_none' => 'Removed from publishing',
        'stage_back_ready' => 'Moved back to Ready',
        'stage_back_sent' => 'Moved back to Sent',
        'tagged_ready' => 'Tagged Ready for Work',
        'untagged_ready' => 'Ready for Work tag removed',
        'url_checked' => 'URL marked checked',
        'url_pending' => 'URL moved to pending',
        'console_assigned' => 'Console assigned',
        'store_synced' => 'Updated from Play Store',
    ];

    return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
}

function activity_entity_label(string $entity): string
{
    $labels = [
        'app' => 'App',
        'console' => 'Console',
    ];

    return $labels[$entity] ?? ucfirst($entity);
}

/* Where this record can be opened, when that makes sense. */
function activity_link(array $row): ?string
{
    if ($row['entity'] === 'app' && !empty($row['entity_id']) && $row['action'] !== 'deleted') {
        return 'app.php?id=' . (int) $row['entity_id'];
    }
    if ($row['entity'] === 'console' && $row['action'] !== 'deleted') {
        return 'consoles.php';
    }

    return null;
}
