<?php
// Quick-create dialogs: small forms for creating a person, event, period, work, publication, source, relation type or category
// without leaving the current form. Rendered once per page as <template>s; admin.js opens them in a <dialog>
// and posts them to api/quick_create.php. The dialogs use the same save rules as the full forms.

declare(strict_types=1);

/** One source picker + locator, as every new entry needs a source. */
function qcSourceField(): string
{
    return '<div class="field"><label>Source <span class="req" aria-hidden="true">*</span></label>'
         . '<div class="row source-row">'
         . comboWidget('sources[0][source_id]', 'source', null, null, 'source', null, null, [], 'Search by code, title or author…')
         . '<input type="text" name="sources[0][locator]" placeholder="Page / chapter" aria-label="Page or chapter" class="src-locator">'
         . '</div></div>';
}

function qcDialog(string $key, string $title, string $postType, string $prefill, string $body, string $intro = ''): string
{
    return '<template id="qc-' . h($key) . '"><dialog class="qc" aria-labelledby="qc-title-' . h($key) . '">'
         . '<form class="qc-form" data-qc-type="' . h($postType) . '" data-prefill="' . h($prefill) . '" novalidate>'
         . '<h2 id="qc-title-' . h($key) . '">' . h($title) . '</h2>'
         . ($intro ? '<p class="hint">' . $intro . '</p>' : '')
         . '<div class="qc-errors notice error" role="alert" hidden></div>'
         . $body
         . '<div class="actions"><button type="submit" class="primary">Create</button><button type="button" class="qc-cancel">Cancel</button></div>'
         . '</form></dialog></template>';
}

function quickCreateTemplates(): string
{
    $tracks = array_column(tracks(), 'name', 'id');
    $trackId = static fn(string $short) => (int) (q1('SELECT id FROM track WHERE short_name = ?', [$short])['id'] ?? 0) ?: null;
    $none = [];

    $html = qcDialog('person', 'New person', 'person', 'name',
        '<div class="two">' . fieldText('first_name', 'First name(s)', '', $none) . fieldText('surname', 'Surname', '', $none, ['required' => true]) . '</div>'
        . '<div class="two">' . fieldDate('birth', 'Born', '') . fieldDate('death', 'Died', '') . '</div>'
        . qcSourceField(),
        'You can add the place of birth, relations and links later in the person’s own form.');

    $html .= qcDialog('event', 'New event', 'event', 'name',
        fieldText('name', 'Name', '', $none, ['required' => true])
        . '<div class="two">' . fieldDate('date', 'Date', '', [], true) . fieldSelect('track_id', 'Lane', $tracks, $trackId('bio'), $none, '(none)') . '</div>'
        . qcSourceField());

    $html .= qcDialog('period', 'New period', 'period', 'name',
        fieldText('name', 'Name', '', $none, ['required' => true])
        . '<div class="two">' . fieldDate('start', 'From', '', [], true) . fieldDate('end', 'To', '', [], true) . '</div>'
        . fieldSelect('track_id', 'Lane', $tracks, $trackId('world'), $none, '(none)')
        . qcSourceField());

    $html .= qcDialog('writing-period', 'New writing period', 'period', 'name',
        fieldText('name', 'Name', '', $none, ['required' => true, 'data-default-from' => 'title', 'data-default-prefix' => 'Writing '])
        . '<div class="two">' . fieldDate('start', 'Started', '', [], true) . fieldDate('end', 'Finished', '', [], true) . '</div>'
        . '<input type="hidden" name="track_id" value="' . ($trackId('works') ?? '') . '">'
        . qcSourceField(),
        'The time the work was written; it appears as a bar in the Works lane.');

    $html .= qcDialog('publication', 'New work', 'publication', 'title',
        fieldText('title', 'Title', '', $none, ['required' => true])
        . '<div class="two">' . fieldDate('release', 'Started', '') . '<div class="field"><label>Author</label>'
        . comboWidget('author_id', 'person', null, null, null) . '</div></div>'
        . qcSourceField());

    $html .= qcDialog('edition', 'New publication', 'edition', 'title',
        fieldText('title', 'Title', '', $none, ['required' => true])
        . '<div class="two">' . fieldSelect('kind', 'Kind', EDITION_KINDS, 'book', $none) . fieldDate('release', 'Published', '') . '</div>'
        . '<div class="two">' . fieldText('publisher', 'Publisher', '', $none) . fieldText('place', 'Place', '', $none) . '</div>'
        . qcSourceField(),
        'A book, collection or journal issue the work appeared in. Editors, ISBN and contents can be added in its own form.');

    $types = '';
    foreach (SOURCE_TYPE_LABELS as $v => $l) {
        $types .= '<label class="inline"><input type="radio" name="source_type" value="' . $v . '"' . ($v === 'wikipedia' ? ' checked' : '') . '> ' . h($l) . '</label>';
    }
    $html .= qcDialog('source', 'New source', 'source', 'title',
        '<fieldset class="choices source-type"><legend>Kind of source</legend>' . $types . '</fieldset>'
        . '<div data-for="wikipedia">'
        .   fieldText('url', 'Address of the article', '', $none, ['placeholder' => 'https://en.wikipedia.org/wiki/…', 'class' => 'wiki-url'],
                      'Paste the address; title and language are filled in from it.')
        . '</div>'
        . fieldText('title', 'Title', '', $none, ['required' => true, 'data-label-newspaper' => 'Headline'])
        . '<div data-for="book newspaper">' . fieldText('author', 'Author', '', $none, [], 'As printed, e.g. “Humphrey Carpenter”.') . '</div>'
        . '<div data-for="book newspaper">' . fieldDate('published', 'Published', '', [], true) . '</div>'
        . '<div data-for="book"><div class="two">' . fieldText('publisher', 'Publisher', '', $none) . fieldText('place', 'Place', '', $none) . '</div>'
        .   '<div class="two">' . fieldText('isbn', 'ISBN', '', $none, ['maxlength' => 17], 'Required from 1970 on.') . fieldText('editor', 'Editor', '', $none) . '</div></div>'
        . '<div data-for="newspaper"><div class="two">' . fieldText('newspaper', 'Newspaper', '', $none, ['placeholder' => 'The Times']) . fieldText('page', 'Page', '', $none) . '</div>'
        .   fieldText('url', 'Online copy', '', $none, ['placeholder' => 'https://…']) . '</div>'
        . '<div data-for="wikipedia"><div class="two">' . fieldText('language', 'Language', '', $none, ['maxlength' => 2, 'placeholder' => 'en'])
        .   fieldDate('accessed', 'Read on', date('j M Y')) . '</div></div>'
        . fieldText('code', 'Code', '', $none, ['maxlength' => 32, 'class' => 'upper'], 'Short unique code shown in the timeline, e.g. CARP. Leave empty to create one.'));

    $html .= qcDialog('relation_type', 'New kind of relation', 'relation_type', 'short_name',
        '<div class="two">' . fieldText('short_name', 'Name', '', $none, ['required' => true, 'placeholder' => 'teacher'], 'Read as “… is teacher of …”.')
        . fieldText('inverse_name', 'Other direction', '', $none, ['placeholder' => 'pupil'], 'Leave empty if it is the same both ways (like “friend”).') . '</div>');

    $html .= qcDialog('pub_category', 'New category', 'pub_category', 'category',
        fieldText('category', 'Name', '', $none, ['required' => true, 'placeholder' => 'Poem']));

    return $html;
}
