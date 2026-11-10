<?php
// Lists every entry that has no source yet. Exit code 1 if any are missing.
// Usage: php tools/check_sources.php
// Period start/end markers are exempt (their period carries the source).

declare(strict_types=1);
require_once __DIR__ . '/../lib/entries.php';

$missing = entriesWithoutSource();
foreach ($missing as $m) {
    printf("missing source: %-11s #%-4d %s\n", $m['type'], $m['id'], $m['label']);
}

$unused = q('SELECT code FROM source s WHERE NOT EXISTS (SELECT 1 FROM source_ref r WHERE r.source_id = s.id) ORDER BY code');
foreach ($unused as $u) {
    echo "note: source {$u['code']} is not cited by any entry\n";
}

$n = count($missing);
echo $n ? "$n entr" . ($n === 1 ? 'y' : 'ies') . " without a source\n" : "All entries have a source\n";
exit($n ? 1 : 0);
