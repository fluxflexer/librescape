<?php
// Shared helpers: PDO connection, JSON output, date formatting.

declare(strict_types=1);

function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $cfg = require __DIR__ . '/../config.php';
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['db_host'], $cfg['db_name']);
        $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

/** Run a prepared query and return all rows. */
function q(string $sql, array $params = []): array
{
    $stmt = getDb()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Run a prepared query and return the first row or null. */
function q1(string $sql, array $params = []): ?array
{
    $rows = q($sql, $params);
    return $rows[0] ?? null;
}

function jsonOut($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Wrap an API handler so errors come back as JSON instead of HTML. */
function apiRun(callable $handler): void
{
    try {
        $handler();
    } catch (Throwable $e) {
        error_log('librescape: ' . $e->getMessage());
        jsonOut(['error' => 'Server error'], 500);
    }
}

/** Date part (YYYY-MM-DD) of a DATE/DATETIME value. */
function isoDate(?string $ts): ?string
{
    return $ts === null ? null : substr($ts, 0, 10);
}

/** Human-readable date according to its precision. */
function formatDate(?string $ts, string $precision = 'day'): string
{
    if ($ts === null || $ts === '') {
        return '';
    }
    $y = (int) substr($ts, 0, 4);
    $m = (int) substr($ts, 5, 2);
    $d = (int) substr($ts, 8, 2);
    $months = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    switch ($precision) {
        case 'month':  return $months[$m] . ' ' . $y;
        case 'year':   return (string) $y;
        case 'decade': return intdiv($y, 10) * 10 . 's';
        case 'circa':  return 'c. ' . $y;
        default:       return $d . ' ' . $months[$m] . ' ' . $y;
    }
}

function formatRange(?string $startTs, string $startPrec, ?string $endTs, string $endPrec): string
{
    return formatDate($startTs, $startPrec) . ' – ' . formatDate($endTs, $endPrec);
}

function personName(?array $p): string
{
    if ($p === null) {
        return '';
    }
    return trim(($p['first_name'] ?? '') . ' ' . ($p['surname'] ?? ''));
}

/** Escape LIKE wildcards in user input. */
function likeEscape(string $s): string
{
    return addcslashes($s, '%_\\');
}
