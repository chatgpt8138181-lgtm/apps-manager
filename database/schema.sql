CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS apps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    app_name VARCHAR(200) NOT NULL,
    loading_status ENUM('Active', 'Inactive') NOT NULL DEFAULT 'Active',
    icon_path VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_apps_category (category_id),
    INDEX idx_apps_loading (loading_status),
    CONSTRAINT fk_apps_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consoles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    privacy_policy_url VARCHAR(255) NULL,
    app_domain_url VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_apps (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    package_name VARCHAR(200) NULL,
    application_id VARCHAR(200) NULL,
    privacy_policy_url VARCHAR(255) NULL,
    app_domain_url VARCHAR(255) NULL,
    status ENUM('prepare', 'ready', 'sent', 'live', 'rejected', 'suspended') NOT NULL DEFAULT 'prepare',
    url_checked TINYINT(1) NOT NULL DEFAULT 0,
    console_id INT UNSIGNED NULL,
    sent_at DATETIME NULL,
    live_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_production_apps_status (status),
    INDEX idx_production_apps_console (console_id),
    CONSTRAINT fk_production_apps_console
        FOREIGN KEY (console_id)
        REFERENCES consoles(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_checklist (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    app_id INT UNSIGNED NOT NULL,
    item_key VARCHAR(50) NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    done_at DATETIME NULL,
    UNIQUE KEY uq_checklist_app_item (app_id, item_key),
    CONSTRAINT fk_checklist_app
        FOREIGN KEY (app_id)
        REFERENCES production_apps(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS activity_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NULL,
    admin_name VARCHAR(100) NULL,
    entity VARCHAR(30) NOT NULL,
    entity_id INT UNSIGNED NULL,
    entity_name VARCHAR(200) NULL,
    action VARCHAR(50) NOT NULL,
    detail VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_activity_entity (entity, entity_id),
    INDEX idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS workflow_settings (
    setting_key VARCHAR(50) NOT NULL PRIMARY KEY,
    setting_value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS loading_daily (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_date DATE NOT NULL,
    app_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    is_done TINYINT(1) NOT NULL DEFAULT 0,
    cycle_no INT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_loading_daily (task_date, app_id, cycle_no),
    INDEX idx_loading_daily_date (task_date),
    INDEX idx_loading_daily_cycle (cycle_no),
    CONSTRAINT fk_loading_daily_app
        FOREIGN KEY (app_id)
        REFERENCES apps(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_loading_daily_category
        FOREIGN KEY (category_id)
        REFERENCES categories(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workflow_settings (setting_key, setting_value) VALUES
('loading_apps_per_day', '2')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

INSERT INTO admins (username, password_hash)
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi')
ON DUPLICATE KEY UPDATE username = username;

INSERT INTO categories (name) VALUES
('Quantum Appx'),
('Kreation Apps'),
('Millionaire Apps'),
('Genius Appx'),
('Royal Tech'),
('Mega Apps Solution'),
('Innovative Appx'),
('LogicByte'),
('Star Technologies'),
('Fairy Studio')
ON DUPLICATE KEY UPDATE name = VALUES(name);
