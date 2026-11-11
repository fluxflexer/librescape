<?php
/**
 * Freely licensed pictures from Wikipedia / Wikimedia Commons, one per entry, taken from the
 * main image of the entry's first Wikipedia link. Used by tools/fetch_images.php (all entries)
 * and by the admin app (the entry that was just saved).
 *
 * Licence rule: only the article's main image, only if Wikipedia marks it as free
 * (pilicense=free) AND its Commons licence is on the allow-list below
 * (public domain, CC0, CC BY, CC BY-SA). Author + licence are stored for the credit line.
 * Files are saved as 480px thumbnails in media/wiki/ so visitors never contact Wikimedia.
 * Requests are batched (50 titles per call) to stay well inside Wikimedia's rate limits.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

const IMG_THUMB_WIDTH = 480;
const IMG_USER_AGENT  = 'LibrescapeTimeline/0.1 (local non-commercial timeline viewer; PHP)';
const IMG_MEDIA_DIR   = 'media/wiki';
const IMG_ALLOWED_LICENCE = '/^(public domain|pd\b|pd-|cc0|cc[ -]by(-sa)?(\s+\d(\.\d)?)?(\s+\w+)?$)/i';

/** Entry types that can have a picture, with the query for their label. */
const IMG_ENTITY_LABELS = [
    'person'      => "SELECT id, CONCAT(first_name, ' ', surname) AS label FROM person",
    'event'       => 'SELECT id, name AS label FROM event',
    'period'      => 'SELECT id, name AS label FROM period',
    'publication' => 'SELECT id, title AS label FROM publication',
    'edition'     => 'SELECT id, title AS label FROM edition',
];

/**
 * Options for the fetch: root (folder that contains media/), force (re-download files), dryRun,
 * attempts (per HTTP request), pause (seconds between API calls), log (callable taking a line of text).
 */
function imgOptions(array $o): array
{
    return $o + ['root' => dirname(__DIR__), 'force' => false, 'dryRun' => false, 'attempts' => 4, 'pause' => 1,
                 'log' => static function (string $line): void {}];
}

function imgHttp(string $url, array $o): ?string
{
    for ($attempt = 1; $attempt <= $o['attempts']; $attempt++) {
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => IMG_USER_AGENT,
            CURLOPT_ENCODING       => '',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$retryAfter) {
                if (stripos($line, 'Retry-After:') === 0) {
                    $retryAfter = (int) trim(substr($line, 12));
                }
                return strlen($line);
            },
        ]);
        // XAMPP's PHP often has no CA bundle configured; use the one shipped with Apache if present.
        if (!ini_get('curl.cainfo')) {
            foreach (['C:/XAMPP/apache/bin/curl-ca-bundle.crt', 'C:/XAMPP/php/extras/ssl/cacert.pem'] as $ca) {
                if (is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
            }
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $code > 0 && $code < 400) {
            return $body;
        }
        if (($code === 429 || $code >= 500) && $attempt < $o['attempts']) {
            $wait = max($retryAfter, 10 * $attempt);
            ($o['log'])("    HTTP $code, retrying in {$wait}s...");
            sleep($wait);
            continue;
        }
        ($o['log'])("    HTTP error ($code) $err for $url");
        return null;
    }
    return null;
}

function imgApiQuery(string $host, array $params, array $o): ?array
{
    $params += ['action' => 'query', 'format' => 'json', 'formatversion' => '2'];
    $body = imgHttp('https://' . $host . '/w/api.php?' . http_build_query($params), $o);
    if ($o['pause']) {
        sleep($o['pause']); // be polite to the API
    }
    return $body === null ? null : json_decode($body, true);
}

function imgPlainText(?string $html, int $max = 300): string
{
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
}

/** Map of requested title -> final title, following the API's normalisation and redirects. */
function imgTitleMap(array $query): array
{
    $map = [];
    foreach (['normalized', 'redirects'] as $k) {
        foreach ($query[$k] ?? [] as $r) {
            $map[$r['from']] = $r['to'];
        }
    }
    return $map;
}

