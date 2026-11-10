-- Librescape seed data: sources for the Tolkien entries.
-- Run after 02_seed_tolkien.sql. Every non-marker entry gets at least one source.
-- Bibliographic details (ISBNs, headlines) should be checked against the physical copies.

SET NAMES utf8mb4;

SET @acc = '2026-11-10';   -- access date for the Wikipedia sources

-- ---------------------------------------------------------------- books

INSERT INTO source (id, code, source_type, title, author, author_person_id, editor, published_date, published_precision,
                    publisher, place, isbn, wikipedia_url, publication_id) VALUES
 ( 1, 'CARP', 'book', 'J. R. R. Tolkien: A Biography', 'Humphrey Carpenter', NULL, NULL, '1977-01-01', 'year',
      'George Allen & Unwin', 'London', '0-04-928037-6', 'https://en.wikipedia.org/wiki/J._R._R._Tolkien:_A_Biography', NULL),
 ( 2, 'LETT', 'book', 'The Letters of J. R. R. Tolkien', 'J. R. R. Tolkien', 1, 'Humphrey Carpenter, with Christopher Tolkien', '1981-01-01', 'year',
      'George Allen & Unwin', 'London', '0-04-826005-3', 'https://en.wikipedia.org/wiki/The_Letters_of_J._R._R._Tolkien', NULL),
 ( 3, 'GARTH', 'book', 'Tolkien and the Great War: The Threshold of Middle-earth', 'John Garth', NULL, NULL, '2003-01-01', 'year',
      'HarperCollins', 'London', '0-00-711952-4', 'https://en.wikipedia.org/wiki/Tolkien_and_the_Great_War', NULL),
 -- Tolkien's own works as primary sources (codes match the publication shortcodes)
 ( 4, 'HOB',  'book', 'The Hobbit, or There and Back Again', 'J. R. R. Tolkien', 1, NULL, '1937-09-21', 'day',
      'George Allen & Unwin', 'London', NULL, 'https://en.wikipedia.org/wiki/The_Hobbit', 1),
 ( 5, 'FOTR', 'book', 'The Fellowship of the Ring: being the first part of The Lord of the Rings', 'J. R. R. Tolkien', 1, NULL, '1954-07-29', 'day',
      'George Allen & Unwin', 'London', NULL, 'https://en.wikipedia.org/wiki/The_Fellowship_of_the_Ring', 3),
 ( 6, 'TT',   'book', 'The Two Towers: being the second part of The Lord of the Rings', 'J. R. R. Tolkien', 1, NULL, '1954-11-11', 'day',
      'George Allen & Unwin', 'London', NULL, 'https://en.wikipedia.org/wiki/The_Two_Towers', 4),
 ( 7, 'ROTK', 'book', 'The Return of the King: being the third part of The Lord of the Rings', 'J. R. R. Tolkien', 1, NULL, '1955-10-20', 'day',
      'George Allen & Unwin', 'London', NULL, 'https://en.wikipedia.org/wiki/The_Return_of_the_King', 5),
 ( 8, 'SIL',  'book', 'The Silmarillion', 'J. R. R. Tolkien', 1, 'Christopher Tolkien', '1977-09-15', 'day',
      'George Allen & Unwin', 'London', '0-04-823139-8', 'https://en.wikipedia.org/wiki/The_Silmarillion', 6);

-- ---------------------------------------------------------------- newspaper articles

INSERT INTO source (id, code, source_type, title, author, published_date, published_precision, newspaper, url, wikipedia_url) VALUES
 (20, 'NYT-1973-09-03', 'newspaper', 'J. R. R. Tolkien Dead at 81; Wrote ''The Lord of the Rings''', NULL, '1973-09-03', 'day',
      'The New York Times', 'https://archive.nytimes.com/www.nytimes.com/books/01/02/11/specials/tolkien-obit.html',
      'https://en.wikipedia.org/wiki/The_New_York_Times'),
 (21, 'TIMES-1973-09-03', 'newspaper', 'Professor J. R. R. Tolkien: Creator of Hobbits and inventor of a new mythology', NULL, '1973-09-03', 'day',
      'The Times', NULL, 'https://en.wikipedia.org/wiki/The_Times');

-- ---------------------------------------------------------------- Wikipedia articles

