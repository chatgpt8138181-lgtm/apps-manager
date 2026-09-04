-- The app's own domain URL: a stored value, like the console's, not
-- something derived from the app's name.
ALTER TABLE apps
    ADD COLUMN domain_url VARCHAR(255) NULL AFTER application_id;
