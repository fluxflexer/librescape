<?php
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';

// Maintenance mode (switched in the admin app): show a short notice instead of the timeline.
if (($maintenance = maintenanceMessage()) !== null) {
    http_response_code(503);
    header('Retry-After: 600');
    ?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Librescape</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px;
               font: 16px/1.6 system-ui, "Segoe UI", sans-serif; background: #f6f3ee; color: #24211d; }
        main { max-width: 460px; text-align: center; }
        h1 { font: 600 30px "Palatino Linotype", Palatino, Georgia, serif; margin: 0 0 8px; }
        p { color: #5d574f; margin: 0; }
        @media (prefers-color-scheme: dark) { body { background: #17150f; color: #eee8de; } p { color: #bdb4a6; } }
    </style>
</head>
<body><main><h1>Librescape</h1><p><?= htmlspecialchars($maintenance, ENT_QUOTES, 'UTF-8') ?></p></main></body>
</html>
<?php
    exit;
}

$v = static fn(string $f): string => $f . '?v=' . (is_file(__DIR__ . '/' . $f) ? filemtime(__DIR__ . '/' . $f) : 0);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Librescape</title>
    <link rel="stylesheet" href="assets/vendor/vis-timeline/vis-timeline-graph2d.min.css">
    <link rel="stylesheet" href="<?= $v('assets/style.css') ?>">
</head>
<body>
<header class="topbar">
    <div class="brand">
        <h1>Librescape</h1>
        <p id="subtitle" class="subtitle">Loading…</p>
    </div>

    <label class="keyword-pick" title="Show everything tagged with a keyword in its own lane">
        <span class="keyword-hash" aria-hidden="true">#</span>
        <select id="keyword" aria-label="Keyword lane" disabled>
            <option value="">keyword</option>
        </select>
    </label>

    <div class="search" role="search">
        <svg class="search-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input id="search" type="search" placeholder="Search people, events, works, keywords…"
               autocomplete="off" spellcheck="false" aria-label="Search" aria-controls="search-results" aria-expanded="false">
        <ul id="search-results" class="search-results" role="listbox" hidden></ul>
    </div>

    <div class="controls">
        <div id="lanes" class="lanes" aria-label="Show or hide lanes"></div>
        <div class="zoom" role="group" aria-label="Zoom">
            <button type="button" data-zoom="in"   title="Zoom in">+</button>
            <button type="button" data-zoom="out"  title="Zoom out">−</button>
            <button type="button" data-zoom="life" title="Fit to the author's lifetime">Lifetime</button>
            <button type="button" data-zoom="all"  title="Show everything">All</button>
        </div>
    </div>
</header>

<main class="stage">
    <div id="timeline" class="timeline"></div>
    <div id="status" class="status">Loading timeline…</div>

    <aside id="panel" class="panel" aria-live="polite" hidden>
        <button type="button" class="panel-close" id="panel-close" aria-label="Close details">×</button>
        <div id="panel-body" class="panel-body"></div>
    </aside>
</main>

<footer class="bottombar">Click an entry for its details · drag the timeline to move it · + and − zoom</footer>

<script src="assets/vendor/vis-timeline/vis-timeline-graph2d.min.js"></script>
<script src="<?= $v('assets/app.js') ?>"></script>
</body>
</html>
