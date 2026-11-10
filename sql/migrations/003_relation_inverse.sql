-- Directional relation types read differently from the second person's side:
-- "parent" -> "child", "guardian" -> "ward". NULL = symmetric (spouse, friend).
ALTER TABLE relation_type ADD COLUMN inverse_name VARCHAR(50) NULL AFTER short_name;
UPDATE relation_type SET inverse_name = 'child' WHERE short_name = 'parent';
UPDATE relation_type SET inverse_name = 'ward' WHERE short_name = 'guardian';
