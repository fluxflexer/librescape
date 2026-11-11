<?php
// GET ?type=event|period|publication|edition|person|source&id=N -> details for the detail panel.
// ("publication" is a work; "edition" is a publication such as a book or journal issue that contains works.)
// Every type returns the same shape:
//   { type, id, itemId, title, kind, date, description, keywords[], persons[], related[], links[], window? }

declare(strict_types=1);
require_once __DIR__ . '/../lib/entries.php';

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
                 ORDER BY FIELD(entity_type, 'person', 'event', 'period', 'publication', 'edition'), entity_id", [$id]) as $r) {
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
        'date'        => $w['release_date'] ? 'Started ' . formatDate($w['release_date'], $w['release_precision']) : null,
        'description' => $w['description'],
        'keywords'    => keywordsFor('publication_keyword', 'publication_id', $id),
        'persons'     => $persons,
        'related'     => $related,
        'publishedIn' => editionsOfWork($id),
        'links'       => weblinksFor('publication', $id),
        'sources'     => sourcesFor('publication', $id),
        'image'       => imageFor('publication', $id),
    ];
}

/** A publication (book, collection, journal issue ...): not drawn on the timeline, its works are. */
function editionDetails(int $id): ?array
{
    $e = q1('SELECT * FROM edition WHERE id = ?', [$id]);
    if (!$e) {
        return null;
    }
    $facts = array_values(array_filter([
        ['label' => 'Publisher', 'value' => trim(($e['publisher'] ?? '') . ($e['place'] ? ', ' . $e['place'] : ''), ', ') ?: null],
        ['label' => 'ISBN', 'value' => $e['isbn']],
    ], fn($f) => $f['value'] !== null && $f['value'] !== ''));

    $persons = [];
    foreach (q('SELECT p.id, p.first_name, p.surname FROM edition_editor x JOIN person p ON p.id = x.person_id
                 WHERE x.edition_id = ? ORDER BY x.sort_order', [$id]) as $p) {
        $persons[] = ['id' => (int) $p['id'], 'name' => personName($p), 'role' => 'editor'];
    }
    $children = [];
    $workIds = [];
    foreach (q('SELECT w.id, w.title, w.release_date, w.gestation_period_id, x.locator, x.first_publication
                  FROM edition_work x JOIN publication w ON w.id = x.work_id
                 WHERE x.edition_id = ? ORDER BY x.sort_order', [$id]) as $w) {
        $workIds[] = (int) $w['id'];
        $children[] = ['label' => $w['title'], 'note' => implode(' · ', array_filter([$w['first_publication'] ? 'first published here' : null, $w['locator']])),
                       'type' => 'publication', 'id' => (int) $w['id'], 'itemId' => pubItemId($w)];
    }
    // Authors of the works, for collections and anthologies.
    if ($workIds) {
        $in = implode(',', $workIds);   // integers only
        foreach (q("SELECT DISTINCT p.id, p.first_name, p.surname FROM publication w JOIN person p ON p.id IN (w.author_id, w.author2_id, w.author3_id)
                     WHERE w.id IN ($in) ORDER BY p.surname") as $p) {
            if (!in_array((int) $p['id'], array_column($persons, 'id'), true)) {
                $persons[] = ['id' => (int) $p['id'], 'name' => personName($p), 'role' => 'author'];
            }
        }
    }
    return [
        'type'        => 'edition',
        'id'          => (int) $e['id'],
        'itemId'      => editionItemId($e),
        'title'       => $e['title'],
        'kind'        => 'Publication · ' . (EDITION_KINDS[$e['kind']] ?? $e['kind']),
        'date'        => $e['release_date'] ? 'Published ' . formatDate($e['release_date'], $e['release_precision']) : null,
        'description' => $e['description'],
        'keywords'    => keywordsFor('edition_keyword', 'edition_id', $id),
        'facts'       => $facts,
        'persons'     => $persons,
        'children'    => $children,
        'related'     => [],
        'links'       => weblinksFor('edition', $id),
        'sources'     => sourcesFor('edition', $id),
        'image'       => imageFor('edition', $id),
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
        'SELECT r.person1_id, r.person2_id, r.relation_status, rt.short_name, rt.inverse_name,
                a.first_name AS a_first, a.surname AS a_sur, b.first_name AS b_first, b.surname AS b_sur
           FROM relationship r
           JOIN relation_type rt ON rt.id = r.relation_type_id
           JOIN person a ON a.id = r.person1_id
           JOIN person b ON b.id = r.person2_id
          WHERE r.person1_id = ? OR r.person2_id = ?
          ORDER BY r.id', [$id, $id]
    );
    foreach ($rels as $r) {
        $isFirst = (int) $r['person1_id'] === $id;
        $otherId = $isFirst ? (int) $r['person2_id'] : (int) $r['person1_id'];
        $other = $isFirst ? ['first_name' => $r['b_first'], 'surname' => $r['b_sur']]
                          : ['first_name' => $r['a_first'], 'surname' => $r['a_sur']];
        // Directional types (with an inverse name) read from the other person's point of view:
        // if this person is the parent, the other one is shown as "child".
        $role = $isFirst && $r['inverse_name'] ? $r['inverse_name'] : $r['short_name'];
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
    foreach (q('SELECT e.id, e.title, e.release_date, e.release_precision FROM edition_editor x JOIN edition e ON e.id = x.edition_id
                 WHERE x.person_id = ? ORDER BY e.release_date IS NULL, e.release_date', [$id]) as $e) {
        $related[] = ['label' => $e['title'], 'note' => trim('editor · ' . ($e['release_date'] ? formatDate($e['release_date'], $e['release_precision']) : ''), ' ·'),
                      'type' => 'edition', 'id' => (int) $e['id'], 'itemId' => editionItemId($e)];
    }

    $born = $p['date_of_birth'] ? formatDate($p['date_of_birth'], $p['birth_precision'] ?? 'day') : '?';
    $died = $p['date_of_death'] ? formatDate($p['date_of_death'], $p['death_precision'] ?? 'day') : '';
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
        'edition'     => 'editionDetails',
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
