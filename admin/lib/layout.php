<?php
// Page frame of the admin app: head, navigation, flash messages, footer.

declare(strict_types=1);

/** Cache-busting URL of an admin asset. */
function asset(string $file): string
{
    $path = dirname(__DIR__) . '/assets/' . $file;
    return adminUrl('assets/' . $file) . '?v=' . (is_file($path) ? filemtime($path) : 0);
}

function pageHeader(string $title, string $section = ''): void
{
    $u = currentUser();
    $nav = [
        'dashboard'   => ['index.php', 'Dashboard'],
        'event'       => ['list.php?type=event', 'Events'],
        'period'      => ['list.php?type=period', 'Periods'],
        'publication' => ['list.php?type=publication', 'Works'],
        'person'      => ['list.php?type=person', 'People'],
    ];
    $adminNav = [
        'users'    => ['users.php', 'Users'],
        'log'      => ['log.php', 'Change log'],
        'backups'  => ['backups.php', 'Backups'],
        'settings' => ['settings.php', 'Settings'],
    ];
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'");
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf" content="<?= $u ? h(csrfToken()) : '' ?>">
    <meta name="admin-base" content="<?= h(adminUrl('')) ?>">
    <title><?= h($title) ?> · Librescape admin</title>
    <link rel="stylesheet" href="<?= h(asset('admin.css')) ?>">
    <script src="<?= h(asset('admin.js')) ?>" defer></script>
</head>
<body>
<header class="topbar">
    <a class="brand" href="<?= h(adminUrl('index.php')) ?>">Librescape <span>admin</span></a>
    <?php if ($u): ?>
    <nav class="mainnav">
        <?php foreach ($nav as $key => [$href, $label]): ?>
            <a href="<?= h(adminUrl($href)) ?>"<?= $key === $section ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a>
        <?php endforeach; ?>
        <?php if ($u['role'] === 'admin'): ?>
            <span class="nav-sep" aria-hidden="true"></span>
            <?php foreach ($adminNav as $key => [$href, $label]): ?>
                <a href="<?= h(adminUrl($href)) ?>"<?= $key === $section ? ' class="active" aria-current="page"' : '' ?>><?= h($label) ?></a>
            <?php endforeach; ?>
        <?php endif; ?>
    </nav>
    <div class="usermenu">
        <a href="<?= h(viewerUrl()) ?>" target="_blank" rel="noopener" title="Open the public timeline">Timeline ↗</a>
        <a href="<?= h(adminUrl('profile.php')) ?>"<?= $section === 'profile' ? ' class="active"' : '' ?> title="<?= h(ROLE_LABELS[$u['role']]) ?>"><?= h($u['display_name']) ?></a>
        <form method="post" action="<?= h(adminUrl('logout.php')) ?>"><?= csrfField() ?><button type="submit" class="linkish">Log out</button></form>
    </div>
    <?php endif; ?>
</header>
<?php if ($u && maintenanceOn()): ?>
<div class="maintenance-bar">Maintenance mode is on: the public timeline is offline and editors cannot log in.
    <?php if ($u['role'] === 'admin'): ?><a href="<?= h(adminUrl('settings.php')) ?>">Settings</a><?php endif; ?></div>
<?php endif; ?>
<main class="page">
<?php foreach (takeFlashes() as $f): ?>
    <div class="notice <?= h($f['kind']) ?>" role="status"><?= h($f['message']) ?></div>
<?php endforeach;
}

function pageFooter(): void
{
    echo "</main>\n</body>\n</html>\n";
}
