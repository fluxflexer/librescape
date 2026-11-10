<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$me = requireLogin();

$error = null;
if (isPost()) {
    $row = q1('SELECT password_hash FROM app_user WHERE id = ?', [$me['id']]);
    $new = (string) ($_POST['new_password'] ?? '');
    if (!password_verify((string) ($_POST['current_password'] ?? ''), $row['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
        $error = 'The two new passwords are not the same.';
    } elseif ($p = passwordProblem($new, $me['username'])) {
        $error = $p;
    } else {
        q('UPDATE app_user SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        logChange('user', $me['id'], 'update', $me['username'], ['password' => ['•••', 'changed']]);
        session_regenerate_id(true);
        flash('Your password has been changed.');
        redirect(adminUrl('profile.php'));
    }
}

pageHeader('My account', 'profile');
?>
<h1>My account</h1>
<p><?= h($me['display_name']) ?> · user name <strong><?= h($me['username']) ?></strong> · <?= h(ROLE_LABELS[$me['role']]) ?></p>

<form method="post" class="card narrow">
    <h2>Change password</h2>
    <?php if ($error): ?><div class="notice error" role="alert"><?= h($error) ?></div><?php endif; ?>
    <?= csrfField() ?>
    <label>Current password <input type="password" name="current_password" autocomplete="current-password" required></label>
    <label>New password <input type="password" name="new_password" autocomplete="new-password" minlength="<?= MIN_PASSWORD_LENGTH ?>" required>
        <small>At least <?= MIN_PASSWORD_LENGTH ?> characters.</small></label>
    <label>New password again <input type="password" name="new_password2" autocomplete="new-password" required></label>
    <button type="submit" class="primary">Change password</button>
</form>

<h2>My recent changes</h2>
<?= renderChangeLog(changeLog(['user_id' => $me['id']], 20)) ?>
<?php
pageFooter();
