-- Provider, country and city are picked from a list rather than typed each
-- time, and the lists are kept here. Safe to run twice.

CREATE TABLE IF NOT EXISTS ip_options (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind ENUM('provider', 'country', 'city') NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip_options (kind, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE rotation_ips ADD COLUMN city VARCHAR(100) NULL AFTER country',
        'SELECT "rotation_ips.city already exists" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rotation_ips'
      AND COLUMN_NAME = 'city'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Anything already typed in becomes a list entry, so nothing is lost.
INSERT IGNORE INTO ip_options (kind, name)
SELECT 'provider', provider FROM rotation_ips WHERE provider IS NOT NULL AND provider <> '';

INSERT IGNORE INTO ip_options (kind, name)
SELECT 'country', country FROM rotation_ips WHERE country IS NOT NULL AND country <> '';
