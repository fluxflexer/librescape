<?php
// Lists every entry that has no source yet. Exit code 1 if any are missing.
// Usage: php tools/check_sources.php
// Period start/end markers are exempt (their period carries the source).

declare(strict_types=1);
require __DIR__ . '/../lib/db.php';

$checks = [
    'person'      => "SELECT id, CONCAT(first_name, ' ', surname) AS label FROM person",
    'event'       => 'SELECT id, name AS label FROM event WHERE is_marker = 0',
    'period'      => 'SELECT id, name AS label FROM period',
    'publication' => 'SELECT id, title AS label FROM publication',
];

$missing = 0;
foreach ($checks as $type => $sql) {
    $rows = q("SELECT x.id, x.label FROM ($sql) x
                WHERE NOT EXISTS (SELECT 1 FROM source_ref r WHERE r.entity_type = ? AND r.entity_id = x.id)
                ORDER BY x.id", [$type]);
    foreach ($rows as $r) {
        printf("missing source: %-11s #%-4d %s\n", $type, $r['id'], $r['label']);
        $missing++;
    }
}

$unused = q('SELECT code FROM source s WHERE NOT EXISTS (SELECT 1 FROM source_ref r WHERE r.source_id = s.id) ORDER BY code');
foreach ($unused as $u) {
    echo "note: source {$u['code']} is not cited by any entry\n";
}

echo $missing ? "$missing entr" . ($missing === 1 ? 'y' : 'ies') . " without a source\n" : "All entries have a source\n";
exit($missing ? 1 : 0);
