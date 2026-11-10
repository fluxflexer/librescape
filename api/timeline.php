<?php
// GET -> { meta, groups, items } in vis-timeline format.
//   Item ids: e<id> = event, p<id> = period, w<id> = publication (work), bg<id> = background shading.
//   `parent` (p<id> or null) links sub-events and sub-periods to the period they belong to.
//   `kw` (optional) lists the item's keywords; meta.keywords = [{name, count}] for the keyword dropdown.

declare(strict_types=1);
require __DIR__ . '/../lib/db.php';

apiRun(function () {
    $groups = [];
    $trackClass = [];
    foreach (q('SELECT id, name, short_name, sort_order, css_class, parent_track_id FROM track ORDER BY sort_order, id') as $t) {
        $trackClass[(int) $t['id']] = $t['css_class'] ?: 'lane-' . $t['short_name'];
        $g = [
            'id'        => (int) $t['id'],
            'content'   => $t['name'],
            'order'     => (int) $t['sort_order'],
            'className' => $trackClass[(int) $t['id']],
            'key'       => $t['short_name'],
        ];
        $groups[] = $g;
    }
    $worldTrack = (int) (q1("SELECT id FROM track WHERE short_name = 'world'")['id'] ?? 0);
    $worksTrack = (int) (q1("SELECT id FROM track WHERE short_name = 'works'")['id'] ?? 0);
    // Publication dates have their own lane; fall back to Works if it doesn't exist.
    $pubsTrack = (int) (q1("SELECT id FROM track WHERE short_name = 'pubs'")['id'] ?? 0) ?: $worksTrack;

    $items = [];

    // Single events (period start/end markers are not drawn on their own).
    $events = q(
        'SELECT e.id, e.name, e.short_name, e.track_id, e.parent_period_id, p.ts, p.date_precision
           FROM event e JOIN pit p ON p.id = e.pit_id
          WHERE e.is_marker = 0 AND e.track_id IS NOT NULL'
    );
    foreach ($events as $e) {
        $tid = (int) $e['track_id'];
        $items[] = [
            'id'        => 'e' . $e['id'],
            'group'     => $tid,
            'type'      => 'point',
            'start'     => isoDate($e['ts']),
            'content'   => htmlspecialchars($e['short_name'] ?: $e['name']),
            'title'     => htmlspecialchars($e['name'] . ' · ' . formatDate($e['ts'], $e['date_precision'])),
            'className' => trim(($trackClass[$tid] ?? '') . ' item-event prec-' . $e['date_precision']),
            'parent'    => $e['parent_period_id'] ? 'p' . $e['parent_period_id'] : null,
        ];
    }

    // Periods.
    $periods = q(
        'SELECT pe.id, pe.name, pe.track_id, pe.parent_period_id,
                ps.ts AS start_ts, ps.date_precision AS start_prec,
                pf.ts AS end_ts,   pf.date_precision AS end_prec
           FROM period pe
           JOIN event es ON es.id = pe.start_event_id JOIN pit ps ON ps.id = es.pit_id
           JOIN event ef ON ef.id = pe.end_event_id   JOIN pit pf ON pf.id = ef.pit_id
          WHERE pe.track_id IS NOT NULL'
    );
    foreach ($periods as $p) {
        $tid = (int) $p['track_id'];
        $fuzzy = in_array($p['start_prec'], ['circa', 'decade'], true) || in_array($p['end_prec'], ['circa', 'decade'], true);
        $items[] = [
            'id'        => 'p' . $p['id'],
            'group'     => $tid,
            'type'      => 'range',
            'start'     => isoDate($p['start_ts']),
            'end'       => isoDate($p['end_ts']),
            'content'   => htmlspecialchars($p['name']),
            'title'     => htmlspecialchars($p['name'] . ' · ' . formatRange($p['start_ts'], $p['start_prec'], $p['end_ts'], $p['end_prec'])),
            'className' => trim(($trackClass[$tid] ?? '') . ' item-period' . ($fuzzy ? ' prec-circa' : '')),
            'parent'    => $p['parent_period_id'] ? 'p' . $p['parent_period_id'] : null,
        ];
        // Top-level world-event periods also shade every lane, to give historical context.
        if ($tid === $worldTrack && !$p['parent_period_id']) {
            $items[] = [
                'id'         => 'bg' . $p['id'],
                'type'       => 'background',
                'start'      => isoDate($p['start_ts']),
                'end'        => isoDate($p['end_ts']),
                'content'    => '',
                'className'  => 'bg-world',
                'selectable' => false,
            ];
        }
    }

    // Publications: a point at the release date.
    $pubs = q('SELECT id, title, short_title, release_date, release_precision FROM publication WHERE release_date IS NOT NULL');
    foreach ($pubs as $w) {
        $items[] = [
            'id'        => 'w' . $w['id'],
            'group'     => $pubsTrack,
            'type'      => 'point',
            'start'     => isoDate($w['release_date']),
            'content'   => htmlspecialchars($w['short_title'] ?: $w['title']),
            'title'     => htmlspecialchars($w['title'] . ' · published ' . formatDate($w['release_date'], $w['release_precision'])),
            'className' => trim(($trackClass[$pubsTrack] ?? '') . ' item-pub prec-' . $w['release_precision']),
        ];
    }

    // Keywords per drawn item (`kw`), for the keyword lane; plus the list of keywords that have any.
    $kwByItem = [];
    foreach ([
        'e' => 'SELECT ek.event_id AS id, k.name FROM event_keyword ek JOIN keyword k ON k.id = ek.keyword_id',
        'p' => 'SELECT pk.period_id AS id, k.name FROM period_keyword pk JOIN keyword k ON k.id = pk.keyword_id',
        'w' => 'SELECT wk.publication_id AS id, k.name FROM publication_keyword wk JOIN keyword k ON k.id = wk.keyword_id',
    ] as $prefix => $sql) {
        foreach (q($sql) as $r) {
            $kwByItem[$prefix . $r['id']][] = $r['name'];
        }
    }
    $kwCount = [];
    foreach ($items as &$it) {
        if (isset($kwByItem[$it['id']])) {
            $it['kw'] = $kwByItem[$it['id']];
            foreach ($it['kw'] as $name) {
                $kwCount[$name] = ($kwCount[$name] ?? 0) + 1;
            }
        }
    }
    unset($it);
    uksort($kwCount, 'strcasecmp');
    $keywords = [];
    foreach ($kwCount as $name => $n) {
        $keywords[] = ['name' => (string) $name, 'count' => $n];
    }

    // Main author = person with the most publications; the viewer opens on their lifetime.
    $author = q1(
        'SELECT pr.id, pr.first_name, pr.surname, pr.date_of_birth, pr.date_of_death
           FROM person pr JOIN publication pb ON pb.author_id = pr.id
          GROUP BY pr.id ORDER BY COUNT(*) DESC LIMIT 1'
    );

    jsonOut([
        'meta' => [
            'author' => $author ? [
                'id'    => (int) $author['id'],
                'name'  => personName($author),
                'start' => $author['date_of_birth'],
                'end'   => $author['date_of_death'],
            ] : null,
            'keywords' => $keywords,
        ],
        'groups' => $groups,
        'items'  => $items,
    ]);
});
