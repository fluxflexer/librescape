<?php
/**
 * Fetches freely licensed pictures from Wikipedia / Wikimedia Commons for every
 * person, event, period and publication that has a Wikipedia weblink (the first one by sort_order).
 *
 *   php tools/fetch_images.php            fetch metadata, download missing files
 *   php tools/fetch_images.php --force    re-download every file
 *   php tools/fetch_images.php --dry-run  only report what would be stored
 *
 * Licence rule: only the article's main image, only if Wikipedia marks it as free
 * (pilicense=free) AND its Commons licence is on the allow-list below
 * (public domain, CC0, CC BY, CC BY-SA). Author + licence are stored for the credit line.
 * Files are saved as 480px thumbnails in media/wiki/ so visitors never contact Wikimedia.
 *
 * Requests are batched (50 titles per call) to stay well inside Wikimedia's rate limits.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/db.php';

const THUMB_WIDTH = 480;
const USER_AGENT  = 'LibrescapeTimeline/0.1 (local non-commercial timeline viewer; PHP)';
const MEDIA_DIR   = 'media/wiki';
const ALLOWED_LICENCE = '/^(public domain|pd\b|pd-|cc0|cc[ -]by(-sa)?(\s+\d(\.\d)?)?(\s+\w+)?$)/i';

$force  = in_array('--force', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);
$root   = dirname(__DIR__);
@mkdir($root . '/' . MEDIA_DIR, 0775, true);

// ------------------------------------------------------------------ HTTP

function http(string $url): ?string
{
    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => USER_AGENT,
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
        if ($code === 429 || $code >= 500) {
            $wait = max($retryAfter, 10 * $attempt);
            fwrite(STDERR, "    HTTP $code, retrying in {$wait}s...\n");
            sleep($wait);
            continue;
        }
        fwrite(STDERR, "    HTTP error ($code) $err for $url\n");
        return null;
    }
    fwrite(STDERR, "    giving up on $url\n");
    return null;
}

function apiQuery(string $host, array $params): ?array
{
    $params += ['action' => 'query', 'format' => 'json', 'formatversion' => '2'];
    $body = http('https://' . $host . '/w/api.php?' . http_build_query($params));
    sleep(1); // be polite to the API
    return $body === null ? null : json_decode($body, true);
}

function plainText(?string $html, int $max = 300): string
{
    $t = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
}

/** Map of requested title -> final title, following the API's normalisation and redirects. */
function titleMap(array $query): array
{
    $map = [];
    foreach (['normalized', 'redirects'] as $k) {
        foreach ($query[$k] ?? [] as $r) {
            $map[$r['from']] = $r['to'];
        }
    }
    return $map;
}

function resolveTitle(string $t, array $map): string
{
    for ($i = 0; $i < 5 && isset($map[$t]); $i++) {
        $t = $map[$t];
    }
    return $t;
}

// ------------------------------------------------------------------ Wikipedia lookups

/**
 * Main image per article, only if Wikipedia considers it free.
 * Returns [title => file name|null], or null if a request failed.
 */
function freePageImages(string $host, array $titles): ?array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($titles)), 50) as $chunk) {
        $r = apiQuery($host, [
            'prop' => 'pageimages', 'piprop' => 'name', 'pilicense' => 'free', 'pilimit' => '50',
            'redirects' => '1', 'titles' => implode('|', $chunk),
        ]);
        if (!isset($r['query'])) {
            return null;
        }
        $map = titleMap($r['query']);
        $byTitle = [];
        foreach ($r['query']['pages'] ?? [] as $pg) {
            $byTitle[$pg['title']] = $pg['pageimage'] ?? null;
        }
        foreach ($chunk as $t) {
            $out[$t] = $byTitle[resolveTitle($t, $map)] ?? null;
        }
    }
    return $out;
}

/**
 * Licence + thumbnail info per file.
 * Returns [file name => info array | rejection reason string], or null if a request failed.
 */
