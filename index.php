<?php
declare(strict_types=1);
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

<script src="assets/vendor/vis-timeline/vis-timeline-graph2d.min.js"></script>
<script src="<?= $v('assets/app.js') ?>"></script>
</body>
</html>
