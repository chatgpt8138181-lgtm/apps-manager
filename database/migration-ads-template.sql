-- A console can keep its own default ads.json, used by every app under it
-- that has not been given one of its own.
-- Safe to run twice: the ALTER is skipped when the column already exists.

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE consoles ADD COLUMN ads_template LONGTEXT NULL AFTER app_domain_url',
        'SELECT "consoles.ads_template already exists" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'consoles'
      AND COLUMN_NAME = 'ads_template'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
