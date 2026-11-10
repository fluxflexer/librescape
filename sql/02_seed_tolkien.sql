-- Librescape seed data: J.R.R. Tolkien
-- Run after 01_schema.sql. IDs are explicit so the links below stay readable.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------- lanes

INSERT INTO track (id, name, short_name, sort_order, css_class) VALUES
 (1, 'Biography',    'bio',   10, 'lane-bio'),
 (2, 'Works',        'works', 20, 'lane-works'),
 (5, 'Publications', 'pubs',  25, 'lane-pubs'),
 (3, 'World events', 'world', 30, 'lane-world'),
 (4, 'Other',        'other', 40, 'lane-other');

-- ---------------------------------------------------------------- persons

INSERT INTO person (id, first_name, surname, date_of_birth, place_of_birth, date_of_death, shortcode) VALUES
 (1, 'John Ronald Reuel', 'Tolkien', '1892-01-03', 'Bloemfontein, Orange Free State', '1973-09-02', 'JRRT'),
 (2, 'Edith Mary',        'Tolkien (née Bratt)', '1889-01-21', 'Gloucester, England', '1971-11-29', 'EDTH'),
 (3, 'Christopher',       'Tolkien', '1924-11-21', 'Leeds, England', '2020-01-16', 'CHRT'),
 (4, 'Clive Staples',     'Lewis',   '1898-11-29', 'Belfast, Ireland', '1963-11-22', 'CSL'),
 (5, 'Arthur Reuel',      'Tolkien', NULL, 'Birmingham, England', '1896-02-15', 'ART'),
 (6, 'Mabel',             'Tolkien (née Suffield)', NULL, 'Birmingham, England', '1904-11-14', 'MAB'),
 (7, 'Francis Xavier',    'Morgan',  '1857-01-18', 'El Puerto de Santa María, Spain', '1935-06-11', 'FXM');

INSERT INTO relation_type (id, short_name, description) VALUES
 (1, 'spouse',   'Married couple'),
 (2, 'parent',   'First person is a parent of the second'),
 (3, 'friend',   'Friendship'),
 (4, 'guardian', 'First person was the legal guardian of the second');

INSERT INTO relationship (person1_id, person2_id, relation_type_id, relation_status) VALUES
 (1, 2, 1, 'married 1916 until her death in 1971'),
 (5, 1, 2, NULL),
 (6, 1, 2, NULL),
 (1, 3, 2, NULL),
 (1, 4, 3, 'close friends from 1926, cooler after the 1940s'),
 (7, 1, 4, 'from 1904 until Tolkien came of age');

-- ---------------------------------------------------------------- points in time
-- pit.id = event.id throughout this seed, for readability.

INSERT INTO pit (id, name, date_precision, ts) VALUES
 -- biography
 ( 1, '3 January 1892',     'day',   '1892-01-03'),
 ( 2, 'April 1895',         'month', '1895-04-01'),
 ( 3, '15 February 1896',   'day',   '1896-02-15'),
 ( 4, '14 November 1904',   'day',   '1904-11-14'),
 ( 5, '1908',               'year',  '1908-01-01'),
 ( 6, '22 March 1916',      'day',   '1916-03-22'),
 ( 7, '14 July 1916',       'day',   '1916-07-14'),
 ( 8, 'November 1916',      'month', '1916-11-01'),
 ( 9, '21 November 1924',   'day',   '1924-11-21'),
 (10, '1925',               'year',  '1925-07-01'),
 (11, '1945',               'year',  '1945-07-01'),
 (12, '1959',               'year',  '1959-07-01'),
 (13, '29 November 1971',   'day',   '1971-11-29'),
 (14, '1972',               'year',  '1972-03-28'),
 (15, '2 September 1973',   'day',   '1973-09-02'),
 -- biography period markers
 (16, '1896',               'year',  '1896-06-01'),
 (17, '1900',               'year',  '1900-06-01'),
 (18, 'October 1911',       'month', '1911-10-01'),
 (19, 'June 1915',          'month', '1915-06-01'),
 (20, '1920',               'year',  '1920-07-01'),
 (21, '1925',               'year',  '1925-07-01'),
 (22, 'c. 1933',            'circa', '1933-01-01'),
 (23, 'c. 1949',            'circa', '1949-10-01'),
 -- works period markers
 (24, 'c. 1930',            'circa', '1930-01-01'),
 (25, '1936',               'year',  '1936-07-01'),
 (26, 'December 1937',      'month', '1937-12-01'),
 (27, '1949',               'year',  '1949-07-01'),
 (28, '1917',               'year',  '1917-01-01'),
 -- works events
 (29, '8 March 1939',       'day',   '1939-03-08'),
 (30, '25 November 1936',   'day',   '1936-11-25'),
 -- world period markers
 (31, '11 October 1899',    'day',   '1899-10-11'),
 (32, '31 May 1902',        'day',   '1902-05-31'),
 (33, '28 July 1914',       'day',   '1914-07-28'),
 (34, '11 November 1918',   'day',   '1918-11-11'),
 (35, '1 July 1916',        'day',   '1916-07-01'),
 (36, '18 November 1916',   'day',   '1916-11-18'),
 (37, '29 October 1929',    'day',   '1929-10-29'),
 (38, 'c. 1939',            'circa', '1939-01-01'),
 (39, '1 September 1939',   'day',   '1939-09-01'),
 (40, '2 September 1945',   'day',   '1945-09-02'),
 -- world events
 (41, '22 January 1901',    'day',   '1901-01-22'),
 (42, '20 July 1969',       'day',   '1969-07-20'),
 -- other
 (43, 'May 1965',           'month', '1965-05-01'),
 (44, 'October 1965',       'month', '1965-10-01'),
 (45, '1969',               'year',  '1969-07-01');

