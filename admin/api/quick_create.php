<?php
// POST (JSON) {type, fields: {...}} -> {id, label, sub} or 422 {errors: {field: message}}
// Creates an entry from a quick-create dialog without leaving the form; same rules as the full forms.
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/entities.php';

if (!isPost()) {
    jsonOut(['error' => 'POST only'], 405);
}
requireLogin();   // also checks the CSRF header

$body = json_decode((string) file_get_contents('php://input'), true);
$type = (string) ($body['type'] ?? '');
$in = is_array($body['fields'] ?? null) ? $body['fields'] : [];

try {
    switch ($type) {
        case 'person':
        case 'event':
        case 'period':
        case 'publication':
        case 'edition':
            $id = saveEntry($type, null, $in);
            $label = entryLabel($type, $id);
            $e = loadEntry($type, $id);
            $sub = match ($type) {
                'person'      => trim(($e['birth'] ?: '?') . ' – ' . $e['death'], ' –'),
                'event'       => $e['date'],
                'period'      => $e['start'] . ' – ' . $e['end'],
                'publication' => $e['release'] ? 'started ' . $e['release'] : 'no date',
                'edition'     => trim(($e['release'] ?: '') . ' · ' . EDITION_KINDS[$e['kind']], ' ·'),
            };
            break;

        case 'source':
            $id = createSource($in);
            $s = q1('SELECT code, title, source_type FROM source WHERE id = ?', [$id]);
            $label = $s['code'] . ' · ' . $s['title'];
            $sub = SOURCE_TYPE_LABELS[$s['source_type']];
            break;

        case 'relation_type':
            $name = mb_strtolower(str($in, 'short_name', 50));
            $inverse = ($v = optStr($in, 'inverse_name', 50)) !== null ? mb_strtolower($v) : null;
            if ($name === '') {
                throw new ValidationError(['short_name' => 'Please enter a name, e.g. “teacher”.']);
            }
            if (q1('SELECT id FROM relation_type WHERE short_name = ?', [$name])) {
                throw new ValidationError(['short_name' => "The relation “{$name}” already exists."]);
            }
            q('INSERT INTO relation_type (short_name, inverse_name, description) VALUES (?, ?, ?)', [$name, $inverse, optStr($in, 'description')]);
            $id = (int) getDb()->lastInsertId();
            logChange('relation_type', $id, 'create', $name, array_filter(['inverse_name' => $inverse]));
            // The dropdown needs both directions.
            jsonOut(['id' => $id, 'options' => array_filter([
                ['value' => "$id:1", 'label' => "$name of"],
                $inverse ? ['value' => "$id:2", 'label' => "$inverse of"] : null,
            ])]);

        case 'pub_category':
            $name = str($in, 'category', 100);
            if ($name === '') {
                throw new ValidationError(['category' => 'Please enter a name, e.g. “Poem”.']);
            }
            if (q1('SELECT id FROM pub_category WHERE category = ?', [$name])) {
                throw new ValidationError(['category' => "The category “{$name}” already exists."]);
            }
            q('INSERT INTO pub_category (category, description) VALUES (?, ?)', [$name, optStr($in, 'description')]);
            $id = (int) getDb()->lastInsertId();
            logChange('pub_category', $id, 'create', $name);
            $label = $name;
            $sub = '';
            break;

        default:
            jsonOut(['error' => 'Unknown type'], 400);
    }
    jsonOut(['id' => $id, 'label' => $label, 'sub' => $sub]);
} catch (ValidationError $e) {
    jsonOut(['errors' => $e->errors], 422);
} catch (Throwable $e) {
    error_log('librescape quick_create: ' . $e->getMessage());
    jsonOut(['error' => 'Server error'], 500);
}
