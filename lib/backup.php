<?php
// Database dumps with mysqldump, stored outside the web root.
// Used by tools/backup.php (CLI, called by deploy.ps1 before -ResetDb) and by admin/backups.php.

declare(strict_types=1);
require_once __DIR__ . '/db.php';

function appConfig(): array
{
    static $cfg = null;
    return $cfg ??= (require __DIR__ . '/../config.php') + [
        'mysqldump_path' => 'C:\XAMPP\mysql\bin\mysqldump.exe',
        'backup_dir'     => 'C:\XAMPP\librescape-backups',
    ];
}

function backupDir(): string
{
    $dir = rtrim(appConfig()['backup_dir'], '\/');
    if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
        throw new RuntimeException("Cannot create backup folder $dir");
    }
    return $dir;
}

/** Writes a full dump (structure + data) and returns the file name. $tag goes into the name, e.g. "before-reset". */
function createBackup(string $tag = 'manual'): string
{
    $cfg = appConfig();
    $tag = preg_replace('/[^a-z0-9-]+/', '-', strtolower($tag)) ?: 'manual';
    $name = 'librescape-' . date('Y-m-d-His') . '-' . $tag . '.sql';
    $path = backupDir() . DIRECTORY_SEPARATOR . $name;

    $cmd = [$cfg['mysqldump_path'], '--host=' . $cfg['db_host'], '--user=' . $cfg['db_user'],
            '--single-transaction', '--routines', '--default-character-set=utf8mb4',
            '--result-file=' . $path, $cfg['db_name']];
    // Password via environment, so it never shows up in a process list.
    $env = getenv() + ['MYSQL_PWD' => $cfg['db_pass']];
    $env['MYSQL_PWD'] = $cfg['db_pass'];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    if (!is_resource($proc)) {
        throw new RuntimeException('Cannot start mysqldump');
    }
    stream_get_contents($pipes[1]);
    $err = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($code !== 0 || !is_file($path) || filesize($path) === 0) {
        @unlink($path);
        throw new RuntimeException('mysqldump failed' . ($err !== '' ? ": $err" : " (exit $code)"));
    }
    return $name;
}

/** Existing dumps, newest first: [{name, size, time}]. */
function listBackups(): array
{
    $out = [];
    foreach (glob(backupDir() . DIRECTORY_SEPARATOR . 'librescape-*.sql') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
    }
    usort($out, fn($a, $b) => $b['time'] <=> $a['time']);
    return $out;
}

/** Full path of an existing dump, or null; only accepts our own file names (no path tricks). */
function backupPath(string $name): ?string
{
    if (!preg_match('/^librescape-[0-9-]+-[a-z0-9-]+\.sql$/', $name)) {
        return null;
    }
    $path = backupDir() . DIRECTORY_SEPARATOR . $name;
    return is_file($path) ? $path : null;
}
