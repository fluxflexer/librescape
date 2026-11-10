<?php
// GET ?q=text -> { results: [ {type, id, label, sub, date, itemId, score} ] }
// Matches names/titles (best), keywords, then descriptions, across persons, events, periods, works and sources.

declare(strict_types=1);
require_once __DIR__ . '/../lib/db.php';

apiRun(function () {
    $term = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($term) < 2) {
        jsonOut(['results' => []]);
    }
    $like = '%' . likeEscape($term) . '%';
    // Descriptions only match at the start of a word ("ring" should not hit "during").
    $wordA = likeEscape($term) . '%';
    $wordB = '% ' . likeEscape($term) . '%';
    $results = [];

    // Persons
    foreach (q(
        "SELECT id, first_name, surname, date_of_birth, date_of_death
           FROM person WHERE CONCAT(first_name, ' ', surname) LIKE :q OR shortcode = :exact
          ORDER BY surname, first_name",
        ['q' => $like, 'exact' => $term]
    ) as $p) {
        $results[] = [
            'type' => 'person', 'id' => (int) $p['id'], 'label' => personName($p), 'sub' => 'Person',
            'date' => ($p['date_of_birth'] ? substr($p['date_of_birth'], 0, 4) : '?') . '–' . ($p['date_of_death'] ? substr($p['date_of_death'], 0, 4) : ''),
            'itemId' => null, 'score' => 3,
        ];
    }

    // Events (not period markers)
    foreach (q(
        'SELECT e.id, e.name, p.ts, p.date_precision, t.name AS track,
                (e.name LIKE :q1 OR e.short_name LIKE :q2) AS by_name,
                EXISTS (SELECT 1 FROM event_keyword ek JOIN keyword k ON k.id = ek.keyword_id
                         WHERE ek.event_id = e.id AND k.name LIKE :q3) AS by_kw
           FROM event e JOIN pit p ON p.id = e.pit_id LEFT JOIN track t ON t.id = e.track_id
          WHERE e.is_marker = 0 AND (
                e.name LIKE :q4 OR e.short_name LIKE :q5 OR (e.description LIKE :wa OR e.description LIKE :wb)
                OR EXISTS (SELECT 1 FROM event_keyword ek JOIN keyword k ON k.id = ek.keyword_id
                            WHERE ek.event_id = e.id AND k.name LIKE :q7))',
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'wa' => $wordA, 'wb' => $wordB, 'q7' => $like]
    ) as $e) {
        $results[] = [
            'type' => 'event', 'id' => (int) $e['id'], 'label' => $e['name'], 'sub' => 'Event · ' . $e['track'],
            'date' => formatDate($e['ts'], $e['date_precision']), 'sort' => $e['ts'],
            'itemId' => 'e' . $e['id'], 'score' => $e['by_name'] ? 2 : ($e['by_kw'] ? 1 : 0),
        ];
    }

    // Periods
    foreach (q(
        'SELECT pe.id, pe.name, t.name AS track, ps.ts AS s, ps.date_precision AS sp, pf.ts AS e, pf.date_precision AS ep,
                pe.name LIKE :q1 AS by_name,
                EXISTS (SELECT 1 FROM period_keyword pk JOIN keyword k ON k.id = pk.keyword_id
                         WHERE pk.period_id = pe.id AND k.name LIKE :q2) AS by_kw
           FROM period pe
           JOIN event es ON es.id = pe.start_event_id JOIN pit ps ON ps.id = es.pit_id
           JOIN event ef ON ef.id = pe.end_event_id   JOIN pit pf ON pf.id = ef.pit_id
           LEFT JOIN track t ON t.id = pe.track_id
          WHERE pe.name LIKE :q3 OR (pe.description LIKE :wa OR pe.description LIKE :wb)
                OR EXISTS (SELECT 1 FROM period_keyword pk JOIN keyword k ON k.id = pk.keyword_id
                            WHERE pk.period_id = pe.id AND k.name LIKE :q5)',
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'wa' => $wordA, 'wb' => $wordB, 'q5' => $like]
    ) as $p) {
        $results[] = [
            'type' => 'period', 'id' => (int) $p['id'], 'label' => $p['name'], 'sub' => 'Period · ' . $p['track'],
            'date' => formatRange($p['s'], $p['sp'], $p['e'], $p['ep']), 'sort' => $p['s'],
            'itemId' => 'p' . $p['id'], 'score' => $p['by_name'] ? 2 : ($p['by_kw'] ? 1 : 0),
        ];
    }

    // Publications
    foreach (q(
        'SELECT pb.id, pb.title, pb.release_date, pb.release_precision, pb.gestation_period_id, c.category,
                (pb.title LIKE :q1 OR pb.short_title LIKE :q2) AS by_name,
                EXISTS (SELECT 1 FROM publication_keyword wk JOIN keyword k ON k.id = wk.keyword_id
                         WHERE wk.publication_id = pb.id AND k.name LIKE :q3) AS by_kw
           FROM publication pb LEFT JOIN pub_category c ON c.id = pb.category_id
          WHERE pb.title LIKE :q4 OR pb.short_title LIKE :q5 OR (pb.description LIKE :wa OR pb.description LIKE :wb) OR pb.shortcode = :exact
                OR EXISTS (SELECT 1 FROM publication_keyword wk JOIN keyword k ON k.id = wk.keyword_id
                            WHERE wk.publication_id = pb.id AND k.name LIKE :q7)',
        ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like, 'wa' => $wordA, 'wb' => $wordB, 'q7' => $like, 'exact' => $term]
    ) as $w) {
        $itemId = $w['release_date'] ? 'w' . $w['id'] : ($w['gestation_period_id'] ? 'p' . $w['gestation_period_id'] : null);
        $results[] = [
            'type' => 'publication', 'id' => (int) $w['id'], 'label' => $w['title'], 'sub' => 'Work · ' . ($w['category'] ?? ''),
            'date' => $w['release_date'] ? formatDate($w['release_date'], $w['release_precision']) : '',
            'sort' => $w['release_date'] ?? '9999',
            'itemId' => $itemId, 'score' => $w['by_name'] ? 2 : ($w['by_kw'] ? 1 : 0),
        ];
    }

    // Sources: exact code first (e.g. "ROTK"), then title/author/newspaper
    $typeLabels = ['book' => 'Book', 'newspaper' => 'Newspaper', 'wikipedia' => 'Wikipedia'];
    foreach (q(
        'SELECT id, code, source_type, title, published_date, published_precision, code = :exact1 AS by_code
           FROM source
          WHERE code = :exact2 OR title LIKE :q1 OR author LIKE :q2 OR newspaper LIKE :q3',
        ['exact1' => $term, 'exact2' => $term, 'q1' => $like, 'q2' => $like, 'q3' => $like]
    ) as $s) {
        $results[] = [
            'type' => 'source', 'id' => (int) $s['id'], 'label' => $s['title'],
            'sub' => 'Source · ' . $typeLabels[$s['source_type']] . ' · ' . $s['code'],
            'date' => $s['published_date'] ? formatDate($s['published_date'], $s['published_precision']) : '',
            'sort' => $s['published_date'] ?? '9999',
            // An exact code is the most specific query there is; otherwise rank sources below the entries themselves.
            'itemId' => null, 'score' => $s['by_code'] ? 4 : 0,
        ];
    }

    // Best matches first, then chronological.
    usort($results, function ($a, $b) {
        return [$b['score'], $a['sort'] ?? ''] <=> [$a['score'], $b['sort'] ?? ''];
    });
    $results = array_slice($results, 0, 30);
    foreach ($results as &$r) {
        unset($r['sort']);
    }
    unset($r);

    jsonOut(['results' => $results]);
});
