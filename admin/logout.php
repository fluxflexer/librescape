<?php
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

// Logging out changes state, so it is a POST with CSRF token (a link could be triggered by other sites).
if (isPost()) {
    checkCsrf();
    logout();
    flash('You have been logged out.', 'info');
}
redirect(adminUrl('login.php'));
