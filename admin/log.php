<?php
// Full change log with filters (administrators only).
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
requireAdmin();

const LOG_PAGE_SIZE = 50;

$filter = [];
foreach (['type', 'user_id', 'action'] as $k) {
    $filter[$k] = trim((string) ($_GET[$k] ?? ''));
}
foreach (['from', 'to'] as $k) {
    $v = (string) ($_GET[$k] ?? '');
    $filter[$k] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
}
$page  = max(1, (int) ($_GET['page'] ?? 1));
$total = changeLogCount($filter);
$rows  = changeLog($filter, LOG_PAGE_SIZE, ($page - 1) * LOG_PAGE_SIZE);
$pages = max(1, (int) ceil($total / LOG_PAGE_SIZE));

$users = q('SELECT id, display_name FROM app_user ORDER BY display_name');
$types = array_column(q('SELECT DISTINCT entity_type FROM change_log ORDER BY entity_type'), 'entity_type');

$pageLink = static fn(int $p) => h(adminUrl('log.php') . '?' . http_build_query(array_filter($filter + ['page' => $p], fn($v) => $v !== '' && $v !== 1)));

pageHeader('Change log', 'log');
?>
<h1>Change log</h1>
<form method="get" class="filters">
    <label>Type
        <select name="type"><option value="">all</option>
            <?php foreach ($types as $t): ?><option value="<?= h($t) ?>"<?= $filter['type'] === $t ? ' selected' : '' ?>><?= h(ENTITY_LABELS[$t] ?? $t) ?></option><?php endforeach; ?>
        </select></label>
    <label>User
        <select name="user_id"><option value="">all</option>
            <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>"<?= $filter['user_id'] === (string) $u['id'] ? ' selected' : '' ?>><?= h($u['display_name']) ?></option><?php endforeach; ?>
        </select></label>
    <label>Action
        <select name="action"><option value="">all</option>
            <?php foreach (['create', 'update', 'delete'] as $a): ?><option<?= $filter['action'] === $a ? ' selected' : '' ?>><?= $a ?></option><?php endforeach; ?>
        </select></label>
    <label>From <input type="date" name="from" value="<?= h($filter['from']) ?>"></label>
    <label>To <input type="date" name="to" value="<?= h($filter['to']) ?>"></label>
    <button type="submit">Filter</button>
    <a href="<?= h(adminUrl('log.php')) ?>">Reset</a>
</form>

<p class="muted"><?= $total ?> change<?= $total === 1 ? '' : 's' ?></p>
<?= renderChangeLog($rows) ?>

<?php if ($pages > 1): ?>
<nav class="pager">
    <?php if ($page > 1): ?><a href="<?= $pageLink($page - 1) ?>">‹ Newer</a><?php endif; ?>
    <span>Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a href="<?= $pageLink($page + 1) ?>">Older ›</a><?php endif; ?>
</nav>
<?php endif;
pageFooter();
