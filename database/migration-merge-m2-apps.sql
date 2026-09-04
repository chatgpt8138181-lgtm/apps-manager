-- M2: one app table.
--
-- `apps` gains the publishing side, and every row of `production_apps`
-- is copied in. The two sets share no names, so nothing is merged away:
-- 90 loading apps + 54 publishing apps = 144 rows.
--
-- Loading apps keep their ids, so loading_daily and the activity log stay
-- valid. Publishing apps get new ids, and `legacy_production_id` records
-- where each came from so the child tables can be re-pointed.

ALTER TABLE apps
    ADD COLUMN stage ENUM('none','prepare','ready','sent','live','rejected','suspended')
        NOT NULL DEFAULT 'none' AFTER ready_loading_status,
    ADD COLUMN package_name VARCHAR(200) NULL AFTER stage,
    ADD COLUMN application_id VARCHAR(200) NULL AFTER package_name,
    ADD COLUMN ready_for_work TINYINT(1) NOT NULL DEFAULT 0 AFTER application_id,
    ADD COLUMN url_checked TINYINT(1) NOT NULL DEFAULT 0 AFTER ready_for_work,
    ADD COLUMN sent_at DATETIME NULL AFTER url_checked,
    ADD COLUMN live_at DATETIME NULL AFTER sent_at,
    ADD COLUMN legacy_production_id INT UNSIGNED NULL AFTER live_at,
    ADD INDEX idx_apps_stage (stage),
    ADD UNIQUE KEY uq_apps_legacy_production (legacy_production_id);

-- Publishing apps arrive as new rows. They are not in the loading rotation,
-- so they start Inactive.
INSERT INTO apps
    (console_id, app_name, loading_status, ready_loading_status, stage,
     package_name, application_id, ready_for_work, url_checked,
     sent_at, live_at, legacy_production_id, created_at)
SELECT p.console_id, p.name, 'Inactive', 'Not Ready', p.status,
       p.package_name, p.application_id, p.ready_for_work, p.url_checked,
       p.sent_at, p.live_at, p.id, p.created_at
FROM production_apps p;

-- The child tables still point at production_apps, so their keys are
-- released before the ids move and re-made against `apps` afterwards.
ALTER TABLE production_checklist DROP FOREIGN KEY fk_checklist_app;
ALTER TABLE daily_tasks DROP FOREIGN KEY fk_daily_tasks_app;

-- Child rows follow their app to its new id.
UPDATE production_checklist pc
JOIN apps a ON a.legacy_production_id = pc.app_id
SET pc.app_id = a.id;

UPDATE daily_tasks dt
JOIN apps a ON a.legacy_production_id = dt.app_id
SET dt.app_id = a.id;

UPDATE activity_log al
JOIN apps a ON a.legacy_production_id = al.entity_id
SET al.entity_id = a.id
WHERE al.entity = 'app';

ALTER TABLE production_checklist
    ADD CONSTRAINT fk_checklist_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE;

ALTER TABLE daily_tasks
    ADD CONSTRAINT fk_daily_tasks_app FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE CASCADE;
