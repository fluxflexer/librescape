<?php
/**
 * Creates a user for the admin app, e.g. the first administrator after a fresh install or -ResetDb.
 *
 *   php tools/create_user.php <username> <admin|editor> ["Display name"]
 *
 * Asks for the password (input is visible in the console; change it afterwards in the app
 * under your name, top right). The password can also be piped in: echo secret | php tools/create_user.php ...
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../lib/db.php';

[$username, $role, $display] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ($argv[1] ?? '')];
if (!preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username) || !in_array($role, ['admin', 'editor'], true)) {
    fwrite(STDERR, "Usage: php tools/create_user.php <username> <admin|editor> [\"Display name\"]\n"
                 . "User name: 3-50 characters, letters, digits, dot, dash or underscore.\n");
    exit(1);
}
if (q1('SELECT id FROM app_user WHERE username = ?', [$username])) {
    fwrite(STDERR, "User $username already exists. Reset the password in the admin app (Users).\n");
    exit(1);
}

fwrite(STDOUT, 'Password (at least 10 characters): ');
$password = rtrim((string) fgets(STDIN), "\r\n");
if (mb_strlen($password) < 10 || stripos($password, $username) !== false) {
    fwrite(STDERR, "\nThe password needs at least 10 characters and must not contain the user name.\n");
    exit(1);
}

q('INSERT INTO app_user (username, display_name, password_hash, role) VALUES (?, ?, ?, ?)',
  [$username, $display, password_hash($password, PASSWORD_DEFAULT), $role]);
$id = (int) getDb()->lastInsertId();
q("INSERT INTO change_log (user_id, user_name, entity_type, entity_id, action, label, changes)
   VALUES (NULL, 'command line', 'user', ?, 'create', ?, ?)",
  [$id, $username, json_encode(['display_name' => $display, 'role' => $role])]);
echo "\nCreated $role $username.\n";
