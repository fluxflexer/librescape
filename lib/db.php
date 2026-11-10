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

/**
 * Message to show while the public timeline is in maintenance mode (switched in the admin app), else null.
 */
function maintenanceMessage(): ?string
{
    try {
        $rows = q("SELECT name, value FROM app_setting WHERE name IN ('maintenance_mode', 'maintenance_message')");
    } catch (PDOException $e) {
        return null;   // settings table not there yet (before the first migration)
    }
    $set = array_column($rows, 'value', 'name');
    return ($set['maintenance_mode'] ?? '0') === '1'
        ? ($set['maintenance_message'] ?: 'Librescape is being updated and will be back shortly.')
        : null;
}

/** Wrap an API handler so errors come back as JSON instead of HTML. Answers 503 in maintenance mode. */
function apiRun(callable $handler): void
{
    if (($msg = maintenanceMessage()) !== null) {
        header('Retry-After: 600');
        jsonOut(['error' => $msg, 'maintenance' => true], 503);
    }
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
        case 'season': return seasonLabel($y, $m);
        default:       return $d . ' ' . $months[$m] . ' ' . $y;
    }
}

/**
 * "Spring 1937", "Summer 1937", "Autumn 1937"; winter spans two years: "Winter 1942/43".
 * Meteorological seasons: spring Mar–May, summer Jun–Aug, autumn Sep–Nov, winter Dec–Feb.
 */
function seasonLabel(int $y, int $m): string
{
    if ($m === 12 || $m <= 2) {
        $from = $m === 12 ? $y : $y - 1;
        $to = $from + 1;
        // 1942/43, but 1999/2000 in full
        return 'Winter ' . $from . '/' . (intdiv($from, 100) === intdiv($to, 100) ? sprintf('%02d', $to % 100) : $to);
    }
    return ($m <= 5 ? 'Spring' : ($m <= 8 ? 'Summer' : 'Autumn')) . ' ' . $y;
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
