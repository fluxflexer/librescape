<?php
// Entry helpers shared by the viewer API (api/) and the admin app (admin/):
// keywords, images, sources, weblinks and short references to entries.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

function keywordsFor(string $table, string $col, int $id): array
{
    // $table / $col are fixed strings from this file, never user input.
    $rows = q("SELECT k.name FROM $table x JOIN keyword k ON k.id = x.keyword_id WHERE x.$col = ? ORDER BY k.name", [$id]);
    return array_column($rows, 'name');
}

/**
 * Locally stored, freely licensed picture for an entity (see tools/fetch_images.php), with credit data.
 */
function imageFor(string $type, int $id): ?array
{
    $img = q1('SELECT * FROM image WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
    if (!$img || !is_file(__DIR__ . '/../' . $img['local_path'])) {
        return null;
    }
    return [
        'src'         => $img['local_path'],
        'width'       => $img['width'] ? (int) $img['width'] : null,
        'height'      => $img['height'] ? (int) $img['height'] : null,
        'artist'      => $img['artist'],
        'license'     => $img['license'],
        'licenseUrl'  => $img['license_url'],
        'source'      => $img['description_url'],
        'attribution' => (bool) $img['attribution_required'],
    ];
}

const SOURCE_TYPE_LABELS = ['book' => 'Book', 'newspaper' => 'Newspaper article', 'wikipedia' => 'Wikipedia'];

/** One-line bibliographic citation for a source row. */
function citation(array $s): string
{
    $date = formatDate($s['published_date'], $s['published_precision']);
    switch ($s['source_type']) {
        case 'book':
            $who = $s['author'] . ($s['editor'] ? ' (ed. ' . $s['editor'] . ')' : '');
            $imprint = trim(($s['place'] ? $s['place'] . ': ' : '') . $s['publisher'] . ', ' . $date);
            return $who . ': ' . $s['title'] . '. ' . $imprint . '.' . ($s['isbn'] ? ' ISBN ' . $s['isbn'] . '.' : '');
        case 'newspaper':
            return ($s['author'] ? $s['author'] . ': ' : '') . '“' . $s['title'] . '”. ' . $s['newspaper'] . ', ' . $date
                . ($s['page'] ? ', ' . $s['page'] : '') . '.';
        default:
            return '“' . $s['title'] . '”. Wikipedia (' . $s['language'] . '), accessed ' . formatDate($s['accessed_date']) . '.';
    }
}

/** Sources cited by an entry, books first. */
function sourcesFor(string $type, int $id): array
{
    $rows = q(
        "SELECT s.*, r.locator, r.note AS ref_note
           FROM source_ref r JOIN source s ON s.id = r.source_id
          WHERE r.entity_type = ? AND r.entity_id = ?
          ORDER BY FIELD(s.source_type, 'book', 'newspaper', 'wikipedia'), s.code", [$type, $id]
    );
    return array_map(fn($s) => [
        'id'       => (int) $s['id'],
        'code'     => $s['code'],
        'kind'     => SOURCE_TYPE_LABELS[$s['source_type']],
        'citation' => citation($s),
        'locator'  => $s['locator'],
        'note'     => $s['ref_note'],
        'url'      => $s['url'],
    ], $rows);
}

/** Label and timeline item of any entry, for "cited by" lists. */
function entryRef(string $type, int $id): ?array
{
    switch ($type) {
        case 'person':
            $p = q1('SELECT id, first_name, surname FROM person WHERE id = ?', [$id]);
            return $p ? ['label' => personName($p), 'note' => 'person', 'type' => 'person', 'id' => $id, 'itemId' => null] : null;
        case 'event':
            $e = q1('SELECT e.name, e.is_marker, p.ts, p.date_precision FROM event e JOIN pit p ON p.id = e.pit_id WHERE e.id = ?', [$id]);
            return $e ? ['label' => $e['name'], 'note' => formatDate($e['ts'], $e['date_precision']), 'type' => 'event', 'id' => $id,
                         'itemId' => $e['is_marker'] ? null : 'e' . $id] : null;
        case 'period':
            return periodRef($id, '');
        case 'publication':
            $w = q1('SELECT id, title, release_date, release_precision, gestation_period_id FROM publication WHERE id = ?', [$id]);
            return $w ? ['label' => $w['title'], 'note' => $w['release_date'] ? formatDate($w['release_date'], $w['release_precision']) : 'work',
                         'type' => 'publication', 'id' => $id, 'itemId' => pubItemId($w)] : null;
    }
    return null;
}

/** All web links of an entry, in their stored order. */
function weblinksFor(string $type, int $id): array
{
    $rows = q('SELECT link_type, label, url FROM weblink WHERE entity_type = ? AND entity_id = ? ORDER BY sort_order, id', [$type, $id]);
    return array_map(fn($l) => ['label' => $l['label'] ?: defaultLinkLabel($l['link_type'], $l['url']), 'url' => $l['url'], 'kind' => $l['link_type']], $rows);
}

/** "Wikipedia" for English articles, "Wikipedia (de)" for others, "CV", or the host name. */
function defaultLinkLabel(string $type, string $url): string
{
    $host = (string) parse_url($url, PHP_URL_HOST);
    switch ($type) {
        case 'wikipedia':
            $lang = preg_match('~^([a-z\-]+)\.(?:m\.)?wikipedia\.org$~i', $host, $m) ? strtolower($m[1]) : '';
            return $lang && $lang !== 'en' ? "Wikipedia ($lang)" : 'Wikipedia';
        case 'cv':
            return 'CV';
        default:
            return preg_replace('~^www\.~', '', $host) ?: $url;
    }
}

function personRef(?int $pid, string $role): ?array
{
    if (!$pid) {
        return null;
    }
    $p = q1('SELECT id, first_name, surname FROM person WHERE id = ?', [$pid]);
    return $p ? ['id' => (int) $p['id'], 'name' => personName($p), 'role' => $role] : null;
}

/** Timeline item id for a publication: its release point, else its gestation range. */
function pubItemId(array $pub): ?string
{
    if (!empty($pub['release_date'])) {
        return 'w' . $pub['id'];
    }
    return !empty($pub['gestation_period_id']) ? 'p' . $pub['gestation_period_id'] : null;
}

/** Related-entry for a period, with its date range as note prefix. */
function periodRef(int $pid, string $note): ?array
{
    $p = q1(
        'SELECT pe.id, pe.name, ps.ts AS s, ps.date_precision AS sp, pf.ts AS e, pf.date_precision AS ep
           FROM period pe
           JOIN event es ON es.id = pe.start_event_id JOIN pit ps ON ps.id = es.pit_id
           JOIN event ef ON ef.id = pe.end_event_id   JOIN pit pf ON pf.id = ef.pit_id
          WHERE pe.id = ?', [$pid]
    );
    return $p ? ['label' => $p['name'], 'note' => $note . formatRange($p['s'], $p['sp'], $p['e'], $p['ep']),
                 'type' => 'period', 'id' => (int) $p['id'], 'itemId' => 'p' . $p['id']] : null;
}

/** Sub-periods and sub-events of a period, chronologically. */
function childrenOf(int $pid): array
{
    $out = [];
    foreach (q(
        'SELECT pe.id, pe.name, ps.ts AS s, ps.date_precision AS sp, pf.ts AS e, pf.date_precision AS ep
           FROM period pe
           JOIN event es ON es.id = pe.start_event_id JOIN pit ps ON ps.id = es.pit_id
           JOIN event ef ON ef.id = pe.end_event_id   JOIN pit pf ON pf.id = ef.pit_id
          WHERE pe.parent_period_id = ?', [$pid]) as $c) {
        $out[] = ['label' => $c['name'], 'note' => formatRange($c['s'], $c['sp'], $c['e'], $c['ep']),
                  'type' => 'period', 'id' => (int) $c['id'], 'itemId' => 'p' . $c['id'], 'sort' => $c['s']];
    }
    foreach (q(
        'SELECT e.id, e.name, e.is_marker, p.ts, p.date_precision
           FROM event e JOIN pit p ON p.id = e.pit_id WHERE e.parent_period_id = ?', [$pid]) as $c) {
        $out[] = ['label' => $c['name'], 'note' => formatDate($c['ts'], $c['date_precision']),
                  'type' => 'event', 'id' => (int) $c['id'], 'itemId' => $c['is_marker'] ? null : 'e' . $c['id'], 'sort' => $c['ts']];
    }
    usort($out, fn($a, $b) => strcmp($a['sort'], $b['sort']));
    return array_map(function ($r) { unset($r['sort']); return $r; }, $out);
}

/**
 * Entries that cite no source yet: [{type, id, label}], ordered by type and id.
 * Period start/end markers are exempt: their period carries the source.
 */
function entriesWithoutSource(): array
{
    $checks = [
        'person'      => "SELECT id, CONCAT(first_name, ' ', surname) AS label FROM person",
        'event'       => 'SELECT id, name AS label FROM event WHERE is_marker = 0',
        'period'      => 'SELECT id, name AS label FROM period',
        'publication' => 'SELECT id, title AS label FROM publication',
    ];
    $out = [];
    foreach ($checks as $type => $sql) {
        $rows = q("SELECT x.id, x.label FROM ($sql) x
                    WHERE NOT EXISTS (SELECT 1 FROM source_ref r WHERE r.entity_type = ? AND r.entity_id = x.id)
                    ORDER BY x.id", [$type]);
        foreach ($rows as $r) {
            $out[] = ['type' => $type, 'id' => (int) $r['id'], 'label' => $r['label']];
        }
    }
    return $out;
}