function fileInfos(string $host, array $files): ?array
{
    $out = [];
    foreach (array_chunk(array_values(array_unique($files)), 50) as $chunk) {
        $r = apiQuery($host, [
            'prop'   => 'imageinfo',
            'titles' => implode('|', array_map(fn($f) => 'File:' . $f, $chunk)),
            'iiprop' => 'url|extmetadata|mime',
            'iiurlwidth' => (string) THUMB_WIDTH,
            'iiextmetadatafilter' => 'LicenseShortName|LicenseUrl|Artist|Credit|NonFree|AttributionRequired',
        ]);
        if (!isset($r['query'])) {
            return null;
        }
        $map = titleMap($r['query']);
        $pages = [];
        foreach ($r['query']['pages'] ?? [] as $pg) {
            $pages[$pg['title']] = $pg;
        }

        foreach ($chunk as $f) {
            $page = $pages[resolveTitle('File:' . $f, $map)] ?? null;
            $ii = $page['imageinfo'][0] ?? null;
            if (!$ii || empty($ii['thumburl'])) {
                $out[$f] = 'no image info';
                continue;
            }
            $meta = $ii['extmetadata'] ?? [];
            $licence = plainText($meta['LicenseShortName']['value'] ?? '', 100);
            if (($meta['NonFree']['value'] ?? '') === 'true') {
                $out[$f] = "non-free ($licence)";
                continue;
            }
            if ($licence === '' || !preg_match(ALLOWED_LICENCE, $licence)) {
                $out[$f] = "licence not on allow-list: '$licence'";
                continue;
            }
            $out[$f] = [
                'file_title'           => $page['title'],
                'thumb_url'            => $ii['thumburl'],
                'width'                => (int) ($ii['thumbwidth'] ?? 0),
                'height'               => (int) ($ii['thumbheight'] ?? 0),
                'artist'               => plainText($meta['Artist']['value'] ?? '')
                                          ?: plainText($meta['Credit']['value'] ?? '')
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

// ------------------------------------------------------------------ main

// One article per entry: its first Wikipedia link (lowest sort_order).
$entities = [];
$labels = [
    'person'      => "SELECT id, CONCAT(first_name, ' ', surname) AS label FROM person",
    'event'       => 'SELECT id, name AS label FROM event',
    'period'      => 'SELECT id, name AS label FROM period',
    'publication' => 'SELECT id, title AS label FROM publication',
];
foreach ($labels as $type => $sql) {
    foreach (q("SELECT x.id, x.label,
                       (SELECT w.url FROM weblink w
                         WHERE w.entity_type = ? AND w.entity_id = x.id AND w.link_type = 'wikipedia'
                         ORDER BY w.sort_order, w.id LIMIT 1) AS wikipedia_url
                  FROM ($sql) x HAVING wikipedia_url IS NOT NULL", [$type]) as $row) {
        $entities[] = ['type' => $type] + $row;
    }
}

// Parse article URLs and group them by wiki host.
$byHost = [];
foreach ($entities as $i => $e) {
    if (!preg_match('~^https?://([a-z\-]+\.wikipedia\.org)/wiki/([^?#]+)~i', $e['wikipedia_url'], $m)) {
        echo "  skip  {$e['type']} {$e['id']} ({$e['label']}): not a Wikipedia article URL\n";
        unset($entities[$i]);
        continue;
    }
    $entities[$i]['host']  = strtolower($m[1]);
    $entities[$i]['title'] = rawurldecode(str_replace('_', ' ', $m[2]));
    $byHost[$entities[$i]['host']][] = $entities[$i]['title'];
}

// Two batched lookups per wiki: main images, then their licences.
$pageImage = [];
$fileInfo  = [];
foreach ($byHost as $host => $titles) {
    $imgs = freePageImages($host, $titles);
    if ($imgs === null) {
        fwrite(STDERR, "Could not query $host - nothing changed for its entries.\n");
        continue;
    }
    $files = array_values(array_filter($imgs));
    $infos = $files ? fileInfos($host, $files) : [];
    if ($infos === null) {
        fwrite(STDERR, "Could not read file info from $host - nothing changed for its entries.\n");
        continue;
    }
    $pageImage[$host] = $imgs;
    $fileInfo[$host]  = $infos;
}

$stats = ['stored' => 0, 'none' => 0, 'rejected' => 0, 'error' => 0];
$upsert = getDb()->prepare(
    'INSERT INTO image (entity_type, entity_id, file_title, local_path, width, height, artist, license, license_url,
                        attribution_required, description_url, fetched_at)
     VALUES (:t, :id, :file, :path, :w, :h, :artist, :lic, :licurl, :attr, :desc, NOW())
     ON DUPLICATE KEY UPDATE file_title = VALUES(file_title), local_path = VALUES(local_path), width = VALUES(width),
        height = VALUES(height), artist = VALUES(artist), license = VALUES(license), license_url = VALUES(license_url),
        attribution_required = VALUES(attribution_required), description_url = VALUES(description_url), fetched_at = NOW()'
);
$delete = getDb()->prepare('DELETE FROM image WHERE entity_type = ? AND entity_id = ?');

foreach ($entities as $e) {
    $label = "{$e['type']} {$e['id']} ({$e['label']})";
    if (!isset($pageImage[$e['host']])) {
        $stats['error']++; // request failed: leave any stored image untouched
        continue;
    }

    $file = $pageImage[$e['host']][$e['title']] ?? null;
    if ($file === null) {
        echo "  none  $label: no free main image\n";
        $stats['none']++;
        if (!$dryRun) $delete->execute([$e['type'], $e['id']]);
        continue;
    }
    $info = $fileInfo[$e['host']][$file] ?? 'no image info';
    if (is_string($info)) {
        echo "  skip  $label: $file - $info\n";
        $stats['rejected']++;
        if (!$dryRun) $delete->execute([$e['type'], $e['id']]);
        continue;
    }

    $ext = strtolower(pathinfo((string) parse_url($info['thumb_url'], PHP_URL_PATH), PATHINFO_EXTENSION)) ?: 'jpg';
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) $ext = 'jpg';
    $rel = MEDIA_DIR . "/{$e['type']}-{$e['id']}.$ext";
    $abs = $root . '/' . $rel;

    if ($dryRun) {
        echo "  ok    $label: {$info['file_title']} [{$info['license']}]\n";
        $stats['stored']++;
        continue;
    }

    if ($force || !is_file($abs)) {
        $bytes = http($info['thumb_url']);
        usleep(500000);
        if ($bytes === null || @getimagesizefromstring($bytes) === false) {
            echo "  error $label: download failed\n";
            $stats['error']++;
            continue;
        }
        foreach (glob($root . '/' . MEDIA_DIR . "/{$e['type']}-{$e['id']}.*") ?: [] as $old) @unlink($old);
        file_put_contents($abs, $bytes);
    }
    $size = @getimagesize($abs);

    $upsert->execute([
        't' => $e['type'], 'id' => $e['id'], 'file' => $info['file_title'], 'path' => $rel,
        'w' => $size[0] ?? $info['width'], 'h' => $size[1] ?? $info['height'],
        'artist' => $info['artist'], 'lic' => $info['license'], 'licurl' => $info['license_url'],
        'attr' => $info['attribution_required'] ? 1 : 0, 'desc' => $info['description_url'],
    ]);
    echo "  ok    $label: {$info['file_title']} [{$info['license']}]\n";
    $stats['stored']++;
}

printf("\nImages stored: %d | no free image: %d | rejected by licence: %d | errors: %d\n",
    $stats['stored'], $stats['none'], $stats['rejected'], $stats['error']);