-- ---------------------------------------------------------------- events

INSERT INTO event (id, name, short_name, description, pit_id, person1_id, person2_id, track_id, is_marker) VALUES
 -- biography (track 1)
 ( 1, 'Birth of J.R.R. Tolkien', 'Born', 'Born in Bloemfontein, Orange Free State, where his father managed a branch of the Bank of Africa.', 1, 1, NULL, 1, 0),
 ( 2, 'Family moves to England', 'To England', 'Mabel Tolkien takes Ronald and his brother Hilary to England for what was meant to be a long visit; they never return.', 2, 6, 1, 1, 0),
 ( 3, 'Death of Arthur Tolkien', 'Father dies', 'His father dies of rheumatic fever in South Africa before he can join the family.', 3, 5, 1, 1, 0),
 ( 4, 'Death of Mabel Tolkien', 'Mother dies', 'His mother dies of diabetes. Father Francis Morgan of the Birmingham Oratory becomes guardian of the two boys.', 4, 6, 1, 1, 0),
 ( 5, 'Meets Edith Bratt', 'Meets Edith', 'At sixteen he meets Edith Bratt, a fellow lodger at a boarding house in Edgbaston, Birmingham.', 5, 1, 2, 1, 0),
 ( 6, 'Marriage to Edith Bratt', 'Marriage', 'Married at the Catholic church of St Mary Immaculate in Warwick, shortly before leaving for France.', 6, 1, 2, 1, 0),
 ( 7, 'In action at the Somme', 'Somme', 'As a signals officer with the 11th Lancashire Fusiliers he goes into action at Ovillers. Most of his close school friends are killed in the war.', 7, 1, NULL, 1, 0),
 ( 8, 'Invalided home with trench fever', 'Trench fever', 'Sent back to England; during his recovery he begins "The Book of Lost Tales", the seed of the legendarium.', 8, 1, NULL, 1, 0),
 ( 9, 'Birth of Christopher Tolkien', 'Christopher born', 'Third son, later his literary executor and editor of the posthumous works.', 9, 3, 1, 1, 0),
 (10, 'Professor of Anglo-Saxon at Oxford', 'Oxford chair', 'Elected Rawlinson and Bosworth Professor of Anglo-Saxon and a fellow of Pembroke College.', 10, 1, NULL, 1, 0),
 (11, 'Merton Professor of English', 'Merton chair', 'Becomes Merton Professor of English Language and Literature.', 11, 1, NULL, 1, 0),
 (12, 'Retirement from Oxford', 'Retires', 'Retires from his professorship.', 12, 1, NULL, 1, 0),
 (13, 'Death of Edith Tolkien', 'Edith dies', 'Edith dies in Bournemouth; her gravestone bears the name "Lúthien".', 13, 2, 1, 1, 0),
 (14, 'Appointed CBE', 'CBE', 'Appointed Commander of the Order of the British Empire.', 14, 1, NULL, 1, 0),
 (15, 'Death of J.R.R. Tolkien', 'Dies', 'Dies in Bournemouth aged 81; buried beside Edith in Oxford with the name "Beren".', 15, 1, NULL, 1, 0),
 -- biography period markers
 (16, 'Move to Sarehole', NULL, NULL, 16, 1, NULL, 1, 1),
 (17, 'Leaves Sarehole', NULL, NULL, 17, 1, NULL, 1, 1),
 (18, 'Goes up to Exeter College', NULL, NULL, 18, 1, NULL, 1, 1),
 (19, 'First-class degree', NULL, NULL, 19, 1, NULL, 1, 1),
 (20, 'Appointed Reader at Leeds', NULL, NULL, 20, 1, NULL, 1, 1),
 (21, 'Leaves Leeds', NULL, NULL, 21, 1, NULL, 1, 1),
 (22, 'Inklings begin meeting', NULL, NULL, 22, 1, 4, 1, 1),
 (23, 'Inklings readings end', NULL, NULL, 23, 1, 4, 1, 1),
 -- works period markers
 (24, 'Begins The Hobbit', NULL, NULL, 24, 1, NULL, 2, 1),
 (25, 'Completes The Hobbit', NULL, NULL, 25, 1, NULL, 2, 1),
 (26, 'Begins "the new Hobbit"', NULL, NULL, 26, 1, NULL, 2, 1),
 (27, 'Completes The Lord of the Rings', NULL, NULL, 27, 1, NULL, 2, 1),
 (28, 'Begins The Book of Lost Tales', NULL, NULL, 28, 1, NULL, 2, 1),
 -- works events
 (29, 'Andrew Lang Lecture "On Fairy-Stories"', 'Fairy-Stories lecture', 'Delivered at the University of St Andrews; his central statement on fantasy and "sub-creation".', 29, 1, NULL, 2, 0),
 (30, 'Lecture "Beowulf: The Monsters and the Critics"', 'Beowulf lecture', 'British Academy lecture that changed how Beowulf was read as literature.', 30, 1, NULL, 2, 0),
 -- world period markers
 (31, 'Second Boer War begins', NULL, NULL, 31, NULL, NULL, 3, 1),
 (32, 'Treaty of Vereeniging', NULL, NULL, 32, NULL, NULL, 3, 1),
 (33, 'Austria-Hungary declares war on Serbia', NULL, NULL, 33, NULL, NULL, 3, 1),
 (34, 'Armistice', NULL, NULL, 34, NULL, NULL, 3, 1),
 (35, 'Somme offensive begins', NULL, NULL, 35, NULL, NULL, 3, 1),
 (36, 'Somme offensive ends', NULL, NULL, 36, NULL, NULL, 3, 1),
 (37, 'Wall Street Crash', NULL, NULL, 37, NULL, NULL, 3, 1),
 (38, 'End of the Depression', NULL, NULL, 38, NULL, NULL, 3, 1),
 (39, 'Germany invades Poland', NULL, NULL, 39, NULL, NULL, 3, 1),
 (40, 'Surrender of Japan', NULL, NULL, 40, NULL, NULL, 3, 1),
 -- world events
 (41, 'Death of Queen Victoria', 'Victoria dies', 'End of the Victorian era after a reign of 63 years.', 41, NULL, NULL, 3, 0),
 (42, 'Apollo 11 Moon landing', 'Moon landing', 'Neil Armstrong and Buzz Aldrin walk on the Moon.', 42, NULL, NULL, 3, 0),
 -- other (track 4)
 (43, 'Ace Books paperback of The Lord of the Rings', 'Ace paperback', 'An unauthorised US paperback edition sells in huge numbers and sparks the campus Tolkien craze.', 43, NULL, NULL, 4, 0),
 (44, 'Authorised Ballantine paperback', 'Ballantine edition', 'The revised, authorised US paperback edition appears, with a note asking readers to buy only this one.', 44, 1, NULL, 4, 0),
 (45, 'The Tolkien Society is founded', 'Tolkien Society', 'Founded in the UK; Tolkien accepts the title of Honorary President.', 45, 1, NULL, 4, 0);

