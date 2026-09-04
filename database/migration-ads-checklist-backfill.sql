-- The checklist gained an "Ads Config Created" item. Apps that already left
-- Prepare were approved under the old list, so they should not be held back
-- by an item that did not exist when they were checked.
-- Safe to run twice: rows that already exist are left alone.

INSERT INTO production_checklist (app_id, item_key, is_done, done_at)
SELECT a.id, 'ads_json', 1, NOW()
FROM apps a
WHERE a.stage IN ('ready', 'sent', 'live', 'rejected', 'suspended')
ON DUPLICATE KEY UPDATE is_done = is_done;
