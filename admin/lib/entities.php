<?php
// Loading, validating, saving and deleting entries (person, event, period, publication) and sources.
// Used by the edit pages and by the quick-create dialogs, so both apply exactly the same rules.
// Every save runs in one transaction and writes one change-log row.

declare(strict_types=1);

require_once __DIR__ . '/dates.php';
require_once dirname(__DIR__, 2) . '/lib/images.php';

// 'publication' = a work (The Hobbit), 'edition' = a publication it appeared in (see sql/migrations/005_editions.sql).
const ENTRY_TYPES = ['person', 'event', 'period', 'publication', 'edition'];
const LINK_TYPES  = ['wikipedia' => 'Wikipedia', 'website' => 'Website', 'cv' => 'CV', 'archive' => 'Archive', 'other' => 'Other'];
const PRECISIONS  = ['day', 'month', 'season', 'year', 'decade', 'circa'];

/** Thrown with field => message pairs; the form shows them next to the fields. */
final class ValidationError extends RuntimeException
{
    public function __construct(public array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}

/** Someone else saved the entry after this form was opened. */
final class ConflictError extends RuntimeException
{
}

// ------------------------------------------------------------------ small helpers

function str(array $in, string $key, int $max = 0): string
{
    // mb_scrub: malformed UTF-8 (e.g. from a script posting Windows-1252) would make the database refuse the row.
    $v = trim(mb_scrub((string) ($in[$key] ?? ''), 'UTF-8'));
    return $max > 0 ? mb_substr($v, 0, $max) : $v;
}

function optStr(array $in, string $key, int $max = 0): ?string
{
    $v = str($in, $key, $max);
    return $v === '' ? null : $v;
}

function optId(array $in, string $key): ?int
{
    $v = $in[$key] ?? null;
    return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
}

/** Smart date from $in[$key]; adds an error if the text is not understood, or if it is missing and required. */
function smartDate(array $in, string $key, array &$errors, bool $required, string $what): ?array
{
    $text = str($in, $key);
    if ($text === '') {
        if ($required) {
            $errors[$key] = "Please enter the $what.";
        }
        return null;
    }
    $d = parseSmartDate($text);
    if (!$d) {
        $errors[$key] = "“{$text}” is not a date I understand. Examples: 21 Sep 1937, Sep 1937, Summer 1937, 1937, 1930s, c. 1930.";
    }
    return $d;
}

function existsRow(string $table, ?int $id): bool
{
    // $table is always a fixed name from this file.
    return $id !== null && q1("SELECT id FROM $table WHERE id = ?", [$id]) !== null;
}

function tracks(): array
{
    return q('SELECT id, name, short_name FROM track ORDER BY sort_order, id');
}

function trackName(?int $id): ?string
{
    foreach (tracks() as $t) {
        if ((int) $t['id'] === $id) {
            return $t['name'];
        }
    }
    return null;
}

// ------------------------------------------------------------------ keywords, weblinks, sources (shared by all entry types)

const KEYWORD_TABLES = ['event' => ['event_keyword', 'event_id'], 'period' => ['period_keyword', 'period_id'], 'publication' => ['publication_keyword', 'publication_id'],
                        'edition' => ['edition_keyword', 'edition_id']];

function keywordNames(string $type, int $id): array
{
    if (!isset(KEYWORD_TABLES[$type])) {
        return [];
    }
    [$table, $col] = KEYWORD_TABLES[$type];
    return keywordsFor($table, $col, $id);
}

/** Cleans a list of keyword names: trimmed, lower-case like the existing ones, no duplicates. */
function cleanKeywords(array $names): array
{
    $out = [];
    foreach ($names as $n) {
        $n = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $n)));
        if ($n !== '' && mb_strlen($n) <= 100) {
            $out[$n] = $n;
        }
    }
    ksort($out);
    return array_values($out);
}

function saveKeywords(string $type, int $id, array $names): void
{
    if (!isset(KEYWORD_TABLES[$type])) {
        return;
    }
    [$table, $col] = KEYWORD_TABLES[$type];
    q("DELETE FROM $table WHERE $col = ?", [$id]);
    foreach ($names as $n) {
        $kw = q1('SELECT id FROM keyword WHERE name = ?', [$n]);
        if (!$kw) {
            q('INSERT INTO keyword (name) VALUES (?)', [$n]);
            $kwId = (int) getDb()->lastInsertId();
            logChange('keyword', $kwId, 'create', $n);
        } else {
            $kwId = (int) $kw['id'];
        }
        q("INSERT INTO $table ($col, keyword_id) VALUES (?, ?)", [$id, $kwId]);
    }
}