-- ---------------------------------------------------------------- periods

INSERT INTO period (id, start_event_id, end_event_id, name, description, track_id) VALUES
 ( 1, 16, 17, 'Childhood in Sarehole', 'The rural hamlet near Birmingham that he later remembered as the model for the Shire.', 1),
 ( 2, 18, 19, 'Undergraduate at Exeter College, Oxford', 'Studies Classics, then switches to English Language and Literature; graduates with first-class honours.', 1),
 ( 3, 20, 21, 'Leeds years', 'Reader and then Professor of English Language at the University of Leeds; works on Sir Gawain and the Green Knight with E.V. Gordon.', 1),
 ( 4, 22, 23, 'The Inklings', 'Informal literary group around C.S. Lewis, meeting in Lewis''s rooms at Magdalen and at the Eagle and Child pub. Chapters of The Lord of the Rings are read aloud here.', 1),
 ( 5, 24, 25, 'Writing The Hobbit', 'Begun as a story for his children; the famous first line was scribbled on a blank exam paper.', 2),
 ( 6, 26, 27, 'Writing The Lord of the Rings', 'Started at his publisher''s request for a sequel to The Hobbit; it grew into a much larger and darker work over twelve years.', 2),
 ( 7, 28, 15, 'Writing the Silmarillion legendarium', 'The mythology of the Elder Days, worked on and rewritten from 1917 until his death but never finished.', 2),
 ( 8, 31, 32, 'Second Boer War', 'War between the British Empire and the Boer republics, including the Orange Free State where Tolkien was born.', 3),
 ( 9, 33, 34, 'First World War', 'Global war centred on Europe; about 20 million dead.', 3),
 (10, 35, 36, 'Battle of the Somme', 'One of the bloodiest battles in history; over one million men wounded or killed.', 3),
 (11, 37, 38, 'Great Depression', 'Worldwide economic depression following the Wall Street Crash.', 3),
 (12, 39, 40, 'Second World War', 'Global war; Christopher Tolkien serves in the RAF and receives chapters of The Lord of the Rings by letter.', 3);

