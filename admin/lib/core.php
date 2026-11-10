<?php
// Admin app core: session, login, roles, CSRF, settings, small HTML helpers.
// Every admin page starts with:  require __DIR__ . '/lib/bootstrap.php'; requireLogin();

declare(strict_types=1);

const SESSION_IDLE_SECONDS = 2 * 3600;
const MAX_FAILED_LOGINS    = 5;
const LOCKOUT_MINUTES      = 15;
const MIN_PASSWORD_LENGTH  = 10;
const ROLE_LABELS          = ['editor' => 'Editor', 'admin' => 'Administrator'];

/** URL path of the admin folder, e.g. "/librescape/admin" (works from admin/ and admin/api/). */
function adminUrl(string $page = ''): string
{
    static $base = null;
    if ($base === null) {
        $adminDir = str_replace('\\', '/', (string) realpath(dirname(__DIR__)));
        $script   = str_replace('\\', '/', (string) realpath($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $rel      = substr($script, strlen($adminDir));                     // "/api/lookup.php"
        $base     = substr($_SERVER['SCRIPT_NAME'] ?? '', 0, -strlen($rel)) ?: '';
    }
    return $base . '/' . ltrim($page, '/');
}

/** URL of the public viewer (the folder above admin/). */
function viewerUrl(string $query = ''): string
{
    // dirname() of "/admin" is "\" on Windows, hence the backslash in the trim list.
    return rtrim(dirname(rtrim(adminUrl(''), '/')), '/\\') . '/' . $query;
}

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_name('librescape_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => adminUrl(''),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    $now = time();
    if (isset($_SESSION['uid'], $_SESSION['seen']) && $now - $_SESSION['seen'] > SESSION_IDLE_SECONDS) {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Your session expired. Please log in again.', 'info');
    }
    $_SESSION['seen'] = $now;
}

// ------------------------------------------------------------------ output helpers

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 303);
    exit;
}

function flash(string $message, string $kind = 'ok'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'kind' => $kind];
}

function takeFlashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function forbidden(string $why = 'You are not allowed to do this.'): never
{
    http_response_code(403);
    if (isJsonRequest()) {
        jsonOut(['error' => $why], 403);
    }
    pageHeader('Not allowed');
    echo '<div class="notice error">' . h($why) . '</div>';
    pageFooter();
    exit;
}

function isJsonRequest(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
}

// ------------------------------------------------------------------ CSRF

function csrfToken(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">';
}

/** Every state-changing request must carry the token (form field or X-CSRF-Token header). */
function checkCsrf(): void
{
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        forbidden('The form has expired or did not come from this site. Please reload the page and try again.');
    }
}

function isPost(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

// ------------------------------------------------------------------ settings

function setting(string $name, ?string $default = null): ?string
{
    $r = q1('SELECT value FROM app_setting WHERE name = ?', [$name]);
    return $r ? $r['value'] : $default;
}

function setSetting(string $name, ?string $value): void
{
    q('INSERT INTO app_setting (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)', [$name, $value]);
}

function maintenanceOn(): bool
{
    return setting('maintenance_mode', '0') === '1';
}

// ------------------------------------------------------------------ users and login

/** The logged-in user (fresh from the DB on every request, so deactivation and role changes apply at once). */
function currentUser(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['uid'])) {
            $u = q1('SELECT id, username, display_name, role, is_active FROM app_user WHERE id = ?', [$_SESSION['uid']]);
            if ($u && $u['is_active']) {
                $u['id'] = (int) $u['id'];
                $user = $u;
            } else {
                unset($_SESSION['uid']);
            }
        }
    }
    return $user;
}

function isAdmin(): bool
{
    return (currentUser()['role'] ?? '') === 'admin';
}

function requireLogin(): array
{
    $u = currentUser();
    if (!$u) {
        if (isJsonRequest() || str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/')) {
            jsonOut(['error' => 'Not logged in'], 401);
        }
        redirect(adminUrl('login.php') . '?next=' . rawurlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    if (maintenanceOn() && $u['role'] !== 'admin') {
        logout();
        flash('Maintenance mode is on: only administrators can work right now.', 'error');
        redirect(adminUrl('login.php'));
    }
    if (isPost()) {
        checkCsrf();
    }
    return $u;
}

function requireAdmin(): array
{
    $u = requireLogin();
    if ($u['role'] !== 'admin') {
        forbidden('Only administrators can open this page.');
    }
    return $u;
}

/**
 * Checks username + password. Returns the user row, or an error message.
 * After MAX_FAILED_LOGINS wrong passwords the account is locked for LOCKOUT_MINUTES.
 */
function attemptLogin(string $username, string $password): array|string
{
    $u = q1('SELECT * FROM app_user WHERE username = ?', [$username]);
    // Same work for unknown users, so response times don't reveal which names exist.
    $hash = $u['password_hash'] ?? '$2y$10$OeJa9eaIa3wlfQK5ln105us1N0TEBoiKgRJhjHGzZPZk1cGYjIDAO';
    $ok = password_verify($password, $hash);

    $generic = 'Wrong user name or password.';
    if (!$u || !$u['is_active']) {
        return $generic;
    }
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        return 'Too many failed attempts. This account is locked until ' . date('H:i', strtotime($u['locked_until'])) . '.';
    }
    if (!$ok) {
        $fails = (int) $u['failed_logins'] + 1;
        if ($fails >= MAX_FAILED_LOGINS) {
            q('UPDATE app_user SET failed_logins = 0, locked_until = NOW() + INTERVAL ? MINUTE WHERE id = ?', [LOCKOUT_MINUTES, $u['id']]);
            return 'Too many failed attempts. This account is locked for ' . LOCKOUT_MINUTES . ' minutes.';
        }
        q('UPDATE app_user SET failed_logins = ? WHERE id = ?', [$fails, $u['id']]);
        return $generic;
    }
    if (maintenanceOn() && $u['role'] !== 'admin') {
        return 'Maintenance mode is on: only administrators can log in right now.';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE app_user SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    q('UPDATE app_user SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$u['id']]);

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    unset($_SESSION['csrf']);
    return $u;
}

function logout(): void
{
    $_SESSION = [];
    session_regenerate_id(true);
}

/** Returns an error message, or null if the password is acceptable. */
function passwordProblem(string $password, string $username = ''): ?string
{
    if (mb_strlen($password) < MIN_PASSWORD_LENGTH) {
        return 'The password must have at least ' . MIN_PASSWORD_LENGTH . ' characters.';
    }
    if ($username !== '' && stripos($password, $username) !== false) {
        return 'The password must not contain the user name.';
    }
    return null;
}
