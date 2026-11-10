-- Birth and death dates are often known only to the month, year or roughly ("c. 1857"),
-- like every other date in Librescape.
ALTER TABLE person
    ADD COLUMN birth_precision ENUM('day','month','year','decade','circa') NOT NULL DEFAULT 'day' AFTER date_of_birth,
    ADD COLUMN death_precision ENUM('day','month','year','decade','circa') NOT NULL DEFAULT 'day' AFTER date_of_death;
