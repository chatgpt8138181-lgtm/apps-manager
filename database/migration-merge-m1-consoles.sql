-- M1: the two console lists become one.
--
-- Loading's `categories` are folded into `consoles`. Four pairs were the
-- same console under slightly different names, three existed only on the
-- loading side and are created here. Nothing is deleted: `categories`
-- stays in place until the merge is signed off.

-- 1. Consoles that only existed on the loading side.
INSERT INTO consoles (name)
SELECT c.name FROM categories c
WHERE NOT EXISTS (SELECT 1 FROM consoles s WHERE s.name = c.name)
  AND c.name IN ('Quantum Appx', 'Kreation Apps', 'Millionaire Apps');

-- 2. The mapping this migration works from.
CREATE TABLE IF NOT EXISTS console_map (
    category_id INT UNSIGNED NOT NULL PRIMARY KEY,
    console_id INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Same console, different spelling.
INSERT INTO console_map (category_id, console_id)
SELECT c.id, s.id FROM categories c JOIN consoles s
    ON s.name = CASE c.name
        WHEN 'Royal Tech' THEN 'Royals Tech'
        WHEN 'Fairy Studio' THEN 'Fairy Studio Inc'
        WHEN 'Star Technologies' THEN 'Stars Technologies'
        WHEN 'Mega Apps Solution' THEN 'Mega Apps Solutions'
        ELSE c.name
    END
ON DUPLICATE KEY UPDATE console_id = VALUES(console_id);

-- 3. Loading apps point at the console.
ALTER TABLE apps ADD COLUMN console_id INT UNSIGNED NULL AFTER category_id;
UPDATE apps a JOIN console_map m ON m.category_id = a.category_id
    SET a.console_id = m.console_id;
ALTER TABLE apps
    ADD INDEX idx_apps_console (console_id),
    ADD CONSTRAINT fk_apps_console FOREIGN KEY (console_id) REFERENCES consoles(id) ON DELETE SET NULL;

-- 4. So does the loading rotation.
ALTER TABLE loading_daily ADD COLUMN console_id INT UNSIGNED NULL AFTER category_id;
UPDATE loading_daily d JOIN console_map m ON m.category_id = d.category_id
    SET d.console_id = m.console_id;
ALTER TABLE loading_daily
    ADD INDEX idx_loading_daily_console (console_id);

-- 5. Rotation settings are keyed by owner id, and category ids overlap with
--    console ids, so the new keys are built aside and swapped in one go.
CREATE TABLE ws_tmp AS
SELECT CONCAT('loading_cycle_c', m.console_id) AS setting_key, w.setting_value
FROM workflow_settings w JOIN console_map m ON w.setting_key = CONCAT('loading_cycle_c', m.category_id)
UNION ALL
SELECT CONCAT('loading_pos_c', m.console_id), w.setting_value
FROM workflow_settings w JOIN console_map m ON w.setting_key = CONCAT('loading_pos_c', m.category_id);

DELETE FROM workflow_settings
WHERE setting_key LIKE 'loading_cycle_c%' OR setting_key LIKE 'loading_pos_c%';

INSERT INTO workflow_settings (setting_key, setting_value)
SELECT setting_key, setting_value FROM ws_tmp;

DROP TABLE ws_tmp;

-- 6. The old category columns stay for now as a fallback, but new rows are
--    written with console_id only, so they must accept NULL.
ALTER TABLE apps MODIFY COLUMN category_id INT UNSIGNED NULL;
ALTER TABLE loading_daily MODIFY COLUMN category_id INT UNSIGNED NULL;
