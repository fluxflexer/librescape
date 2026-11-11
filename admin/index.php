<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$me = requireLogin();

$missing = entriesWithoutSource();
$counts = q1('SELECT (SELECT COUNT(*) FROM event WHERE is_marker = 0) AS event, (SELECT COUNT(*) FROM period) AS period,
                     (SELECT COUNT(*) FROM publication) AS publication, (SELECT COUNT(*) FROM edition) AS edition,
                     (SELECT COUNT(*) FROM person) AS person');
$types = ['event' => 'Event', 'period' => 'Period', 'publication' => 'Work', 'edition' => 'Publication', 'person' => 'Person'];

pageHeader('Dashboard', 'dashboard');
?>
<h1>Hello, <?= h($me['display_name']) ?></h1>

<section class="quick-add" aria-label="Add a new entry">
    <?php foreach ($types as $t => $label): ?>
        <div class="tile">
            <a class="add" href="<?= h(adminUrl("edit.php?type=$t")) ?>"><span class="plus">+</span> New <?= h(strtolower($label)) ?></a>
            <a class="count" href="<?= h(adminUrl("list.php?type=$t")) ?>"><?= (int) $counts[$t] ?> <?= h($t === 'person' ? ((int) $counts[$t] === 1 ? 'person' : 'people') : strtolower($label) . ((int) $counts[$t] === 1 ? '' : 's')) ?></a>
        </div>
    <?php endforeach; ?>
</section>

<div class="columns">
    <section>
        <h2>Entries without a source <span class="badge<?= $missing ? ' warn' : '' ?>"><?= count($missing) ?></span></h2>
        <?php if (!$missing): ?>
            <p class="muted">Every entry cites at least one source.</p>
        <?php else: ?>
            <ul class="plain">
                <?php foreach ($missing as $m): ?>
                    <li><a href="<?= h(adminUrl("edit.php?type={$m['type']}&id={$m['id']}")) ?>"><?= h($m['label']) ?></a>
                        <span class="muted"><?= h(ENTITY_LABELS[$m['type']]) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
    <section>
        <h2>My recent changes</h2>
        <?= renderChangeLog(changeLog(['user_id' => $me['id']], 8)) ?>
    </section>
</div>

<h2>Recent changes by everyone</h2>
<?= renderChangeLog(changeLog([], 15)) ?>
<?php
pageFooter();
