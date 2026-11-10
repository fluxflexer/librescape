-- Dates can be known to the season: "Summer 1937", "Winter 1942/43".
-- Stored as the first day of the (meteorological, northern) season: 1 Mar, 1 Jun, 1 Sep, 1 Dec.
ALTER TABLE pit MODIFY date_precision ENUM('day','month','year','decade','circa','season') NOT NULL DEFAULT 'day';
ALTER TABLE publication MODIFY release_precision ENUM('day','month','year','decade','circa','season') NOT NULL DEFAULT 'day';
ALTER TABLE source MODIFY published_precision ENUM('day','month','year','decade','circa','season') NOT NULL DEFAULT 'day';
ALTER TABLE person
    MODIFY birth_precision ENUM('day','month','year','decade','circa','season') NOT NULL DEFAULT 'day',
    MODIFY death_precision ENUM('day','month','year','decade','circa','season') NOT NULL DEFAULT 'day';
