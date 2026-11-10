-- Admin application: users, change log, settings, and "last changed" columns on entries.

CREATE TABLE app_user (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username       VARCHAR(50)  NOT NULL,
    display_name   VARCHAR(100) NOT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    role           ENUM('editor','admin') NOT NULL DEFAULT 'editor',
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    failed_logins  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until   DATETIME NULL,
    last_login_at  DATETIME NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_user_name (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who changed what and when. `changes` is JSON: {"field": [old, new], ...};
-- for deletes it holds the full old record.
CREATE TABLE change_log (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    user_name    VARCHAR(100) NOT NULL,             -- snapshot, survives user changes
    changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    entity_type  VARCHAR(30)  NOT NULL,             -- person, event, period, publication, source, keyword, user, setting ...
    entity_id    INT UNSIGNED NULL,
    action       ENUM('create','update','delete') NOT NULL,
    label        VARCHAR(300) NULL,                 -- entry name at the time of the change
    changes      LONGTEXT NULL,
    KEY ix_change_entity (entity_type, entity_id, changed_at),
    KEY ix_change_time (changed_at),
    CONSTRAINT fk_change_user FOREIGN KEY (user_id) REFERENCES app_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_setting (
    name   VARCHAR(50) PRIMARY KEY,
    value  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_setting (name, value) VALUES
    ('maintenance_mode', '0'),
    ('maintenance_message', 'Librescape is being updated and will be back shortly.');

-- Last change per entry; row_version is the optimistic lock for concurrent editors.
ALTER TABLE person
    ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN updated_at DATETIME NULL,
    ADD COLUMN updated_by INT UNSIGNED NULL,
    ADD CONSTRAINT fk_person_upd FOREIGN KEY (updated_by) REFERENCES app_user(id) ON DELETE SET NULL;
ALTER TABLE event
    ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN updated_at DATETIME NULL,
    ADD COLUMN updated_by INT UNSIGNED NULL,
    ADD CONSTRAINT fk_event_upd FOREIGN KEY (updated_by) REFERENCES app_user(id) ON DELETE SET NULL;
ALTER TABLE period
    ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN updated_at DATETIME NULL,
    ADD COLUMN updated_by INT UNSIGNED NULL,
    ADD CONSTRAINT fk_period_upd FOREIGN KEY (updated_by) REFERENCES app_user(id) ON DELETE SET NULL;
ALTER TABLE publication
    ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN updated_at DATETIME NULL,
    ADD COLUMN updated_by INT UNSIGNED NULL,
    ADD CONSTRAINT fk_pub_upd FOREIGN KEY (updated_by) REFERENCES app_user(id) ON DELETE SET NULL;
