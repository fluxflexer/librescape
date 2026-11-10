<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

/** Only allow redirects back into the admin app. */
function safeNext(?string $next): string
{
    $base = adminUrl('');
    return is_string($next) && str_starts_with($next, $base) && !str_contains($next, '//') ? $next : adminUrl('index.php');
}

if (currentUser()) {
    redirect(safeNext($_GET['next'] ?? null));
}

$error = null;
$username = '';
if (isPost()) {
    checkCsrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $result = attemptLogin($username, (string) ($_POST['password'] ?? ''));
    if (is_array($result)) {
        redirect(safeNext($_POST['next'] ?? null));
    }
    $error = $result;
}

pageHeader('Log in');
?>
<form method="post" class="card login">
    <h1>Log in</h1>
    <?php if (maintenanceOn()): ?>
        <div class="notice info">Maintenance mode is on: only administrators can log in.</div>
    <?php endif; ?>
    <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?= csrfField() ?>
    <input type="hidden" name="next" value="<?= h($_GET['next'] ?? ($_POST['next'] ?? '')) ?>">
    <label>User name <input name="username" value="<?= h($username) ?>" autocomplete="username" autofocus required></label>
    <label>Password <input type="password" name="password" autocomplete="current-password" required></label>
    <button type="submit" class="primary">Log in</button>
</form>
<?php
pageFooter();
