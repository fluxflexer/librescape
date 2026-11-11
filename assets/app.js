/* Librescape timeline viewer. Vanilla JS + vis-timeline. */
(function () {
    'use strict';

    const DAY = 864e5;
    const YEAR = 365.25 * DAY;
    const ANIM = { duration: 700, easingFunction: 'easeInOutCubic' };
    const TYPE_BY_PREFIX = { e: 'event', p: 'period', w: 'publication' };

    // Semantic zoom: a period that has sub-items in its own lane opens up into a band
    // (with its children inside) once it is at least this many pixels wide on screen.
    const EXPAND_PX = 420;
    const ITEM_MARGIN_V = 8;   // vertical gap between stacked items (vis margin.item.vertical)

    // Inside every lane, periods (ranges) are stacked on top and events/works (points) below them.
    // Done with vis subgroups, ordered by `sgOrder`: 'periods' (0), then one row per opened period
    // (1.xxx, see sync), then 'events' (2). An opened period's band only covers its own row, so
    // unrelated items of the same lane are never drawn inside it.
    const LANE_GROUP_OPTS = { subgroupStack: true, subgroupOrder: 'sgOrder' };
    const lanePart = (type) => type === 'range'
        ? { subgroup: 'periods', sgOrder: 0 }
        : { subgroup: 'events', sgOrder: 2 };

    const $ = (sel) => document.querySelector(sel);
    const els = {
        timeline: $('#timeline'),
        status: $('#status'),
        subtitle: $('#subtitle'),
        lanes: $('#lanes'),
        search: $('#search'),
        results: $('#search-results'),
        panel: $('#panel'),
        panelBody: $('#panel-body'),
        keyword: $('#keyword'),
    };

    // Keyword lanes: one extra lane per chosen keyword, on top (newest first), holding copies of
    // every item tagged with it. Copies have the id 'k<slot>_<original id>' (k1_e7, k2_p9) and keep
    // their original lane colour. A slot is a number given to each keyword lane when it is added.
    const keywordLanes = new Map();     // keyword name -> slot number (insertion order = age)
    let nextSlot = 1;
    const kwGroupId = (slot) => 'kw' + slot;

    if (typeof vis === 'undefined') {
        els.status.textContent = 'vis-timeline failed to load.';
        els.status.classList.add('is-error');
        return;
    }

    let timeline, groups, meta;
    const items = new vis.DataSet();   // what vis currently draws (derived)
    const all = new Map();             // every item from the API, by id (source of truth)
    const children = new Map();        // period id -> ids of its children in the SAME lane
    let matchSet = new Set();          // search matches (item ids)
    let selectedId = null;             // logical id of the selected item (e1 / p9 / w3)

    // ------------------------------------------------------------------ helpers

    async function api(path) {
        const res = await fetch('api/' + path, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error('HTTP ' + res.status + ' for ' + path);
        return res.json();
    }

    /** Tiny DOM builder: el('a', {href: x, class: 'y', onclick: fn}, 'text', childNode, ...) */
    function el(tag, attrs, ...kids) {
        const node = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v == null || v === false) continue;
            if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
            else if (k === 'class') node.className = v;
            else node.setAttribute(k, v === true ? '' : v);
        }
        for (const c of kids.flat()) {
            if (c == null || c === false) continue;
            node.append(c instanceof Node ? c : document.createTextNode(String(c)));
        }
        return node;
    }

    function debounce(fn, ms) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }

    function parseItemId(itemId) {
        const m = /^([epw])(\d+)$/.exec(itemId || '');
        return m ? { type: TYPE_BY_PREFIX[m[1]], id: Number(m[2]) } : null;
    }

    const time = (d) => new Date(d).getTime();

    function showStatus(text, isError) {
        els.status.textContent = text;
        els.status.classList.toggle('is-error', !!isError);
        els.status.hidden = !text;
    }

    // ------------------------------------------------------------------ nesting / semantic zoom

    /** Parent period id, but only if it lives in the same lane (cross-lane parents never hide a child). */
    function sameLaneParent(id) {
        const it = all.get(id);
        const p = it && it.parent && all.get(it.parent);
        return p && p.group === it.group ? p.id : null;
    }

    function ancestors(id) {
        const out = [];
        for (let p = all.get(id) && all.get(id).parent; p && all.has(p) && !out.includes(p); p = all.get(p).parent) out.push(p);
        return out;
    }

    function centerWidth() {
        const c = els.timeline.querySelector('.vis-panel.vis-center');
        return (c && c.clientWidth) || Math.max(200, els.timeline.clientWidth - 140);
    }

    /** Which expandable periods are open at the current zoom level. */
    function computeExpanded() {
        const open = new Set();
        if (!timeline) return open;
        const w = timeline.getWindow();
        const pxPerMs = centerWidth() / (w.end - w.start);
        const forced = new Set(selectedId ? ancestors(selectedId) : []);
        children.forEach((_, pid) => {
            const p = all.get(pid);
            if (forced.has(pid) || (time(p.end) - time(p.start)) * pxPerMs >= EXPAND_PX) open.add(pid);
        });
        return open;
    }

    function isVisible(id, open) {
        for (let p = sameLaneParent(id); p; p = sameLaneParent(p)) {
            if (!open.has(p)) return false;
        }
        return true;
    }

    /** Rebuild the drawn items from `all` for the current zoom, search and selection state. */
    function sync() {
        if (!timeline) return;
        const open = computeExpanded();
        const searching = matchSet.size > 0;
        // Collapsed parents of hidden matches stay highlighted so the user sees where to zoom.
        const matchAncestors = new Set();
        matchSet.forEach((id) => ancestors(id).forEach((a) => matchAncestors.add(a)));
        const searchClass = (id) => !searching ? ''
            : matchSet.has(id) ? ' is-match'
            : matchAncestors.has(id) ? ' is-match-parent'
            : ' is-dimmed';

        // Every opened period gets its own row in its lane, holding its label and its direct children.
        // Rows are numbered depth-first (outer period, then its opened sub-periods, by start), so a
        // nested band sits right below its parent's row. The row number is part of the subgroup id:
        // when rows are inserted the id changes, which makes vis re-sort the rows.
        const byStart = (a, b) => time(all.get(a).start) - time(all.get(b).start);
        const bandRow = new Map();      // opened period id -> { subgroup, sgOrder }
        const bandPieces = new Map();   // opened period id -> rows its band covers (own row + nested rows)
        let rowNo = 0;
        const visitBand = (pid, outer) => {
            rowNo++;
            const row = { subgroup: 'band' + rowNo + '-' + pid, sgOrder: 1 + rowNo / 1000 };
            bandRow.set(pid, row);
            bandPieces.set(pid, [pid]);
            outer.forEach((o) => bandPieces.get(o).push(pid));   // enclosing bands continue through this row
            (children.get(pid) || []).filter((c) => open.has(c)).sort(byStart).forEach((c) => visitBand(c, [...outer, pid]));
        };
        [...open].filter((id) => !sameLaneParent(id) && isVisible(id, open)).sort(byStart).forEach((id) => visitBand(id, []));
        const rowFor = (it) => {
            const p = sameLaneParent(it.id);
            return p && bandRow.has(p) ? bandRow.get(p) : lanePart(it.type);
        };

        const desired = [];
        all.forEach((it) => {
            if (it.type === 'background') { desired.push(it); return; }
            if (!isVisible(it.id, open)) return;
            if (open.has(it.id)) {
                // Expanded parent: a band (limited to its own rows) with the children inside, plus a label
                // on top (a real range item, so vis reserves space for it and it is clickable).
                const state = (selectedId === it.id ? ' is-selected' : '') + searchClass(it.id);
                const lane = it.baseClass.split(' ').filter((c) => c.startsWith('lane-')).join(' ');
                const pieces = bandPieces.get(it.id);
                pieces.forEach((rowPid, i) => desired.push({
                    // q9 = the band in its own row; q9_12 = its continuation through nested band 12's row
                    id: 'q' + it.id.slice(1) + (rowPid === it.id ? '' : '_' + rowPid.slice(1)),
                    type: 'background',
                    group: it.group,
                    start: it.start,
                    end: it.end,
                    content: '',
                    ...bandRow.get(rowPid),
                    className: (lane + ' band' + (i > 0 ? ' band-cont' : '') + (i === pieces.length - 1 ? ' band-end' : '') + state).trim(),
                }));
                desired.push({
                    id: 'l' + it.id.slice(1),
                    type: 'range',
                    group: it.group,
                    start: it.start,
                    end: it.end,
                    content: it.content,
                    title: it.title,
                    isBandLabel: true,
                    ...bandRow.get(it.id),
                    className: (lane + ' band-label' + state).trim(),
                });
                return;
            }
            const hidden = (children.get(it.id) || []).length;
            desired.push(Object.assign({}, it, rowFor(it), {
                className: (it.baseClass + (hidden ? ' has-children' : '') + searchClass(it.id)).trim(),
                title: hidden ? it.title + ' · zoom in for ' + hidden + ' sub-item' + (hidden > 1 ? 's' : '') : it.title,
            }));
        });

        // Keyword lanes: flat (no semantic zoom), everything tagged is always shown.
        keywordLanes.forEach((slot, name) => keywordItems(name).forEach((it) => {
            desired.push({
                id: 'k' + slot + '_' + it.id,
                group: kwGroupId(slot),
                type: it.type,
                start: it.start,
                end: it.end,
                content: it.content,
                title: it.title,
                className: (it.baseClass + ' kw-copy' + searchClass(it.id)).trim(),
                ...lanePart(it.type),
            });
        }));

        // Apply as a diff so vis only re-renders what changed.
        const keep = new Set(desired.map((d) => d.id));
        const remove = items.getIds().filter((id) => !keep.has(id));
        if (remove.length) items.remove(remove);
        const changed = desired.filter((d) => {
            const cur = items.get(d.id);
            return !cur || cur.className !== d.className || cur.type !== d.type || cur.title !== d.title || cur.content !== d.content
                || cur.subgroup !== d.subgroup || cur.sgOrder !== d.sgOrder;
        });
        if (changed.length) items.update(changed);

        // Keep vis' own selection in line with ours.
        const want = selectedId
            ? [selectedId, ...[...keywordLanes.values()].map((slot) => 'k' + slot + '_' + selectedId)].filter((id) => items.get(id))
            : [];
        const have = timeline.getSelection();
        if (want.join() !== have.join()) timeline.setSelection(want);
    }

    /**
     * Stretch each band to the full height of its row. vis-timeline sizes background items from the
     * subgroup's stored height, but it resets that height on every redraw and only recomputes it when it
     * restacks, so a band would shrink to its label line. Runs after every redraw ('changed').
     */
    function fitBands() {
        const set = timeline && timeline.itemSet;
        if (!set || !set.items) return;
        items.get({ filter: (it) => it.type === 'background' && it.subgroup && /\bband\b/.test(it.className || '') }).forEach((b) => {
            const bi = set.items[b.id];
            const sg = bi && bi.parent && bi.parent.subgroups && bi.parent.subgroups[b.subgroup];
            if (!sg || !bi.dom || !bi.dom.box) return;
            let bottom = 0;
            sg.items.forEach((x) => {
                if (x !== bi && x.displayed && x.data.type !== 'background') bottom = Math.max(bottom, x.top + x.height);
            });
            if (bottom > sg.top) bi.dom.box.style.height = (bottom - sg.top + ITEM_MARGIN_V / 2) + 'px';
        });
    }

    let syncQueued = false;
    function scheduleSync() {
        if (syncQueued) return;
        syncQueued = true;
        requestAnimationFrame(() => { syncQueued = false; sync(); });
    }

    // ------------------------------------------------------------------ keyword lane

    function keywordItems(name) {
        const out = [];
        all.forEach((it) => { if (it.kw && it.kw.includes(name)) out.push(it); });
        return out;
    }

    /** Add a lane for a keyword (or just zoom to it if it is already shown). */
    function addKeyword(name) {
        if (!name) return;
        if (!keywordLanes.has(name)) {
            const slot = nextSlot++;
            keywordLanes.set(name, slot);
            // Newest lane on top, directly above the older keyword lanes.
            groups.add(Object.assign({ id: kwGroupId(slot), content: name, keyword: name, order: -1000 - slot, className: 'lane-keyword', visible: true }, LANE_GROUP_OPTS));
        }
        keywordsChanged();

        let s = Infinity, e = -Infinity;
        keywordItems(name).forEach((it) => {
            s = Math.min(s, time(it.start));
            e = Math.max(e, it.end ? time(it.end) : time(it.start));
        });
        if (s === Infinity) return;
        const pad = Math.max((e - s) * 0.06, 2 * YEAR);
        showWindow(s - pad, e + pad);
        const scroller = els.timeline.querySelector('.vis-panel.vis-left');
        if (scroller) scroller.scrollTop = 0; // new lanes go on top
    }

    function removeKeyword(name) {
        const slot = keywordLanes.get(name);
        if (slot == null) return;
        keywordLanes.delete(name);
        groups.remove(kwGroupId(slot));
        keywordsChanged();
    }

    /** Redraw, and bring the dropdown and the panel's keyword chips in line with the open lanes. */
    function keywordsChanged() {
        sync();
        els.keyword.value = '';
        els.keyword.querySelectorAll('option[value]:not([value=""])').forEach((o) => {
            const on = keywordLanes.has(o.value);
            o.textContent = (on ? '✓ ' : '') + o.dataset.label;
        });
        els.panelBody.querySelectorAll('.chip').forEach((c) => c.classList.toggle('is-active', keywordLanes.has(c.textContent)));
    }

    /** Keyword chip in the detail panel: toggles its lane; falls back to a text search if nothing on the timeline has it. */
    function toggleKeyword(name) {
        if (keywordLanes.has(name)) removeKeyword(name);
        else if (keywordItems(name).length) addKeyword(name);
        else searchFor(name);
    }

    function buildKeywordPicker() {
        const list = (meta && meta.keywords) || [];
        els.keyword.replaceChildren(
            el('option', { value: '' }, 'keyword'),
            ...list.map((k) => el('option', { value: k.name, 'data-label': k.name + ' (' + k.count + ')' }, k.name + ' (' + k.count + ')')));
        els.keyword.disabled = !list.length;
        // The dropdown adds lanes (choosing an open one removes it again) and then resets to "keyword".
        els.keyword.addEventListener('change', () => {
            const name = els.keyword.value;
            if (keywordLanes.has(name)) removeKeyword(name);
            else addKeyword(name);
        });
    }

    // ------------------------------------------------------------------ timeline

    function lifetimeWindow() {
        const a = meta && meta.author;
        if (!a || !a.start) return null;
        const start = time(a.start);
        const end = a.end ? time(a.end) : Date.now();
        const pad = (end - start) * 0.04;
        return { start: new Date(start - pad), end: new Date(end + pad) };
    }

    function dataBounds() {
        let min = Infinity, max = -Infinity;
        all.forEach((it) => {
            const s = time(it.start), e = it.end ? time(it.end) : s;
            if (s < min) min = s;
            if (e > max) max = e;
        });
        return { min, max };
    }

    async function init() {
        let data;
        try {
            data = await api('timeline.php');
        } catch (err) {
            showStatus('Could not load the timeline. Is the database running? (' + err.message + ')', true);
            return;
        }
        meta = data.meta || {};
        groups = new vis.DataSet(data.groups.map((g) => Object.assign({ visible: true }, LANE_GROUP_OPTS, g)));

        data.items.forEach((it) => all.set(it.id, Object.assign({ baseClass: it.className || '' }, it)));
        all.forEach((it) => {
            const pid = sameLaneParent(it.id);
            if (pid) {
                if (!children.has(pid)) children.set(pid, []);
                children.get(pid).push(it.id);
            }
        });

        if (meta.author) {
            const a = meta.author;
            const y = (d) => (d ? d.slice(0, 4) : '');
            els.subtitle.textContent = a.name + ' · ' + y(a.start) + '–' + y(a.end);
            document.title = 'Librescape · ' + a.name;
        } else {
            els.subtitle.textContent = 'Timeline';
        }

        const bounds = dataBounds();
        const win = lifetimeWindow() || { start: new Date(bounds.min), end: new Date(bounds.max) };

        timeline = new vis.Timeline(els.timeline, items, groups, {
            start: win.start,
            end: win.end,
            min: new Date(bounds.min - 15 * YEAR),
            max: new Date(bounds.max + 15 * YEAR),
            zoomMin: 7 * DAY,
            zoomMax: 200 * YEAR,
            orientation: { axis: 'top', item: 'top' },
            stack: true,
            groupOrder: 'order',
            // Stacking order: band labels first so they take the top row of their band.
            order: (a, b) => (b.isBandLabel ? 1 : 0) - (a.isBandLabel ? 1 : 0) || time(a.start) - time(b.start),
            height: '100%',
            verticalScroll: true,
            showCurrentTime: false,
            selectable: true,
            margin: { item: { horizontal: 6, vertical: ITEM_MARGIN_V }, axis: 8 },
            tooltip: { followMouse: true, overflowMethod: 'cap', delay: 150 },
            groupTemplate: (g) => g.keyword
                ? el('div', { class: 'lane-label' },
                    el('span', { class: 'lane-swatch' }),
                    el('span', { class: 'kw-name' }, '# ' + g.content),
                    el('button', {
                        type: 'button', class: 'lane-close', title: 'Remove keyword lane', 'aria-label': 'Remove keyword lane',
                        onclick: (ev) => { ev.stopPropagation(); setTimeout(() => removeKeyword(g.keyword)); },
                    }, '×'))
                : el('div', { class: 'lane-label' }, el('span', { class: 'lane-swatch' }), g.content),
        });

        timeline.on('click', onTimelineClick);
        timeline.on('changed', fitBands);
        timeline.on('rangechange', scheduleSync);
        timeline.on('rangechanged', scheduleSync);
        window.addEventListener('resize', scheduleSync);

        sync();
        buildLaneToggles();
        buildKeywordPicker();
        showStatus('');
        openFromHash();
        window.addEventListener('hashchange', openFromHash);
    }

    // Deep links like index.php#event-12 (used by the admin app's "Save & view in timeline").
    function openFromHash() {
        const m = /^#(event|period|publication|person|source)-(\d+)$/.exec(location.hash);
        if (m) openDetails(m[1], Number(m[2]), { focus: true });
    }

    function onTimelineClick(props) {
        const target = props.event && (props.event.target || (props.event.srcEvent && props.event.srcEvent.target));
        if (target && target.closest && target.closest('.lane-close')) return; // handled by the button itself
        let id = props.item;
        if (id && String(id).startsWith('bg')) id = null; // global shading is not clickable

        // Expanded bands: vis may or may not report them as the clicked item, so also hit-test by lane, time
        // and height: a band only covers its own rows, so a click on the events below it must not select it.
        if (!id && props.group != null && props.time && props.what !== 'axis') {
            const t = props.time.getTime();
            const ev = props.event;
            const y = ev && (ev.srcEvent ? ev.srcEvent.clientY : (ev.center ? ev.center.y : ev.clientY));
            const insideRow = (bandId) => {
                const box = timeline.itemSet && timeline.itemSet.items[bandId] && timeline.itemSet.items[bandId].dom
                    && timeline.itemSet.items[bandId].dom.box;
                if (!box || y == null) return true; // can't tell: fall back to lane + time
                const r = box.getBoundingClientRect();
                return y >= r.top && y <= r.bottom;
            };
            const hits = items.get({ filter: (it) => it.type === 'background' && /\bband\b/.test(it.className || '') && it.group === props.group
                                                 && time(it.start) <= t && t <= time(it.end) && insideRow(it.id) });
            hits.sort((a, b) => (time(a.end) - time(a.start)) - (time(b.end) - time(b.start)));
            if (hits.length) id = hits[0].id; // innermost band
        }

        if (!id) {
            if (props.what === 'background' || props.what === 'group-label' || props.what == null) closePanel();
            return;
        }
        let logical = String(id);
        if (/^[ql]\d+(_\d+)?$/.test(logical)) logical = 'p' + logical.slice(1).replace(/_\d+$/, '');   // expanded band (or its continuation) -> its period
        else if (/^k\d+_[epw]\d+$/.test(logical)) logical = logical.replace(/^k\d+_/, '');  // keyword-lane copy -> original
        const ref = parseItemId(logical);
        if (!ref) return;
        selectedId = logical;
        sync();
        openDetails(ref.type, ref.id, { focus: false });
    }

    function buildLaneToggles() {
        els.lanes.replaceChildren(...groups.get({ order: 'order' }).map((g) =>
            el('label', { class: 'lane-toggle ' + (g.className || '') },
                el('input', {
                    type: 'checkbox', checked: true,
                    onchange: (ev) => groups.update({ id: g.id, visible: ev.target.checked }),
                }),
                el('span', { class: 'lane-swatch' }),
                g.content)
        ));
    }

    /** Select an item and move it into view (opening its lane and parent periods if needed). */
    function focusItem(itemId) {
        const it = all.get(itemId);
        if (!it) return;
        if (it.group != null) {
            const g = groups.get(it.group);
            if (g && g.visible === false) {
                groups.update({ id: g.id, visible: true });
                const box = els.lanes.querySelector('.' + CSS.escape(g.className) + ' input');
                if (box) box.checked = true;
            }
        }
        selectedId = itemId; // forces its parent periods open
        sync();

        const s = time(it.start);
        if (it.end) {
            // Ranges: show the whole period with some context on both sides.
            const e = time(it.end);
            // Parents with sub-items get less padding, so they are wide enough to open up.
            const pad = children.has(itemId) ? (e - s) * 0.2 : Math.max((e - s) * 0.6, 3 * YEAR);
            showWindow(s - pad, e + pad);
        } else {
            // Points: keep the zoom level unless we are zoomed far out, then go to ~20 years.
            const w = timeline.getWindow();
            const span = Math.min(w.end - w.start, 20 * YEAR);
            showWindow(s - span / 2, s + span / 2);
        }
    }

    /** setWindow, stretched so the target stays in the part of the timeline not covered by the side panel. */
    function showWindow(start, end) {
        const covered = panelCoverage();
        if (covered > 0) end = start + (end - start) / (1 - covered);
        timeline.setWindow(new Date(start), new Date(end), { animation: ANIM });
    }

    /** Fraction (0..0.6) of the timeline width hidden behind the open side panel. */
    function panelCoverage() {
        if (els.panel.hidden || window.matchMedia('(max-width: 700px)').matches) return 0;
        const width = centerWidth();
        return width > 0 ? Math.min(0.6, (els.panel.offsetWidth + 24) / width) : 0;
    }

    function zoom(action) {
        if (!timeline) return;
        if (action === 'in') timeline.zoomIn(0.5, { animation: ANIM });
        if (action === 'out') timeline.zoomOut(0.5, { animation: ANIM });
        if (action === 'all') timeline.fit({ animation: ANIM });
        if (action === 'life') {
            const w = lifetimeWindow();
            w ? timeline.setWindow(w.start, w.end, { animation: ANIM }) : timeline.fit({ animation: ANIM });
        }
    }

    // ------------------------------------------------------------------ detail panel

    let detailsRequest = 0;

    async function openDetails(type, id, opts) {
        const req = ++detailsRequest;
        els.panel.hidden = false;
        requestAnimationFrame(() => els.panel.classList.add('is-open'));
        els.panelBody.replaceChildren(el('p', { class: 'muted' }, 'Loading…'));

        let d;
        try {
            d = await api('item.php?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id));
        } catch (err) {
            if (req === detailsRequest) els.panelBody.replaceChildren(el('p', { class: 'error' }, 'Could not load details.'));
            return;
        }
        if (req !== detailsRequest) return; // a newer request superseded this one

        renderDetails(d);

        if (opts && opts.focus) {
            if (d.itemId && all.has(d.itemId)) focusItem(d.itemId);
            else if (d.window) {
                selectedId = null;
                sync();
                const s = time(d.window.start), e = time(d.window.end);
                const pad = (e - s) * 0.04;
                showWindow(s - pad, e + pad);
            }
        }
    }

    function renderDetails(d) {
        const lane = laneClassFor(d);
        const section = (title, body) => body ? el('section', { class: 'panel-section' }, el('h3', null, title), body) : null;
        const refList = (list) => list && list.length ? el('ul', { class: 'list' }, list.map((r) =>
            el('li', null,
                el('button', { type: 'button', class: 'link', onclick: () => openDetails(r.type, r.id, { focus: true }) }, r.label),
                r.note ? el('span', { class: 'muted' }, ' · ' + r.note) : null))) : null;

        const persons = d.persons.length ? el('ul', { class: 'list' }, d.persons.map((p) =>
            el('li', null,
                el('button', { type: 'button', class: 'link', onclick: () => openDetails('person', p.id, { focus: true }) }, p.name),
                p.role ? el('span', { class: 'muted' }, ' · ' + p.role) : null))) : null;

        const keywords = d.keywords.map((k) =>
            el('button', {
                type: 'button', class: 'chip' + (keywordLanes.has(k) ? ' is-active' : ''),
                title: keywordLanes.has(k) ? 'Remove the “' + k + '” lane' : 'Show everything tagged “' + k + '” in its own lane',
                onclick: () => toggleKeyword(k),
            }, k));

        const hostOf = (url) => { try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return ''; } };
        const links = d.links.length ? el('ul', { class: 'list weblinks' }, d.links.map((l) => {
            const host = hostOf(l.url);
            return el('li', null,
                el('a', { href: l.url, target: '_blank', rel: 'noopener noreferrer', class: 'ext-link' }, l.label + ' ↗'),
                host && host !== l.label ? el('span', { class: 'muted' }, ' · ' + host) : null);
        })) : null;

        const isSource = d.type === 'source';
        const facts = d.facts && d.facts.length ? el('dl', { class: 'facts' }, d.facts.flatMap((f) =>
            [el('dt', null, f.label), el('dd', null, f.value)])) : null;

        // Each entry must cite a source; an empty list is flagged rather than hidden.
        const sources = isSource ? null : (d.sources && d.sources.length
            ? el('ul', { class: 'list sources' }, d.sources.map((s) =>
                el('li', null,
                    el('button', { type: 'button', class: 'source-code', title: s.kind + ' – show source details',
                                   onclick: () => openDetails('source', s.id, { focus: true }) }, s.code),
                    el('span', { class: 'source-citation' }, s.citation,
                        s.locator ? el('span', { class: 'muted' }, ' ' + s.locator) : null,
                        s.note ? el('span', { class: 'muted' }, ' – ' + s.note) : null))))
            : el('p', { class: 'missing-source' }, 'No source recorded for this entry.'));

        els.panelBody.replaceChildren(...[
            d.image ? renderImage(d.image, d.title) : null,
            el('p', { class: 'kind ' + lane }, d.kind),
            el('h2', null, d.title),
            d.date ? el('p', { class: 'date' }, d.date) : null,
            d.description ? el('p', { class: isSource ? 'description citation' : 'description' }, d.description) : null,
            keywords.length ? el('div', { class: 'chips' }, keywords) : null,
            facts,
            section('Contains', refList(d.children)),
            section(d.type === 'person' ? 'Relations' : 'People', persons),
            section(d.type === 'person' ? 'Works' : 'Related', refList(d.related)),
            isSource ? section('Cited by', refList(d.citedBy) || el('p', { class: 'muted' }, 'Not cited by any entry yet.')) : null,
            section('Links', links),
            section('Sources', sources),
            !d.itemId && d.type !== 'person' && !isSource ? el('p', { class: 'muted small' }, 'Not drawn on its own on the timeline.') : null,
        ].filter(Boolean));
        els.panelBody.scrollTop = 0;
    }

    /** Picture with the credit line its licence requires (author · licence · source). */
    function renderImage(img, title) {
        const ext = { target: '_blank', rel: 'noopener noreferrer' };
        const licence = img.licenseUrl
            ? el('a', Object.assign({ href: img.licenseUrl }, ext), img.license)
            : img.license;
        const figure = el('figure', { class: 'panel-figure' },
            el('img', {
                src: img.src, alt: title, loading: 'lazy', decoding: 'async',
                width: img.width, height: img.height,
                onload: (ev) => ev.target.classList.add('is-loaded'),
                onerror: (ev) => ev.target.closest('figure').remove(),
            }),
            el('figcaption', null,
                el('span', { class: 'credit-artist', title: img.artist }, img.artist), ' · ',
                licence, ' · ',
                el('a', Object.assign({ href: img.source }, ext), 'Wikimedia Commons')));
        return figure;
    }

    function laneClassFor(d) {
        const it = d.itemId && all.get(d.itemId);
        const g = it && groups.get(it.group);
        if (g) return g.className;
        return d.type === 'publication' ? 'lane-pubs' : '';
    }

    function closePanel() {
        detailsRequest++;
        els.panel.classList.remove('is-open');
        setTimeout(() => { if (!els.panel.classList.contains('is-open')) els.panel.hidden = true; }, 300);
        if (selectedId) { selectedId = null; sync(); }
    }

    // ------------------------------------------------------------------ search

    let results = [];
    let activeIndex = -1;
    let searchRequest = 0;

    const runSearch = debounce(async (term) => {
        const req = ++searchRequest;
        if (term.length < 2) { renderResults([], term); return; }
        try {
            const data = await api('search.php?q=' + encodeURIComponent(term));
            if (req === searchRequest) renderResults(data.results, term);
        } catch (err) {
            if (req === searchRequest) renderResults(null, term);
        }
    }, 250);

    function highlight(text, term) {
        const i = text.toLowerCase().indexOf(term.toLowerCase());
        if (!term || i < 0) return [text];
        return [text.slice(0, i), el('mark', null, text.slice(i, i + term.length)), text.slice(i + term.length)];
    }

    function renderResults(list, term) {
        results = list || [];
        activeIndex = -1;
        matchSet = new Set(results.map((r) => r.itemId).filter(Boolean));
        sync();

        if (!term || term.length < 2) { hideResults(); return; }

        const rows = list === null
            ? [el('li', { class: 'empty' }, 'Search failed.')]
            : results.length === 0
                ? [el('li', { class: 'empty' }, 'No matches for “' + term + '”')]
                : results.map((r, i) =>
                    el('li', {
                        role: 'option', id: 'sr-' + i, class: 'result type-' + r.type,
                        onmousedown: (ev) => { ev.preventDefault(); chooseResult(i); },
                        onmousemove: () => setActive(i),
                    },
                    el('span', { class: 'result-label' }, highlight(r.label, term)),
                    el('span', { class: 'result-meta' }, [r.sub, r.date].filter(Boolean).join(' · '))));

        els.results.replaceChildren(...rows);
        els.results.hidden = false;
        els.search.setAttribute('aria-expanded', 'true');
    }

    function hideResults() {
        els.results.hidden = true;
        els.search.setAttribute('aria-expanded', 'false');
        els.search.removeAttribute('aria-activedescendant');
    }

    function setActive(i) {
        activeIndex = i;
        els.results.querySelectorAll('.result').forEach((li, j) => li.classList.toggle('is-active', j === i));
        if (i >= 0) {
            els.search.setAttribute('aria-activedescendant', 'sr-' + i);
            const li = els.results.children[i];
            if (li) li.scrollIntoView({ block: 'nearest' });
        }
    }

    function chooseResult(i) {
        const r = results[i];
        if (!r) return;
        hideResults();
        openDetails(r.type, r.id, { focus: true });
    }

    function searchFor(term) {
        els.search.value = term;
        els.search.focus();
        runSearch(term);
    }

    els.search.addEventListener('input', () => runSearch(els.search.value.trim()));
    els.search.addEventListener('focus', () => { if (els.search.value.trim().length >= 2 && results.length) els.results.hidden = false; });
    els.search.addEventListener('blur', () => setTimeout(hideResults, 120));
    els.search.addEventListener('keydown', (ev) => {
        const n = results.length;
        if (ev.key === 'ArrowDown' && n) { ev.preventDefault(); els.results.hidden = false; setActive((activeIndex + 1) % n); }
        else if (ev.key === 'ArrowUp' && n) { ev.preventDefault(); setActive((activeIndex - 1 + n) % n); }
        else if (ev.key === 'Enter') { ev.preventDefault(); chooseResult(activeIndex >= 0 ? activeIndex : 0); }
        else if (ev.key === 'Escape') {
            if (!els.results.hidden) hideResults();
            else { els.search.value = ''; renderResults([], ''); }
        }
    });

    // ------------------------------------------------------------------ wiring

    document.querySelectorAll('[data-zoom]').forEach((b) => b.addEventListener('click', () => zoom(b.dataset.zoom)));
    $('#panel-close').addEventListener('click', closePanel);
    document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && document.activeElement !== els.search && !els.panel.hidden) closePanel();
        if (ev.key === '/' && document.activeElement !== els.search) { ev.preventDefault(); els.search.focus(); }
    });

    init();
})();
