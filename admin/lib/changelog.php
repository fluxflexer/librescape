<?php
// Change log: who changed which entry, when, and the old/new values.

declare(strict_types=1);

const ENTITY_LABELS = [
    'person' => 'Person', 'event' => 'Event', 'period' => 'Period', 'publication' => 'Work', 'edition' => 'Publication',
    'relationship' => 'Relation', 'source' => 'Source', 'keyword' => 'Keyword', 'track' => 'Lane',
    'relation_type' => 'Relation type', 'pub_category' => 'Category', 'user' => 'User', 'setting' => 'Setting',
    'backup' => 'Backup',
];

/**
 * Records one change. $changes: {field: [old, new]} for updates, the full record for creates/deletes.
 * Writes nothing for an update without changes.
 */
function logChange(string $type, ?int $id, string $action, ?string $label, array $changes = []): void
{
    if ($action === 'update' && !$changes) {
        return;
    }
    $u = currentUser();
    q('INSERT INTO change_log (user_id, user_name, entity_type, entity_id, action, label, changes) VALUES (?, ?, ?, ?, ?, ?, ?)', [
        $u['id'] ?? null,
        $u['display_name'] ?? 'system',
        $type, $id, $action,
        $label !== null ? mb_substr($label, 0, 300) : null,
        $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

/** {field: [old, new]} for every field in $fields whose value differs (compared as strings; '' equals null). */
function diffRecords(array $old, array $new, array $fields): array
{
    $norm = static fn($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : ($v === null || $v === '' ? null : (string) $v);
    $out = [];
    foreach ($fields as $f) {
        $a = $norm($old[$f] ?? null);
        $b = $norm($new[$f] ?? null);
        if ($a !== $b) {
            $out[$f] = [$old[$f] ?? null, $new[$f] ?? null];
        }
    }
    return $out;
}

/** Change-log rows, newest first. Filters: type, id, user_id, action, from, to (Y-m-d). */
function changeLog(array $filter = [], int $limit = 50, int $offset = 0): array
{
    [$where, $params] = changeLogWhere($filter);
    return q("SELECT * FROM change_log $where ORDER BY changed_at DESC, id DESC LIMIT " . max(1, $limit) . ' OFFSET ' . max(0, $offset), $params);
}

function changeLogCount(array $filter = []): int
{
    [$where, $params] = changeLogWhere($filter);
    return (int) q1("SELECT COUNT(*) AS n FROM change_log $where", $params)['n'];
}

function changeLogWhere(array $filter): array
{
    $w = [];
    $p = [];
    foreach (['type' => 'entity_type', 'id' => 'entity_id', 'user_id' => 'user_id', 'action' => 'action'] as $k => $col) {
        if (isset($filter[$k]) && $filter[$k] !== '') {
            $w[] = "$col = ?";
            $p[] = $filter[$k];
        }
    }
    if (!empty($filter['from'])) {
        $w[] = 'changed_at >= ?';
        $p[] = $filter['from'] . ' 00:00:00';
    }
    if (!empty($filter['to'])) {
        $w[] = 'changed_at <= ?';
        $p[] = $filter['to'] . ' 23:59:59';
    }
    return [$w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

/** Renders the change-log rows as a table; $showEntry adds the entry column (off on an entry's own page). */
function renderChangeLog(array $rows, bool $showEntry = true): string
{
    if (!$rows) {
        return '<p class="muted">No changes recorded yet.</p>';
    }
    $html = '<table class="list log"><thead><tr><th>When</th><th>Who</th>' . ($showEntry ? '<th>Entry</th>' : '')
          . '<th>Action</th><th>Details</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $entry = '';
        if ($showEntry) {
            $label = (ENTITY_LABELS[$r['entity_type']] ?? $r['entity_type']) . ' · ' . ($r['label'] ?? '#' . $r['entity_id']);
            $link = in_array($r['entity_type'], ['person', 'event', 'period', 'publication', 'edition'], true) && $r['action'] !== 'delete' && $r['entity_id']
                ? '<a href="' . h(adminUrl('edit.php') . '?type=' . $r['entity_type'] . '&id=' . $r['entity_id']) . '">' . h($label) . '</a>'
                : h($label);
            $entry = "<td>$link</td>";
        }
        $html .= '<tr><td class="nowrap">' . h(date('j M Y H:i', strtotime($r['changed_at']))) . '</td><td>' . h($r['user_name']) . '</td>'
               . $entry . '<td><span class="badge act-' . h($r['action']) . '">' . h($r['action']) . '</span></td><td>'
               . renderChanges($r['action'], $r['changes']) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/** Readable names of stored fields in the change log. */
const FIELD_LABELS = [
    'track_id' => 'lane', 'parent_period_id' => 'part of period', 'gestation_period_id' => 'writing period',
    'person1_id' => 'person', 'person2_id' => 'second person', 'author_id' => 'author', 'author2_id' => 'second author',
    'author3_id' => 'third author', 'parent_work_id' => 'part of work', 'category_id' => 'category',
    'start' => 'from', 'end' => 'to', 'birth' => 'born', 'death' => 'died', 'release' => 'date',
    'is_active' => 'active', 'source_type' => 'kind', 'published_date' => 'published', 'accessed_date' => 'accessed',
    'published_in' => 'published in', 'contents' => 'contains', 'isbn' => 'ISBN',
];

function renderChanges(string $action, ?string $json): string
{
    $c = $json ? json_decode($json, true) : null;
    if (!is_array($c) || !$c) {
        return '';
    }
    $show = static function ($v): string {
        if ($v === null || $v === '') {
            return '<span class="muted">–</span>';
        }
        $s = is_array($v) ? implode(', ', array_map(fn($x) => is_array($x) ? implode(' ', $x) : (string) $x, $v)) : (string) $v;
        return h(mb_strlen($s) > 140 ? mb_substr($s, 0, 140) . '…' : $s);
    };
    $out = '<details><summary>' . count($c) . ' field' . (count($c) === 1 ? '' : 's') . '</summary><dl class="changes">';
    foreach ($c as $field => $v) {
        $out .= '<dt>' . h(FIELD_LABELS[$field] ?? str_replace('_', ' ', (string) $field)) . '</dt><dd>';
        $out .= $action === 'update' && is_array($v) && array_key_exists(0, $v) && array_key_exists(1, $v) && count($v) === 2
            ? $show($v[0]) . ' <span class="arrow">→</span> ' . $show($v[1])
            : $show($v);
        $out .= '</dd>';
    }
    return $out . '</dl></details>';
}
