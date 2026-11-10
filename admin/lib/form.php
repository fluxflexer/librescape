<?php
// HTML for form fields and the interactive widgets (admin.js brings them to life).
// Field names follow PHP's array syntax, e.g. links[3][url], so the posted data arrives ready to save.

declare(strict_types=1);

/** id attribute derived from a field name: "links[3][url]" -> "f-links-3-url". */
function fieldId(string $name): string
{
    return 'f-' . trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-');
}

function errorText(array $errors, string $name): string
{
    return isset($errors[$name]) ? '<span class="field-error" id="' . fieldId($name) . '-err">' . h($errors[$name]) . '</span>' : '';
}

/** aria attributes marking a field as invalid and pointing at its message. */
function errorAttrs(array $errors, string $name): string
{
    return isset($errors[$name]) ? ' aria-invalid="true" aria-describedby="' . fieldId($name) . '-err"' : '';
}

function attrs(array $a): string
{
    $out = '';
    foreach ($a as $k => $v) {
        if ($v === false || $v === null) {
            continue;
        }
        $out .= $v === true ? ' ' . $k : ' ' . $k . '="' . h((string) $v) . '"';
    }
    return $out;
}

function fieldText(string $name, string $label, ?string $value, array $errors = [], array $a = [], string $hint = ''): string
{
    $id = fieldId($name);
    return '<div class="field"><label for="' . $id . '">' . h($label) . (!empty($a['required']) ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
         . '<input type="text" id="' . $id . '" name="' . h($name) . '" value="' . h($value) . '"' . attrs($a) . errorAttrs($errors, $name) . '>'
         . ($hint ? '<small class="hint">' . $hint . '</small>' : '') . errorText($errors, $name) . '</div>';
}

function fieldTextarea(string $name, string $label, ?string $value, array $errors = [], array $a = [], string $hint = ''): string
{
    $id = fieldId($name);
    return '<div class="field"><label for="' . $id . '">' . h($label) . '</label>'
         . '<textarea id="' . $id . '" name="' . h($name) . '"' . attrs($a + ['rows' => 5]) . errorAttrs($errors, $name) . '>' . h($value) . '</textarea>'
         . ($hint ? '<small class="hint">' . $hint . '</small>' : '') . errorText($errors, $name) . '</div>';
}

/** $options: value => label. $empty: label of an empty first option (null = none). */
function fieldSelect(string $name, string $label, array $options, $value, array $errors = [], ?string $empty = null, array $a = [], string $hint = ''): string
{
    $id = fieldId($name);
    $html = '<div class="field"><label for="' . $id . '">' . h($label) . '</label><select id="' . $id . '" name="' . h($name) . '"' . attrs($a) . errorAttrs($errors, $name) . '>';
    if ($empty !== null) {
        $html .= '<option value="">' . h($empty) . '</option>';
    }
    foreach ($options as $v => $l) {
        $html .= '<option value="' . h((string) $v) . '"' . ((string) $v === (string) $value ? ' selected' : '') . '>' . h($l) . '</option>';
    }
    return $html . '</select>' . ($hint ? '<small class="hint">' . $hint . '</small>' : '') . errorText($errors, $name) . '</div>';
}

/** Smart date: free text with a live preview of how it was understood. */
function fieldDate(string $name, string $label, ?string $value, array $errors = [], bool $required = false, string $hint = ''): string
{
    $id = fieldId($name);
    return '<div class="field date-field"><label for="' . $id . '">' . h($label) . ($required ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
         . '<input type="text" id="' . $id . '" name="' . h($name) . '" value="' . h($value) . '" class="smart-date" autocomplete="off"'
         . ' placeholder="e.g. 21 Sep 1937, Sep 1937, Summer 1937, 1937, 1930s, c. 1930"' . ($required ? ' required' : '') . errorAttrs($errors, $name) . '>'
         . '<output class="date-preview" for="' . $id . '" aria-live="polite"></output>'
         . ($hint ? '<small class="hint">' . $hint . '</small>' : '') . errorText($errors, $name) . '</div>';
}

/**
 * Search-as-you-type dropdown over existing entries; picks an id.
 * $create: entry type offered as "+ Create …" (opens the quick-create dialog), or null.
 */
function fieldCombo(string $name, string $label, string $type, ?int $id, ?string $text, array $errors = [], ?string $create = null,
                    ?int $exclude = null, string $hint = '', bool $required = false): string
{
    $fid = fieldId($name);
    return '<div class="field">'
         . '<label for="' . $fid . '">' . h($label) . ($required ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</label>'
         . comboWidget($name, $type, $id, $text, $create, $exclude, $fid, $errors)
         . ($hint ? '<small class="hint">' . $hint . '</small>' : '') . errorText($errors, $name) . '</div>';
}

function comboWidget(string $name, string $type, ?int $id, ?string $text, ?string $create = null, ?int $exclude = null, ?string $inputId = null, array $errors = [], string $placeholder = ''): string
{
    $placeholder = $placeholder ?: 'Type to search…';
    return '<div class="combo"' . attrs(['data-type' => $type, 'data-create' => $create, 'data-exclude' => $exclude]) . '>'
         . '<input type="hidden" name="' . h($name) . '" value="' . ($id ?: '') . '">'
         . '<input type="text" class="combo-input"' . attrs(['id' => $inputId]) . ' value="' . h($id ? $text : '') . '" autocomplete="off"'
         . ' role="combobox" aria-autocomplete="list" aria-expanded="false" placeholder="' . h($placeholder) . '"' . errorAttrs($errors, $name) . '>'
         . '<button type="button" class="combo-clear" aria-label="Clear" title="Clear"' . ($id ? '' : ' hidden') . '>×</button>'
         . '<ul class="combo-list" role="listbox" hidden></ul>'
         . '</div>';
}

/** Keyword chips with autocomplete; new words become new keywords on save. */
function fieldKeywords(array $names): string
{
    $chips = '';
    foreach ($names as $n) {
        $chips .= keywordChip($n);
    }
    return '<div class="field"><label for="f-keyword-input">Keywords</label>'
         . '<div class="tags" data-name="keywords[]">' . $chips
         . '<span class="tag-entry combo" data-type="keyword"><input type="text" id="f-keyword-input" class="combo-input tag-input" autocomplete="off" role="combobox"'
         . ' aria-autocomplete="list" aria-expanded="false" placeholder="Add keyword…"><ul class="combo-list" role="listbox" hidden></ul></span>'
         . '<input type="hidden" name="keywords[]" value=""></div>'
         . '<small class="hint">Enter or comma adds a keyword; new words are created on save. Keywords group entries into their own lane in the timeline.</small></div>';
}

function keywordChip(string $n): string
{
    return '<span class="chip">' . h($n) . '<input type="hidden" name="keywords[]" value="' . h($n) . '">'
         . '<button type="button" class="chip-remove" aria-label="Remove keyword ' . h($n) . '">×</button></span>';
}

/** Repeating rows (links, sources, relations): rendered rows + a <template> for new ones (index __i__). */
function rowsBlock(string $key, string $title, array $rows, callable $renderRow, string $addLabel, array $errors = [], string $hint = '', bool $required = false): string
{
    $html = '<fieldset class="rows-block" id="' . fieldId($key) . '"' . errorAttrs($errors, $key) . '><legend>' . h($title)
          . ($required ? ' <span class="req" aria-hidden="true">*</span>' : '') . '</legend>'
          . ($hint ? '<small class="hint">' . $hint . '</small>' : '')
          . errorText($errors, $key)
          . '<div class="rows" data-key="' . h($key) . '">';
    foreach (array_values($rows) as $i => $r) {
        $html .= $renderRow((string) $i, $r);
    }
    $html .= '</div><template class="row-template">' . $renderRow('__i__', []) . '</template>'
           . '<button type="button" class="small add-row">' . h($addLabel) . '</button></fieldset>';
    return $html;
}

function rowButtons(bool $sortable = false): string
{
    return '<span class="row-buttons">'
         . ($sortable ? '<button type="button" class="icon row-up" aria-label="Move up" title="Move up">↑</button>'
                      . '<button type="button" class="icon row-down" aria-label="Move down" title="Move down">↓</button>' : '')
         . '<button type="button" class="icon row-remove" aria-label="Remove" title="Remove">×</button></span>';
}

function linksBlock(array $rows, array $errors): string
{
    return rowsBlock('links', 'Links', $rows, function (string $i, array $r): string {
        $sel = '';
        foreach (LINK_TYPES as $v => $l) {
            $sel .= '<option value="' . $v . '"' . (($r['link_type'] ?? 'website') === $v ? ' selected' : '') . '>' . $l . '</option>';
        }
        return '<div class="row link-row">'
             . '<select name="links[' . $i . '][link_type]" class="link-type" aria-label="Kind of link">' . $sel . '</select>'
             . '<input type="url" name="links[' . $i . '][url]" value="' . h($r['url'] ?? '') . '" placeholder="https://…" aria-label="Web address" class="link-url">'
             . '<input type="text" name="links[' . $i . '][label]" value="' . h($r['label'] ?? '') . '" placeholder="Label (optional)" aria-label="Label" class="link-label">'
             . rowButtons(true) . '</div>';
    }, '+ Add link', $errors, 'The first Wikipedia link is also where the picture comes from.');
}

function sourcesBlock(array $rows, array $errors): string
{
    return rowsBlock('sources', 'Sources', $rows, function (string $i, array $r): string {
        return '<div class="row source-row">'
             . comboWidget("sources[$i][source_id]", 'source', isset($r['source_id']) ? (int) $r['source_id'] : null,
                           isset($r['code']) ? $r['code'] . ' · ' . $r['title'] : null, 'source', null, null, [], 'Search by code, title or author…')
             . '<input type="text" name="sources[' . $i . '][locator]" value="' . h($r['locator'] ?? '') . '" placeholder="Page / chapter" aria-label="Page or chapter" class="src-locator">'
             . '<input type="text" name="sources[' . $i . '][note]" value="' . h($r['note'] ?? '') . '" placeholder="Note (optional)" aria-label="Note" class="src-note">'
             . rowButtons() . '</div>';
    }, '+ Add source', $errors, 'What backs this entry. Every entry needs at least one.', true);
}
