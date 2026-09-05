-- The loading side no longer has a "Ready / Not Ready" flag: an app is
-- either Active in the rotation or it is not. The column goes with it.
-- Safe to run twice: the ALTER is skipped when the column is already gone.

SET @sql = (
    SELECT IF(
        COUNT(*) = 1,
        'ALTER TABLE apps DROP COLUMN ready_loading_status',
        'SELECT "apps.ready_loading_status is already gone" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'apps'
      AND COLUMN_NAME = 'ready_loading_status'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
