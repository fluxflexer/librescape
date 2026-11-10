<?php
// Loaded first by every admin page and endpoint.

declare(strict_types=1);

require_once __DIR__ . '/../../lib/entries.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/changelog.php';
require_once __DIR__ . '/layout.php';

startSession();
