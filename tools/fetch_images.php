<?php
/**
 * Fetches freely licensed pictures from Wikipedia / Wikimedia Commons for every person, event, period,
 * work and publication that has a Wikipedia weblink (the first one by sort_order). The rules are in lib/images.php.
 * (The admin app fetches the picture of an entry by itself when it is saved with a new Wikipedia link.)
 *
 *   php tools/fetch_images.php            fetch metadata, download missing files
 *   php tools/fetch_images.php --force    re-download every file
 *   php tools/fetch_images.php --dry-run  only report what would be stored
 *
 * Files go to media/wiki/ of the folder this script runs from; deploy.ps1 copies them to the web root
 * without deleting pictures the admin app stored there.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/images.php';

$stats = fetchImages(imageEntities(), [
    'force'  => in_array('--force', $argv, true),
    'dryRun' => in_array('--dry-run', $argv, true),
    'log'    => static function (string $line): void { echo $line, "\n"; },
]);

printf("\nImages stored: %d | no free image: %d | rejected by licence: %d | errors: %d\n",
    $stats['stored'], $stats['none'], $stats['rejected'], $stats['error']);
