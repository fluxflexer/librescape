<?php
// User management (administrators only): list, create, change role / active flag, reset password, unlock.
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';
$me = requireAdmin();

$id = isset($_GET['id']) ? (string) $_GET['id'] : null;   // null = list, 'new' = create form, number = edit
$errors = [];
$form = ['username' => '', 'display_name' => '', 'role' => 'editor', 'is_active' => 1];

function activeAdminsExcept(int $id): int
{
    return (int) q1("SELECT COUNT(*) AS n FROM app_user WHERE role = 'admin' AND is_active = 1 AND id <> ?", [$id])['n'];
}

if ($id !== null && $id !== 'new') {
    $user = q1('SELECT * FROM app_user WHERE id = ?', [(int) $id]);
    if (!$user) {
        flash('That user does not exist.', 'error');
        redirect(adminUrl('users.php'));
    }
    $form = $user;
}

if (isPost()) {
    $isNew = $id === 'new';
    $in = [
        'username'     => trim((string) ($_POST['username'] ?? '')),
        'display_name' => trim((string) ($_POST['display_name'] ?? '')),
        'role'         => array_key_exists($_POST['role'] ?? '', ROLE_LABELS) ? $_POST['role'] : 'editor',
        'is_active'    => !empty($_POST['is_active']) ? 1 : 0,
    ];
    $password = (string) ($_POST['password'] ?? '');

    if ($isNew) {
        if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $in['username'])) {
            $errors[] = 'User name: 3–50 characters, letters, digits, dot, dash or underscore.';
        } elseif (q1('SELECT id FROM app_user WHERE username = ?', [$in['username']])) {
            $errors[] = 'This user name is already taken.';
        }
    } else {
        $in['username'] = $user['username'];   // user names are fixed (they appear in the change log)
    }
    if ($in['display_name'] === '') {
        $errors[] = 'Please enter a display name.';
    }
    if ($isNew || $password !== '') {
        if ($p = passwordProblem($password, $in['username'])) {
            $errors[] = $p;
        }
    }
    if (!$isNew && (int) $user['id'] === $me['id'] && ($in['role'] !== 'admin' || !$in['is_active'])) {
        $errors[] = 'You cannot remove your own administrator rights or deactivate yourself.';
    }
    if (!$isNew && $user['role'] === 'admin' && ($in['role'] !== 'admin' || !$in['is_active']) && activeAdminsExcept((int) $user['id']) === 0) {
        $errors[] = 'There must always be at least one active administrator.';
    }

    if (!$errors) {
        if ($isNew) {
            q('INSERT INTO app_user (username, display_name, password_hash, role, is_active) VALUES (?, ?, ?, ?, ?)',
              [$in['username'], $in['display_name'], password_hash($password, PASSWORD_DEFAULT), $in['role'], $in['is_active']]);
            $newId = (int) getDb()->lastInsertId();
            logChange('user', $newId, 'create', $in['username'], ['display_name' => $in['display_name'], 'role' => $in['role'], 'is_active' => $in['is_active']]);
            flash("User {$in['username']} created. Tell them their password and ask them to change it under their name (top right).");
        } else {
            $changes = diffRecords($user, $in, ['display_name', 'role', 'is_active']);
            $sql = 'UPDATE app_user SET display_name = ?, role = ?, is_active = ?';
            $params = [$in['display_name'], $in['role'], $in['is_active']];
            if ($password !== '') {
                $sql .= ', password_hash = ?, failed_logins = 0, locked_until = NULL';
                $params[] = password_hash($password, PASSWORD_DEFAULT);
                $changes['password'] = ['•••', 'reset'];
            }
            if (!empty($_POST['unlock'])) {
                $sql .= ', failed_logins = 0, locked_until = NULL';
                $changes['locked'] = ['yes', 'unlocked'];
            }
            $params[] = $user['id'];
            q($sql . ' WHERE id = ?', $params);
            logChange('user', (int) $user['id'], 'update', $user['username'], $changes);
            flash($changes ? "User {$user['username']} saved." : 'Nothing was changed.', $changes ? 'ok' : 'info');
        }
        redirect(adminUrl('users.php'));
    }
    $form = $in + $form;
}

