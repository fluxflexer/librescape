-- Publications: the books, collections, anthologies and journal issues that works appeared in.
-- Naming: the older table `publication` holds the WORKS (The Hobbit, On Fairy-Stories ...); the admin app calls them
-- "Works". This new table is called `edition` internally and "Publication" in the admin app and the viewer.
-- A work can appear in any number of publications (first printing, reprints, collections), so they are linked n:m.

CREATE TABLE edition (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind               ENUM('book','collection','anthology','journal','magazine','other') NOT NULL DEFAULT 'book',
    title              VARCHAR(300) NOT NULL,
    publisher          VARCHAR(200) NULL,
    place              VARCHAR(100) NULL,           -- place of publication
    release_date       DATE NULL,
    release_precision  ENUM('day','month','year','decade','circa','season') NOT NULL DEFAULT 'day',
    isbn               VARCHAR(17)  NULL,
    description        TEXT NULL,
    row_version        INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at         DATETIME NULL,
    updated_by         INT UNSIGNED NULL,
    CONSTRAINT fk_edition_upd FOREIGN KEY (updated_by) REFERENCES app_user(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Editors of a publication (people from the person table), in the order they are printed.
CREATE TABLE edition_editor (
    edition_id  INT UNSIGNED NOT NULL,
    person_id   INT UNSIGNED NOT NULL,
    sort_order  INT NOT NULL DEFAULT 10,
    PRIMARY KEY (edition_id, person_id),
    CONSTRAINT fk_ed_edition FOREIGN KEY (edition_id) REFERENCES edition(id) ON DELETE CASCADE,
    CONSTRAINT fk_ed_person  FOREIGN KEY (person_id)  REFERENCES person(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contents: work X appears in publication Y (optionally at pages ...), and whether this was its first printing.
CREATE TABLE edition_work (
    edition_id         INT UNSIGNED NOT NULL,
    work_id            INT UNSIGNED NOT NULL,       -- publication.id (a work)
    locator            VARCHAR(100) NULL,           -- e.g. "pp. 38–89", "ch. 3"
    first_publication  TINYINT(1) NOT NULL DEFAULT 0,
    sort_order         INT NOT NULL DEFAULT 10,
    PRIMARY KEY (edition_id, work_id),
    KEY ix_edition_work_work (work_id),
    CONSTRAINT fk_ew_edition FOREIGN KEY (edition_id) REFERENCES edition(id)     ON DELETE CASCADE,
    CONSTRAINT fk_ew_work    FOREIGN KEY (work_id)    REFERENCES publication(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE edition_keyword (
    edition_id  INT UNSIGNED NOT NULL,
    keyword_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (edition_id, keyword_id),
    CONSTRAINT fk_edk_edition FOREIGN KEY (edition_id) REFERENCES edition(id) ON DELETE CASCADE,
    CONSTRAINT fk_edk_kw      FOREIGN KEY (keyword_id) REFERENCES keyword(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Publications get links, citations and pictures like every other entry.
ALTER TABLE weblink    MODIFY entity_type ENUM('person','event','period','publication','edition') NOT NULL;
ALTER TABLE source_ref MODIFY entity_type ENUM('person','event','period','publication','edition') NOT NULL;
ALTER TABLE image      MODIFY entity_type ENUM('person','event','period','publication','edition') NOT NULL;