-- ---------------------------------------------------------------- publications

INSERT INTO pub_category (id, category, short_title, description) VALUES
 (1, 'Novel',              'novel',  'Full-length fiction'),
 (2, 'Essay',              'essay',  'Scholarly or critical essay or lecture'),
 (3, 'Posthumous edition', 'posth',  'Published after the author''s death, edited by others'),
 (4, 'Short fiction',      'short',  'Novella or short story'),
 (5, 'Volume',             'volume', 'One volume of a larger work');

INSERT INTO publication (id, author_id, author2_id, author3_id, title, category_id, short_title, parent_work_id, description, release_date, release_precision, shortcode, gestation_period_id) VALUES
 (1, 1, NULL, NULL, 'The Hobbit, or There and Back Again', 1, 'The Hobbit', NULL, 'Bilbo Baggins'' journey to the Lonely Mountain. Published by George Allen & Unwin with the author''s own illustrations.', '1937-09-21', 'day', 'HOB', 5),
 (2, 1, NULL, NULL, 'The Lord of the Rings', 1, 'LotR', NULL, 'The War of the Ring and the destruction of the One Ring. Published in three volumes for paper-shortage and cost reasons.', NULL, 'day', 'LOTR', 6),
 (3, 1, NULL, NULL, 'The Fellowship of the Ring', 5, 'Fellowship', 2, 'First volume of The Lord of the Rings.', '1954-07-29', 'day', 'FOTR', NULL),
 (4, 1, NULL, NULL, 'The Two Towers', 5, 'Two Towers', 2, 'Second volume of The Lord of the Rings.', '1954-11-11', 'day', 'TT', NULL),
 (5, 1, NULL, NULL, 'The Return of the King', 5, 'Return of the King', 2, 'Third volume of The Lord of the Rings, with the appendices.', '1955-10-20', 'day', 'ROTK', NULL),
 (6, 1, 3, NULL, 'The Silmarillion', 3, 'Silmarillion', NULL, 'The history of the Elder Days, assembled and edited by Christopher Tolkien with the help of Guy Gavriel Kay.', '1977-09-15', 'day', 'SIL', 7),
 (7, 1, NULL, NULL, 'On Fairy-Stories', 2, 'On Fairy-Stories', NULL, 'Expanded version of the 1939 Andrew Lang Lecture, first printed in "Essays Presented to Charles Williams".', '1947-06-01', 'year', 'OFS', NULL),
 (8, 1, NULL, NULL, 'Beowulf: The Monsters and the Critics', 2, 'Monsters and Critics', NULL, 'The 1936 lecture, printed in the Proceedings of the British Academy.', '1937-06-01', 'year', 'BMC', NULL),
 (9, 1, NULL, NULL, 'Farmer Giles of Ham', 4, 'Farmer Giles', NULL, 'Comic medieval tale of a reluctant dragon-tamer, illustrated by Pauline Baynes.', '1949-10-20', 'day', 'FGH', NULL);

