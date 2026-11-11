<?php
// List of entries of one type with search, filters, sorting and paging.
//   list.php?type=event|period|publication|edition|person [&q=…&track=…&keyword=…&nosource=1&sort=…&dir=asc|desc&page=…]
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/entities.php';
requireLogin();

const LIST_PAGE_SIZE = 50;

$type = (string) ($_GET['type'] ?? '');
if (!in_array($type, ENTRY_TYPES, true)) {
    redirect(adminUrl('index.php'));
}
$f = [
    'q'        => trim((string) ($_GET['q'] ?? '')),
    'track'    => (int) ($_GET['track'] ?? 0),
    'keyword'  => trim((string) ($_GET['keyword'] ?? '')),
    'category' => (int) ($_GET['category'] ?? 0),
    'nosource' => !empty($_GET['nosource']),
];
$page = max(1, (int) ($_GET['page'] ?? 1));
$dir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';

// Per type: base query (alias x, with the columns listed), searchable columns, sortable columns.
$like = '%' . likeEscape($f['q']) . '%';
$config = [
    'event' => [
        'title' => 'Events', 'new' => 'New event',
        'from'  => 'event x JOIN pit pt ON pt.id = x.pit_id LEFT JOIN track t ON t.id = x.track_id LEFT JOIN period pp ON pp.id = x.parent_period_id',
        'cols'  => 'x.id, x.name AS label, pt.ts AS d, pt.date_precision AS dp, t.name AS track, pp.name AS parent',
        'base'  => 'x.is_marker = 0',
        'search'=> ['x.name', 'x.short_name', 'x.description'],
        'sort'  => ['label' => 'x.name', 'date' => 'pt.ts', 'track' => 't.sort_order', 'parent' => 'pp.name'],
        'default' => 'date',
        'kw'    => ['event_keyword', 'event_id'],
        'head'  => ['label' => 'Name', 'date' => 'Date', 'track' => 'Lane', 'parent' => 'Part of'],
    ],
    'period' => [
        'title' => 'Periods', 'new' => 'New period',
        'from'  => 'period x JOIN event es ON es.id = x.start_event_id JOIN pit ps ON ps.id = es.pit_id
                   JOIN event ef ON ef.id = x.end_event_id JOIN pit pf ON pf.id = ef.pit_id
                   LEFT JOIN track t ON t.id = x.track_id LEFT JOIN period pp ON pp.id = x.parent_period_id',
        'cols'  => 'x.id, x.name AS label, ps.ts AS d, ps.date_precision AS dp, pf.ts AS d2, pf.date_precision AS dp2, t.name AS track, pp.name AS parent',
        'base'  => '1 = 1',
        'search'=> ['x.name', 'x.description'],
        'sort'  => ['label' => 'x.name', 'date' => 'ps.ts', 'track' => 't.sort_order', 'parent' => 'pp.name'],
        'default' => 'date',
        'kw'    => ['period_keyword', 'period_id'],
        'head'  => ['label' => 'Name', 'date' => 'From – to', 'track' => 'Lane', 'parent' => 'Part of'],
    ],
    'publication' => [
        'title' => 'Works', 'new' => 'New work',
        'from'  => 'publication x LEFT JOIN pub_category c ON c.id = x.category_id LEFT JOIN person a ON a.id = x.author_id
                   LEFT JOIN publication pw ON pw.id = x.parent_work_id',
        'cols'  => "x.id, x.title AS label, x.release_date AS d, x.release_precision AS dp, c.category AS track,
                    CONCAT_WS(' ', a.first_name, a.surname) AS author, pw.title AS parent",
        'base'  => '1 = 1',
        'search'=> ['x.title', 'x.short_title', 'x.shortcode', 'x.description'],
        'sort'  => ['label' => 'x.title', 'date' => 'x.release_date', 'track' => 'c.category', 'author' => 'a.surname'],
        'default' => 'date',
        'kw'    => ['publication_keyword', 'publication_id'],
        'head'  => ['label' => 'Title', 'date' => 'Published', 'track' => 'Category', 'author' => 'Author'],
    ],
    'edition' => [
        'title' => 'Publications', 'new' => 'New publication',
        'from'  => 'edition x',
        'cols'  => "x.id, x.title AS label, x.release_date AS d, x.release_precision AS dp, x.kind, x.publisher,
                    (SELECT COUNT(*) FROM edition_work ew WHERE ew.edition_id = x.id) AS works",
        'base'  => '1 = 1',
        'search'=> ['x.title', 'x.publisher', 'x.isbn', 'x.description'],
        'sort'  => ['label' => 'x.title', 'date' => 'x.release_date', 'kind' => 'x.kind', 'publisher' => 'x.publisher', 'works' => 'works'],
        'default' => 'date',
        'kw'    => ['edition_keyword', 'edition_id'],
        'head'  => ['label' => 'Title', 'date' => 'Published', 'kind' => 'Kind', 'publisher' => 'Publisher', 'works' => 'Works'],
    ],
    'person' => [
        'title' => 'People', 'new' => 'New person',
        'from'  => 'person x',
        'cols'  => "x.id, CONCAT_WS(' ', x.first_name, x.surname) AS label, x.date_of_birth AS d, x.birth_precision AS dp,
                    x.date_of_death AS d2, x.death_precision AS dp2, x.shortcode AS code",
        'base'  => '1 = 1',
        'search'=> ["CONCAT_WS(' ', x.first_name, x.surname)", 'x.shortcode', 'x.place_of_birth'],
        'sort'  => ['label' => 'x.surname', 'date' => 'x.date_of_birth', 'code' => 'x.shortcode'],
        'default' => 'label',
        'kw'    => null,
        'head'  => ['label' => 'Name', 'date' => 'Born – died', 'code' => 'Short code'],
    ],
][$type];

