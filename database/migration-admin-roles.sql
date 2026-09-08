-- Not everyone who signs in needs the same reach. A role says what a person
-- may do; everyone who already had an account keeps full access.
-- Safe to run twice.

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        "ALTER TABLE admins
            ADD COLUMN role ENUM('admin','manager','operator','viewer') NOT NULL DEFAULT 'operator' AFTER username,
            ADD COLUMN last_login_at DATETIME NULL AFTER password_hash",
        'SELECT "admins.role already exists" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'admins'
      AND COLUMN_NAME = 'role'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The accounts that existed before roles did keep everything they had.
UPDATE admins SET role = 'admin' WHERE role = 'operator' AND created_at < NOW();