INSERT INTO source (id, code, source_type, title, language, accessed_date, url) VALUES
 (40, 'W-JRRT',       'wikipedia', 'J. R. R. Tolkien',                     'en', @acc, 'https://en.wikipedia.org/wiki/J._R._R._Tolkien'),
 (41, 'W-EDITH',      'wikipedia', 'Edith Tolkien',                        'en', @acc, 'https://en.wikipedia.org/wiki/Edith_Tolkien'),
 (42, 'W-CHRT',       'wikipedia', 'Christopher Tolkien',                  'en', @acc, 'https://en.wikipedia.org/wiki/Christopher_Tolkien'),
 (43, 'W-CSL',        'wikipedia', 'C. S. Lewis',                          'en', @acc, 'https://en.wikipedia.org/wiki/C._S._Lewis'),
 (44, 'W-FXM',        'wikipedia', 'Francis Xavier Morgan',                'en', @acc, 'https://en.wikipedia.org/wiki/Francis_Xavier_Morgan'),
 (45, 'W-RAWL',       'wikipedia', 'Rawlinson and Bosworth Professor of Anglo-Saxon', 'en', @acc, 'https://en.wikipedia.org/wiki/Rawlinson_and_Bosworth_Professor_of_Anglo-Saxon'),
 (46, 'W-MERTON',     'wikipedia', 'Merton Professor of English Language and Literature', 'en', @acc, 'https://en.wikipedia.org/wiki/Merton_Professor_of_English_Language_and_Literature'),
 (47, 'W-OFS',        'wikipedia', 'On Fairy-Stories',                     'en', @acc, 'https://en.wikipedia.org/wiki/On_Fairy-Stories'),
 (48, 'W-BMC',        'wikipedia', 'Beowulf: The Monsters and the Critics', 'en', @acc, 'https://en.wikipedia.org/wiki/Beowulf:_The_Monsters_and_the_Critics'),
 (49, 'W-VICTORIA',   'wikipedia', 'Death and state funeral of Queen Victoria', 'en', @acc, 'https://en.wikipedia.org/wiki/Death_and_state_funeral_of_Queen_Victoria'),
 (50, 'W-APOLLO11',   'wikipedia', 'Apollo 11',                            'en', @acc, 'https://en.wikipedia.org/wiki/Apollo_11'),
 (51, 'W-TSOC',       'wikipedia', 'The Tolkien Society',                  'en', @acc, 'https://en.wikipedia.org/wiki/The_Tolkien_Society'),
 (52, 'W-LUSITANIA',  'wikipedia', 'Sinking of the RMS Lusitania',         'en', @acc, 'https://en.wikipedia.org/wiki/Sinking_of_the_RMS_Lusitania'),
 (53, 'W-USWWI',      'wikipedia', 'American entry into World War I',      'en', @acc, 'https://en.wikipedia.org/wiki/American_entry_into_World_War_I'),
 (54, 'W-PEARL',      'wikipedia', 'Attack on Pearl Harbor',               'en', @acc, 'https://en.wikipedia.org/wiki/Attack_on_Pearl_Harbor'),
 (55, 'W-DDAY',       'wikipedia', 'Normandy landings',                    'en', @acc, 'https://en.wikipedia.org/wiki/Normandy_landings'),
 (56, 'W-VEDAY',      'wikipedia', 'Victory in Europe Day',                'en', @acc, 'https://en.wikipedia.org/wiki/Victory_in_Europe_Day'),
 (57, 'W-SAREHOLE',   'wikipedia', 'Sarehole',                             'en', @acc, 'https://en.wikipedia.org/wiki/Sarehole'),
 (58, 'W-INKLINGS',   'wikipedia', 'Inklings',                             'en', @acc, 'https://en.wikipedia.org/wiki/Inklings'),
 (59, 'W-HOBBIT',     'wikipedia', 'The Hobbit',                           'en', @acc, 'https://en.wikipedia.org/wiki/The_Hobbit'),
 (60, 'W-LOTR',       'wikipedia', 'The Lord of the Rings',                'en', @acc, 'https://en.wikipedia.org/wiki/The_Lord_of_the_Rings'),
 (61, 'W-FOTR',       'wikipedia', 'The Fellowship of the Ring',           'en', @acc, 'https://en.wikipedia.org/wiki/The_Fellowship_of_the_Ring'),
 (62, 'W-TT',         'wikipedia', 'The Two Towers',                       'en', @acc, 'https://en.wikipedia.org/wiki/The_Two_Towers'),
 (63, 'W-ROTK',       'wikipedia', 'The Return of the King',               'en', @acc, 'https://en.wikipedia.org/wiki/The_Return_of_the_King'),
 (64, 'W-SIL',        'wikipedia', 'The Silmarillion',                     'en', @acc, 'https://en.wikipedia.org/wiki/The_Silmarillion'),
 (65, 'W-FGH',        'wikipedia', 'Farmer Giles of Ham',                  'en', @acc, 'https://en.wikipedia.org/wiki/Farmer_Giles_of_Ham'),
 (66, 'W-BOER',       'wikipedia', 'Second Boer War',                      'en', @acc, 'https://en.wikipedia.org/wiki/Second_Boer_War'),
 (67, 'W-WWI',        'wikipedia', 'World War I',                          'en', @acc, 'https://en.wikipedia.org/wiki/World_War_I'),
 (68, 'W-SOMME',      'wikipedia', 'Battle of the Somme',                  'en', @acc, 'https://en.wikipedia.org/wiki/Battle_of_the_Somme'),
 (69, 'W-DEPR',       'wikipedia', 'Great Depression',                     'en', @acc, 'https://en.wikipedia.org/wiki/Great_Depression'),
 (70, 'W-WWII',       'wikipedia', 'World War II',                         'en', @acc, 'https://en.wikipedia.org/wiki/World_War_II'),
 (71, 'W-GALLIPOLI',  'wikipedia', 'Gallipoli campaign',                   'en', @acc, 'https://en.wikipedia.org/wiki/Gallipoli_campaign'),
 (72, 'W-VERDUN',     'wikipedia', 'Battle of Verdun',                     'en', @acc, 'https://en.wikipedia.org/wiki/Battle_of_Verdun'),
 (73, 'W-BOB',        'wikipedia', 'Battle of Britain',                    'en', @acc, 'https://en.wikipedia.org/wiki/Battle_of_Britain'),
 (74, 'W-STALINGRAD', 'wikipedia', 'Battle of Stalingrad',                 'en', @acc, 'https://en.wikipedia.org/wiki/Battle_of_Stalingrad'),
 (75, 'W-VIETNAM',    'wikipedia', 'Vietnam War',                          'en', @acc, 'https://en.wikipedia.org/wiki/Vietnam_War'),
 (76, 'W-CORONATION', 'wikipedia', 'Coronation of Elizabeth II',           'en', @acc, 'https://en.wikipedia.org/wiki/Coronation_of_Elizabeth_II'),
 (77, 'W-TONKIN',     'wikipedia', 'Gulf of Tonkin incident',              'en', @acc, 'https://en.wikipedia.org/wiki/Gulf_of_Tonkin_incident');

