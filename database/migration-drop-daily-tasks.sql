-- The daily task rotation is gone: an app is either in the loading rotation
-- or it is not, and there is no second list of live apps to work through.
-- This removes the table it kept, the flag that fed it, and its settings.
-- Safe to run twice.

DROP TABLE IF EXISTS daily_tasks;

SET @sql = (
    SELECT IF(
        COUNT(*) = 1,
        'ALTER TABLE apps DROP COLUMN ready_for_work',
        'SELECT "apps.ready_for_work is already gone" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'apps'
      AND COLUMN_NAME = 'ready_for_work'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

DELETE FROM workflow_settings
WHERE setting_key = 'cycle_days'
   OR setting_key = 'task_cycle_base'
   OR setting_key LIKE 'task_cycle_c%'
   OR setting_key LIKE 'task_pos_c%';
