-- Whether an app's ads.json folder has been put on the server. Set by hand
-- on the Ads Config page, so that page can show what is still to be done.
-- Safe to run twice: the ALTER is skipped when the column already exists.

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE apps
            ADD COLUMN ads_created TINYINT(1) NOT NULL DEFAULT 0 AFTER ads_updated_at,
            ADD COLUMN ads_created_at DATETIME NULL AFTER ads_created',
        'SELECT "apps.ads_created already exists" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'apps'
      AND COLUMN_NAME = 'ads_created'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
