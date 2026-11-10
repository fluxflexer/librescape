<?php
// GET ?type=event|period|publication|person&id=N -> details for the detail panel.
// Every type returns the same shape:
//   { type, id, itemId, title, kind, date, description, keywords[], persons[], related[], links[], window? }

declare(strict_types=1);
require __DIR__ . '/../lib/db.php';

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

function sourceDetails(int $id): ?array
{
    $s = q1('SELECT * FROM source WHERE id = ?', [$id]);
    if (!$s) {
        return null;
    }
    $facts = array_values(array_filter([
        ['label' => 'Code', 'value' => $s['code']],
        ['label' => $s['source_type'] === 'newspaper' ? 'Headline' : 'Title', 'value' => $s['title']],
        ['label' => 'Author', 'value' => $s['author']],
        ['label' => 'Editor', 'value' => $s['editor']],
        ['label' => 'Newspaper', 'value' => $s['newspaper']],
        ['label' => 'Page', 'value' => $s['page']],
        ['label' => 'Publisher', 'value' => trim(($s['publisher'] ?? '') . ($s['place'] ? ', ' . $s['place'] : ''), ', ') ?: null],
        ['label' => 'Published', 'value' => $s['published_date'] ? formatDate($s['published_date'], $s['published_precision']) : null],
        ['label' => 'ISBN', 'value' => $s['isbn'] ?? ($s['source_type'] === 'book' ? 'none (printed before ISBNs)' : null)],
        ['label' => 'Language', 'value' => $s['language']],
        ['label' => 'Accessed', 'value' => $s['accessed_date'] ? formatDate($s['accessed_date']) : null],
        ['label' => 'Notes', 'value' => $s['notes']],
    ], fn($f) => $f['value'] !== null && $f['value'] !== ''));

    $related = [];
    if ($s['publication_id'] && ($w = entryRef('publication', (int) $s['publication_id']))) {
        $w['note'] = 'this work in the timeline';
        $related[] = $w;
    }
    $citedBy = [];
    foreach (q("SELECT entity_type, entity_id, locator FROM source_ref WHERE source_id = ?
                 ORDER BY FIELD(entity_type, 'person', 'event', 'period', 'publication'), entity_id", [$id]) as $r) {
        if ($ref = entryRef($r['entity_type'], (int) $r['entity_id'])) {
            if ($r['locator']) {
                $ref['note'] = trim($ref['note'] . ' · ' . $r['locator'], ' ·');
            }
            $citedBy[] = $ref;
        }
    }

    $links = [];
    if ($s['url']) {
        $links[] = ['label' => $s['source_type'] === 'wikipedia' ? 'Wikipedia' : 'Online', 'url' => $s['url']];
    }
    if ($s['wikipedia_url']) {
        $links[] = ['label' => $s['source_type'] === 'newspaper' ? 'About the newspaper' : 'About the book', 'url' => $s['wikipedia_url']];
    }
    return [
        'type'        => 'source',
        'id'          => (int) $s['id'],
        'itemId'      => null,
        'title'       => $s['title'],
        'kind'        => 'Source · ' . SOURCE_TYPE_LABELS[$s['source_type']],
        'code'        => $s['code'],
        'date'        => $s['published_date'] ? formatDate($s['published_date'], $s['published_precision']) : null,
        'description' => citation($s),
        'facts'       => $facts,
        'keywords'    => [],
        'persons'     => array_values(array_filter([personRef($s['author_person_id'] ? (int) $s['author_person_id'] : null, 'author')])),
        'related'     => $related,
        'citedBy'     => $citedBy,
        'sources'     => [],
        'links'       => $links,
        'image'       => null,
    ];
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

function eventDetails(int $id): ?array
{
    $e = q1(
        'SELECT e.*, p.ts, p.date_precision, t.name AS track_name
           FROM event e JOIN pit p ON p.id = e.pit_id LEFT JOIN track t ON t.id = e.track_id
          WHERE e.id = ?', [$id]
    );
    if (!$e) {
        return null;
    }
    $related = [];
    if ($e['parent_period_id'] && ($parent = periodRef((int) $e['parent_period_id'], 'part of · '))) {
        $related[] = $parent;
    }
    foreach (q('SELECT id, name, start_event_id FROM period WHERE start_event_id = ? OR end_event_id = ?', [$id, $id]) as $p) {
        $related[] = [
            'label'  => $p['name'],
            'note'   => (int) $p['start_event_id'] === $id ? 'starts' : 'ends',
            'type'   => 'period',
            'id'     => (int) $p['id'],
            'itemId' => 'p' . $p['id'],
        ];
    }
    return [
        'type'        => 'event',
        'id'          => (int) $e['id'],
        'itemId'      => $e['is_marker'] ? null : 'e' . $e['id'],
        'title'       => $e['name'],
        'kind'        => 'Event' . ($e['track_name'] ? ' · ' . $e['track_name'] : ''),
        'date'        => formatDate($e['ts'], $e['date_precision']),
        'description' => $e['description'],
        'keywords'    => keywordsFor('event_keyword', 'event_id', $id),
        'persons'     => array_values(array_filter([
            personRef($e['person1_id'] ? (int) $e['person1_id'] : null, ''),
            personRef($e['person2_id'] ? (int) $e['person2_id'] : null, ''),
        ])),
        'related'     => $related,
        'links'       => weblinksFor('event', $id),
        'sources'     => sourcesFor('event', $id),
        'image'       => imageFor('event', $id),
    ];
}

function periodDetails(int $id): ?array
{
    $p = q1(
        'SELECT pe.*, t.name AS track_name,
                es.name AS start_name, es.person1_id AS sp1, es.person2_id AS sp2, es.is_marker AS start_marker,
                ef.name AS end_name, ef.is_marker AS end_marker,
                ps.ts AS start_ts, ps.date_precision AS start_prec,
                pf.ts AS end_ts, pf.date_precision AS end_prec
           FROM period pe
           JOIN event es ON es.id = pe.start_event_id JOIN pit ps ON ps.id = es.pit_id
           JOIN event ef ON ef.id = pe.end_event_id   JOIN pit pf ON pf.id = ef.pit_id
           LEFT JOIN track t ON t.id = pe.track_id
          WHERE pe.id = ?', [$id]
    );
    if (!$p) {
        return null;
    }
    $related = [];
    if ($p['parent_period_id'] && ($parent = periodRef((int) $p['parent_period_id'], 'part of · '))) {
        $related[] = $parent;
    }
    $related = array_merge($related, [
        ['label' => $p['start_name'], 'note' => 'start · ' . formatDate($p['start_ts'], $p['start_prec']),
         'type' => 'event', 'id' => (int) $p['start_event_id'], 'itemId' => $p['start_marker'] ? null : 'e' . $p['start_event_id']],
        ['label' => $p['end_name'], 'note' => 'end · ' . formatDate($p['end_ts'], $p['end_prec']),
         'type' => 'event', 'id' => (int) $p['end_event_id'], 'itemId' => $p['end_marker'] ? null : 'e' . $p['end_event_id']],
    ]);
    foreach (q('SELECT id, title, release_date, gestation_period_id FROM publication WHERE gestation_period_id = ?', [$id]) as $w) {
        $related[] = ['label' => $w['title'], 'note' => 'work written in this period',
                      'type' => 'publication', 'id' => (int) $w['id'], 'itemId' => pubItemId($w)];
    }
    return [
        'type'        => 'period',
        'id'          => (int) $p['id'],
        'itemId'      => 'p' . $p['id'],
        'title'       => $p['name'],
        'kind'        => 'Period' . ($p['track_name'] ? ' · ' . $p['track_name'] : ''),
        'date'        => formatRange($p['start_ts'], $p['start_prec'], $p['end_ts'], $p['end_prec']),
        'description' => $p['description'],
        'keywords'    => keywordsFor('period_keyword', 'period_id', $id),
        'children'    => childrenOf($id),
        'persons'     => array_values(array_filter([
            personRef($p['sp1'] ? (int) $p['sp1'] : null, ''),
            personRef($p['sp2'] ? (int) $p['sp2'] : null, ''),
        ])),
        'related'     => $related,
        'links'       => weblinksFor('period', $id),
        'sources'     => sourcesFor('period', $id),
        'image'       => imageFor('period', $id),
    ];
}

function publicationDetails(int $id): ?array
{
    $w = q1(
        'SELECT pb.*, c.category FROM publication pb LEFT JOIN pub_category c ON c.id = pb.category_id WHERE pb.id = ?',
        [$id]
    );
    if (!$w) {
        return null;
    }
    $persons = array_values(array_filter([
        personRef($w['author_id'] ? (int) $w['author_id'] : null, 'author'),
        personRef($w['author2_id'] ? (int) $w['author2_id'] : null, $w['category'] === 'Posthumous edition' ? 'editor' : 'co-author'),
        personRef($w['author3_id'] ? (int) $w['author3_id'] : null, 'co-author'),
    ]));

    $related = [];
    if ($w['parent_work_id']) {
        $parent = q1('SELECT id, title, release_date, gestation_period_id FROM publication WHERE id = ?', [(int) $w['parent_work_id']]);
        if ($parent) {
            $related[] = ['label' => $parent['title'], 'note' => 'part of', 'type' => 'publication',
                          'id' => (int) $parent['id'], 'itemId' => pubItemId($parent)];
        }
    }
    foreach (q('SELECT id, title, release_date, release_precision, gestation_period_id FROM publication
                 WHERE parent_work_id = ? ORDER BY release_date', [$id]) as $c) {
        $related[] = ['label' => $c['title'], 'note' => formatDate($c['release_date'], $c['release_precision']),
                      'type' => 'publication', 'id' => (int) $c['id'], 'itemId' => pubItemId($c)];
    }
    if ($w['gestation_period_id']) {
        $g = q1(
            'SELECT pe.id, pe.name, ps.ts AS s, ps.date_precision AS sp, pf.ts AS e, pf.date_precision AS ep
               FROM period pe
               JOIN event es ON es.id = pe.start_event_id JOIN pit ps ON ps.id = es.pit_id
               JOIN event ef ON ef.id = pe.end_event_id   JOIN pit pf ON pf.id = ef.pit_id
              WHERE pe.id = ?', [(int) $w['gestation_period_id']]
        );
        if ($g) {
            $related[] = ['label' => $g['name'], 'note' => formatRange($g['s'], $g['sp'], $g['e'], $g['ep']),
                          'type' => 'period', 'id' => (int) $g['id'], 'itemId' => 'p' . $g['id']];
        }
    }

    return [
        'type'        => 'publication',
        'id'          => (int) $w['id'],
        'itemId'      => pubItemId($w),
        'title'       => $w['title'],
        'kind'        => 'Work' . ($w['category'] ? ' · ' . $w['category'] : ''),
        'date'        => $w['release_date'] ? 'Published ' . formatDate($w['release_date'], $w['release_precision']) : 'Published in parts',
        'description' => $w['description'],
        'keywords'    => keywordsFor('publication_keyword', 'publication_id', $id),
        'persons'     => $persons,
        'related'     => $related,
        'links'       => weblinksFor('publication', $id),
        'sources'     => sourcesFor('publication', $id),
        'image'       => imageFor('publication', $id),
    ];
}

function personDetails(int $id): ?array
{
    $p = q1('SELECT * FROM person WHERE id = ?', [$id]);
    if (!$p) {
        return null;
    }
    $persons = [];
    $rels = q(
        'SELECT r.person1_id, r.person2_id, r.relation_status, rt.short_name,
                a.first_name AS a_first, a.surname AS a_sur, b.first_name AS b_first, b.surname AS b_sur
           FROM relationship r
           JOIN relation_type rt ON rt.id = r.relation_type_id
           JOIN person a ON a.id = r.person1_id
           JOIN person b ON b.id = r.person2_id
          WHERE r.person1_id = ? OR r.person2_id = ?', [$id, $id]
    );
    foreach ($rels as $r) {
        $isFirst = (int) $r['person1_id'] === $id;
        $otherId = $isFirst ? (int) $r['person2_id'] : (int) $r['person1_id'];
        $other = $isFirst ? ['first_name' => $r['b_first'], 'surname' => $r['b_sur']]
                          : ['first_name' => $r['a_first'], 'surname' => $r['a_sur']];
        // Directional types read from the other person's point of view.
        $role = $r['short_name'];
        if ($role === 'parent')   { $role = $isFirst ? 'child' : 'parent'; }
        if ($role === 'guardian') { $role = $isFirst ? 'ward' : 'guardian'; }
        $persons[] = ['id' => $otherId, 'name' => personName($other),
                      'role' => $role . ($r['relation_status'] ? ' (' . $r['relation_status'] . ')' : '')];
    }

    $related = [];
    foreach (q('SELECT id, title, release_date, release_precision, gestation_period_id FROM publication
                 WHERE (author_id = ? OR author2_id = ? OR author3_id = ?) AND parent_work_id IS NULL
                 ORDER BY release_date IS NULL, release_date', [$id, $id, $id]) as $w) {
        $related[] = ['label' => $w['title'], 'note' => $w['release_date'] ? formatDate($w['release_date'], $w['release_precision']) : '',
                      'type' => 'publication', 'id' => (int) $w['id'], 'itemId' => pubItemId($w)];
    }

    $born = $p['date_of_birth'] ? formatDate($p['date_of_birth']) : '?';
    $died = $p['date_of_death'] ? formatDate($p['date_of_death']) : '';
    return [
        'type'        => 'person',
        'id'          => (int) $p['id'],
        'itemId'      => null,
        'title'       => personName($p),
        'kind'        => 'Person',
        'date'        => $born . ($died ? ' – ' . $died : ''),
        'description' => $p['place_of_birth'] ? 'Born in ' . $p['place_of_birth'] . '.' : null,
        'keywords'    => [],
        'persons'     => $persons,
        'related'     => $related,
        'links'       => weblinksFor('person', $id),
        'sources'     => sourcesFor('person', $id),
        'image'       => imageFor('person', $id),
        'window'      => ($p['date_of_birth'] || $p['date_of_death']) ? [
            'start' => $p['date_of_birth'] ?: $p['date_of_death'],
            'end'   => $p['date_of_death'] ?: date('Y-m-d'),
        ] : null,
    ];
}

apiRun(function () {
    $type = $_GET['type'] ?? '';
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$id) {
        jsonOut(['error' => 'Missing or invalid id'], 400);
    }
    $handlers = [
        'event'       => 'eventDetails',
        'period'      => 'periodDetails',
        'publication' => 'publicationDetails',
        'person'      => 'personDetails',
        'source'      => 'sourceDetails',
    ];
    if (!isset($handlers[$type])) {
        jsonOut(['error' => 'Unknown type'], 400);
    }
    $data = $handlers[$type]($id);
    if ($data === null) {
        jsonOut(['error' => 'Not found'], 404);
    }
    jsonOut($data);
});
