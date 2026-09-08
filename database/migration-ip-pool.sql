-- An IP is a thing with a name. The name is what people read; the address
-- and where it comes from belong to the IP itself, not to each use of it.
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS ip_pool (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    provider VARCHAR(100) NULL,
    country VARCHAR(60) NULL,
    city VARCHAR(100) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ip_pool_name (name),
    UNIQUE KEY uq_ip_pool_ip (ip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @sql = (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE rotation_ips
            ADD COLUMN ip_id INT UNSIGNED NULL AFTER app_id,
            ADD KEY idx_rotation_ips_pool (ip_id)',
        'SELECT "rotation_ips.ip_id already exists" AS note'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'rotation_ips'
      AND COLUMN_NAME = 'ip_id'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Every address already recorded becomes an entry in the list, named after
-- itself until someone gives it a better name.
INSERT IGNORE INTO ip_pool (name, ip, provider, country, city)
SELECT ip, ip, MAX(provider), MAX(country), MAX(city)
FROM rotation_ips
WHERE ip IS NOT NULL AND ip <> ''
GROUP BY ip;

UPDATE rotation_ips r JOIN ip_pool p ON p.ip = r.ip
SET r.ip_id = p.id
WHERE r.ip_id IS NULL;
