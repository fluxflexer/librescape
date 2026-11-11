<?php
// Create / edit / duplicate / delete an entry.
//   edit.php?type=event               new event (optional prefill: parent_period_id, track_id, author_id, gestation_period_id, person1_id,
//                                     parent_work_id; for a publication work_id, for a work edition_id)
//   edit.php?type=event&id=12         edit
//   edit.php?type=event&dup=12        new event as a copy of 12
//   edit.php?type=event&id=12&delete  confirm deletion
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/entities.php';
require_once __DIR__ . '/lib/form.php';
require_once __DIR__ . '/lib/quickcreate.php';
$me = requireLogin();

// Internal type 'publication' is a work; 'edition' is a publication (book, collection, journal issue) that contains works.
const TYPE_TITLES = ['person' => 'person', 'event' => 'event', 'period' => 'period', 'publication' => 'work', 'edition' => 'publication'];

$type = (string) ($_GET['type'] ?? '');
if (!in_array($type, ENTRY_TYPES, true)) {
    redirect(adminUrl('index.php'));
}
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$errors = [];
$conflict = false;

/** Label shown in a dropdown for a stored id. */
function comboLabel(string $type, ?int $id): ?string
{
    return $id ? entryLabel($type, $id) : null;
}

/** Posted values -> form values, with the labels the dropdowns need (used after a failed save). */
function formFromPost(string $type, array $post): array
{
    $v = $post;
    $v['keywords'] = cleanKeywords((array) ($post['keywords'] ?? []));
    $v['links'] = array_values(array_filter((array) ($post['links'] ?? []), fn($l) => trim((string) ($l['url'] ?? '')) !== ''));
    $v['sources'] = [];
    foreach ((array) ($post['sources'] ?? []) as $r) {
        $sid = optId($r, 'source_id');
        $s = $sid ? q1('SELECT code, title FROM source WHERE id = ?', [$sid]) : null;
        $v['sources'][] = ['source_id' => $sid, 'code' => $s['code'] ?? null, 'title' => $s['title'] ?? null,
                           'locator' => $r['locator'] ?? '', 'note' => $r['note'] ?? ''];
    }
    if ($type === 'edition') {
        $v['editors'] = [];
        foreach ((array) ($post['editors'] ?? []) as $r) {
            $pid = optId($r, 'person_id');
            $v['editors'][] = ['person_id' => $pid, 'label' => comboLabel('person', $pid)];
        }
        $v['contents'] = [];
        foreach ((array) ($post['contents'] ?? []) as $r) {
            $wid = optId($r, 'work_id');
            $v['contents'][] = ['work_id' => $wid, 'work_label' => comboLabel('publication', $wid), 'locator' => $r['locator'] ?? '', 'first' => !empty($r['first'])];
        }
    }
    if ($type === 'publication') {
        $v['published_in'] = [];
        foreach ((array) ($post['published_in'] ?? []) as $r) {
            $eid = optId($r, 'edition_id');
            $v['published_in'][] = ['edition_id' => $eid, 'edition_label' => comboLabel('edition', $eid), 'locator' => $r['locator'] ?? '', 'first' => !empty($r['first'])];
        }
    }
    if ($type === 'person') {
        $v['relations'] = [];
        foreach ((array) ($post['relations'] ?? []) as $r) {
            $oid = optId($r, 'other_id');
            $v['relations'][] = ['role' => $r['role'] ?? '', 'other_id' => $oid, 'other_label' => comboLabel('person', $oid), 'status' => $r['status'] ?? ''];
        }
    }
    return $v;
}

// ------------------------------------------------------------------ actions