function imgResolveTitle(string $t, array $map): string
{
    for ($i = 0; $i < 5 && isset($map[$t]); $i++) {
        $t = $map[$t];
    }
    return $t;
}

/** Main image per article, only if Wikipedia considers it free. [title => file name|null], or null if a request failed. */
function imgFreePageImages(string $host, array $titles, array $o): ?array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($titles)), 50) as $chunk) {
        $r = imgApiQuery($host, [
            'prop' => 'pageimages', 'piprop' => 'name', 'pilicense' => 'free', 'pilimit' => '50',
            'redirects' => '1', 'titles' => implode('|', $chunk),
        ], $o);
        if (!isset($r['query'])) {
            return null;
        }
        $map = imgTitleMap($r['query']);
        $byTitle = [];
        foreach ($r['query']['pages'] ?? [] as $pg) {
            $byTitle[$pg['title']] = $pg['pageimage'] ?? null;
        }
        foreach ($chunk as $t) {
            $out[$t] = $byTitle[imgResolveTitle($t, $map)] ?? null;
        }
    }
    return $out;
}

/** Licence + thumbnail info per file. [file name => info array | rejection reason string], or null if a request failed. */
function imgFileInfos(string $host, array $files, array $o): ?array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($files)), 50) as $chunk) {
        $r = imgApiQuery($host, [
            'prop'   => 'imageinfo',
            'titles' => implode('|', array_map(fn($f) => 'File:' . $f, $chunk)),
            'iiprop' => 'url|extmetadata|mime',
            'iiurlwidth' => (string) IMG_THUMB_WIDTH,
            'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist|Credit|NonFree|AttributionRequired',
        ], $o);
        if (!isset($r['query'])) {
            return null;
        }
        $map = imgTitleMap($r['query']);
        $pages = [];
        foreach ($r['query']['pages'] ?? [] as $pg) {
            $pages[$pg['title']] = $pg;
        }

        foreach ($chunk as $f) {
            $page = $pages[imgResolveTitle('File:' . $f, $map)] ?? null;
            $ii = $page['imageinfo'][0] ?? null;
            if (!$ii || empty($ii['thumburl'])) {
                $out[$f] = 'no image info';
                continue;
            }
            $meta = $ii['extmetadata'] ?? [];
            $licence = imgPlainText($meta['LicenseShortName']['value'] ?? '', 100);
            if (($meta['NonFree']['value'] ?? '') === 'true') {
                $out[$f] = "non-free ($licence)";
                continue;
            }
            if ($licence === '' || !preg_match(IMG_ALLOWED_LICENCE, $licence)) {
                $out[$f] = "licence not on allow-list: '$licence'";
                continue;
            }
            $out[$f] = [
                'file_title'           => $page['title'],
                'thumb_url'            => $ii['thumburl'],
                'width'                => (int) ($ii['thumbwidth'] ?? 0),
                'height'               => (int) ($ii['thumbheight'] ?? 0),
                'artist'               => imgPlainText($meta['Artist']['value'] ?? '')
                                          ?: imgPlainText($meta['Credit']['value'] ?? '')
                                          ?: 'Unknown author',
                'license'              => $licence,
                'license_url'          => $meta['LicenseUrl']['value'] ?? null,
                'attribution_required' => ($meta['AttributionRequired']['value'] ?? 'true') !== 'false',
                'description_url'      => $ii['descriptionurl'],
            ];
        }
    }
    return $out;
}

