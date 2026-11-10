<?php
/**
 * Applies pending schema migrations from sql/migrations/ (files NNN_name.sql, in name order).
 * Applied files are recorded in table schema_migration, so each runs once.
 *
 *   php tools/migrate.php            apply pending migrations
 *   php tools/migrate.php --status   list applied / pending
 *
 * The database is the master copy of the data: never change a live schema by editing
 * sql/01_schema.sql; add a new migration file instead.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/db.php';

$db = getDb();
$db->exec('CREATE TABLE IF NOT EXISTS schema_migration (
    name        VARCHAR(100) PRIMARY KEY,
    applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

$applied = array_column(q('SELECT name FROM schema_migration'), 'name');
$files = glob(__DIR__ . '/../sql/migrations/*.sql') ?: [];
sort($files, SORT_STRING);

if (in_array('--status', $argv, true)) {
    foreach ($files as $f) {
        $name = basename($f);
        echo (in_array($name, $applied, true) ? 'applied  ' : 'PENDING  ') . $name . "\n";
    }
    exit(0);
}

/** Split a migration file into statements (no semicolons inside string literals, please). */
function statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)), 'strlen'));
}

$count = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (in_array($name, $applied, true)) {
        continue;
    }
    foreach (statements((string) file_get_contents($f)) as $i => $stmt) {
        try {
            $db->exec($stmt);
        } catch (PDOException $e) {
            // DDL commits implicitly, so a half-applied migration has to be fixed by hand.
            fwrite(STDERR, "Migration $name failed at statement " . ($i + 1) . ': ' . $e->getMessage() . "\n");
            exit(1);
        }
    }
    q('INSERT INTO schema_migration (name) VALUES (?)', [$name]);
    echo "applied $name\n";
    $count++;
}
echo $count ? "$count migration(s) applied\n" : "Schema is up to date\n";