pageHeader('Users', 'users');

if ($id === null):
    $users = q('SELECT * FROM app_user ORDER BY is_active DESC, role, display_name');
?>
<div class="page-head">
    <h1>Users</h1>
    <a class="button primary" href="<?= h(adminUrl('users.php?id=new')) ?>">+ New user</a>
</div>
<p class="muted">Editors create, change and delete entries. Administrators can also manage users, backups and maintenance mode.</p>
<table class="list">
    <thead><tr><th>Name</th><th>User name</th><th>Role</th><th>Status</th><th>Last login</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u):
        $locked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
        <tr class="<?= $u['is_active'] ? '' : 'inactive' ?>">
            <td><a href="<?= h(adminUrl('users.php?id=' . $u['id'])) ?>"><?= h($u['display_name']) ?></a><?= (int) $u['id'] === $me['id'] ? ' <span class="muted">(you)</span>' : '' ?></td>
            <td><?= h($u['username']) ?></td>
            <td><span class="badge role-<?= h($u['role']) ?>"><?= h(ROLE_LABELS[$u['role']]) ?></span></td>
            <td><?= !$u['is_active'] ? 'deactivated' : ($locked ? 'locked until ' . h(date('H:i', strtotime($u['locked_until']))) : 'active') ?></td>
            <td class="nowrap"><?= $u['last_login_at'] ? h(date('j M Y H:i', strtotime($u['last_login_at']))) : '<span class="muted">never</span>' ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php else:
    $isNew = $id === 'new';
    $locked = !$isNew && $form['locked_until'] && strtotime($form['locked_until']) > time();
?>
<p class="crumbs"><a href="<?= h(adminUrl('users.php')) ?>">Users</a> ›</p>
<h1><?= $isNew ? 'New user' : h($form['display_name']) ?></h1>
<form method="post" class="card narrow">
    <?php foreach ($errors as $e): ?><div class="notice error" role="alert"><?= h($e) ?></div><?php endforeach; ?>
    <?= csrfField() ?>
    <label>User name
        <?php if ($isNew): ?>
            <input name="username" value="<?= h($form['username']) ?>" required pattern="[a-zA-Z0-9._\-]{3,50}" autocomplete="off" autofocus>
            <small>Used to log in; cannot be changed later.</small>
        <?php else: ?>
            <input value="<?= h($form['username']) ?>" disabled>
        <?php endif; ?>
    </label>
    <label>Display name <input name="display_name" value="<?= h($form['display_name']) ?>" required maxlength="100"></label>
    <fieldset class="choices">
        <legend>Role</legend>
        <?php foreach (ROLE_LABELS as $r => $label): ?>
            <label class="inline"><input type="radio" name="role" value="<?= h($r) ?>"<?= $form['role'] === $r ? ' checked' : '' ?>> <?= h($label) ?></label>
        <?php endforeach; ?>
    </fieldset>
    <label class="inline"><input type="checkbox" name="is_active" value="1"<?= $form['is_active'] ? ' checked' : '' ?>> Active (can log in)</label>
    <label><?= $isNew ? 'Password' : 'New password' ?>
        <input type="password" name="password" autocomplete="new-password" <?= $isNew ? 'required' : '' ?> minlength="<?= MIN_PASSWORD_LENGTH ?>">
        <small><?= $isNew ? 'At least ' . MIN_PASSWORD_LENGTH . ' characters.' : 'Leave empty to keep the current password.' ?></small>
    </label>
    <?php if ($locked): ?>
        <label class="inline"><input type="checkbox" name="unlock" value="1"> Unlock (locked after too many failed logins)</label>
    <?php endif; ?>
    <div class="actions">
        <button type="submit" class="primary"><?= $isNew ? 'Create user' : 'Save' ?></button>
        <a href="<?= h(adminUrl('users.php')) ?>">Cancel</a>
    </div>
</form>
<?php if (!$isNew): ?>
    <h2>History</h2>
    <?= renderChangeLog(changeLog(['type' => 'user', 'id' => $form['id']], 20), false) ?>
<?php endif;
endif;
pageFooter();