-- ---------------------------------------------------------------- keywords

INSERT INTO keyword (id, name) VALUES
 ( 1, 'Middle-earth'), ( 2, 'philology'), ( 3, 'war'), ( 4, 'Oxford'), ( 5, 'Inklings'),
 ( 6, 'mythology'), ( 7, 'family'), ( 8, 'Birmingham'), ( 9, 'Catholicism'), (10, 'South Africa'),
 (11, 'Beowulf'), (12, 'fantasy'), (13, 'fandom'), (14, 'academia'), (15, 'economy'),
 (16, 'Shire'), (17, 'Edith'), (18, 'C.S. Lewis');

INSERT INTO event_keyword (event_id, keyword_id) VALUES
 (1, 10), (1, 7),
 (2, 7), (2, 8),
 (3, 7), (3, 10),
 (4, 7), (4, 9), (4, 8),
 (5, 17), (5, 8),
 (6, 17), (6, 7), (6, 9),
 (7, 3),
 (8, 3), (8, 6), (8, 1),
 (9, 7),
 (10, 4), (10, 14), (10, 2),
 (11, 4), (11, 14),
 (12, 4), (12, 14),
 (13, 17), (13, 7),
 (15, 7),
 (29, 12), (29, 6),
 (30, 11), (30, 2), (30, 14),
 (41, 3),
 (43, 13), (43, 1),
 (44, 13), (44, 1),
 (45, 13);

INSERT INTO period_keyword (period_id, keyword_id) VALUES
 (1, 16), (1, 8), (1, 7),
 (2, 4), (2, 2), (2, 14),
 (3, 14), (3, 2),
 (4, 5), (4, 18), (4, 4),
 (5, 1), (5, 12),
 (6, 1), (6, 12), (6, 5),
 (7, 1), (7, 6),
 (8, 3), (8, 10),
 (9, 3),
 (10, 3),
 (11, 15),
 (12, 3);

INSERT INTO publication_keyword (publication_id, keyword_id) VALUES
 (1, 1), (1, 12), (1, 16),
 (2, 1), (2, 12), (2, 5),
 (3, 1), (3, 16),
 (4, 1),
 (5, 1),
 (6, 1), (6, 6),
 (7, 12), (7, 6),
 (8, 11), (8, 2), (8, 14),
 (9, 12);

-- ---------------------------------------------------------------- nesting (sub-periods and sub-events)
-- Parent links: period.parent_period_id / event.parent_period_id.
-- The viewer shows children only when their parent is zoomed in far enough.

INSERT INTO pit (id, name, date_precision, ts) VALUES
 (46, '25 April 1915',     'day', '1915-04-25'),
 (47, '9 January 1916',    'day', '1916-01-09'),
 (48, '21 February 1916',  'day', '1916-02-21'),
 (49, '18 December 1916',  'day', '1916-12-18'),
 (50, '7 May 1915',        'day', '1915-05-07'),
 (51, '6 April 1917',      'day', '1917-04-06'),
 (52, '10 July 1940',      'day', '1940-07-10'),
 (53, '31 October 1940',   'day', '1940-10-31'),
 (54, '23 August 1942',    'day', '1942-08-23'),
 (55, '2 February 1943',   'day', '1943-02-02'),
 (56, '7 December 1941',   'day', '1941-12-07'),
 (57, '6 June 1944',       'day', '1944-06-06'),
 (58, '8 May 1945',        'day', '1945-05-08');