if (isPost()) {
    $action = (string) ($_POST['action'] ?? 'save');
    if ($action === 'delete' && $id) {
        try {
            $label = entryLabel($type, $id);
            deleteEntry($type, $id);
            flash('Deleted “' . $label . '”.');
            redirect(adminUrl("list.php?type=$type"));
        } catch (ValidationError $e) {
            flash(implode(' ', $e->errors), 'error');
            redirect(adminUrl("edit.php?type=$type&id=$id&delete"));
        }
    }
    try {
        $version = isset($_POST['row_version']) && $id ? (int) $_POST['row_version'] : null;
        $savedId = saveEntry($type, $id, $_POST, $version);
        $label = entryLabel($type, $savedId);
        switch ($_POST['after'] ?? 'stay') {
            case 'new':
                flash("Saved “{$label}”. Here is a new, empty " . TYPE_TITLES[$type] . '.');
                redirect(adminUrl("edit.php?type=$type"));
            case 'view':
                redirect(viewerUrl() . "#$type-$savedId");
            case 'list':
                flash("Saved “{$label}”.");
                redirect(adminUrl("list.php?type=$type"));
            default:
                flash("Saved “{$label}”.");
                redirect(adminUrl("edit.php?type=$type&id=$savedId"));
        }
    } catch (ValidationError $e) {
        $errors = $e->errors;
    } catch (ConflictError $e) {
        $conflict = true;
    }
    $v = formFromPost($type, $_POST);
    if ($id) {
        $current = loadEntry($type, $id);
        // After a conflict, saving again overwrites the other version (the editor has been warned).
        $v['row_version'] = $current['row_version'] ?? 0;
        $v += ['updated_at' => $current['updated_at'] ?? null, 'updated_by' => $current['updated_by'] ?? null,
               'start_event_id' => $current['start_event_id'] ?? null, 'end_event_id' => $current['end_event_id'] ?? null];
    }
} elseif ($id) {
    $v = loadEntry($type, $id);
    if (!$v) {
        flash('That entry does not exist (any more).', 'error');
        redirect(adminUrl("list.php?type=$type"));
    }
    if ($type === 'event' && (int) $v['is_marker'] === 1) {
        // Start/end events of a period are edited in the period.
        $p = q1('SELECT id FROM period WHERE start_event_id = ? OR end_event_id = ?', [$id, $id]);
        if ($p) {
            flash('This event marks the start or end of a period; you can change it here, under Start and End.', 'info');
            redirect(adminUrl('edit.php?type=period&id=' . $p['id']));
        }
    }
} elseif (isset($_GET['dup'])) {
    $v = loadEntry($type, (int) $_GET['dup']) ?? [];
    $titleKey = in_array($type, ['publication', 'edition'], true) ? 'title' : ($type === 'person' ? 'surname' : 'name');
    if ($v) {
        $v[$titleKey] .= ' (copy)';
        unset($v['shortcode'], $v['row_version'], $v['updated_at'], $v['updated_by'], $v['start_event_id'], $v['end_event_id']);
        $v['relations'] = [];
        // A copy (e.g. a later edition) is not where the works were first published.
        foreach (['contents', 'published_in'] as $k) {
            foreach ($v[$k] ?? [] as $i => $r) {
                $v[$k][$i]['first'] = false;
            }
        }
    }
} else {
    // New entry, optionally pre-filled from the link that led here.
    $v = ['keywords' => [], 'links' => [], 'sources' => [], 'relations' => []];
    foreach (['parent_period_id', 'track_id', 'author_id', 'gestation_period_id', 'person1_id', 'parent_work_id'] as $k) {
        if (($p = optId($_GET, $k)) !== null) {
            $v[$k] = $p;
        }
    }
    if ($type === 'event' || $type === 'period') {
        $v['track_id'] ??= (int) (q1("SELECT id FROM track WHERE short_name = 'bio'")['id'] ?? 0) ?: null;
    }
    if ($type === 'edition' && ($wid = optId($_GET, 'work_id'))) {
        $v['contents'] = [['work_id' => $wid, 'work_label' => comboLabel('publication', $wid), 'locator' => '', 'first' => false]];
    }
    if ($type === 'publication' && ($eid = optId($_GET, 'edition_id'))) {
        $v['published_in'] = [['edition_id' => $eid, 'edition_label' => comboLabel('edition', $eid), 'locator' => '', 'first' => false]];
    }
    if ($type === 'publication' && !isset($v['author_id'])) {
        // Most works belong to the main author of the timeline.
        $v['author_id'] = (int) (q1('SELECT author_id FROM publication WHERE author_id IS NOT NULL GROUP BY author_id ORDER BY COUNT(*) DESC LIMIT 1')['author_id'] ?? 0) ?: null;
    }
}

