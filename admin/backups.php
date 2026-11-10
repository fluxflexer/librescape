<?php
// Database dumps (administrators only): create, download, delete.
// Restoring is deliberately not a button: see the instructions on the page.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/../lib/backup.php';
requireAdmin();

if (isset($_GET['download'])) {
    $path = backupPath((string) $_GET['download']);
    if (!$path) {
        http_response_code(404);
        exit('Backup not found');
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: no-store');
    readfile($path);
    exit;
}

if (isPost()) {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create') {
            $name = createBackup('manual');
            logChange('backup', null, 'create', $name);
            flash("Backup $name created.");
        } elseif ($action === 'delete') {
            $path = backupPath((string) ($_POST['name'] ?? ''));
            if ($path && unlink($path)) {
                logChange('backup', null, 'delete', basename($path));
                flash('Backup ' . basename($path) . ' deleted.');
            } else {
                flash('That backup could not be deleted.', 'error');
            }
        }
    } catch (Throwable $e) {
        error_log('librescape backup: ' . $e->getMessage());
        flash('The backup failed: ' . $e->getMessage(), 'error');
    }
    redirect(adminUrl('backups.php'));
}

function humanSize(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1) . ' MB' : number_format(max(1, $bytes / 1024), 0) . ' KB';
}

$error = null;
try {
    $backups = listBackups();
    $dir = backupDir();
} catch (Throwable $e) {
    $backups = [];
    $dir = appConfig()['backup_dir'];
    $error = $e->getMessage();
}

pageHeader('Backups', 'backups');
?>
<div class="page-head">
    <h1>Backups</h1>
    <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="create">
        <button type="submit" class="primary">Create backup now</button></form>
</div>
<p class="muted">Complete database dumps (all entries, sources, users and the change log). They are stored outside the
    web folder in <code><?= h($dir) ?></code>. <code>deploy.ps1 -ResetDb</code> also writes one automatically before it wipes the database.</p>
<?php if ($error): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<?php if (!$backups): ?>
    <p class="muted">No backups yet.</p>
<?php else: ?>
<table class="list">
    <thead><tr><th>File</th><th>Created</th><th>Size</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($backups as $b): ?>
        <tr>
            <td><a href="<?= h(adminUrl('backups.php') . '?download=' . rawurlencode($b['name'])) ?>"><?= h($b['name']) ?></a></td>
            <td class="nowrap"><?= h(date('j M Y H:i', $b['time'])) ?></td>
            <td class="nowrap"><?= h(humanSize($b['size'])) ?></td>
            <td><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="delete">
                <input type="hidden" name="name" value="<?= h($b['name']) ?>">
                <button type="submit" class="small danger" data-confirm="Delete <?= h($b['name']) ?>? This cannot be undone.">Delete</button></form></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Restoring a backup</h2>
<p>Restoring replaces <strong>everything</strong> in the database with the state of the backup, including users.
    It is done on the server's command line on purpose:</p>
<ol>
    <li>Switch on maintenance mode under <a href="<?= h(adminUrl('settings.php')) ?>">Settings</a> and create a fresh backup of the current state.</li>
    <li>In PowerShell: <code>C:\XAMPP\mysql\bin\mysql.exe -u librescape -p --default-character-set=utf8mb4 librescape --execute="SOURCE C:/XAMPP/librescape-backups/&lt;file&gt;.sql"</code></li>
    <li>Run <code>.\deploy.ps1</code> (applies any newer schema migrations), then switch maintenance mode off.</li>
</ol>
<?php
pageFooter();