INSERT INTO event (id, name, short_name, description, pit_id, person1_id, person2_id, track_id, is_marker) VALUES
 (46, 'Allied landings at Gallipoli', NULL, NULL, 46, NULL, NULL, 3, 1),
 (47, 'Evacuation of Gallipoli completed', NULL, NULL, 47, NULL, NULL, 3, 1),
 (48, 'German offensive at Verdun begins', NULL, NULL, 48, NULL, NULL, 3, 1),
 (49, 'Verdun offensive ends', NULL, NULL, 49, NULL, NULL, 3, 1),
 (50, 'Sinking of the Lusitania', 'Lusitania', 'A German U-boat sinks the British liner RMS Lusitania; nearly 1,200 people die.', 50, NULL, NULL, 3, 0),
 (51, 'United States enters the war', 'USA enters war', 'The US Congress declares war on Germany.', 51, NULL, NULL, 3, 0),
 (52, 'Battle of Britain begins', NULL, NULL, 52, NULL, NULL, 3, 1),
 (53, 'Battle of Britain ends', NULL, NULL, 53, NULL, NULL, 3, 1),
 (54, 'German assault on Stalingrad begins', NULL, NULL, 54, NULL, NULL, 3, 1),
 (55, 'German 6th Army surrenders', NULL, NULL, 55, NULL, NULL, 3, 1),
 (56, 'Attack on Pearl Harbor', 'Pearl Harbor', 'Japanese attack on the US fleet; the United States enters the war.', 56, NULL, NULL, 3, 0),
 (57, 'D-Day', 'D-Day', 'Allied landings in Normandy.', 57, NULL, NULL, 3, 0),
 (58, 'VE Day', 'VE Day', 'Germany''s unconditional surrender; victory in Europe.', 58, NULL, NULL, 3, 0);

INSERT INTO period (id, start_event_id, end_event_id, name, description, track_id, parent_period_id) VALUES
 (13, 46, 47, 'Gallipoli campaign', 'Failed Allied attempt to knock the Ottoman Empire out of the war.', 3, 9),
 (14, 48, 49, 'Battle of Verdun', 'The longest battle of the war, fought between France and Germany.', 3, 9),
 (15, 52, 53, 'Battle of Britain', 'Air campaign over Britain; the RAF prevents a German invasion.', 3, 12),
 (16, 54, 55, 'Battle of Stalingrad', 'Turning point on the Eastern Front.', 3, 12);

-- existing items become children
UPDATE period SET parent_period_id = 9  WHERE id = 10;              -- Somme      -> First World War
UPDATE event  SET parent_period_id = 9  WHERE id IN (50, 51);       -- Lusitania, USA enters war -> WWI
UPDATE event  SET parent_period_id = 12 WHERE id IN (56, 57, 58);   -- Pearl Harbor, D-Day, VE Day -> WWII
UPDATE event  SET parent_period_id = 10 WHERE id = 7;               -- Tolkien "In action at the Somme" (Biography lane) -> Battle of the Somme

INSERT INTO event_keyword (event_id, keyword_id) VALUES (50, 3), (51, 3), (56, 3), (57, 3), (58, 3);
INSERT INTO period_keyword (period_id, keyword_id) VALUES (13, 3), (14, 3), (15, 3), (16, 3);

-- ---------------------------------------------------------------- more world events

INSERT INTO pit (id, name, date_precision, ts) VALUES
 (59, '2 August 1964',   'day', '1964-08-02'),
 (60, '30 April 1975',   'day', '1975-04-30');

INSERT INTO event (id, name, short_name, description, pit_id, person1_id, person2_id, track_id, is_marker) VALUES
 (59, 'Gulf of Tonkin incident', 'Gulf of Tonkin', 'North Vietnamese torpedo boats engage the US destroyer Maddox; a second, disputed attack is reported two days later. The Gulf of Tonkin Resolution that follows lets the United States enter the war openly.', 59, NULL, NULL, 3, 1),
 (60, 'Fall of Saigon', NULL, NULL, 60, NULL, NULL, 3, 1);

INSERT INTO period (id, start_event_id, end_event_id, name, description, track_id) VALUES
 (17, 59, 60, 'Vietnam War', 'War between North Vietnam and South Vietnam and its ally the United States; it ends with the fall of Saigon and the reunification of Vietnam. It shapes the counterculture in which The Lord of the Rings becomes a campus cult book.', 3);

