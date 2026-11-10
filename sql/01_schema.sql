-- Librescape schema
-- Based on data_representation/*.md, extended with track (lanes) and keywords.
-- Re-runnable: drops and recreates all tables.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS weblink;
DROP TABLE IF EXISTS source_ref;
DROP TABLE IF EXISTS source;
DROP TABLE IF EXISTS image;
DROP TABLE IF EXISTS publication_keyword;
DROP TABLE IF EXISTS period_keyword;
DROP TABLE IF EXISTS event_keyword;
DROP TABLE IF EXISTS keyword;
DROP TABLE IF EXISTS publication;
DROP TABLE IF EXISTS pub_category;
DROP TABLE IF EXISTS period;
DROP TABLE IF EXISTS event;
DROP TABLE IF EXISTS pit;
DROP TABLE IF EXISTS track;
DROP TABLE IF EXISTS relationship;
DROP TABLE IF EXISTS relation_type;
DROP TABLE IF EXISTS person;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- persons

CREATE TABLE person (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name      VARCHAR(100) NOT NULL,
    surname         VARCHAR(100) NOT NULL,
    date_of_birth   DATE NULL,
    place_of_birth  VARCHAR(150) NULL,
    date_of_death   DATE NULL,
    shortcode       VARCHAR(32)  NOT NULL,
    UNIQUE KEY uq_person_shortcode (shortcode),
    KEY ix_person_name (surname, first_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE relation_type (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    short_name   VARCHAR(50)  NOT NULL,
    description  TEXT NULL,
    UNIQUE KEY uq_relation_type_short (short_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE relationship (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person1_id        INT UNSIGNED NOT NULL,
    person2_id        INT UNSIGNED NOT NULL,
    relation_type_id  INT UNSIGNED NOT NULL,
    relation_status   VARCHAR(50) NULL,
    CONSTRAINT fk_rel_p1   FOREIGN KEY (person1_id)       REFERENCES person(id)        ON DELETE CASCADE,
    CONSTRAINT fk_rel_p2   FOREIGN KEY (person2_id)       REFERENCES person(id)        ON DELETE CASCADE,
    CONSTRAINT fk_rel_type FOREIGN KEY (relation_type_id) REFERENCES relation_type(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- lanes (extension)

CREATE TABLE track (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name             VARCHAR(100) NOT NULL,
    short_name       VARCHAR(32)  NOT NULL,
    sort_order       INT NOT NULL DEFAULT 0,
    css_class        VARCHAR(50)  NULL,
    parent_track_id  INT UNSIGNED NULL,
    UNIQUE KEY uq_track_short (short_name),
    CONSTRAINT fk_track_parent FOREIGN KEY (parent_track_id) REFERENCES track(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- time

-- PiT = point in time. `date_precision` tells how exact `ts` is
-- ('precision' is a reserved word in MariaDB, hence the prefix).
CREATE TABLE pit (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(200) NULL,
    description     TEXT NULL,
    date_precision  ENUM('day','month','year','decade','circa') NOT NULL DEFAULT 'day',
    ts              DATETIME NOT NULL,
    KEY ix_pit_ts (ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name         VARCHAR(200) NOT NULL,
    short_name   VARCHAR(80)  NULL,
    description  TEXT NULL,
    pit_id       INT UNSIGNED NOT NULL,
    person1_id   INT UNSIGNED NULL,
    person2_id   INT UNSIGNED NULL,
    track_id     INT UNSIGNED NULL,
    -- Events that only mark the start/end of a period are not drawn on their own.
    is_marker    TINYINT(1) NOT NULL DEFAULT 0,
    CONSTRAINT fk_event_pit   FOREIGN KEY (pit_id)     REFERENCES pit(id),
    CONSTRAINT fk_event_p1    FOREIGN KEY (person1_id) REFERENCES person(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_p2    FOREIGN KEY (person2_id) REFERENCES person(id) ON DELETE SET NULL,
    CONSTRAINT fk_event_track FOREIGN KEY (track_id)   REFERENCES track(id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE period (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    start_event_id  INT UNSIGNED NOT NULL,
    end_event_id    INT UNSIGNED NOT NULL,
    name            VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    track_id        INT UNSIGNED NULL,
    -- Nesting: e.g. "Battle of the Somme" is part of "First World War".
    parent_period_id  INT UNSIGNED NULL,
    CONSTRAINT fk_period_start  FOREIGN KEY (start_event_id)   REFERENCES event(id),
    CONSTRAINT fk_period_end    FOREIGN KEY (end_event_id)     REFERENCES event(id),
    CONSTRAINT fk_period_track  FOREIGN KEY (track_id)         REFERENCES track(id)  ON DELETE SET NULL,
    CONSTRAINT fk_period_parent FOREIGN KEY (parent_period_id) REFERENCES period(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An event can be part of a period (e.g. "D-Day" is part of "Second World War").
-- Added here because period is created after event.
ALTER TABLE event
    ADD COLUMN parent_period_id INT UNSIGNED NULL AFTER track_id,
    ADD CONSTRAINT fk_event_parent FOREIGN KEY (parent_period_id) REFERENCES period(id) ON DELETE SET NULL;

-- ---------------------------------------------------------------- publications

CREATE TABLE pub_category (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category     VARCHAR(100) NOT NULL,
    short_title  VARCHAR(50)  NULL,
    description  TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publication (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_id            INT UNSIGNED NULL,
    author2_id           INT UNSIGNED NULL,
    author3_id           INT UNSIGNED NULL,
    title                VARCHAR(300) NOT NULL,
    category_id          INT UNSIGNED NULL,
    short_title          VARCHAR(100) NULL,
    parent_work_id       INT UNSIGNED NULL,
    description          TEXT NULL,
    release_date         DATE NULL,
    release_precision    ENUM('day','month','year','decade','circa') NOT NULL DEFAULT 'day',
    shortcode           VARCHAR(32) NULL,
    gestation_period_id  INT UNSIGNED NULL,
    UNIQUE KEY uq_pub_shortcode (shortcode),
    CONSTRAINT fk_pub_a1     FOREIGN KEY (author_id)           REFERENCES person(id)       ON DELETE SET NULL,
    CONSTRAINT fk_pub_a2     FOREIGN KEY (author2_id)          REFERENCES person(id)       ON DELETE SET NULL,
    CONSTRAINT fk_pub_a3     FOREIGN KEY (author3_id)          REFERENCES person(id)       ON DELETE SET NULL,
    CONSTRAINT fk_pub_cat    FOREIGN KEY (category_id)         REFERENCES pub_category(id) ON DELETE SET NULL,
    CONSTRAINT fk_pub_parent FOREIGN KEY (parent_work_id)      REFERENCES publication(id)  ON DELETE SET NULL,
    CONSTRAINT fk_pub_gest   FOREIGN KEY (gestation_period_id) REFERENCES period(id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- keywords (extension)

CREATE TABLE keyword (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name  VARCHAR(100) NOT NULL,
    UNIQUE KEY uq_keyword_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_keyword (
    event_id    INT UNSIGNED NOT NULL,
    keyword_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, keyword_id),
    CONSTRAINT fk_ek_event FOREIGN KEY (event_id)   REFERENCES event(id)   ON DELETE CASCADE,
    CONSTRAINT fk_ek_kw    FOREIGN KEY (keyword_id) REFERENCES keyword(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE period_keyword (
    period_id   INT UNSIGNED NOT NULL,
    keyword_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (period_id, keyword_id),
    CONSTRAINT fk_pk_period FOREIGN KEY (period_id)  REFERENCES period(id)  ON DELETE CASCADE,
    CONSTRAINT fk_pk_kw     FOREIGN KEY (keyword_id) REFERENCES keyword(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE publication_keyword (
    publication_id  INT UNSIGNED NOT NULL,
    keyword_id      INT UNSIGNED NOT NULL,
    PRIMARY KEY (publication_id, keyword_id),
    CONSTRAINT fk_pubk_pub FOREIGN KEY (publication_id) REFERENCES publication(id) ON DELETE CASCADE,
    CONSTRAINT fk_pubk_kw  FOREIGN KEY (keyword_id)     REFERENCES keyword(id)     ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- weblinks (extension)
-- Any number of web links per entry: Wikipedia articles (any language), websites, a CV, archives ...
-- Links are "further reading"; what backs an entry's facts is recorded in source/source_ref.
-- The first Wikipedia link (lowest sort_order) is the article tools/fetch_images.php takes the picture from.

CREATE TABLE weblink (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type  ENUM('person','event','period','publication') NOT NULL,
    entity_id    INT UNSIGNED NOT NULL,
    link_type    ENUM('wikipedia','website','cv','archive','other') NOT NULL DEFAULT 'website',
    label        VARCHAR(150) NULL,         -- shown text; NULL = derived from type/URL ("Wikipedia", "Wikipedia (de)", host name)
    url          VARCHAR(500) NOT NULL,
    sort_order   INT NOT NULL DEFAULT 10,
    KEY ix_weblink_entity (entity_type, entity_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- images (extension)
-- Freely licensed pictures taken from Wikipedia/Wikimedia Commons by tools/fetch_images.php.
-- One image per entity; the file itself is stored locally under media/wiki/.

CREATE TABLE image (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type           ENUM('person','event','period','publication') NOT NULL,
    entity_id             INT UNSIGNED NOT NULL,
    file_title            VARCHAR(300) NOT NULL,   -- e.g. "File:J. R. R. Tolkien, ca. 1925.jpg"
    local_path            VARCHAR(300) NOT NULL,   -- relative to the web root, e.g. media/wiki/person-1.jpg
    width                 SMALLINT UNSIGNED NULL,
    height                SMALLINT UNSIGNED NULL,
    artist                VARCHAR(500) NULL,       -- plain text
    license               VARCHAR(100) NOT NULL,   -- e.g. "CC BY-SA 4.0", "Public domain"
    license_url           VARCHAR(500) NULL,
    attribution_required  TINYINT(1) NOT NULL DEFAULT 1,
    description_url       VARCHAR(500) NOT NULL,   -- the file's page on Commons
    fetched_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_image_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- sources (extension)
-- Every entry (person, event, period, publication) must cite at least one source.
-- Period start/end markers are exempt: they are covered by their period.
-- tools/check_sources.php lists entries that still lack a source.

CREATE TABLE source (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code                VARCHAR(32)  NOT NULL,      -- e.g. ROTK, CARP, NYT-1973-09-03, W-WWI
    source_type         ENUM('book','newspaper','wikipedia') NOT NULL,
    title               VARCHAR(500) NOT NULL,      -- book: full title; newspaper: headline; wikipedia: article title
    author              VARCHAR(300) NULL,          -- as printed, e.g. "Humphrey Carpenter"; NULL for unsigned articles
    author_person_id    INT UNSIGNED NULL,          -- optional link when the author is a person in this database
    editor              VARCHAR(300) NULL,
    published_date      DATE NULL,
    published_precision ENUM('day','month','year','decade','circa') NOT NULL DEFAULT 'day',
    -- book
    publisher           VARCHAR(200) NULL,
    place               VARCHAR(100) NULL,          -- place of publication
    isbn                VARCHAR(17)  NULL,          -- ISBN-10 or ISBN-13, hyphens allowed
    -- newspaper
    newspaper           VARCHAR(200) NULL,          -- name of the paper, e.g. "The New York Times"
    page                VARCHAR(50)  NULL,          -- page/section of the article in the paper
    -- wikipedia
    language            CHAR(2)      NULL,          -- Wikipedia language edition, e.g. "en"
    accessed_date       DATE NULL,                  -- when the online version was consulted
    -- all types
    url                 VARCHAR(500) NULL,          -- online copy: the article itself, or a digitised edition
    wikipedia_url       VARCHAR(500) NULL,          -- Wikipedia article ABOUT the book / the newspaper
    publication_id      INT UNSIGNED NULL,          -- set when the source is a work shown in this timeline (e.g. ROTK)
    notes               TEXT NULL,
    UNIQUE KEY uq_source_code (code),
    CONSTRAINT fk_source_author FOREIGN KEY (author_person_id) REFERENCES person(id)      ON DELETE SET NULL,
    CONSTRAINT fk_source_pub    FOREIGN KEY (publication_id)   REFERENCES publication(id) ON DELETE SET NULL,
    -- Required fields per type. Books printed before 1970 have no ISBN, so it is only required after that.
    CONSTRAINT ck_source_book CHECK (source_type <> 'book' OR (
        author IS NOT NULL AND published_date IS NOT NULL AND publisher IS NOT NULL
        AND (isbn IS NOT NULL OR published_date < '1970-01-01'))),
    CONSTRAINT ck_source_newspaper CHECK (source_type <> 'newspaper' OR (
        newspaper IS NOT NULL AND published_date IS NOT NULL)),
    CONSTRAINT ck_source_wikipedia CHECK (source_type <> 'wikipedia' OR (
        url IS NOT NULL AND language IS NOT NULL AND accessed_date IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Citation: entry X is backed by source Y (optionally at a page/chapter).
CREATE TABLE source_ref (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id    INT UNSIGNED NOT NULL,
    entity_type  ENUM('person','event','period','publication') NOT NULL,
    entity_id    INT UNSIGNED NOT NULL,
    locator      VARCHAR(100) NULL,                 -- e.g. "p. 81", "ch. 4", "Appendix A"
    note         VARCHAR(500) NULL,
    KEY ix_source_ref_entity (entity_type, entity_id),
    CONSTRAINT fk_source_ref_source FOREIGN KEY (source_id) REFERENCES source(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
