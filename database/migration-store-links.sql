-- What the Play Store listing says about an app.
ALTER TABLE apps
    ADD COLUMN store_url VARCHAR(255) NULL AFTER application_id,
    ADD COLUMN store_icon_url VARCHAR(500) NULL AFTER store_url,
    ADD COLUMN store_title VARCHAR(255) NULL AFTER store_icon_url,
    ADD COLUMN store_checked_at DATETIME NULL AFTER store_title;