INSERT INTO period_keyword (period_id, keyword_id) VALUES (17, 3);

INSERT INTO pit (id, name, date_precision, ts) VALUES
 (61, '2 June 1953', 'day', '1953-06-02');

INSERT INTO event (id, name, short_name, description, pit_id, person1_id, person2_id, track_id, is_marker) VALUES
 (61, 'Coronation of Elizabeth II', 'Coronation', 'Elizabeth II is crowned in Westminster Abbey, sixteen months after her accession. The first coronation to be televised live, watched by millions across Britain.', 61, NULL, NULL, 3, 0);

INSERT INTO keyword (id, name) VALUES (19, 'monarchy');
INSERT INTO event_keyword (event_id, keyword_id) VALUES (61, 19), (41, 19);

-- ---------------------------------------------------------------- weblinks
-- Any number of links per entry. The first Wikipedia link (by sort_order) is also
-- the article tools/fetch_images.php takes the picture from.

INSERT INTO weblink (entity_type, entity_id, link_type, label, url, sort_order) VALUES
 ('person', 1, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/J._R._R._Tolkien', 10),
 ('person', 1, 'wikipedia', 'Wikipedia (Deutsch)', 'https://de.wikipedia.org/wiki/J._R._R._Tolkien', 20),
 ('person', 1, 'website', 'The Tolkien Estate', 'https://www.tolkienestate.com/', 30),
 ('person', 2, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Edith_Tolkien', 10),
 ('person', 3, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Christopher_Tolkien', 10),
 ('person', 4, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/C._S._Lewis', 10),
 ('person', 7, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Francis_Xavier_Morgan', 10),
 ('event', 10, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Rawlinson_and_Bosworth_Professor_of_Anglo-Saxon', 10),
 ('event', 11, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Merton_Professor_of_English_Language_and_Literature', 10),
 ('event', 29, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/On_Fairy-Stories', 10),
 ('event', 30, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Beowulf:_The_Monsters_and_the_Critics', 10),
 ('event', 41, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Death_and_state_funeral_of_Queen_Victoria', 10),
 ('event', 42, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Apollo_11', 10),
 ('event', 61, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Coronation_of_Elizabeth_II', 10),
 ('event', 59, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Gulf_of_Tonkin_incident', 10),
 ('event', 45, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/The_Tolkien_Society', 10),
 ('event', 45, 'website', 'Tolkien Society website', 'https://www.tolkiensociety.org/', 20),
 ('event', 50, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Sinking_of_the_RMS_Lusitania', 10),
 ('event', 51, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/American_entry_into_World_War_I', 10),
 ('event', 56, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Attack_on_Pearl_Harbor', 10),
 ('event', 57, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Normandy_landings', 10),
 ('event', 58, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Victory_in_Europe_Day', 10),
 ('period', 1, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Sarehole', 10),
 ('period', 2, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Exeter_College,_Oxford', 10),
 ('period', 3, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/University_of_Leeds', 10),
 ('period', 4, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Inklings', 10),
 ('period', 8, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Second_Boer_War', 10),
 ('period', 9, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/World_War_I', 10),
 ('period', 10, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Battle_of_the_Somme', 10),
 ('period', 11, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Great_Depression', 10),
 ('period', 12, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/World_War_II', 10),
 ('period', 13, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Gallipoli_campaign', 10),
 ('period', 14, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Battle_of_Verdun', 10),
 ('period', 15, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Battle_of_Britain', 10),
 ('period', 16, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Battle_of_Stalingrad', 10),
 ('period', 17, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Vietnam_War', 10),
 ('publication', 1, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/The_Hobbit', 10),
 ('publication', 2, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/The_Lord_of_the_Rings', 10),
 ('publication', 3, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/The_Fellowship_of_the_Ring', 10),
 ('publication', 4, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/The_Two_Towers', 10),
 ('publication', 5, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/The_Return_of_the_King', 10),
 ('publication', 6, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/The_Silmarillion', 10),
 ('publication', 7, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/On_Fairy-Stories', 10),
 ('publication', 8, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Beowulf:_The_Monsters_and_the_Critics', 10),
 ('publication', 9, 'wikipedia', NULL, 'https://en.wikipedia.org/wiki/Farmer_Giles_of_Ham', 10);
