<?php
// Writes a database dump to the backup folder (config backup_dir).
// Usage: php tools/backup.php [tag]      e.g. php tools/backup.php before-reset

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/backup.php';

try {
    $name = createBackup($argv[1] ?? 'manual');
    echo 'Backup written: ' . backupDir() . DIRECTORY_SEPARATOR . $name . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
