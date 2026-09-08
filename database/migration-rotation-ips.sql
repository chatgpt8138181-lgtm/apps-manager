-- The IPs used while loading, kept by the day they were used on.
-- A month's record stands on its own: nothing is deleted when the month
-- turns, the page simply opens on the new one.
-- Safe to run twice.

CREATE TABLE IF NOT EXISTS rotation_ips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    used_on DATE NOT NULL,
    console_id INT UNSIGNED NULL,
    app_id INT UNSIGNED NULL,
    ip VARCHAR(45) NOT NULL,
    provider VARCHAR(100) NULL,
    country VARCHAR(60) NULL,
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rotation_ips_date (used_on),
    INDEX idx_rotation_ips_console (console_id, used_on),
    INDEX idx_rotation_ips_ip (ip, used_on),
    CONSTRAINT fk_rotation_ips_app
        FOREIGN KEY (app_id) REFERENCES apps(id) ON DELETE SET NULL,
    CONSTRAINT fk_rotation_ips_console
        FOREIGN KEY (console_id) REFERENCES consoles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
