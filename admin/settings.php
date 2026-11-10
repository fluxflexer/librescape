<?php
// Administrator settings: maintenance mode.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
requireAdmin();

if (isPost()) {
    $on  = !empty($_POST['maintenance_mode']) ? '1' : '0';
    $msg = trim((string) ($_POST['maintenance_message'] ?? ''));
    if ($msg === '') {
        $msg = 'Librescape is being updated and will be back shortly.';
    }
    $old = ['maintenance_mode' => setting('maintenance_mode', '0'), 'maintenance_message' => setting('maintenance_message')];
    $new = ['maintenance_mode' => $on, 'maintenance_message' => mb_substr($msg, 0, 500)];
    foreach ($new as $k => $v) {
        setSetting($k, $v);
    }
    logChange('setting', null, 'update', 'Maintenance mode', diffRecords($old, $new, array_keys($new)));
    flash($on === '1'
        ? 'Maintenance mode is ON: the public timeline shows your message and editors are logged out.'
        : 'Maintenance mode is off: the timeline is public again and editors can log in.', $on === '1' ? 'warn' : 'ok');
    redirect(adminUrl('settings.php'));
}

$on = maintenanceOn();
$editorsOnline = (int) q1("SELECT COUNT(*) AS n FROM app_user WHERE role = 'editor' AND is_active = 1")['n'];

pageHeader('Settings', 'settings');
?>
<h1>Settings</h1>

<form method="post" class="card narrow">
    <h2>Maintenance mode</h2>
    <p class="muted">While maintenance mode is on, the public timeline shows the message below instead of the data,
        and editors cannot log in (logged-in editors are sent out on their next click). Administrators keep working,
        e.g. for larger corrections or before restoring a backup.</p>
    <?= csrfField() ?>
    <label class="inline"><input type="checkbox" name="maintenance_mode" value="1"<?= $on ? ' checked' : '' ?>> Maintenance mode on</label>
    <label>Message for visitors
        <textarea name="maintenance_message" rows="3" maxlength="500"><?= h(setting('maintenance_message')) ?></textarea>
    </label>
    <div class="actions">
        <button type="submit" class="primary">Save</button>
        <span class="muted">Currently <?= $on ? '<strong>on</strong>' : 'off' ?> · <?= $editorsOnline ?> active editor account<?= $editorsOnline === 1 ? '' : 's' ?></span>
    </div>
</form>

<h2>History</h2>
<?= renderChangeLog(changeLog(['type' => 'setting'], 10), false) ?>
<?php
pageFooter();
