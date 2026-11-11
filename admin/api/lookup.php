<?php
// GET ?type=person|event|period|publication|edition|source|keyword&q=text[&exclude=id]
//  -> { results: [{id, label, sub}] } for the search-as-you-type dropdowns (max. 20, best matches first).
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
requireLogin();

const LOOKUP_LIMIT = 20;

$type = (string) ($_GET['type'] ?? '');
$term = trim((string) ($_GET['q'] ?? ''));
$exclude = (int) ($_GET['exclude'] ?? 0);
$like = '%' . likeEscape($term) . '%';
$start = likeEscape($term) . '%';

try {
    $results = match ($type) {
        'person' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'label' => personName($r),
                'sub' => trim(($r['date_of_birth'] ? formatDate($r['date_of_birth'], $r['birth_precision']) : '?') . ' – '
                       . ($r['date_of_death'] ? formatDate($r['date_of_death'], $r['death_precision']) : ''), ' –') . ' · ' . $r['shortcode'],
            ], q("SELECT id, first_name, surname, shortcode, date_of_birth, birth_precision, date_of_death, death_precision FROM person
                   WHERE id <> ? AND (CONCAT(first_name, ' ', surname) LIKE ? OR surname LIKE ? OR shortcode = ?)
                   ORDER BY (surname LIKE ?) DESC, surname, first_name LIMIT " . LOOKUP_LIMIT, [$exclude, $like, $like, $term, $start])),

        'event' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'label' => $r['name'],
                'sub' => formatDate($r['ts'], $r['date_precision']) . ($r['track'] ? ' · ' . $r['track'] : ''),
            ], q("SELECT e.id, e.name, p.ts, p.date_precision, t.name AS track FROM event e JOIN pit p ON p.id = e.pit_id
                   LEFT JOIN track t ON t.id = e.track_id
                   WHERE e.is_marker = 0 AND e.id <> ? AND (e.name LIKE ? OR e.short_name LIKE ?)
                   ORDER BY (e.name LIKE ?) DESC, p.ts LIMIT " . LOOKUP_LIMIT, [$exclude, $like, $like, $start])),

        'period' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'label' => $r['name'],
                'sub' => formatRange($r['s'], $r['sp'], $r['e'], $r['ep']) . ($r['track'] ? ' · ' . $r['track'] : ''),
            ], q("SELECT pe.id, pe.name, ps.ts AS s, ps.date_precision AS sp, pf.ts AS e, pf.date_precision AS ep, t.name AS track
                    FROM period pe
                    JOIN event es ON es.id = pe.start_event_id JOIN pit ps ON ps.id = es.pit_id
                    JOIN event ef ON ef.id = pe.end_event_id   JOIN pit pf ON pf.id = ef.pit_id
                    LEFT JOIN track t ON t.id = pe.track_id
                   WHERE pe.id <> ? AND pe.name LIKE ?
                   ORDER BY (pe.name LIKE ?) DESC, ps.ts LIMIT " . LOOKUP_LIMIT, [$exclude, $like, $start])),

        'publication' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'label' => $r['title'],
                'sub' => trim(($r['release_date'] ? 'started ' . formatDate($r['release_date'], $r['release_precision']) : 'no date')
                       . ($r['category'] ? ' · ' . $r['category'] : '')),
            ], q("SELECT w.id, w.title, w.release_date, w.release_precision, c.category FROM publication w
                   LEFT JOIN pub_category c ON c.id = w.category_id
                   WHERE w.id <> ? AND (w.title LIKE ? OR w.short_title LIKE ? OR w.shortcode = ?)
                   ORDER BY (w.title LIKE ?) DESC, w.release_date IS NULL, w.release_date LIMIT " . LOOKUP_LIMIT,
                 [$exclude, $like, $like, $term, $start])),

        // Publications (books, collections, journal issues) - internal type "edition".
        'edition' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'label' => $r['title'],
                'sub' => trim(implode(' · ', array_filter([
                    $r['release_date'] ? formatDate($r['release_date'], $r['release_precision']) : null,
                    EDITION_KINDS[$r['kind']] ?? null, $r['publisher'],
                ]))),
            ], q("SELECT id, title, kind, publisher, release_date, release_precision FROM edition
                   WHERE id <> ? AND (title LIKE ? OR publisher LIKE ? OR isbn = ?)
                   ORDER BY (title LIKE ?) DESC, release_date IS NULL, release_date LIMIT " . LOOKUP_LIMIT,
                 [$exclude, $like, $like, $term, $start])),

        'source' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'label' => $r['code'] . ' · ' . $r['title'],
                'sub' => SOURCE_TYPE_LABELS[$r['source_type']] . ($r['author'] ? ' · ' . $r['author'] : '')
                       . ($r['newspaper'] ? ' · ' . $r['newspaper'] : '')
                       . ($r['published_date'] ? ' · ' . formatDate($r['published_date'], $r['published_precision']) : ''),
            ], q("SELECT id, code, source_type, title, author, newspaper, published_date, published_precision FROM source
                   WHERE code LIKE ? OR title LIKE ? OR author LIKE ? OR newspaper LIKE ?
                   ORDER BY (code = ?) DESC, (code LIKE ?) DESC, FIELD(source_type, 'book', 'newspaper', 'wikipedia'), code
                   LIMIT " . LOOKUP_LIMIT, [$start, $like, $like, $like, $term, $start])),

        'keyword' => array_map(fn($r) => [
                'id' => (int) $r['id'], 'label' => $r['name'],
                'sub' => $r['n'] . ' entr' . ((int) $r['n'] === 1 ? 'y' : 'ies'),
            ], q("SELECT k.id, k.name,
                         (SELECT COUNT(*) FROM event_keyword x WHERE x.keyword_id = k.id)
                       + (SELECT COUNT(*) FROM period_keyword x WHERE x.keyword_id = k.id)
                       + (SELECT COUNT(*) FROM publication_keyword x WHERE x.keyword_id = k.id)
                       + (SELECT COUNT(*) FROM edition_keyword x WHERE x.keyword_id = k.id) AS n
                    FROM keyword k WHERE k.name LIKE ?
                   ORDER BY (k.name LIKE ?) DESC, n DESC, k.name LIMIT " . LOOKUP_LIMIT, [$like, $start])),

        default => null,
    };
    if ($results === null) {
        jsonOut(['error' => 'Unknown type'], 400);
    }
    jsonOut(['results' => $results]);
} catch (Throwable $e) {
    error_log('librescape lookup: ' . $e->getMessage());
    jsonOut(['error' => 'Server error'], 500);
}