/** The first Wikipedia link of an entry (lowest sort_order), or null. */
function firstWikipediaUrl(string $type, int $id): ?string
{
    return q1("SELECT url FROM weblink WHERE entity_type = ? AND entity_id = ? AND link_type = 'wikipedia'
                ORDER BY sort_order, id LIMIT 1", [$type, $id])['url'] ?? null;
}

/** Entries with a Wikipedia link: [{type, id, label, wikipedia_url}]. $only = [type, id] limits it to one entry. */
function imageEntities(?array $only = null): array
{
    $out = [];
    foreach (IMG_ENTITY_LABELS as $type => $sql) {
        if ($only && $only[0] !== $type) {
            continue;
        }
        $rows = q("SELECT x.id, x.label,
                          (SELECT w.url FROM weblink w
                            WHERE w.entity_type = ? AND w.entity_id = x.id AND w.link_type = 'wikipedia'
                            ORDER BY w.sort_order, w.id LIMIT 1) AS wikipedia_url
                     FROM ($sql) x " . ($only ? 'WHERE x.id = ? ' : '') . 'HAVING wikipedia_url IS NOT NULL',
                  $only ? [$type, $only[1]] : [$type]);
        foreach ($rows as $row) {
            $out[] = ['type' => $type] + $row;
        }
    }
    return $out;
}

/** Removes an entry's picture (row and file). */
function deleteEntryImage(string $type, int $id, ?string $root = null): void
{
    $root ??= dirname(__DIR__);
    foreach (q('SELECT local_path FROM image WHERE entity_type = ? AND entity_id = ?', [$type, $id]) as $img) {
        $file = $root . '/' . $img['local_path'];
        if (is_file($file)) {
            @unlink($file);
        }
    }
    q('DELETE FROM image WHERE entity_type = ? AND entity_id = ?', [$type, $id]);
}

/**
 * Fetches and stores the pictures of the given entries (see imageEntities). Entries whose article has
 * no acceptable free picture lose any stored one. Returns counts: stored, none, rejected, error.
 */
function fetchImages(array $entities, array $o = []): array
{
    $o = imgOptions($o);
    $log = $o['log'];
    $byHost = [];
    foreach ($entities as $i => $e) {
        if (!preg_match('~^https?://([a-z\-]+\.(?:m\.)?wikipedia\.org)/wiki/([^?#]+)~i', $e['wikipedia_url'], $m)) {
            $log("  skip  {$e['type']} {$e['id']} ({$e['label']}): not a Wikipedia article URL");
            unset($entities[$i]);
            continue;
        }
        $entities[$i]['host']  = strtolower(str_replace('.m.', '.', $m[1]));
        $entities[$i]['title'] = rawurldecode(str_replace('_', ' ', $m[2]));
        $byHost[$entities[$i]['host']][] = $entities[$i]['title'];
    }

    // Two batched lookups per wiki: main images, then their licences.
    $pageImage = [];
    $fileInfo  = [];
    foreach ($byHost as $host => $titles) {
        $imgs = imgFreePageImages($host, $titles, $o);
        if ($imgs === null) {
            $log("Could not query $host - nothing changed for its entries.");
            continue;
        }
        $files = array_values(array_filter($imgs));
        $infos = $files ? imgFileInfos($host, $files, $o) : [];
        if ($infos === null) {
            $log("Could not read file info from $host - nothing changed for its entries.");
            continue;
        }
        $pageImage[$host] = $imgs;
        $fileInfo[$host]  = $infos;
    }

    $stats = ['stored' => 0, 'none' => 0, 'rejected' => 0, 'error' => 0];
    @mkdir($o['root'] . '/' . IMG_MEDIA_DIR, 0775, true);
    foreach ($entities as $e) {
        $label = "{$e['type']} {$e['id']} ({$e['label']})";
        if (!isset($pageImage[$e['host']])) {
            $stats['error']++; // request failed: leave any stored image untouched
            continue;
        }
        $file = $pageImage[$e['host']][$e['title']] ?? null;
        if ($file === null) {
            $log("  none  $label: no free main image");
            $stats['none']++;
            if (!$o['dryRun']) deleteEntryImage($e['type'], (int) $e['id'], $o['root']);
            continue;
        }
        $info = $fileInfo[$e['host']][$file] ?? 'no image info';
        if (is_string($info)) {
            $log("  skip  $label: $file - $info");
            $stats['rejected']++;
            if (!$o['dryRun']) deleteEntryImage($e['type'], (int) $e['id'], $o['root']);
            continue;
        }

        $ext = strtolower(pathinfo((string) parse_url($info['thumb_url'], PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) $ext = 'jpg';
        $rel = IMG_MEDIA_DIR . "/{$e['type']}-{$e['id']}.$ext";
        $abs = $o['root'] . '/' . $rel;
        if ($o['dryRun']) {
            $log("  ok    $label: {$info['file_title']} [{$info['license']}]");
            $stats['stored']++;
            continue;
        }

        // Re-download when forced, when the file is missing, or when the article now has a different picture.
        $stored = q1('SELECT file_title, local_path FROM image WHERE entity_type = ? AND entity_id = ?', [$e['type'], $e['id']]);
        if ($o['force'] || !is_file($abs) || ($stored && ($stored['file_title'] !== $info['file_title'] || $stored['local_path'] !== $rel))) {
            $bytes = imgHttp($info['thumb_url'], $o);
            if ($bytes === null || @getimagesizefromstring($bytes) === false) {
                $log("  error $label: download failed");
                $stats['error']++;
                continue;
            }
            foreach (glob($o['root'] . '/' . IMG_MEDIA_DIR . "/{$e['type']}-{$e['id']}.*") ?: [] as $old) @unlink($old);
            file_put_contents($abs, $bytes);
        }
        $size = @getimagesize($abs);
        q('INSERT INTO image (entity_type, entity_id, file_title, local_path, width, height, artist, license, license_url,
                              attribution_required, description_url, fetched_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
           ON DUPLICATE KEY UPDATE file_title = VALUES(file_title), local_path = VALUES(local_path), width = VALUES(width),
              height = VALUES(height), artist = VALUES(artist), license = VALUES(license), license_url = VALUES(license_url),
              attribution_required = VALUES(attribution_required), description_url = VALUES(description_url), fetched_at = NOW()',
          [$e['type'], $e['id'], $info['file_title'], $rel, $size[0] ?? $info['width'], $size[1] ?? $info['height'],
           $info['artist'], $info['license'], $info['license_url'], $info['attribution_required'] ? 1 : 0, $info['description_url']]);
        $log("  ok    $label: {$info['file_title']} [{$info['license']}]");
        $stats['stored']++;
    }
    return $stats;
}

/**
 * After an entry was saved: fetches its picture if its first Wikipedia link is new or changed, or if it has
 * none yet; removes the picture if the entry no longer has a Wikipedia link. Quick (one try per request,
 * no pauses) and never throws - a failure only means no picture for now.
 * Returns 'stored', 'none', 'removed', 'unchanged' or 'error'.
 */
function refreshEntryImage(string $type, int $id, ?string $urlBefore): string
{
    try {
        $url = firstWikipediaUrl($type, $id);
        $has = q1('SELECT 1 AS x FROM image WHERE entity_type = ? AND entity_id = ?', [$type, $id]) !== null;
        if ($url === null) {
            if ($has) {
                deleteEntryImage($type, $id);
                return 'removed';
            }
            return 'unchanged';
        }
        if ($url === $urlBefore && $has) {
            return 'unchanged';
        }
        $stats = fetchImages(imageEntities([$type, $id]), [
            'attempts' => 1, 'pause' => 0,
            'log' => static function (string $line): void { if (str_contains($line, 'error') || str_contains($line, 'Could not')) error_log('librescape images: ' . trim($line)); },
        ]);
        if ($stats['stored']) {
            return 'stored';
        }
        if ($stats['error']) {
            return 'error';
        }
        // "No free picture" is only news when the link is new or changed.
        return $url === $urlBefore ? 'unchanged' : 'none';
    } catch (Throwable $e) {
        error_log('librescape images: ' . $e->getMessage());
        return 'error';
    }
}