/** Link rows from the form: [{link_type, url, label}], validated; errors go to $errors['links']. */
function cleanLinks(array $rows, array &$errors): array
{
    $out = [];
    foreach (array_values($rows) as $i => $r) {
        $url = trim((string) ($r['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        if (!preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $url) || mb_strlen($url) > 500) {
            $errors['links'] = "Link " . ($i + 1) . ": “{$url}” is not a valid web address (it must start with http:// or https://).";
            continue;
        }
        $type = (string) ($r['link_type'] ?? '');
        if (!isset(LINK_TYPES[$type])) {
            $type = preg_match('~^https?://[a-z\-]+\.(m\.)?wikipedia\.org/~i', $url) ? 'wikipedia' : 'website';
        }
        $label = trim((string) ($r['label'] ?? ''));
        $out[] = ['link_type' => $type, 'url' => $url, 'label' => $label === '' ? null : mb_substr($label, 0, 150)];
    }
    return $out;
}

function linkRows(string $type, int $id): array
{
    return q('SELECT link_type, url, label FROM weblink WHERE entity_type = ? AND entity_id = ? ORDER BY sort_order, id', [$type, $id]);
}

function saveLinks(string $type, int $id, array $links): void
{
    q('DELETE FROM weblink WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
    foreach ($links as $i => $l) {
        q('INSERT INTO weblink (entity_type, entity_id, link_type, label, url, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
          [$type, $id, $l['link_type'], $l['label'], $l['url'], ($i + 1) * 10]);
    }
}

/** Source rows from the form: [{source_id, locator, note}]; unknown sources and duplicates are dropped. */
function cleanSourceRefs(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $sid = optId($r, 'source_id');
        if (!$sid || isset($out[$sid]) || !existsRow('source', $sid)) {
            continue;
        }
        $out[$sid] = ['source_id' => $sid, 'locator' => optStr($r, 'locator', 100), 'note' => optStr($r, 'note', 500)];
    }
    return array_values($out);
}

function sourceRefRows(string $type, int $id): array
{
    return q('SELECT r.source_id, r.locator, r.note, s.code, s.title FROM source_ref r JOIN source s ON s.id = r.source_id
               WHERE r.entity_type = ? AND r.entity_id = ? ORDER BY r.id', [$type, $id]);
}

function saveSourceRefs(string $type, int $id, array $refs): void
{
    q('DELETE FROM source_ref WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
    foreach ($refs as $r) {
        q('INSERT INTO source_ref (source_id, entity_type, entity_id, locator, note) VALUES (?, ?, ?, ?, ?)',
          [$r['source_id'], $type, $id, $r['locator'], $r['note']]);
    }
}

/** Readable one-line forms of the sub-lists, used for the change-log diff. */
function subListSummary(string $type, int $id): array
{
    $codes = [];
    foreach (sourceRefRows($type, $id) as $r) {
        $codes[] = $r['code'] . ($r['locator'] ? ' ' . $r['locator'] : '');
    }
    $out = [
        'keywords' => keywordNames($type, $id),
        'links'    => array_map(fn($l) => $l['url'] . ($l['label'] ? " ({$l['label']})" : ''), linkRows($type, $id)),
        'sources'  => $codes,
    ];
    $contentLine = static fn(array $r, string $label) => $label . ($r['locator'] ? ', ' . $r['locator'] : '') . ($r['first'] ? ' (first)' : '');
    if ($type === 'edition') {
        $out['editors'] = array_column(editorRows($id), 'label');
        $out['contents'] = array_map(fn($r) => $contentLine($r, $r['work_label']), contentRows($id));
    } elseif ($type === 'publication') {
        $out['published_in'] = array_map(fn($r) => $contentLine($r, $r['edition_label']), publishedInRows($id));
    }
    return $out;
}

// ------------------------------------------------------------------ labels

function entryLabel(string $type, int $id): string
{
    switch ($type) {
        case 'person':
            return personName(q1('SELECT first_name, surname FROM person WHERE id = ?', [$id]));
        case 'event':
            return (string) (q1('SELECT name FROM event WHERE id = ?', [$id])['name'] ?? '');
        case 'period':
            return (string) (q1('SELECT name FROM period WHERE id = ?', [$id])['name'] ?? '');
        case 'publication':
            return (string) (q1('SELECT title FROM publication WHERE id = ?', [$id])['title'] ?? '');
        case 'edition':
            return (string) (q1('SELECT title FROM edition WHERE id = ?', [$id])['title'] ?? '');
        case 'source':
            $s = q1('SELECT code, title FROM source WHERE id = ?', [$id]);
            return $s ? $s['code'] . ' · ' . $s['title'] : '';
    }
    return '';
}

// ------------------------------------------------------------------ loading for the forms

/** The values an edit form shows for an existing entry (dates as smart-date text, sub-lists included). */
function loadEntry(string $type, int $id): ?array
{
    switch ($type) {
        case 'person':
            $r = q1('SELECT * FROM person WHERE id = ?', [$id]);
            if (!$r) {
                return null;
            }
            $r['birth'] = smartDateText($r['date_of_birth'], $r['birth_precision']);
            $r['death'] = smartDateText($r['date_of_death'], $r['death_precision']);
            $r['relations'] = relationRows($id);
            break;
        case 'event':
            $r = q1('SELECT e.*, p.ts, p.date_precision FROM event e JOIN pit p ON p.id = e.pit_id WHERE e.id = ?', [$id]);
            if (!$r) {
                return null;
            }
            $r['date'] = smartDateText($r['ts'], $r['date_precision']);
            break;
        case 'period':
            $r = q1('SELECT * FROM period WHERE id = ?', [$id]);
            if (!$r) {
                return null;
            }
            foreach (['start' => 'start_event_id', 'end' => 'end_event_id'] as $k => $col) {
                $m = q1('SELECT e.name, e.description, p.ts, p.date_precision FROM event e JOIN pit p ON p.id = e.pit_id WHERE e.id = ?', [$r[$col]]);
                $r[$k] = smartDateText($m['ts'] ?? null, $m['date_precision'] ?? null);
                $r[$k . '_name'] = $m['name'] ?? '';
                $r[$k . '_description'] = $m['description'] ?? '';
            }
            break;
        case 'publication':
            $r = q1('SELECT * FROM publication WHERE id = ?', [$id]);
            if (!$r) {
                return null;
            }
            $r['release'] = smartDateText($r['release_date'], $r['release_precision']);
            $r['published_in'] = publishedInRows($id);
            break;
        case 'edition':
            $r = q1('SELECT * FROM edition WHERE id = ?', [$id]);
            if (!$r) {
                return null;
            }
            $r['release'] = smartDateText($r['release_date'], $r['release_precision']);
            $r['editors'] = editorRows($id);
            $r['contents'] = contentRows($id);
            break;
        default:
            return null;
    }
    $r['keywords'] = keywordNames($type, $id);
    $r['links'] = linkRows($type, $id);
    $r['sources'] = sourceRefRows($type, $id);
    return $r;
}

/** Editors of a publication, in order: [{person_id, label}]. */
function editorRows(int $editionId): array
{
    return array_map(fn($r) => ['person_id' => (int) $r['id'], 'label' => personName($r)],
        q('SELECT p.id, p.first_name, p.surname FROM edition_editor x JOIN person p ON p.id = x.person_id
            WHERE x.edition_id = ? ORDER BY x.sort_order, p.surname', [$editionId]));
}

/** Works in a publication, in order: [{work_id, work_label, locator, first}]. */
function contentRows(int $editionId): array
{
    return array_map(fn($r) => ['work_id' => (int) $r['work_id'], 'work_label' => $r['title'], 'locator' => $r['locator'], 'first' => (bool) $r['first_publication']],
        q('SELECT x.work_id, x.locator, x.first_publication, w.title FROM edition_work x JOIN publication w ON w.id = x.work_id
            WHERE x.edition_id = ? ORDER BY x.sort_order, w.title', [$editionId]));
}

/** Publications a work appeared in, oldest first: [{edition_id, edition_label, locator, first}]. */
function publishedInRows(int $workId): array
{
    return array_map(fn($r) => ['edition_id' => (int) $r['edition_id'], 'edition_label' => $r['title'], 'locator' => $r['locator'], 'first' => (bool) $r['first_publication']],
        q('SELECT x.edition_id, x.locator, x.first_publication, e.title FROM edition_work x JOIN edition e ON e.id = x.edition_id
            WHERE x.work_id = ? ORDER BY e.release_date IS NULL, e.release_date, e.title', [$workId]));
}

/** Relations of a person from their point of view: [{id, role ("typeId:1|2"), other_id, other_label, status}]. */
function relationRows(int $personId): array
{
    $out = [];
    foreach (q('SELECT r.*, a.first_name AS a_first, a.surname AS a_sur, b.first_name AS b_first, b.surname AS b_sur
                  FROM relationship r JOIN person a ON a.id = r.person1_id JOIN person b ON b.id = r.person2_id
                 WHERE r.person1_id = ? OR r.person2_id = ? ORDER BY r.id', [$personId, $personId]) as $r) {
        $first = (int) $r['person1_id'] === $personId;
        $out[] = [
            'id'          => (int) $r['id'],
            'role'        => $r['relation_type_id'] . ':' . ($first ? '1' : '2'),
            'other_id'    => $first ? (int) $r['person2_id'] : (int) $r['person1_id'],
            'other_label' => $first ? personName(['first_name' => $r['b_first'], 'surname' => $r['b_sur']])
                                    : personName(['first_name' => $r['a_first'], 'surname' => $r['a_sur']]),
            'status'      => $r['relation_status'],
        ];
    }
    return $out;
}

/**
 * Choices for the relation dropdown, from this person's point of view:
 * "typeId:1" = this person is person 1 ("parent of …"), "typeId:2" = this person is person 2 ("child of …").
 */
function relationRoleOptions(): array
{
    $out = [];
    foreach (q('SELECT id, short_name, inverse_name FROM relation_type ORDER BY short_name') as $t) {
        $out[$t['id'] . ':1'] = $t['short_name'] . ' of';
        if ($t['inverse_name']) {
            $out[$t['id'] . ':2'] = $t['inverse_name'] . ' of';
        }
    }
    asort($out);
    return $out;
}

// ------------------------------------------------------------------ saving

/**
 * Validates and saves an entry. $id null = create. $version = row_version the form was loaded with (updates only).
 * $in: the posted form values. Returns the entry id. Throws ValidationError / ConflictError.
 */
function saveEntry(string $type, ?int $id, array $in, ?int $version = null): int
{
    $errors = [];
    $links = cleanLinks((array) ($in['links'] ?? []), $errors);
    $sources = cleanSourceRefs((array) ($in['sources'] ?? []));
    $keywords = cleanKeywords((array) ($in['keywords'] ?? []));
    if (!$sources) {
        $errors['sources'] = 'Every entry needs at least one source. Pick one, or create it with “+ New source”.';
    }

    $row = match ($type) {
        'person'      => validatePerson($id, $in, $errors),
        'event'       => validateEvent($id, $in, $errors),
        'period'      => validatePeriod($id, $in, $errors),
        'publication' => validatePublication($id, $in, $errors),
        'edition'     => validateEdition($id, $in, $errors),
        default       => throw new InvalidArgumentException("Unknown type $type"),
    };
    if ($errors) {
        throw new ValidationError($errors);
    }

    $wikiBefore = $id !== null ? firstWikipediaUrl($type, $id) : null;
    $db = getDb();
    $db->beginTransaction();
    try {
        $before = null;
        if ($id !== null) {
            $before = loadEntry($type, $id);
            if (!$before) {
                throw new ValidationError(['_' => 'This entry no longer exists (someone may have deleted it).']);
            }
            $beforeReadable = readableEntry($before);
            if ($version !== null && (int) $before['row_version'] !== $version) {
                throw new ConflictError('Someone else saved this entry while you were editing it.');
            }
            $beforeSubs = subListSummary($type, $id);
        }

        $id = match ($type) {
            'person'      => writePerson($id, $row),
            'event'       => writeEvent($id, $row),
            'period'      => writePeriod($id, $row),
            'publication' => writePublication($id, $row),
            'edition'     => writeEdition($id, $row),
        };
        saveKeywords($type, $id, $keywords);
        saveLinks($type, $id, $links);
        saveSourceRefs($type, $id, $sources);
        // The person form posts relations_form=1, so removing the last relation is saved too.
        // (Quick-create dialogs don't, and leave relations alone.)
        if ($type === 'person' && (!empty($in['relations_form']) || array_key_exists('relations', $in))) {
            savePersonRelations($id, $row['_relations']);
        }
        if ($type === 'edition') {
            saveEditionLists($id, $row['_editors'], $row['_contents']);
        }
        // Like relations: only the full work form (published_in_form=1) changes where a work was published.
        if ($type === 'publication' && isset($row['_published_in'])) {
            saveWorkEditions($id, $row['_published_in']);
        }

        $uid = currentUser()['id'] ?? null;
        q("UPDATE $type SET row_version = row_version + 1, updated_at = NOW(), updated_by = ? WHERE id = ?", [$uid, $id]);

        $after = loadEntry($type, $id);
        $afterReadable = readableEntry($after);
        $fields = FORM_FIELDS[$type];
        if ($before === null) {
            logChange($type, $id, 'create', entryLabel($type, $id),
                array_filter(array_intersect_key($afterReadable, array_flip($fields)) + subListSummary($type, $id), fn($v) => $v !== null && $v !== '' && $v !== []));
        } else {
            $changes = diffRecords($beforeReadable, $afterReadable, $fields)
                     + diffRecords($beforeSubs, subListSummary($type, $id), array_keys($beforeSubs));
            if ($type === 'person') {
                $changes += diffRecords(['relations' => relationSummary($before['relations'])], ['relations' => relationSummary($after['relations'])], ['relations']);
            }
            logChange($type, $id, 'update', entryLabel($type, $id), $changes);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    // The picture comes from the first Wikipedia link; fetched after the commit, as it needs the internet.
    lastImageResult(refreshEntryImage($type, $id, $wikiBefore));
    return $id;
}

/** What happened to the picture in the last saveEntry(): stored, none, removed, unchanged or error. */
function lastImageResult(?string $set = null): ?string
{
    static $last = null;
    if ($set !== null) {
        $last = $set;
    }
    return $last;
}

/** The form fields compared for the change log, per type (dates in their readable form). */
const FORM_FIELDS = [
    'person'      => ['first_name', 'surname', 'shortcode', 'birth', 'place_of_birth', 'death'],
    'event'       => ['name', 'short_name', 'description', 'date', 'track_id', 'parent_period_id', 'person1_id', 'person2_id'],
    'period'      => ['name', 'description', 'track_id', 'parent_period_id', 'start', 'start_name', 'start_description', 'end', 'end_name', 'end_description'],
    'publication' => ['title', 'short_title', 'shortcode', 'category_id', 'author_id', 'author2_id', 'author3_id', 'parent_work_id', 'release', 'gestation_period_id', 'description'],
    'edition'     => ['title', 'kind', 'release', 'publisher', 'place', 'isbn', 'description'],
];

/** Fields that hold the id of another entry, and what they point to. */
const REF_FIELDS = [
    'track_id' => 'track', 'parent_period_id' => 'period', 'gestation_period_id' => 'period',
    'person1_id' => 'person', 'person2_id' => 'person', 'author_id' => 'person', 'author2_id' => 'person',
    'author3_id' => 'person', 'parent_work_id' => 'publication', 'category_id' => 'pub_category',
];

/** The entry with ids of linked entries replaced by their names, so the change log stays readable. */
function readableEntry(array $r): array
{
    foreach (REF_FIELDS as $field => $refType) {
        if (empty($r[$field])) {
            continue;
        }
        $id = (int) $r[$field];
        $r[$field] = match ($refType) {
            'track'        => trackName($id),
            'pub_category' => q1('SELECT category FROM pub_category WHERE id = ?', [$id])['category'] ?? null,
            default        => entryLabel($refType, $id),
        } ?? "#$id";
    }
    if (isset($r['kind'], EDITION_KINDS[$r['kind']])) {
        $r['kind'] = EDITION_KINDS[$r['kind']];
    }
    return $r;
}

function relationSummary(array $rels): array
{
    $roles = relationRoleOptions();
    $out = array_map(fn($r) => ($roles[$r['role']] ?? $r['role']) . ' ' . $r['other_label'] . ($r['status'] ? " ({$r['status']})" : ''), $rels);
    sort($out);
    return $out;
}

// ---- person

function validatePerson(?int $id, array $in, array &$errors): array
{
    $row = [
        'first_name'     => str($in, 'first_name', 100),
        'surname'        => str($in, 'surname', 100),
        'shortcode'      => strtoupper(str($in, 'shortcode', 32)),
        'place_of_birth' => optStr($in, 'place_of_birth', 150),
    ];
    if ($row['first_name'] === '' && $row['surname'] === '') {
        $errors['surname'] = 'Please enter at least a surname.';
    }
    if ($row['shortcode'] === '') {
        $row['shortcode'] = suggestShortcode($row['first_name'], $row['surname'], $id);
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $row['shortcode'])) {
        $errors['shortcode'] = 'The short code may only contain letters, digits, dash and underscore.';
    } elseif (q1('SELECT id FROM person WHERE shortcode = ? AND id <> ?', [$row['shortcode'], $id ?? 0])) {
        $errors['shortcode'] = "The short code {$row['shortcode']} is already used by another person.";
    }
    $birth = smartDate($in, 'birth', $errors, false, 'date of birth');
    $death = smartDate($in, 'death', $errors, false, 'date of death');
    if ($birth && $death && $death['date'] < $birth['date']) {
        $errors['death'] = 'The date of death is before the date of birth.';
    }
    $row += ['date_of_birth' => $birth['date'] ?? null, 'birth_precision' => $birth['precision'] ?? 'day',
             'date_of_death' => $death['date'] ?? null, 'death_precision' => $death['precision'] ?? 'day'];

    // Relations: [{role: "typeId:1|2", other_id, status}]
    $rels = [];
    foreach ((array) ($in['relations'] ?? []) as $i => $r) {
        $other = optId($r, 'other_id');
        $role = (string) ($r['role'] ?? '');
        if (!$other && $role === '') {
            continue;
        }
        if (!preg_match('/^(\d+):([12])$/', $role, $m) || !existsRow('relation_type', (int) $m[1])) {
            $errors['relations'] = 'Please choose the kind of every relation.';
            continue;
        }
        if (!$other || !existsRow('person', $other)) {
            $errors['relations'] = 'Please choose the other person of every relation.';
            continue;
        }
        if ($id !== null && $other === $id) {
            $errors['relations'] = 'A person cannot be related to themselves.';
            continue;
        }
        $rels[] = ['type_id' => (int) $m[1], 'self_first' => $m[2] === '1', 'other_id' => $other, 'status' => optStr($r, 'status', 50)];
    }
    $row['_relations'] = $rels;
    return $row;
}

/** Initials of the name, e.g. "John Ronald Reuel Tolkien" -> JRRT; made unique with a number. */
function suggestShortcode(string $first, string $surname, ?int $exceptId = null): string
{
    $words = preg_split('/[\s\-]+/u', trim(preg_replace('/\(.*?\)/u', '', "$first $surname")), -1, PREG_SPLIT_NO_EMPTY);
    $code = '';
    foreach ($words as $w) {
        $code .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    $code = preg_replace('/[^A-Z0-9]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $code) ?: $code) ?: 'P';
    $try = $code;
    for ($n = 2; q1('SELECT id FROM person WHERE shortcode = ? AND id <> ?', [$try, $exceptId ?? 0]); $n++) {
        $try = $code . $n;
    }
    return $try;
}

function writePerson(?int $id, array $row): int
{
    $cols = ['first_name', 'surname', 'shortcode', 'date_of_birth', 'birth_precision', 'place_of_birth', 'date_of_death', 'death_precision'];
    $vals = array_map(fn($c) => $row[$c], $cols);
    if ($id === null) {
        q('INSERT INTO person (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
        return (int) getDb()->lastInsertId();
    }
    q('UPDATE person SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?', [...$vals, $id]);
    return $id;
}

function savePersonRelations(int $id, array $rels): void
{
    q('DELETE FROM relationship WHERE person1_id = ? OR person2_id = ?', [$id, $id]);
    foreach ($rels as $r) {
        [$p1, $p2] = $r['self_first'] ? [$id, $r['other_id']] : [$r['other_id'], $id];
        q('INSERT INTO relationship (person1_id, person2_id, relation_type_id, relation_status) VALUES (?, ?, ?, ?)', [$p1, $p2, $r['type_id'], $r['status']]);
    }
}

// ---- event

function validateEvent(?int $id, array $in, array &$errors): array
{
    if ($id !== null && (int) (q1('SELECT is_marker FROM event WHERE id = ?', [$id])['is_marker'] ?? 0) === 1) {
        $errors['_'] = 'This event marks the start or end of a period; edit it in the period.';
    }
    $row = [
        'name'             => str($in, 'name', 200),
        'short_name'       => optStr($in, 'short_name', 80),
        'description'      => optStr($in, 'description'),
        'track_id'         => optId($in, 'track_id'),
        'parent_period_id' => optId($in, 'parent_period_id'),
        'person1_id'       => optId($in, 'person1_id'),
        'person2_id'       => optId($in, 'person2_id'),
    ];
    if ($row['name'] === '') {
        $errors['name'] = 'Please enter a name.';
    }
    $row['_date'] = smartDate($in, 'date', $errors, true, 'date');
    if ($row['track_id'] !== null && !existsRow('track', $row['track_id'])) {
        $errors['track_id'] = 'Please choose a lane.';
    }
    if ($row['parent_period_id'] !== null && !existsRow('period', $row['parent_period_id'])) {
        $errors['parent_period_id'] = 'That period does not exist.';
    }
    foreach (['person1_id', 'person2_id'] as $k) {
        if ($row[$k] !== null && !existsRow('person', $row[$k])) {
            $errors[$k] = 'That person does not exist.';
        }
    }
    return $row;
}

/** Creates or updates the point in time of an event; returns the pit id. */
function writePit(?int $pitId, array $date): int
{
    if ($pitId === null) {
        q('INSERT INTO pit (name, date_precision, ts) VALUES (?, ?, ?)', [$date['label'], $date['precision'], $date['date']]);
        return (int) getDb()->lastInsertId();
    }
    q('UPDATE pit SET name = ?, date_precision = ?, ts = ? WHERE id = ?', [$date['label'], $date['precision'], $date['date'], $pitId]);
    return $pitId;
}

function writeEvent(?int $id, array $row): int
{
    $pitId = $id === null ? null : (int) q1('SELECT pit_id FROM event WHERE id = ?', [$id])['pit_id'];
    $pitId = writePit($pitId, $row['_date']);
    $cols = ['name', 'short_name', 'description', 'track_id', 'parent_period_id', 'person1_id', 'person2_id'];
    $vals = array_map(fn($c) => $row[$c], $cols);
    if ($id === null) {
        q('INSERT INTO event (pit_id, is_marker, ' . implode(', ', $cols) . ') VALUES (?, 0, ' . implode(', ', array_fill(0, count($cols), '?')) . ')', [$pitId, ...$vals]);
        return (int) getDb()->lastInsertId();
    }
    q('UPDATE event SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?', [...$vals, $id]);
    return $id;
}

// ---- period

function validatePeriod(?int $id, array $in, array &$errors): array
{
    $row = [
        'name'              => str($in, 'name', 200),
        'description'       => optStr($in, 'description'),
        'track_id'          => optId($in, 'track_id'),
        'parent_period_id'  => optId($in, 'parent_period_id'),
        'start_name'        => optStr($in, 'start_name', 200),
        'start_description' => optStr($in, 'start_description'),
        'end_name'          => optStr($in, 'end_name', 200),
        'end_description'   => optStr($in, 'end_description'),
    ];
    if ($row['name'] === '') {
        $errors['name'] = 'Please enter a name.';
    }
    $row['_start'] = smartDate($in, 'start', $errors, true, 'start date');
    $row['_end'] = smartDate($in, 'end', $errors, true, 'end date');
    if ($row['_start'] && $row['_end'] && $row['_end']['date'] < $row['_start']['date']) {
        $errors['end'] = 'The period ends before it starts.';
    }
    if ($row['track_id'] !== null && !existsRow('track', $row['track_id'])) {
        $errors['track_id'] = 'Please choose a lane.';
    }
    if ($row['parent_period_id'] !== null) {
        if (!existsRow('period', $row['parent_period_id'])) {
            $errors['parent_period_id'] = 'That period does not exist.';
        } elseif ($id !== null && in_array($row['parent_period_id'], [$id, ...descendantPeriods($id)], true)) {
            $errors['parent_period_id'] = 'A period cannot be part of itself or of one of its own sub-periods.';
        }
    }
    return $row;
}

/** Ids of all sub-periods (any depth). */
function descendantPeriods(int $id): array
{
    $out = [];
    $queue = [$id];
    while ($queue) {
        $children = array_map('intval', array_column(q('SELECT id FROM period WHERE parent_period_id = ?', [array_shift($queue)]), 'id'));
        foreach (array_diff($children, $out) as $c) {
            $out[] = $c;
            $queue[] = $c;
        }
    }
    return $out;
}

function writePeriod(?int $id, array $row): int
{
    $ids = $id === null ? null : q1('SELECT start_event_id, end_event_id FROM period WHERE id = ?', [$id]);
    // The start/end markers belong to the period: same lane, hidden on the timeline (is_marker = 1).
    $marker = function (?int $eventId, array $date, ?string $name, ?string $desc, string $default) use ($row): int {
        $name = $name ?: $default;
        if ($eventId === null) {
            $pit = writePit(null, $date);
            q('INSERT INTO event (name, description, pit_id, track_id, is_marker) VALUES (?, ?, ?, ?, 1)', [$name, $desc, $pit, $row['track_id']]);
            return (int) getDb()->lastInsertId();
        }
        writePit((int) q1('SELECT pit_id FROM event WHERE id = ?', [$eventId])['pit_id'], $date);
        q('UPDATE event SET name = ?, description = ?, track_id = ? WHERE id = ?', [$name, $desc, $row['track_id'], $eventId]);
        return $eventId;
    };
    $start = $marker($ids ? (int) $ids['start_event_id'] : null, $row['_start'], $row['start_name'], $row['start_description'], 'Start of ' . $row['name']);
    $end = $marker($ids ? (int) $ids['end_event_id'] : null, $row['_end'], $row['end_name'], $row['end_description'], 'End of ' . $row['name']);

    $cols = ['name', 'description', 'track_id', 'parent_period_id'];
    $vals = array_map(fn($c) => $row[$c], $cols);
    if ($id === null) {
        q('INSERT INTO period (start_event_id, end_event_id, name, description, track_id, parent_period_id) VALUES (?, ?, ?, ?, ?, ?)', [$start, $end, ...$vals]);
        return (int) getDb()->lastInsertId();
    }
    q('UPDATE period SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?', [...$vals, $id]);
    return $id;
}

// ---- publication

function validatePublication(?int $id, array $in, array &$errors): array
{
    $row = [
        'title'               => str($in, 'title', 300),
        'short_title'         => optStr($in, 'short_title', 100),
        'shortcode'           => ($c = optStr($in, 'shortcode', 32)) !== null ? strtoupper($c) : null,
        'category_id'         => optId($in, 'category_id'),
        'author_id'           => optId($in, 'author_id'),
        'author2_id'          => optId($in, 'author2_id'),
        'author3_id'          => optId($in, 'author3_id'),
        'parent_work_id'      => optId($in, 'parent_work_id'),
        'gestation_period_id' => optId($in, 'gestation_period_id'),
        'description'         => optStr($in, 'description'),
    ];
    if ($row['title'] === '') {
        $errors['title'] = 'Please enter a title.';
    }
    if ($row['shortcode'] !== null) {
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $row['shortcode'])) {
            $errors['shortcode'] = 'The short code may only contain letters, digits, dash and underscore.';
        } elseif (q1('SELECT id FROM publication WHERE shortcode = ? AND id <> ?', [$row['shortcode'], $id ?? 0])) {
            $errors['shortcode'] = "The short code {$row['shortcode']} is already used by another work.";
        }
    }
    $release = smartDate($in, 'release', $errors, false, 'start date');
    $row['release_date'] = $release['date'] ?? null;
    $row['release_precision'] = $release['precision'] ?? 'day';
    if ($row['category_id'] !== null && !existsRow('pub_category', $row['category_id'])) {
        $errors['category_id'] = 'That category does not exist.';
    }
    foreach (['author_id', 'author2_id', 'author3_id'] as $k) {
        if ($row[$k] !== null && !existsRow('person', $row[$k])) {
            $errors[$k] = 'That person does not exist.';
        }
    }
    if ($row['parent_work_id'] !== null && (!existsRow('publication', $row['parent_work_id']) || $row['parent_work_id'] === $id)) {
        $errors['parent_work_id'] = $row['parent_work_id'] === $id ? 'A work cannot be part of itself.' : 'That work does not exist.';
    }
    if ($row['gestation_period_id'] !== null && !existsRow('period', $row['gestation_period_id'])) {
        $errors['gestation_period_id'] = 'That period does not exist.';
    }
    if (!empty($in['published_in_form'])) {
        $rows = [];
        foreach ((array) ($in['published_in'] ?? []) as $r) {
            $eid = optId($r, 'edition_id');
            if (!$eid) {
                continue;
            }
            if (!existsRow('edition', $eid)) {
                $errors['published_in'] = 'One of the publications does not exist (any more).';
                continue;
            }
            $rows[$eid] = ['edition_id' => $eid, 'locator' => optStr($r, 'locator', 100), 'first' => !empty($r['first'])];
        }
        if (count(array_filter($rows, fn($r) => $r['first'])) > 1) {
            $errors['published_in'] = 'A work can be first published in only one publication.';
        }
        $row['_published_in'] = array_values($rows);
    }
    return $row;
}

function writePublication(?int $id, array $row): int
{
    $cols = ['title', 'short_title', 'shortcode', 'category_id', 'author_id', 'author2_id', 'author3_id', 'parent_work_id',
             'release_date', 'release_precision', 'gestation_period_id', 'description'];
    $vals = array_map(fn($c) => $row[$c], $cols);
    if ($id === null) {
        q('INSERT INTO publication (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
        return (int) getDb()->lastInsertId();
    }
    q('UPDATE publication SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?', [...$vals, $id]);
    return $id;
}

// ---- edition (a publication: book, collection, journal issue ... that contains works)

function validateEdition(?int $id, array $in, array &$errors): array
{
    $row = [
        'title'       => str($in, 'title', 300),
        'kind'        => (string) ($in['kind'] ?? 'book'),
        'publisher'   => optStr($in, 'publisher', 200),
        'place'       => optStr($in, 'place', 100),
        'isbn'        => optStr($in, 'isbn', 17),
        'description' => optStr($in, 'description'),
    ];
    if ($row['title'] === '') {
        $errors['title'] = 'Please enter a title.';
    }
    if (!isset(EDITION_KINDS[$row['kind']])) {
        $errors['kind'] = 'Please choose the kind of publication.';
    }
    if ($row['isbn'] !== null && !preg_match('/^[0-9][0-9\- ]{8,15}[0-9Xx]$/', $row['isbn'])) {
        $errors['isbn'] = 'This does not look like an ISBN (10 or 13 digits, dashes allowed).';
    }
    $release = smartDate($in, 'release', $errors, false, 'publication date');
    $row['release_date'] = $release['date'] ?? null;
    $row['release_precision'] = $release['precision'] ?? 'day';

    $editors = [];
    foreach ((array) ($in['editors'] ?? []) as $r) {
        $pid = optId($r, 'person_id');
        if (!$pid) {
            continue;
        }
        if (!existsRow('person', $pid)) {
            $errors['editors'] = 'One of the editors does not exist (any more).';
            continue;
        }
        $editors[$pid] = $pid;
    }
    $row['_editors'] = array_values($editors);

    $contents = [];
    foreach ((array) ($in['contents'] ?? []) as $r) {
        $wid = optId($r, 'work_id');
        if (!$wid) {
            continue;
        }
        if (!existsRow('publication', $wid)) {
            $errors['contents'] = 'One of the works does not exist (any more).';
            continue;
        }
        $contents[$wid] = ['work_id' => $wid, 'locator' => optStr($r, 'locator', 100), 'first' => !empty($r['first'])];
    }
    // A work is first published in one place only.
    foreach ($contents as $c) {
        if ($c['first'] && ($other = q1('SELECT e.title FROM edition_work x JOIN edition e ON e.id = x.edition_id
                                          WHERE x.work_id = ? AND x.first_publication = 1 AND x.edition_id <> ?', [$c['work_id'], $id ?? 0]))) {
            $errors['contents'] = '“' . entryLabel('publication', $c['work_id']) . '” is already marked as first published in “'
                                . $other['title'] . '”. Untick it there first, or untick it here.';
        }
    }
    $row['_contents'] = array_values($contents);
    return $row;
}

function writeEdition(?int $id, array $row): int
{
    $cols = ['kind', 'title', 'publisher', 'place', 'release_date', 'release_precision', 'isbn', 'description'];
    $vals = array_map(fn($c) => $row[$c], $cols);
    if ($id === null) {
        q('INSERT INTO edition (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
        return (int) getDb()->lastInsertId();
    }
    q('UPDATE edition SET ' . implode(' = ?, ', $cols) . ' = ? WHERE id = ?', [...$vals, $id]);
    return $id;
}

/** Editors and contents of a publication, in the order of the form. */
function saveEditionLists(int $id, array $editors, array $contents): void
{
    q('DELETE FROM edition_editor WHERE edition_id = ?', [$id]);
    foreach ($editors as $i => $pid) {
        q('INSERT INTO edition_editor (edition_id, person_id, sort_order) VALUES (?, ?, ?)', [$id, $pid, ($i + 1) * 10]);
    }
    q('DELETE FROM edition_work WHERE edition_id = ?', [$id]);
    foreach ($contents as $i => $c) {
        q('INSERT INTO edition_work (edition_id, work_id, locator, first_publication, sort_order) VALUES (?, ?, ?, ?, ?)',
          [$id, $c['work_id'], $c['locator'], $c['first'] ? 1 : 0, ($i + 1) * 10]);
    }
}

/** Where a work was published, from the work's form. Keeps the work's place in each publication's table of contents. */
function saveWorkEditions(int $workId, array $rows): void
{
    $order = [];
    foreach (q('SELECT edition_id, sort_order FROM edition_work WHERE work_id = ?', [$workId]) as $r) {
        $order[(int) $r['edition_id']] = (int) $r['sort_order'];
    }
    q('DELETE FROM edition_work WHERE work_id = ?', [$workId]);
    foreach ($rows as $r) {
        $sort = $order[$r['edition_id']]
            ?? (int) (q1('SELECT COALESCE(MAX(sort_order), 0) + 10 AS s FROM edition_work WHERE edition_id = ?', [$r['edition_id']])['s']);
        q('INSERT INTO edition_work (edition_id, work_id, locator, first_publication, sort_order) VALUES (?, ?, ?, ?, ?)',
          [$r['edition_id'], $workId, $r['locator'], $r['first'] ? 1 : 0, $sort]);
    }
}

// ------------------------------------------------------------------ deleting

/**
 * What deleting an entry would affect: ['blockers' => [text], 'effects' => [text]].
 * Blockers prevent the delete; effects are shown so the editor knows what changes.
 */
function deleteImpact(string $type, int $id): array
{
    $blockers = [];
    $effects = [];
    $names = static fn(array $rows, string $col = 'name') => implode(', ', array_map(fn($r) => '“' . $r[$col] . '”', $rows));
    switch ($type) {
        case 'person':
            if ($w = q('SELECT title FROM publication WHERE ? IN (author_id, author2_id, author3_id)', [$id])) {
                $blockers[] = 'This person is the author of ' . $names($w, 'title') . '. Change the author there first.';
            }
            if ($s = q('SELECT code AS name FROM source WHERE author_person_id = ?', [$id])) {
                $blockers[] = 'This person is linked as the author of the source(s) ' . $names($s) . '.';
            }
            if ($e = q('SELECT name FROM event WHERE ? IN (person1_id, person2_id)', [$id])) {
                $effects[] = 'The person is removed from these events: ' . $names($e) . '.';
            }
            if ($e = q('SELECT e.title FROM edition_editor x JOIN edition e ON e.id = x.edition_id WHERE x.person_id = ?', [$id])) {
                $effects[] = 'The person is removed as editor of: ' . $names($e, 'title') . '.';
            }
            if ($n = count(relationRows($id))) {
                $effects[] = "$n relation" . ($n === 1 ? '' : 's') . ' to other people will be deleted.';
            }
            break;
        case 'event':
            if ((int) (q1('SELECT is_marker FROM event WHERE id = ?', [$id])['is_marker'] ?? 0) === 1) {
                $blockers[] = 'This event marks the start or end of a period. Delete or change the period instead.';
            }
            break;
        case 'period':
            if ($c = q('SELECT name FROM period WHERE parent_period_id = ?', [$id])) {
                $effects[] = 'These sub-periods will no longer be part of it: ' . $names($c) . '.';
            }
            if ($c = q('SELECT name FROM event WHERE parent_period_id = ? AND is_marker = 0', [$id])) {
                $effects[] = 'These events will no longer be part of it: ' . $names($c) . '.';
            }
            if ($w = q('SELECT title FROM publication WHERE gestation_period_id = ?', [$id])) {
                $effects[] = 'These works lose their writing period: ' . $names($w, 'title') . '.';
            }
            $effects[] = 'Its start and end events are deleted with it.';
            break;
        case 'publication':
            if ($c = q('SELECT title FROM publication WHERE parent_work_id = ?', [$id])) {
                $effects[] = 'These works will no longer be part of it: ' . $names($c, 'title') . '.';
            }
            if ($s = q('SELECT code AS name FROM source WHERE publication_id = ?', [$id])) {
                $effects[] = 'These sources will no longer point to it: ' . $names($s) . '.';
            }
            if ($e = q('SELECT e.title FROM edition_work x JOIN edition e ON e.id = x.edition_id WHERE x.work_id = ?', [$id])) {
                $effects[] = 'It is removed from the contents of these publications: ' . $names($e, 'title') . '.';
            }
            break;
        case 'edition':
            if ($w = q('SELECT w.title FROM edition_work x JOIN publication w ON w.id = x.work_id WHERE x.edition_id = ?', [$id])) {
                $effects[] = 'These works will no longer list it under “Published in”: ' . $names($w, 'title') . '. The works themselves stay.';
            }
            break;
    }
    return ['blockers' => $blockers, 'effects' => $effects];
}

/** Deletes an entry with its keywords, links, citations and picture. Throws ValidationError if blocked. */
function deleteEntry(string $type, int $id): void
{
    $impact = deleteImpact($type, $id);
    if ($impact['blockers']) {
        throw new ValidationError(['_' => implode(' ', $impact['blockers'])]);
    }
    $db = getDb();
    $db->beginTransaction();
    try {
        $before = loadEntry($type, $id);
        if (!$before) {
            throw new ValidationError(['_' => 'This entry no longer exists.']);
        }
        $label = entryLabel($type, $id);
        $snapshot = array_filter(array_intersect_key(readableEntry($before), array_flip(FORM_FIELDS[$type])) + subListSummary($type, $id),
                                 fn($v) => $v !== null && $v !== '' && $v !== []);
        $cleanup = static function (string $t, int $i): void {
            q('DELETE FROM weblink WHERE entity_type = ? AND entity_id = ?', [$t, $i]);
            q('DELETE FROM source_ref WHERE entity_type = ? AND entity_id = ?', [$t, $i]);
            foreach (q('SELECT local_path FROM image WHERE entity_type = ? AND entity_id = ?', [$t, $i]) as $img) {
                $file = dirname(__DIR__, 2) . '/' . $img['local_path'];
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            q('DELETE FROM image WHERE entity_type = ? AND entity_id = ?', [$t, $i]);
        };
        $cleanup($type, $id);

        switch ($type) {
            case 'person':
                q('DELETE FROM person WHERE id = ?', [$id]);   // relations cascade, events get NULL
                break;
            case 'event':
                $pit = (int) $before['pit_id'];
                q('DELETE FROM event WHERE id = ?', [$id]);    // keywords cascade
                q('DELETE FROM pit WHERE id = ? AND NOT EXISTS (SELECT 1 FROM event WHERE pit_id = ?)', [$pit, $pit]);
                break;
            case 'period':
                q('DELETE FROM period WHERE id = ?', [$id]);   // children/works get NULL, keywords cascade
                foreach ([(int) $before['start_event_id'], (int) $before['end_event_id']] as $m) {
                    $cleanup('event', $m);
                    $pit = (int) (q1('SELECT pit_id FROM event WHERE id = ?', [$m])['pit_id'] ?? 0);
                    q('DELETE FROM event WHERE id = ? AND is_marker = 1
                         AND NOT EXISTS (SELECT 1 FROM period WHERE start_event_id = ? OR end_event_id = ?)', [$m, $m, $m]);
                    q('DELETE FROM pit WHERE id = ? AND NOT EXISTS (SELECT 1 FROM event WHERE pit_id = ?)', [$pit, $pit]);
                }
                break;
            case 'publication':
                q('DELETE FROM publication WHERE id = ?', [$id]);   // edition_work rows cascade
                break;
            case 'edition':
                q('DELETE FROM edition WHERE id = ?', [$id]);       // editors, contents and keywords cascade
                break;
        }
        logChange($type, $id, 'delete', $label, $snapshot);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ------------------------------------------------------------------ sources (quick-create now, full library later)

const SOURCE_FIELDS = ['code', 'source_type', 'title', 'author', 'editor', 'published', 'publisher', 'place', 'isbn',
                       'newspaper', 'page', 'language', 'accessed', 'url', 'wikipedia_url', 'notes'];

/** Validates and creates a source; the same required fields as the database CHECK rules. Returns the id. */
function createSource(array $in): int
{
    $errors = [];
    $type = (string) ($in['source_type'] ?? '');
    if (!isset(SOURCE_TYPE_LABELS[$type])) {
        throw new ValidationError(['source_type' => 'Please choose the kind of source.']);
    }
    $row = [
        'source_type' => $type,
        'title'       => str($in, 'title', 500),
        'author'      => optStr($in, 'author', 300),
        'editor'      => optStr($in, 'editor', 300),
        'publisher'   => optStr($in, 'publisher', 200),
        'place'       => optStr($in, 'place', 100),
        'isbn'        => optStr($in, 'isbn', 17),
        'newspaper'   => optStr($in, 'newspaper', 200),
        'page'        => optStr($in, 'page', 50),
        'language'    => ($l = optStr($in, 'language', 2)) !== null ? strtolower($l) : null,
        'url'         => optStr($in, 'url', 500),
        'wikipedia_url' => optStr($in, 'wikipedia_url', 500),
        'notes'       => optStr($in, 'notes'),
    ];
    $published = smartDate($in, 'published', $errors, $type !== 'wikipedia', 'publication date');
    $row['published_date'] = $published['date'] ?? null;
    $row['published_precision'] = $published['precision'] ?? 'day';
    $accessed = null;
    if ($type === 'wikipedia') {
        $accessed = str($in, 'accessed') === '' ? ['date' => date('Y-m-d')] : smartDate($in, 'accessed', $errors, true, 'date you read the article');
    }
    $row['accessed_date'] = $accessed['date'] ?? null;

    if ($row['title'] === '') {
        $errors['title'] = $type === 'newspaper' ? 'Please enter the headline.' : 'Please enter the title.';
    }
    foreach (['url', 'wikipedia_url'] as $k) {
        if ($row[$k] !== null && !preg_match('~^https?://\S+$~i', $row[$k])) {
            $errors[$k] = 'This is not a valid web address (it must start with http:// or https://).';
        }
    }
    switch ($type) {
        case 'book':
            if ($row['author'] === null) {
                $errors['author'] = 'A book needs an author (or editor, as printed on the title page).';
            }
            if ($row['publisher'] === null) {
                $errors['publisher'] = 'A book needs a publisher.';
            }
            if ($row['isbn'] === null && $row['published_date'] !== null && $row['published_date'] >= '1970-01-01') {
                $errors['isbn'] = 'Books published from 1970 on need an ISBN.';
            } elseif ($row['isbn'] !== null && !preg_match('/^[0-9][0-9\- ]{8,15}[0-9Xx]$/', $row['isbn'])) {
                $errors['isbn'] = 'This does not look like an ISBN (10 or 13 digits, dashes allowed).';
            }
            break;
        case 'newspaper':
            if ($row['newspaper'] === null) {
                $errors['newspaper'] = 'Please enter the name of the newspaper.';
            }
            break;
        case 'wikipedia':
            if ($row['url'] === null) {
                $errors['url'] = 'Please paste the address of the Wikipedia article.';
            } elseif ($row['language'] === null && preg_match('~^https?://([a-z]{2})\.(?:m\.)?wikipedia\.org/~i', $row['url'], $m)) {
                $row['language'] = strtolower($m[1]);
            }
            if ($row['language'] === null || !preg_match('/^[a-z]{2}$/', $row['language'])) {
                $errors['language'] = 'Please enter the two-letter language of the Wikipedia (e.g. en, de).';
            }
            break;
    }
    $row['code'] = strtoupper(str($in, 'code', 32));
    if ($row['code'] === '') {
        $row['code'] = suggestSourceCode($row);
    } elseif (!preg_match('/^[A-Z0-9][A-Z0-9_-]*$/', $row['code'])) {
        $errors['code'] = 'The code may only contain letters, digits, dash and underscore.';
    } elseif (q1('SELECT id FROM source WHERE code = ?', [$row['code']])) {
        $errors['code'] = "The code {$row['code']} is already used by another source.";
    }
    if ($errors) {
        throw new ValidationError($errors);
    }

    $cols = array_keys($row);
    q('INSERT INTO source (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', array_values($row));
    $id = (int) getDb()->lastInsertId();
    logChange('source', $id, 'create', $row['code'] . ' · ' . $row['title'], array_filter($row, fn($v) => $v !== null && $v !== ''));
    return $id;
}

/** W-GULF-OF-TONKIN-INCIDENT style codes for Wikipedia, else the initials of the title plus the year. */
function suggestSourceCode(array $row): string
{
    $ascii = static fn(string $s) => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT', $s) ?: $s));
    if ($row['source_type'] === 'wikipedia') {
        $base = 'W-' . trim(substr($ascii($row['title']), 0, 28), '-');
    } elseif ($row['source_type'] === 'newspaper') {
        $paper = preg_replace('/^THE-/', '', $ascii((string) $row['newspaper']));
        $initials = implode('', array_map(fn($w) => $w[0] ?? '', array_filter(explode('-', $paper))));
        $base = substr($initials, 0, 6) . '-' . ($row['published_date'] ?? date('Y-m-d'));
    } else {
        $words = array_filter(explode('-', $ascii(preg_replace('/^(the|a|an)\s+/i', '', $row['title']))));
        $base = substr(implode('', array_map(fn($w) => $w[0], $words)), 0, 6) . ($row['published_date'] ? '-' . substr($row['published_date'], 0, 4) : '');
    }
    $base = trim($base, '-') ?: 'SRC';
    $try = $base;
    for ($n = 2; q1('SELECT id FROM source WHERE code = ?', [$try]); $n++) {
        $try = substr($base, 0, 29) . '-' . $n;
    }
    return $try;
}
