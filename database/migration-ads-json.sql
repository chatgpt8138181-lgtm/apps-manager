-- Each app serves its own ads.json from its domain folder.
-- Safe to run twice: the ALTER is skipped when the column already exists.

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE apps
            ADD COLUMN ads_json LONGTEXT NULL AFTER domain_url,
            ADD COLUMN ads_updated_at DATETIME NULL AFTER ads_json',
        'SELECT "apps.ads_json already exists" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'apps'
      AND COLUMN_NAME = 'ads_json'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
