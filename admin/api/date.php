<?php
// GET ?q=text -> {ok, label, precision} : live preview for the smart date fields (same parser as saving).
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/dates.php';
requireLogin();

$text = (string) ($_GET['q'] ?? '');
if (trim($text) === '') {
    jsonOut(['ok' => true, 'empty' => true]);
}
$d = parseSmartDate($text);
jsonOut($d
    ? ['ok' => true, 'label' => $d['label'], 'precision' => $d['precision'], 'precisionLabel' => PRECISION_LABELS[$d['precision']], 'date' => $d['date']]
    : ['ok' => false]);