$sort = array_key_exists($_GET['sort'] ?? '', $config['sort']) ? $_GET['sort'] : $config['default'];

$where = [$config['base']];
$params = [];
if ($f['q'] !== '') {
    $where[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $config['search'])) . ')';
    array_push($params, ...array_fill(0, count($config['search']), $like));
}
if ($f['track'] && in_array($type, ['event', 'period'], true)) {
    $where[] = 'x.track_id = ?';
    $params[] = $f['track'];
}
if ($f['category'] && $type === 'publication') {
    $where[] = 'x.category_id = ?';
    $params[] = $f['category'];
}
if ($f['keyword'] !== '' && $config['kw']) {
    [$kt, $kc] = $config['kw'];
    $where[] = "EXISTS (SELECT 1 FROM $kt kx JOIN keyword k ON k.id = kx.keyword_id WHERE kx.$kc = x.id AND k.name = ?)";
    $params[] = $f['keyword'];
}
if ($f['nosource']) {
    $where[] = 'NOT EXISTS (SELECT 1 FROM source_ref r WHERE r.entity_type = ? AND r.entity_id = x.id)';
    $params[] = $type;
}
$whereSql = implode(' AND ', $where);
$total = (int) q1("SELECT COUNT(*) AS n FROM {$config['from']} WHERE $whereSql", $params)['n'];
$pages = max(1, (int) ceil($total / LIST_PAGE_SIZE));
$page = min($page, $pages);
$orderCol = $config['sort'][$sort];
$rows = q("SELECT {$config['cols']},
                  (SELECT COUNT(*) FROM source_ref r WHERE r.entity_type = '$type' AND r.entity_id = x.id) AS nsrc
             FROM {$config['from']} WHERE $whereSql
            ORDER BY $orderCol IS NULL, $orderCol $dir, x.id
            LIMIT " . LIST_PAGE_SIZE . ' OFFSET ' . (($page - 1) * LIST_PAGE_SIZE), $params);

$query = static function (array $change) use ($type, $f, $sort, $dir): string {
    $p = array_merge(['type' => $type, 'q' => $f['q'], 'track' => $f['track'] ?: '', 'keyword' => $f['keyword'],
                      'category' => $f['category'] ?: '', 'nosource' => $f['nosource'] ? 1 : '', 'sort' => $sort,
                      'dir' => strtolower($dir)], $change);
    return h(adminUrl('list.php') . '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null)));
};

pageHeader($config['title'], $type);
?>
<div class="page-head">
    <h1><?= h($config['title']) ?> <span class="badge"><?= $total ?></span></h1>
    <a class="button primary" href="<?= h(adminUrl("edit.php?type=$type")) ?>">+ <?= h($config['new']) ?></a>
</div>

<form method="get" class="filters" role="search">
    <input type="hidden" name="type" value="<?= h($type) ?>">
    <label>Search <input type="search" name="q" value="<?= h($f['q']) ?>" placeholder="Name, description…" autofocus></label>
    <?php if (in_array($type, ['event', 'period'], true)): ?>
        <label>Lane <select name="track"><option value="">all</option>
            <?php foreach (tracks() as $t): ?><option value="<?= (int) $t['id'] ?>"<?= $f['track'] === (int) $t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <?php if ($type === 'publication'): ?>
        <label>Category <select name="category"><option value="">all</option>
            <?php foreach (q('SELECT id, category FROM pub_category ORDER BY category') as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $f['category'] === (int) $c['id'] ? ' selected' : '' ?>><?= h($c['category']) ?></option><?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <?php if ($config['kw']): ?>
        <label>Keyword <select name="keyword"><option value="">all</option>
            <?php foreach (q('SELECT name FROM keyword ORDER BY name') as $k): ?><option<?= $f['keyword'] === $k['name'] ? ' selected' : '' ?>><?= h($k['name']) ?></option><?php endforeach; ?>
        </select></label>
    <?php endif; ?>
    <label class="inline"><input type="checkbox" name="nosource" value="1"<?= $f['nosource'] ? ' checked' : '' ?>> without source</label>
    <button type="submit">Filter</button>
    <?php if ($f['q'] !== '' || $f['track'] || $f['keyword'] !== '' || $f['category'] || $f['nosource']): ?>
        <a href="<?= h(adminUrl("list.php?type=$type")) ?>">Reset</a>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <p class="muted">Nothing found.</p>
<?php else: ?>
<table class="list entries">
    <thead><tr>
        <?php foreach ($config['head'] as $col => $label):
            $sortable = isset($config['sort'][$col]);
            $active = $sort === $col; ?>
            <th<?= $active ? ' aria-sort="' . ($dir === 'ASC' ? 'ascending' : 'descending') . '"' : '' ?>>
                <?php if ($sortable): ?>
                    <a href="<?= $query(['sort' => $col, 'dir' => $active && $dir === 'ASC' ? 'desc' : 'asc', 'page' => null]) ?>"><?= h($label) ?><?= $active ? ($dir === 'ASC' ? ' ▲' : ' ▼') : '' ?></a>
                <?php else: ?><?= h($label) ?><?php endif; ?>
            </th>
        <?php endforeach; ?>
        <th>Sources</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $date = $r['d'] ? formatDate($r['d'], $r['dp']) : '';
        if (array_key_exists('d2', $r)) {
            $date = ($date ?: '?') . ' – ' . ($r['d2'] ? formatDate($r['d2'], $r['dp2']) : '');
            $date = trim($date, ' –') === '?' ? '' : $date;
        } ?>
        <tr>
            <td><a href="<?= h(adminUrl("edit.php?type=$type&id={$r['id']}")) ?>"><?= h($r['label']) ?></a></td>
            <td class="nowrap"><?= h($date) ?></td>
            <?php foreach (array_slice(array_keys($config['head']), 2) as $col): ?>
                <td><?= h($col === 'kind' ? (EDITION_KINDS[$r[$col]] ?? $r[$col]) : (string) ($r[$col] ?? '')) ?></td>
            <?php endforeach; ?>
            <td><?= (int) $r['nsrc'] ?: '<span class="badge warn">none</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php if ($pages > 1): ?>
<nav class="pager">
    <?php if ($page > 1): ?><a href="<?= $query(['page' => $page - 1]) ?>">‹ Previous</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= $query(['page' => $page + 1]) ?>">Next ›</a><?php endif; ?>
</nav>
<?php endif;
pageFooter();
