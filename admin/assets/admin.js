/* Librescape admin – widgets on top of the server-rendered forms. No framework.
 *
 *  .combo            search-as-you-type dropdown over existing entries (hidden input holds the id)
 *  .tags             keyword chips with autocomplete
 *  .rows             repeating rows (links, sources, relations) with add / remove / move
 *  .smart-date       live preview of how a typed date is understood
 *  select[data-new-option]   "+ New …" option opens a quick-create dialog
 *  recently used             last choices per kind come first in dropdowns and selects (select[data-recent])
 *  quick-create dialogs      <template id="qc-…"> → <dialog>, posted to api/quick_create.php
 *  form[data-dirty-guard]    warns before leaving with unsaved changes; Ctrl+S saves
 *
 * Everything uses event delegation, so rows and dialogs added later work without extra wiring.
 */
'use strict';

(function () {
    const BASE = document.querySelector('meta[name="admin-base"]')?.content || './';
    const CSRF = document.querySelector('meta[name="csrf"]')?.content || '';
    let uid = 0;

    // ------------------------------------------------------------------ helpers

    async function getJson(path) {
        const res = await fetch(BASE + path, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        if (res.status === 401) throw new Error('Your session has expired. Please log in again (in a new tab, to keep this form).');
        if (!res.ok) throw new Error('Request failed (' + res.status + ')');
        return res.json();
    }

    async function postJson(path, data) {
        const res = await fetch(BASE + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(data),
        });
        let body = null;
        try { body = await res.json(); } catch (e) { /* not JSON */ }
        if (res.status === 401) throw new Error('Your session has expired. Please log in again (in a new tab, to keep this form).');
        if (res.status === 422 && body && body.errors) return { errors: body.errors };
        if (!res.ok) throw new Error((body && body.error) || 'Request failed (' + res.status + ')');
        return body;
    }

    function debounce(fn, ms) {
        let t;
        return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
    }

    function el(tag, attrs, ...kids) {
        const e = document.createElement(tag);
        for (const [k, v] of Object.entries(attrs || {})) {
            if (v === null || v === undefined || v === false) continue;
            if (k === 'class') e.className = v;
            else if (k === 'text') e.textContent = v;
            else e.setAttribute(k, v === true ? '' : v);
        }
        for (const kid of kids) if (kid !== null && kid !== undefined) e.append(kid);
        return e;
    }

    /** Form fields → nested object, understanding PHP-style names like sources[0][source_id]. Skips disabled fields. */
    function formToObject(form) {
        const out = {};
        for (const field of form.elements) {
            if (!field.name || field.disabled) continue;
            if ((field.type === 'radio' || field.type === 'checkbox') && !field.checked) continue;
            const path = field.name.replace(/\]/g, '').split('[');
            let node = out;
            path.forEach((key, i) => {
                const last = i === path.length - 1;
                if (key === '') { // name[] → array
                    if (!Array.isArray(node.__list)) node.__list = [];
                    if (last) node.__list.push(field.value);
                    return;
                }
                if (last) node[key] = field.value;
                else node = node[key] = node[key] || {};
            });
        }
        return JSON.parse(JSON.stringify(out, (k, v) => (v && v.__list ? v.__list : v)));
    }

    // ------------------------------------------------------------------ recently used choices
    // Remembered per browser (localStorage), per kind: the last RECENT_MAX picks, most recent first.
    // They are only a shortcut: if storage is blocked, the dropdowns simply work as before.

    const RECENT_MAX = 3;

    function recentGet(key) {
        try {
            const v = JSON.parse(localStorage.getItem('librescape.recent.' + key) || '[]');
            return Array.isArray(v) ? v : [];
        } catch (e) {
            return [];
        }
    }

    /** Puts `entry` first in the list for `key`; `same` tells which stored entries are the same choice. */
    function recentPush(key, entry, same) {
        const list = [entry, ...recentGet(key).filter((x) => !same(x, entry))].slice(0, RECENT_MAX);
        try { localStorage.setItem('librescape.recent.' + key, JSON.stringify(list)); } catch (e) { /* storage blocked */ }
    }

    /** Selects with data-recent: the recently used options move up, right after the empty and "+ New …" options. */
    function orderRecentSelects(root) {
        root.querySelectorAll('select[data-recent]').forEach((sel) => {
            let anchor = null;
            for (const o of sel.options) {
                if (o.value === '' || o.value === '__new') anchor = o;
                else break;
            }
            const values = recentGet('select.' + sel.dataset.recent).slice().reverse(); // oldest first, each goes to the top
            for (const v of values) {
                const opt = [...sel.options].find((o) => o.value === v);
                if (!opt || opt === anchor) continue;
                if (anchor) anchor.after(opt);
                else sel.prepend(opt);
            }
        });
    }

    function rememberSelect(sel) {
        if (!sel.dataset.recent || sel.value === '' || sel.value === '__new') return;
        recentPush('select.' + sel.dataset.recent, sel.value, (a, b) => a === b);
    }

    document.addEventListener('change', (e) => {
        const sel = e.target.closest('select[data-recent]');
        if (sel) rememberSelect(sel);
    });

    // ------------------------------------------------------------------ confirm buttons

    document.addEventListener('click', (e) => {
        const c = e.target.closest('[data-confirm]');
        if (c && !window.confirm(c.dataset.confirm)) e.preventDefault();
    });

    // ------------------------------------------------------------------ combobox

    const comboState = new WeakMap();

    function state(combo) {
        let s = comboState.get(combo);
        if (!s) {
            s = { items: [], active: -1, req: 0 };
            comboState.set(combo, s);
        }
        return s;
    }

    function parts(combo) {
        return {
            hidden: combo.querySelector(':scope > input[type=hidden]'),
            input: combo.querySelector('.combo-input'),
            list: combo.querySelector('.combo-list'),
            clear: combo.querySelector('.combo-clear'),
        };
    }

    const isTagEntry = (combo) => combo.classList.contains('tag-entry');

    async function search(combo) {
        const s = state(combo);
        const { input } = parts(combo);
        const q = input.value.trim();
        const req = ++s.req;
        let results = [];
        try {
            const data = await getJson('api/lookup.php?type=' + encodeURIComponent(combo.dataset.type)
                + '&q=' + encodeURIComponent(q) + (combo.dataset.exclude ? '&exclude=' + combo.dataset.exclude : ''));
            results = data.results || [];
        } catch (err) {
            results = [];
            s.error = err.message;
        }
        if (req !== s.req) return; // a newer search is on its way
        if (isTagEntry(combo)) {
            // Don't suggest keywords the entry already has.
            const taken = new Set([...combo.closest('.tags').querySelectorAll('.chip input')].map((i) => i.value));
            results = results.filter((r) => !taken.has(r.label));
        }
        let items = results.map((r) => ({ kind: 'pick', ...r }));
        // Without a search text, the recently picked entries of this kind come first (right under "+ Create new…").
        if (q === '' && !isTagEntry(combo)) {
            const recent = recentGet('combo.' + combo.dataset.type)
                .filter((r) => String(r.id) !== String(combo.dataset.exclude || ''));
            const ids = new Set(recent.map((r) => String(r.id)));
            items = [...recent.map((r) => ({ kind: 'pick', recent: true, ...r })), ...items.filter((it) => !ids.has(String(it.id)))];
        }
        // "+ Create …" comes first, so it is always in view; the first match stays highlighted (see render).
        if (q !== '') {
            if (combo.dataset.create) {
                items.unshift({ kind: 'create', label: '+ Create “' + q + '”…', text: q });
            }
            if (isTagEntry(combo) && !results.some((r) => r.label.toLowerCase() === q.toLowerCase())) {
                items.push({ kind: 'newtag', label: '+ New keyword “' + q.toLowerCase() + '”', text: q });
            }
        } else if (combo.dataset.create) {
            items.unshift({ kind: 'create', label: '+ Create new…', text: '' });
        }
        render(combo, items);
    }

    const searchSoon = debounce(search, 160);

    function render(combo, items) {
        const s = state(combo);
        const { input, list } = parts(combo);
        s.items = items;
        // Enter picks the best match, not "+ Create …" at the top (unless there is no match).
        const firstMatch = items.findIndex((it) => it.kind !== 'create');
        s.active = firstMatch >= 0 ? firstMatch : (items.length ? 0 : -1);
        list.replaceChildren(...items.map((it, i) => el('li', {
            role: 'option', id: 'cb' + comboId(combo) + '-' + i, class: 'combo-item ' + it.kind + (it.recent ? ' recent' : ''),
            title: it.recent ? 'Recently used' : null,
            'aria-selected': i === s.active ? 'true' : 'false', 'data-i': i,
        }, el('span', { class: 'ci-label', text: it.label }), it.sub ? el('span', { class: 'ci-sub', text: it.sub }) : null)));
        if (!items.length) {
            list.append(el('li', { class: 'combo-empty', text: s.error || 'Nothing found' }));
        }
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
        input.setAttribute('aria-activedescendant', s.active >= 0 ? 'cb' + comboId(combo) + '-' + s.active : '');
    }

    function comboId(combo) {
        if (!combo.dataset.cid) combo.dataset.cid = String(++uid);
        return combo.dataset.cid;
    }

    function setActive(combo, i) {
        const s = state(combo);
        const { input, list } = parts(combo);
        s.active = i;
        list.querySelectorAll('.combo-item').forEach((li, j) => li.setAttribute('aria-selected', j === i ? 'true' : 'false'));
        const li = list.querySelector('[data-i="' + i + '"]');
        if (li) li.scrollIntoView({ block: 'nearest' });
        input.setAttribute('aria-activedescendant', li ? li.id : '');
    }

    function close(combo) {
        const { input, list } = parts(combo);
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
    }

    function pick(combo, item) {
        if (isTagEntry(combo)) {
            addTag(combo.closest('.tags'), item.label);
            parts(combo).input.value = '';
            search(combo); // keep the suggestions open for the next keyword
            return;
        }
        const { hidden, input, clear } = parts(combo);
        recentPush('combo.' + combo.dataset.type, { id: item.id, label: item.label, sub: item.sub || '' }, (a, b) => String(a.id) === String(b.id));
        hidden.value = item.id;
        input.value = item.label;
        input.classList.remove('unresolved');
        input.removeAttribute('aria-invalid');
        if (clear) clear.hidden = false;
        close(combo);
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function choose(combo, i) {
        const it = state(combo).items[i];
        if (!it) return;
        if (it.kind === 'pick') pick(combo, it);
        else if (it.kind === 'newtag') pick(combo, { label: it.text.toLowerCase() });
        else if (it.kind === 'create') {
            close(combo);
            openQuickCreate(combo.dataset.create, it.text, (res) => pick(combo, res), parts(combo).input);
        }
    }

    document.addEventListener('input', (e) => {
        const input = e.target.closest('.combo-input');
        if (!input) return;
        const combo = input.closest('.combo');
        const { hidden, clear } = parts(combo);
        if (hidden && hidden.value) { // typing changes the choice
            hidden.value = '';
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (clear) clear.hidden = input.value === '';
        searchSoon(combo);
    });

    document.addEventListener('focusin', (e) => {
        const input = e.target.closest('.combo-input');
        if (!input) return;
        const combo = input.closest('.combo');
        const { hidden } = parts(combo);
        if (!hidden || !hidden.value) search(combo); // show suggestions straight away
    });

    document.addEventListener('focusout', (e) => {
        const combo = e.target.closest('.combo');
        if (!combo) return;
        setTimeout(() => {
            if (combo.contains(document.activeElement)) return;
            close(combo);
            const { hidden, input } = parts(combo);
            if (isTagEntry(combo)) return;
            // Text without a picked entry would silently be lost on save: flag it.
            const unresolved = hidden && !hidden.value && input.value.trim() !== '';
            input.classList.toggle('unresolved', unresolved);
            if (unresolved) {
                input.setAttribute('aria-invalid', 'true');
                input.title = 'Pick an entry from the list, create a new one, or clear the field.';
            } else {
                input.removeAttribute('title');
            }
        }, 120);
    });

    document.addEventListener('keydown', (e) => {
        const input = e.target.closest('.combo-input');
        if (!input) return;
        const combo = input.closest('.combo');
        const s = state(combo);
        const { list } = parts(combo);
        const open = !list.hidden;
        const n = s.items.length;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!open) search(combo);
            else if (n) setActive(combo, (s.active + 1) % n);
        } else if (e.key === 'ArrowUp' && open && n) {
            e.preventDefault();
            setActive(combo, (s.active - 1 + n) % n);
        } else if (e.key === 'Enter') {
            if (isTagEntry(combo)) {
                e.preventDefault();
                if (open && s.active >= 0) choose(combo, s.active);
                else if (input.value.trim()) pick(combo, { label: input.value.trim().toLowerCase() });
            } else if (open && s.active >= 0) {
                e.preventDefault();
                choose(combo, s.active);
            }
        } else if (e.key === ',' && isTagEntry(combo)) {
            e.preventDefault();
            if (input.value.trim()) pick(combo, { label: input.value.trim().toLowerCase() });
        } else if (e.key === 'Backspace' && isTagEntry(combo) && input.value === '') {
            const chips = combo.closest('.tags').querySelectorAll('.chip');
            if (chips.length) { chips[chips.length - 1].remove(); markDirty(input); }
        } else if (e.key === 'Escape' && open) {
            e.preventDefault();
            e.stopPropagation(); // don't close a surrounding dialog
            close(combo);
        }
    });

    // Clicking into a search field that already has the focus opens the suggestions again.
    document.addEventListener('click', (e) => {
        const input = e.target.closest('.combo-input');
        if (!input) return;
        const combo = input.closest('.combo');
        const { hidden, list } = parts(combo);
        if (list.hidden && (!hidden || !hidden.value)) search(combo);
    });

    document.addEventListener('mousedown', (e) => {
        const li = e.target.closest('.combo-item');
        if (!li) return;
        e.preventDefault(); // keep focus in the input
        choose(li.closest('.combo'), Number(li.dataset.i));
    });

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.combo-clear');
        if (!btn) return;
        const combo = btn.closest('.combo');
        const { hidden, input } = parts(combo);
        hidden.value = '';
        input.value = '';
        input.classList.remove('unresolved');
        input.removeAttribute('aria-invalid');
        btn.hidden = true;
        hidden.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    });

    // ------------------------------------------------------------------ keyword chips

    function addTag(tags, name) {
        name = name.trim().toLowerCase().replace(/\s+/g, ' ');
        if (!name) return;
        const exists = [...tags.querySelectorAll('.chip input')].some((i) => i.value === name);
        if (exists) return;
        const chip = el('span', { class: 'chip' }, name,
            el('input', { type: 'hidden', name: tags.dataset.name, value: name }),
            el('button', { type: 'button', class: 'chip-remove', 'aria-label': 'Remove keyword ' + name, text: '×' }));
        tags.querySelector('.tag-entry').before(chip);
        markDirty(tags);
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.chip-remove');
        if (!btn) return;
        const tags = btn.closest('.tags');
        btn.closest('.chip').remove();
        markDirty(tags);
        tags.querySelector('.tag-input')?.focus();
    });

    // ------------------------------------------------------------------ repeating rows

    document.addEventListener('click', (e) => {
        const add = e.target.closest('.add-row');
        if (add) {
            const block = add.closest('.rows-block');
            const tpl = block.querySelector(':scope > .row-template');
            const html = tpl.innerHTML.replace(/__i__/g, 'n' + Date.now().toString(36) + (++uid));
            const holder = document.createElement('div');
            holder.innerHTML = html;
            const row = holder.firstElementChild;
            block.querySelector(':scope > .rows').append(row);
            orderRecentSelects(row);
            row.querySelector('input:not([type=hidden]), select')?.focus();
            markDirty(block);
            return;
        }
        const btn = e.target.closest('.row-remove, .row-up, .row-down');
        if (!btn) return;
        const row = btn.closest('.row');
        const rows = row.parentElement;
        if (btn.classList.contains('row-remove')) {
            const next = row.nextElementSibling || row.previousElementSibling;
            row.remove();
            (next?.querySelector('input:not([type=hidden])') || rows.closest('.rows-block')?.querySelector('.add-row'))?.focus();
        } else if (btn.classList.contains('row-up') && row.previousElementSibling) {
            row.previousElementSibling.before(row);
            btn.focus();
        } else if (btn.classList.contains('row-down') && row.nextElementSibling) {
            row.nextElementSibling.after(row);
            btn.focus();
        }
        markDirty(rows);
    });

    // A pasted Wikipedia address sets the link type.
    document.addEventListener('change', (e) => {
        const url = e.target.closest('.link-url');
        if (!url) return;
        const type = url.closest('.row').querySelector('.link-type');
        if (/^https?:\/\/[a-z-]+\.(m\.)?wikipedia\.org\//i.test(url.value) && type) type.value = 'wikipedia';
    });

    // ------------------------------------------------------------------ smart dates

    // One timer per field, so several date fields can update at the same time.
    const dateTimers = new WeakMap();
    const datePreview = (input) => {
        clearTimeout(dateTimers.get(input));
        dateTimers.set(input, setTimeout(() => updateDatePreview(input), 250));
    };

    const updateDatePreview = async (input) => {
        const out = input.parentElement.querySelector('.date-preview');
        if (!out) return;
        const q = input.value.trim();
        if (!q) { out.textContent = ''; out.className = 'date-preview'; return; }
        try {
            const d = await getJson('api/date.php?q=' + encodeURIComponent(q));
            if (input.value.trim() !== q) return;
            out.textContent = d.ok ? '→ ' + d.label + ' · ' + d.precisionLabel : 'Not understood – try 21 Sep 1937, Sep 1937, Summer 1937, 1937, 1930s or c. 1930';
            out.className = 'date-preview ' + (d.ok ? 'ok' : 'bad');
        } catch (err) {
            out.textContent = '';
        }
    };

    document.addEventListener('input', (e) => {
        const input = e.target.closest('.smart-date');
        if (input) datePreview(input);
    });

    function initDates(root) {
        root.querySelectorAll('.smart-date').forEach((i) => { if (i.value.trim()) datePreview(i); });
    }

    // The date-format box opens on mouse-over (CSS); a click keeps it open (touch screens), Escape closes it.
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.info-btn');
        document.querySelectorAll('.date-field.help-open').forEach((f) => {
            if (!btn || f !== btn.closest('.date-field')) f.classList.remove('help-open');
        });
        if (btn) btn.closest('.date-field').classList.toggle('help-open');
    });
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const open = document.querySelector('.date-field.help-open');
        if (open) {
            open.classList.remove('help-open');
            e.preventDefault();
            e.stopPropagation(); // don't close a surrounding dialog
        }
    }, true);

    // ------------------------------------------------------------------ selects with "+ New …"

    document.addEventListener('focusin', (e) => {
        const sel = e.target.closest('select[data-new-option]');
        if (sel && sel.value !== '__new') sel.dataset.prev = sel.value;
    });

    document.addEventListener('change', (e) => {
        const sel = e.target.closest('select[data-new-option]');
        if (!sel || sel.value !== '__new') return;
        const prev = sel.dataset.prev || '';
        sel.value = prev;
        openQuickCreate(sel.dataset.newOption, '', (res) => {
            const opts = res.options || [{ value: String(res.id), label: res.label }];
            // Add the new choice(s) to every select of the same kind on the page (e.g. all relation rows and the row template).
            // ("+ New …" is near the top of the list, so new choices are appended at the end.)
            document.querySelectorAll('select[data-new-option="' + sel.dataset.newOption + '"]').forEach((s) => {
                for (const o of opts) s.append(el('option', { value: o.value, text: o.label }));
            });
            document.querySelectorAll('template.row-template').forEach((tpl) => {
                tpl.content.querySelectorAll('select[data-new-option="' + sel.dataset.newOption + '"]').forEach((s) => {
                    for (const o of opts) s.append(el('option', { value: o.value, text: o.label }));
                });
            });
            sel.value = opts[0].value;
            sel.dataset.prev = sel.value;
            rememberSelect(sel);
            markDirty(sel);
        }, sel);
    });

    // ------------------------------------------------------------------ quick-create dialogs

    function openQuickCreate(key, text, onDone, returnFocus) {
        const tpl = document.getElementById('qc-' + key);
        if (!tpl) return;
        const dialog = tpl.content.firstElementChild.cloneNode(true);
        // Unique ids, so labels keep pointing at their own fields while several dialogs are open.
        const prefix = 'qc' + (++uid) + '-';
        dialog.querySelectorAll('[id]').forEach((n) => { n.id = prefix + n.id; });
        dialog.querySelectorAll('label[for], output[for]').forEach((n) => n.setAttribute('for', prefix + n.getAttribute('for')));
        dialog.querySelectorAll('[aria-labelledby]').forEach((n) => n.setAttribute('aria-labelledby', prefix + n.getAttribute('aria-labelledby')));
        dialog.querySelectorAll('.info-btn[aria-describedby]').forEach((n) => n.setAttribute('aria-describedby', prefix + n.getAttribute('aria-describedby')));
        if (dialog.hasAttribute('aria-labelledby')) dialog.setAttribute('aria-labelledby', prefix + dialog.getAttribute('aria-labelledby'));
        document.body.append(dialog);

        const form = dialog.querySelector('form');
        prefill(form, text);
        if (form.dataset.qcType === 'source') initSourceForm(form);

        dialog.addEventListener('close', () => {
            dialog.remove();
            returnFocus?.focus();
        });
        form.querySelector('.qc-cancel').addEventListener('click', () => dialog.close());
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            const unresolved = form.querySelector('.combo-input.unresolved');
            if (unresolved) { unresolved.focus(); return; }
            const btn = form.querySelector('button[type=submit]');
            btn.disabled = true;
            try {
                const res = await postJson('api/quick_create.php', { type: form.dataset.qcType, fields: formToObject(form) });
                if (res.errors) {
                    showDialogErrors(form, res.errors);
                    return;
                }
                dialog.close();
                onDone(res);
            } catch (err) {
                showDialogErrors(form, { _: err.message });
            } finally {
                btn.disabled = false;
            }
        });
        dialog.showModal();
        initDates(dialog);
        orderRecentSelects(dialog);
        (form.querySelector('[aria-invalid=true], input[value=""]:not([type=hidden]):not([type=radio])') || form.querySelector('input:not([type=hidden]):not([type=radio])'))?.focus();
    }

    function prefill(form, text) {
        text = (text || '').trim();
        const target = form.dataset.prefill;
        if (target === 'name' && form.dataset.qcType === 'person') {
            // "John Ronald Tolkien" → first names + surname
            const words = text.split(/\s+/).filter(Boolean);
            if (words.length) {
                form.elements.surname.value = words.pop();
                form.elements.first_name.value = words.join(' ');
            }
        } else if (target && form.elements[target] && text) {
            form.elements[target].value = text;
        }
        // e.g. the writing period of a work: "Writing The Hobbit"
        form.querySelectorAll('[data-default-from]').forEach((f) => {
            const src = document.querySelector('.entry-form [name="' + f.dataset.defaultFrom + '"]');
            if (!f.value && src && src.value.trim()) f.value = (f.dataset.defaultPrefix || '') + src.value.trim();
        });
    }

    function showDialogErrors(form, errors) {
        const box = form.querySelector('.qc-errors');
        form.querySelectorAll('[aria-invalid=true]').forEach((f) => f.removeAttribute('aria-invalid'));
        const items = [];
        for (const [field, msg] of Object.entries(errors)) {
            items.push(el('li', { text: msg }));
            const f = [...form.elements].find((x) => x.name === field && !x.disabled)
                || form.querySelector('[name^="' + field + '"]')?.closest('.row')?.querySelector('.combo-input');
            f?.setAttribute('aria-invalid', 'true');
        }
        box.replaceChildren(el('ul', {}, ...items));
        box.hidden = false;
        form.querySelector('[aria-invalid=true]')?.focus();
    }

    /** The source dialog: fields depend on the kind of source; a Wikipedia address fills in title and language. */
    function initSourceForm(form) {
        const update = () => {
            const type = form.querySelector('input[name=source_type]:checked')?.value || 'wikipedia';
            form.querySelectorAll('[data-for]').forEach((block) => {
                const on = block.dataset.for.split(' ').includes(type);
                block.hidden = !on;
                block.querySelectorAll('input, select, textarea').forEach((f) => { f.disabled = !on; });
            });
            const title = form.elements.title;
            const label = title && form.querySelector('label[for="' + title.id + '"]');
            if (label) label.firstChild.textContent = type === 'newspaper' ? 'Headline ' : 'Title ';
        };
        form.addEventListener('change', (e) => { if (e.target.name === 'source_type') update(); });
        form.addEventListener('input', (e) => {
            if (!e.target.classList.contains('wiki-url')) return;
            const m = /^https?:\/\/([a-z]{2,3})\.(?:m\.)?wikipedia\.org\/wiki\/([^?#]+)/i.exec(e.target.value.trim());
            if (!m) return;
            let title = m[2];
            try { title = decodeURIComponent(title); } catch (err) { /* keep raw */ }
            form.elements.title.value = title.replace(/_/g, ' ');
            const lang = [...form.elements].find((f) => f.name === 'language' && !f.disabled);
            if (lang) lang.value = m[1].toLowerCase();
        });
        update();
    }

    // ------------------------------------------------------------------ unsaved changes, Ctrl+S

    let dirty = false;

    function markDirty(node) {
        const form = node && node.closest ? node.closest('form[data-dirty-guard]') : null;
        if (!form) return;
        dirty = true;
        const note = form.querySelector('.dirty-note');
        if (note) note.hidden = false;
    }

    document.addEventListener('input', (e) => { if (!e.target.closest('dialog')) markDirty(e.target); });
    document.addEventListener('change', (e) => { if (!e.target.closest('dialog')) markDirty(e.target); });

    window.addEventListener('beforeunload', (e) => {
        if (dirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!form.matches('form[data-dirty-guard]')) return;
        // Text typed into a search field but never picked would be lost: stop and point at it.
        const unresolved = form.querySelector('.combo-input.unresolved');
        if (unresolved) {
            e.preventDefault();
            unresolved.focus();
            unresolved.scrollIntoView({ block: 'center' });
            return;
        }
        dirty = false;
    });

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            const form = document.querySelector('form[data-dirty-guard]');
            if (!form || document.querySelector('dialog[open]')) return;
            e.preventDefault();
            form.requestSubmit(form.querySelector('button[name=after][value=stay]'));
        }
    });

    // ------------------------------------------------------------------ start

    initDates(document);
    orderRecentSelects(document);
    // Jump to the first field with an error after a failed save.
    document.querySelector('.entry-form [aria-invalid=true]')?.focus();
})();