$isNew = !$id;
$typeTitle = TYPE_TITLES[$type];
$listLabels = ['person' => 'People', 'event' => 'Events', 'period' => 'Periods', 'publication' => 'Works', 'edition' => 'Publications'];
$title = $isNew ? 'New ' . $typeTitle : (entryLabel($type, $id) ?: ucfirst($typeTitle));
$val = static fn(string $k) => isset($v[$k]) && $v[$k] !== null ? (string) $v[$k] : '';
$intVal = static fn(string $k) => isset($v[$k]) && is_numeric($v[$k]) && (int) $v[$k] > 0 ? (int) $v[$k] : null;
$trackOptions = array_column(tracks(), 'name', 'id');

// ------------------------------------------------------------------ delete confirmation

if ($id && isset($_GET['delete']) && !isPost()) {
    $impact = deleteImpact($type, $id);
    pageHeader('Delete ' . $title, $type);
    ?>
    <p class="crumbs"><a href="<?= h(adminUrl("list.php?type=$type")) ?>"><?= h($listLabels[$type]) ?></a> › <a href="<?= h(adminUrl("edit.php?type=$type&id=$id")) ?>"><?= h($title) ?></a> ›</p>
    <h1>Delete “<?= h($title) ?>”?</h1>
    <div class="card narrow">
        <?php if ($impact['blockers']): ?>
            <div class="notice error"><strong>This entry cannot be deleted yet.</strong><ul><?php foreach ($impact['blockers'] as $b): ?><li><?= h($b) ?></li><?php endforeach; ?></ul></div>
            <a class="button" href="<?= h(adminUrl("edit.php?type=$type&id=$id")) ?>">Back to the entry</a>
        <?php else: ?>
            <p>The entry is removed from the timeline together with its keywords, links, source citations and picture.
               The change log keeps a copy of its values.</p>
            <?php if ($impact['effects']): ?><ul><?php foreach ($impact['effects'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
            <form method="post" action="<?= h(adminUrl("edit.php?type=$type&id=$id")) ?>" class="actions">
                <?= csrfField() ?><input type="hidden" name="action" value="delete">
                <button type="submit" class="danger">Delete</button>
                <a href="<?= h(adminUrl("edit.php?type=$type&id=$id")) ?>">Cancel</a>
            </form>
        <?php endif; ?>
    </div>
    <?php
    pageFooter();
    exit;
}

// ------------------------------------------------------------------ the form

pageHeader($title, $type);
?>
<p class="crumbs"><a href="<?= h(adminUrl("list.php?type=$type")) ?>"><?= h($listLabels[$type]) ?></a> ›</p>
<div class="page-head">
    <h1><?= h($title) ?></h1>
    <?php if (!$isNew): ?>
        <a class="button small" href="<?= h(viewerUrl() . "#$type-$id") ?>" target="_blank" rel="noopener">View in timeline ↗</a>
    <?php endif; ?>
</div>

<?php if ($conflict): ?>
    <div class="notice warn" role="alert"><strong>Someone else saved this entry while you were editing it.</strong>
        Your version is shown below and has not been saved.
        <a href="<?= h(adminUrl("edit.php?type=$type&id=$id")) ?>" target="_blank" rel="noopener">Open the current version in a new tab</a>
        to compare. If you save again, your version replaces theirs.</div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="notice error" role="alert"><strong>Not saved yet.</strong> Please check:
        <ul><?php foreach ($errors as $f => $e): ?><li><?php if ($f !== '_'): ?><a href="#<?= h(fieldId((string) $f)) ?>"><?= h($e) ?></a><?php else: ?><?= h($e) ?><?php endif; ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="entry-form" data-dirty-guard novalidate>
    <?= csrfField() ?>
    <?php if (!$isNew): ?><input type="hidden" name="row_version" value="<?= (int) ($v['row_version'] ?? 0) ?>"><?php endif; ?>

    <div class="form-grid">
    <div class="form-main">
    <?php switch ($type):
        case 'person': ?>
        <section class="card">
            <h2>Person</h2>
            <div class="two">
                <?= fieldText('first_name', 'First name(s)', $val('first_name'), $errors, ['maxlength' => 100, 'autofocus' => $isNew]) ?>
                <?= fieldText('surname', 'Surname', $val('surname'), $errors, ['maxlength' => 100, 'required' => true], 'Birth names in brackets, e.g. “Tolkien (née Bratt)”.') ?>
            </div>
            <div class="two">
                <?= fieldDate('birth', 'Born', $val('birth'), $errors) ?>
                <?= fieldText('place_of_birth', 'Place of birth', $val('place_of_birth'), $errors, ['maxlength' => 150]) ?>
            </div>
            <div class="two">
                <?= fieldDate('death', 'Died', $val('death'), $errors) ?>
                <?= fieldText('shortcode', 'Short code', $val('shortcode'), $errors, ['maxlength' => 32, 'class' => 'upper', 'data-suggest-shortcode' => true],
                              'Unique abbreviation, e.g. JRRT. Leave empty to create one from the initials.') ?>
            </div>
        </section>
        <section class="card">
            <input type="hidden" name="relations_form" value="1">
            <?php
            $roleOptions = relationRoleOptions();
            echo rowsBlock('relations', 'Relations', $v['relations'] ?? [], function (string $i, array $r) use ($roleOptions, $id): string {
                $opts = '<option value="">kind…</option><option value="__new">+ New kind of relation…</option>';
                foreach ($roleOptions as $val => $label) {
                    $opts .= '<option value="' . h($val) . '"' . (($r['role'] ?? '') === $val ? ' selected' : '') . '>' . h($label) . '</option>';
                }
                return '<div class="row relation-row"><span class="rel-self">is</span>'
                     . '<select name="relations[' . $i . '][role]" class="rel-role" aria-label="Kind of relation" data-new-option="relation_type">' . $opts . '</select>'
                     . comboWidget("relations[$i][other_id]", 'person', isset($r['other_id']) ? (int) $r['other_id'] : null, $r['other_label'] ?? null, 'person', $id, null, [], 'Person…')
                     . '<input type="text" name="relations[' . $i . '][status]" value="' . h($r['status'] ?? '') . '" placeholder="Details, e.g. married 1916" aria-label="Details" maxlength="50" class="rel-status">'
                     . rowButtons() . '</div>';
            }, '+ Add relation', $errors, 'Read as: this person “is parent of” …');
            ?>
        </section>
        <?php break;

        case 'event': ?>
        <section class="card">
            <h2>Event</h2>
            <?= fieldText('name', 'Name', $val('name'), $errors, ['maxlength' => 200, 'required' => true, 'autofocus' => $isNew]) ?>
            <div class="two">
                <?= fieldDate('date', 'Date', $val('date'), $errors, true) ?>
                <?= fieldText('short_name', 'Short name on the timeline', $val('short_name'), $errors, ['maxlength' => 80], 'Optional, e.g. “Born”.') ?>
            </div>
            <?= fieldTextarea('description', 'Description', $val('description'), $errors) ?>
        </section>
        <section class="card">
            <h2>Placement</h2>
            <div class="two">
                <?= fieldSelect('track_id', 'Lane', $trackOptions, $intVal('track_id'), $errors, '(none)') ?>
                <?= fieldCombo('parent_period_id', 'Part of period', 'period', $intVal('parent_period_id'), comboLabel('period', $intVal('parent_period_id')), $errors, 'period', null,
                               'E.g. “D-Day” is part of “Second World War”.') ?>
            </div>
            <div class="two">
                <?= fieldCombo('person1_id', 'Person', 'person', $intVal('person1_id'), comboLabel('person', $intVal('person1_id')), $errors, 'person') ?>
                <?= fieldCombo('person2_id', 'Second person', 'person', $intVal('person2_id'), comboLabel('person', $intVal('person2_id')), $errors, 'person', null, 'E.g. for a wedding.') ?>
            </div>
        </section>
        <?php break;

        case 'period': ?>
        <section class="card">
            <h2>Period</h2>
            <?= fieldText('name', 'Name', $val('name'), $errors, ['maxlength' => 200, 'required' => true, 'autofocus' => $isNew]) ?>
            <div class="two">
                <?= fieldDate('start', 'From', $val('start'), $errors, true) ?>
                <?= fieldDate('end', 'To', $val('end'), $errors, true) ?>
            </div>
            <?= fieldTextarea('description', 'Description', $val('description'), $errors) ?>
            <details class="markers"<?= ($val('start_description') || $val('end_description') || isset($errors['start_name']) || isset($errors['end_name'])) ? ' open' : '' ?>>
                <summary>Describe the start and end events</summary>
                <p class="hint">Optional. The start and end are events of their own (shown in the period’s details, not as separate points),
                    e.g. “Gulf of Tonkin incident” starts the Vietnam War.</p>
                <div class="two">
                    <?= fieldText('start_name', 'Start event', $val('start_name'), $errors, ['maxlength' => 200, 'placeholder' => 'Start of …']) ?>
                    <?= fieldText('end_name', 'End event', $val('end_name'), $errors, ['maxlength' => 200, 'placeholder' => 'End of …']) ?>
                </div>
                <div class="two">
                    <?= fieldTextarea('start_description', 'About the start', $val('start_description'), $errors, ['rows' => 3]) ?>
                    <?= fieldTextarea('end_description', 'About the end', $val('end_description'), $errors, ['rows' => 3]) ?>
                </div>
            </details>
        </section>
        <section class="card">
            <h2>Placement</h2>
            <div class="two">
                <?= fieldSelect('track_id', 'Lane', $trackOptions, $intVal('track_id'), $errors, '(none)') ?>
                <?= fieldCombo('parent_period_id', 'Part of period', 'period', $intVal('parent_period_id'), comboLabel('period', $intVal('parent_period_id')), $errors, 'period', $id,
                               'E.g. “Battle of the Somme” is part of “First World War”.') ?>
            </div>
        </section>
        <?php break;

        case 'publication': ?>
        <section class="card">
            <h2>Work</h2>
            <?= fieldText('title', 'Title', $val('title'), $errors, ['maxlength' => 300, 'required' => true, 'autofocus' => $isNew]) ?>
            <div class="two">
                <?= fieldText('short_title', 'Short title', $val('short_title'), $errors, ['maxlength' => 100], 'Shown on the timeline if set.') ?>
                <?= fieldText('shortcode', 'Short code', $val('shortcode'), $errors, ['maxlength' => 32, 'class' => 'upper'], 'Optional, unique, e.g. ROTK.') ?>
            </div>
            <div class="two">
                <?= fieldDate('release', 'Started', $val('release'), $errors, false, 'When work on it began. Optional.') ?>
                <div class="field">
                    <?php $cats = array_column(q('SELECT id, category FROM pub_category ORDER BY category'), 'category', 'id'); ?>
                    <label for="f-category-id">Category</label>
                    <select id="f-category-id" name="category_id" data-new-option="pub_category"<?= errorAttrs($errors, 'category_id') ?>>
                        <option value="">(none)</option>
                        <option value="__new">+ New category…</option>
                        <?php foreach ($cats as $cid => $cname): ?><option value="<?= (int) $cid ?>"<?= $intVal('category_id') === (int) $cid ? ' selected' : '' ?>><?= h($cname) ?></option><?php endforeach; ?>
                    </select>
                    <?= errorText($errors, 'category_id') ?>
                </div>
            </div>
            <?= fieldTextarea('description', 'Description', $val('description'), $errors) ?>
        </section>
        <section class="card">
            <h2>Authors and context</h2>
            <?= fieldCombo('author_id', 'Author', 'person', $intVal('author_id'), comboLabel('person', $intVal('author_id')), $errors, 'person') ?>
            <div class="two">
                <?= fieldCombo('author2_id', 'Second author', 'person', $intVal('author2_id'), comboLabel('person', $intVal('author2_id')), $errors, 'person') ?>
                <?= fieldCombo('author3_id', 'Third author', 'person', $intVal('author3_id'), comboLabel('person', $intVal('author3_id')), $errors, 'person') ?>
            </div>
            <div class="two">
                <?= fieldCombo('parent_work_id', 'Part of work', 'publication', $intVal('parent_work_id'), comboLabel('publication', $intVal('parent_work_id')), $errors, null, $id,
                               'E.g. a volume of a larger work.') ?>
                <?= fieldCombo('gestation_period_id', 'Writing period', 'period', $intVal('gestation_period_id'), comboLabel('period', $intVal('gestation_period_id')), $errors, 'writing-period', null,
                               'When it was written; shown in the Works lane.') ?>
            </div>
        </section>
        <section class="card">
            <input type="hidden" name="published_in_form" value="1">
            <?= rowsBlock('published_in', 'Published in', $v['published_in'] ?? [], function (string $i, array $r): string {
                return '<div class="row content-row">'
                     . comboWidget("published_in[$i][edition_id]", 'edition', isset($r['edition_id']) ? (int) $r['edition_id'] : null, $r['edition_label'] ?? null,
                                   'edition', null, null, [], 'Book, collection, journal…')
                     . '<input type="text" name="published_in[' . $i . '][locator]" value="' . h($r['locator'] ?? '') . '" placeholder="Pages / chapter" aria-label="Pages or chapter" class="src-locator">'
                     . firstCheckbox("published_in[$i][first]", !empty($r['first']))
                     . rowButtons() . '</div>';
            }, '+ Add publication', $errors, 'The books, collections or journal issues this work appeared in. Tick <em>first</em> where it was first published.') ?>
        </section>
        <?php break;

        case 'edition': ?>
        <section class="card">
            <h2>Publication</h2>
            <?= fieldText('title', 'Title', $val('title'), $errors, ['maxlength' => 300, 'required' => true, 'autofocus' => $isNew],
                          'As printed, e.g. “Essays Presented to Charles Williams” or “Proceedings of the British Academy, vol. 22”.') ?>
            <div class="two">
                <?= fieldSelect('kind', 'Kind', EDITION_KINDS, $val('kind') ?: 'book', $errors) ?>
                <?= fieldDate('release', 'Published', $val('release'), $errors) ?>
            </div>
            <div class="two">
                <?= fieldText('publisher', 'Publisher', $val('publisher'), $errors, ['maxlength' => 200, 'placeholder' => 'George Allen & Unwin']) ?>
                <?= fieldText('place', 'Place', $val('place'), $errors, ['maxlength' => 100, 'placeholder' => 'London']) ?>
            </div>
            <div class="two">
                <?= fieldText('isbn', 'ISBN', $val('isbn'), $errors, ['maxlength' => 17], 'Optional; books printed before 1970 have none.') ?>
            </div>
            <?= fieldTextarea('description', 'Description', $val('description'), $errors) ?>
        </section>
        <section class="card">
            <?= rowsBlock('contents', 'Contents', $v['contents'] ?? [], function (string $i, array $r) use ($id): string {
                return '<div class="row content-row">'
                     . comboWidget("contents[$i][work_id]", 'publication', isset($r['work_id']) ? (int) $r['work_id'] : null, $r['work_label'] ?? null,
                                   'publication', null, null, [], 'Work…')
                     . '<input type="text" name="contents[' . $i . '][locator]" value="' . h($r['locator'] ?? '') . '" placeholder="Pages / chapter" aria-label="Pages or chapter" class="src-locator">'
                     . firstCheckbox("contents[$i][first]", !empty($r['first']))
                     . rowButtons(true) . '</div>';
            }, '+ Add work', $errors, 'The works in this publication, in printed order. Tick <em>first</em> where a work appeared for the first time.') ?>
            <?= rowsBlock('editors', 'Editors', $v['editors'] ?? [], function (string $i, array $r): string {
                return '<div class="row editor-row">'
                     . comboWidget("editors[$i][person_id]", 'person', isset($r['person_id']) ? (int) $r['person_id'] : null, $r['label'] ?? null, 'person', null, null, [], 'Person…')
                     . rowButtons(true) . '</div>';
            }, '+ Add editor', $errors, 'Optional, in the order printed on the title page.') ?>
        </section>
        <?php break;
    endswitch; ?>

        <section class="card">
            <?php if ($type !== 'person'): ?><?= fieldKeywords($v['keywords'] ?? []) ?><?php endif; ?>
            <?= linksBlock($v['links'] ?? [], $errors) ?>
        </section>
        <section class="card">
            <?php // Every entry needs a source, so a new form starts with one empty source row. ?>
            <?= sourcesBlock(($v['sources'] ?? []) ?: [[]], $errors) ?>
        </section>
    </div>

    <aside class="form-side">
        <?php if (!$isNew): ?>
            <div class="card meta">
                <?php if (!empty($v['updated_at'])):
                    $by = $v['updated_by'] ? q1('SELECT display_name FROM app_user WHERE id = ?', [$v['updated_by']]) : null; ?>
                    <p>Last changed <?= h(date('j M Y, H:i', strtotime($v['updated_at']))) ?><?= $by ? ' by ' . h($by['display_name']) : '' ?>.</p>
                <?php else: ?>
                    <p class="muted">Not changed since the data was loaded.</p>
                <?php endif; ?>
                <p><a href="#history">History</a> · <a href="<?= h(adminUrl("edit.php?type=$type&dup=$id")) ?>">Duplicate</a></p>
                <p><a class="danger-link" href="<?= h(adminUrl("edit.php?type=$type&id=$id&delete")) ?>">Delete…</a></p>
            </div>
            <?php
            // Related entries, for quick navigation.
            $related = [];
            if ($type === 'period') {
                foreach (childrenOf($id) as $c) {
                    $related[] = [$c['type'], $c['id'], $c['label'], $c['note']];
                }
            } elseif ($type === 'person') {
                foreach (q('SELECT id, title FROM publication WHERE ? IN (author_id, author2_id, author3_id) ORDER BY release_date', [$id]) as $w) {
                    $related[] = ['publication', (int) $w['id'], $w['title'], 'work'];
                }
                foreach (q('SELECT id, name FROM event WHERE ? IN (person1_id, person2_id) AND is_marker = 0', [$id]) as $e) {
                    $related[] = ['event', (int) $e['id'], $e['name'], 'event'];
                }
            } elseif ($type === 'publication') {
                foreach (q('SELECT id, title FROM publication WHERE parent_work_id = ?', [$id]) as $w) {
                    $related[] = ['publication', (int) $w['id'], $w['title'], 'part'];
                }
            }
            if ($type === 'person') {
                foreach (q('SELECT e.id, e.title FROM edition_editor x JOIN edition e ON e.id = x.edition_id WHERE x.person_id = ? ORDER BY e.release_date', [$id]) as $e) {
                    $related[] = ['edition', (int) $e['id'], $e['title'], 'editor'];
                }
            }
            ?>
            <?php if ($type === 'period' || $type === 'publication' || $related): ?>
                <div class="card meta">
                    <h3><?= $type === 'period' ? 'Contains' : 'Related' ?></h3>
                    <?php if ($related): ?>
                        <ul class="plain related">
                            <?php foreach ($related as [$rt, $rid, $rlabel, $rnote]): ?>
                                <li><a href="<?= h(adminUrl("edit.php?type=$rt&id=$rid")) ?>"><?= h($rlabel) ?></a> <span class="muted"><?= h($rnote) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php elseif ($type === 'period'): ?>
                        <p class="muted">Nothing yet.</p>
                    <?php endif; ?>
                    <?php if ($type === 'period'): ?>
                        <p class="add-links">
                            <a href="<?= h(adminUrl("edit.php?type=event&parent_period_id=$id&track_id=" . ($intVal('track_id') ?? ''))) ?>">+ Event in this period</a><br>
                            <a href="<?= h(adminUrl("edit.php?type=period&parent_period_id=$id&track_id=" . ($intVal('track_id') ?? ''))) ?>">+ Sub-period</a>
                        </p>
                    <?php elseif ($type === 'person'): ?>
                        <p class="add-links"><a href="<?= h(adminUrl("edit.php?type=publication&author_id=$id")) ?>">+ Work by this person</a></p>
                    <?php elseif ($type === 'publication'): ?>
                        <p class="add-links"><a href="<?= h(adminUrl("edit.php?type=edition&work_id=$id")) ?>">+ Publication containing this work</a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <div class="card meta tips">
            <h3>Tips</h3>
            <ul>
                <li>Dates: <em>21 Sep 1937</em>, <em>Sep 1937</em>, <em>Summer 1937</em>, <em>Winter 1942/43</em>, <em>1937</em>, <em>1930s</em> or <em>c. 1930</em>; the precision is taken from what you type.</li>
                <li>In the search fields, pick an existing entry or choose <em>+ Create …</em> to add a new one on the spot.</li>
                <li><kbd>Ctrl</kbd>+<kbd>S</kbd> saves.</li>
            </ul>
        </div>
    </aside>
    </div>

    <div class="savebar">
        <button type="submit" name="after" value="stay" class="primary">Save</button>
        <button type="submit" name="after" value="new">Save &amp; new</button>
        <button type="submit" name="after" value="view">Save &amp; view in timeline</button>
        <a href="<?= h(adminUrl("list.php?type=$type")) ?>" class="cancel">Cancel</a>
        <span class="dirty-note" hidden>Unsaved changes</span>
    </div>
</form>

<?php if (!$isNew): ?>
    <h2 id="history">History</h2>
    <?= renderChangeLog(changeLog(['type' => $type, 'id' => $id], 30), false) ?>
<?php endif;

echo quickCreateTemplates();
pageFooter();