-- ---------------------------------------------------------------- citations
-- Linked by source code; an unknown code yields NULL and makes the import fail.

INSERT INTO source_ref (source_id, entity_type, entity_id) VALUES
 -- persons
 ((SELECT id FROM source WHERE code = 'W-JRRT'), 'person', 1), ((SELECT id FROM source WHERE code = 'CARP'), 'person', 1),
 ((SELECT id FROM source WHERE code = 'W-EDITH'), 'person', 2), ((SELECT id FROM source WHERE code = 'CARP'), 'person', 2),
 ((SELECT id FROM source WHERE code = 'W-CHRT'), 'person', 3),
 ((SELECT id FROM source WHERE code = 'W-CSL'), 'person', 4),
 ((SELECT id FROM source WHERE code = 'CARP'), 'person', 5),
 ((SELECT id FROM source WHERE code = 'CARP'), 'person', 6),
 ((SELECT id FROM source WHERE code = 'W-FXM'), 'person', 7), ((SELECT id FROM source WHERE code = 'CARP'), 'person', 7),
 -- events: biography
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 1), ((SELECT id FROM source WHERE code = 'W-JRRT'), 'event', 1),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 2),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 3),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 4), ((SELECT id FROM source WHERE code = 'W-FXM'), 'event', 4),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 5), ((SELECT id FROM source WHERE code = 'W-EDITH'), 'event', 5),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 6), ((SELECT id FROM source WHERE code = 'W-EDITH'), 'event', 6),
 ((SELECT id FROM source WHERE code = 'GARTH'), 'event', 7), ((SELECT id FROM source WHERE code = 'CARP'), 'event', 7),
 ((SELECT id FROM source WHERE code = 'GARTH'), 'event', 8), ((SELECT id FROM source WHERE code = 'CARP'), 'event', 8),
 ((SELECT id FROM source WHERE code = 'W-CHRT'), 'event', 9),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 10), ((SELECT id FROM source WHERE code = 'W-RAWL'), 'event', 10),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 11), ((SELECT id FROM source WHERE code = 'W-MERTON'), 'event', 11),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 12),
 ((SELECT id FROM source WHERE code = 'CARP'), 'event', 13), ((SELECT id FROM source WHERE code = 'W-EDITH'), 'event', 13),
 ((SELECT id FROM source WHERE code = 'W-JRRT'), 'event', 14),
 ((SELECT id FROM source WHERE code = 'NYT-1973-09-03'), 'event', 15), ((SELECT id FROM source WHERE code = 'TIMES-1973-09-03'), 'event', 15), ((SELECT id FROM source WHERE code = 'CARP'), 'event', 15),
 -- events: works
 ((SELECT id FROM source WHERE code = 'W-OFS'), 'event', 29),
 ((SELECT id FROM source WHERE code = 'W-BMC'), 'event', 30),
 -- events: world
 ((SELECT id FROM source WHERE code = 'W-VICTORIA'), 'event', 41),
 ((SELECT id FROM source WHERE code = 'W-APOLLO11'), 'event', 42),
 ((SELECT id FROM source WHERE code = 'W-LUSITANIA'), 'event', 50),
 ((SELECT id FROM source WHERE code = 'W-USWWI'), 'event', 51),
 ((SELECT id FROM source WHERE code = 'W-PEARL'), 'event', 56),
 ((SELECT id FROM source WHERE code = 'W-DDAY'), 'event', 57),
 ((SELECT id FROM source WHERE code = 'W-VEDAY'), 'event', 58),
 -- events: other
 ((SELECT id FROM source WHERE code = 'W-LOTR'), 'event', 43), ((SELECT id FROM source WHERE code = 'CARP'), 'event', 43),
 ((SELECT id FROM source WHERE code = 'W-LOTR'), 'event', 44), ((SELECT id FROM source WHERE code = 'CARP'), 'event', 44),
 ((SELECT id FROM source WHERE code = 'W-TSOC'), 'event', 45),
 -- periods
 ((SELECT id FROM source WHERE code = 'W-SAREHOLE'), 'period', 1), ((SELECT id FROM source WHERE code = 'CARP'), 'period', 1),
 ((SELECT id FROM source WHERE code = 'CARP'), 'period', 2), ((SELECT id FROM source WHERE code = 'W-JRRT'), 'period', 2),
 ((SELECT id FROM source WHERE code = 'CARP'), 'period', 3),
 ((SELECT id FROM source WHERE code = 'W-INKLINGS'), 'period', 4), ((SELECT id FROM source WHERE code = 'CARP'), 'period', 4),
 ((SELECT id FROM source WHERE code = 'W-HOBBIT'), 'period', 5), ((SELECT id FROM source WHERE code = 'CARP'), 'period', 5), ((SELECT id FROM source WHERE code = 'LETT'), 'period', 5),
 ((SELECT id FROM source WHERE code = 'W-LOTR'), 'period', 6), ((SELECT id FROM source WHERE code = 'LETT'), 'period', 6),
 ((SELECT id FROM source WHERE code = 'W-SIL'), 'period', 7), ((SELECT id FROM source WHERE code = 'SIL'), 'period', 7),
 ((SELECT id FROM source WHERE code = 'W-BOER'), 'period', 8),
 ((SELECT id FROM source WHERE code = 'W-WWI'), 'period', 9),
 ((SELECT id FROM source WHERE code = 'W-SOMME'), 'period', 10), ((SELECT id FROM source WHERE code = 'GARTH'), 'period', 10),
 ((SELECT id FROM source WHERE code = 'W-DEPR'), 'period', 11),
 ((SELECT id FROM source WHERE code = 'W-WWII'), 'period', 12), ((SELECT id FROM source WHERE code = 'LETT'), 'period', 12),
 ((SELECT id FROM source WHERE code = 'W-GALLIPOLI'), 'period', 13),
 ((SELECT id FROM source WHERE code = 'W-VERDUN'), 'period', 14),
 ((SELECT id FROM source WHERE code = 'W-BOB'), 'period', 15),
 ((SELECT id FROM source WHERE code = 'W-STALINGRAD'), 'period', 16),
 ((SELECT id FROM source WHERE code = 'W-VIETNAM'), 'period', 17),
 ((SELECT id FROM source WHERE code = 'W-CORONATION'), 'event', 61),
 ((SELECT id FROM source WHERE code = 'W-TONKIN'), 'event', 59),
 -- publications
 ((SELECT id FROM source WHERE code = 'HOB'), 'publication', 1), ((SELECT id FROM source WHERE code = 'W-HOBBIT'), 'publication', 1),
 ((SELECT id FROM source WHERE code = 'W-LOTR'), 'publication', 2), ((SELECT id FROM source WHERE code = 'LETT'), 'publication', 2),
 ((SELECT id FROM source WHERE code = 'FOTR'), 'publication', 3), ((SELECT id FROM source WHERE code = 'W-FOTR'), 'publication', 3),
 ((SELECT id FROM source WHERE code = 'TT'), 'publication', 4), ((SELECT id FROM source WHERE code = 'W-TT'), 'publication', 4),
 ((SELECT id FROM source WHERE code = 'ROTK'), 'publication', 5), ((SELECT id FROM source WHERE code = 'W-ROTK'), 'publication', 5),
 ((SELECT id FROM source WHERE code = 'SIL'), 'publication', 6), ((SELECT id FROM source WHERE code = 'W-SIL'), 'publication', 6),
 ((SELECT id FROM source WHERE code = 'W-OFS'), 'publication', 7),
 ((SELECT id FROM source WHERE code = 'W-BMC'), 'publication', 8),
 ((SELECT id FROM source WHERE code = 'W-FGH'), 'publication', 9);
