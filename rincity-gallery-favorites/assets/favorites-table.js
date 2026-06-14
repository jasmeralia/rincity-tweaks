/* globals rincgf */
(function () {
    'use strict';

    const container = document.querySelector('.rin-favorites-table-container');
    if (!container) return;

    const cfg = window.rincgf || {};
    if (!cfg.restBase || !cfg.nonce) return;

    // -------------------------------------------------------------------------
    // State
    // -------------------------------------------------------------------------

    let allRows   = [];
    let sortCol   = 'favorite_added_at';
    let sortDir   = 'desc';
    let searchTerm = '';
    let editingId  = null; // gallery_id currently being edited

    const SORT_COLS = ['title', 'gallery_published_at', 'favorite_added_at', 'note_updated_at'];

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    loadFavorites();

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    async function loadFavorites() {
        try {
            const data = await apiFetch('GET', '/favorites');
            allRows = data.rows || [];
            render();
        } catch (err) {
            showError('Could not load favorites. Please refresh the page.');
        }
    }

    // -------------------------------------------------------------------------
    // Filtering & sorting (all in memory)
    // -------------------------------------------------------------------------

    function visibleRows() {
        let rows = allRows.slice();

        if (searchTerm) {
            const term = searchTerm.toLowerCase();
            rows = rows.filter(r =>
                r.title.toLowerCase().includes(term) ||
                (r.note || '').toLowerCase().includes(term)
            );
        }

        rows.sort((a, b) => {
            const av = (a[sortCol] || '').toLowerCase();
            const bv = (b[sortCol] || '').toLowerCase();
            if (av < bv) return sortDir === 'asc' ? -1 : 1;
            if (av > bv) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });

        return rows;
    }

    // -------------------------------------------------------------------------
    // Rendering — DOM only, no innerHTML
    // -------------------------------------------------------------------------

    function render() {
        container.textContent = '';

        // Search bar
        const searchWrap = el('div', { className: 'rincgf-search-wrap' });
        const searchInput = el('input', {
            type        : 'search',
            className   : 'rincgf-search',
            placeholder : 'Search by title or note…',
            value       : searchTerm,
        });
        searchInput.addEventListener('input', e => {
            searchTerm = e.target.value;
            editingId  = null;
            render();
        });
        searchWrap.appendChild(searchInput);
        container.appendChild(searchWrap);

        const rows = visibleRows();

        if (allRows.length === 0) {
            const p = el('p', { className: 'rincgf-empty' });
            p.textContent = 'You have no favorite galleries yet. Visit a gallery page to add one.';
            container.appendChild(p);
            return;
        }

        if (rows.length === 0) {
            const p = el('p', { className: 'rincgf-empty' });
            p.textContent = 'No favorites match your search.';
            container.appendChild(p);
            return;
        }

        const table  = el('table', { className: 'rincgf-table' });
        const thead  = document.createElement('thead');
        const hrow   = document.createElement('tr');

        renderTh(hrow, 'title',                'Gallery',        true);
        renderTh(hrow, 'gallery_published_at',  'Published',     true);
        renderTh(hrow, 'favorite_added_at',     'Favorited',     true);
        renderTh(hrow, 'note_updated_at',       'Note updated',  true);
        renderTh(hrow, null,                    'Note',          false);
        renderTh(hrow, null,                    'Actions',       false);

        thead.appendChild(hrow);
        table.appendChild(thead);

        const tbody = document.createElement('tbody');
        rows.forEach(row => tbody.appendChild(buildRow(row)));
        table.appendChild(tbody);

        container.appendChild(table);

        // Re-focus search after re-render so the user can keep typing.
        const newSearch = container.querySelector('.rincgf-search');
        if (newSearch && document.activeElement && document.activeElement.className === 'rincgf-search') {
            newSearch.focus();
        }
    }

    function renderTh(parent, col, label, sortable) {
        const th = document.createElement('th');
        if (sortable && col) {
            const btn = el('button', { type: 'button', className: 'rincgf-sort-btn' });
            btn.textContent = label;
            if (sortCol === col) {
                btn.appendChild(document.createTextNode(' ' + (sortDir === 'asc' ? '▲' : '▼')));
            }
            btn.addEventListener('click', () => {
                if (sortCol === col) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    sortCol = col;
                    sortDir = 'desc';
                }
                editingId = null;
                render();
            });
            th.appendChild(btn);
        } else {
            th.textContent = label;
        }
        parent.appendChild(th);
    }

    function buildRow(row) {
        const tr = document.createElement('tr');
        if (!row.available) {
            tr.className = 'rincgf-row-unavailable';
        }

        // Gallery title + thumbnail
        const tdTitle = document.createElement('td');
        tdTitle.className = 'rincgf-title-cell';
        if (row.available && row.url) {
            const a = el('a', { href: row.url });
            a.textContent = row.title;
            tdTitle.appendChild(a);
        } else {
            tdTitle.appendChild(document.createTextNode(row.title));
        }
        if (row.cover_image_url && row.available && row.url) {
            const imgLink = el('a', { href: row.url, className: 'rincgf-thumb-link' });
            const img     = document.createElement('img');
            img.src       = row.cover_image_url;
            img.alt       = row.title;
            img.width     = 144;
            img.height    = 180;
            img.style.cssText = 'width:144px;height:180px;object-fit:cover;object-position:center;display:block;';
            imgLink.appendChild(img);
            tdTitle.appendChild(imgLink);
        }
        tr.appendChild(tdTitle);

        // Published date
        tr.appendChild(dateCell(row.gallery_published_at));

        // Favorited date
        tr.appendChild(dateCell(row.favorite_added_at));

        // Note updated date
        tr.appendChild(dateCell(row.note_updated_at));

        // Note / inline editor
        const tdNote = document.createElement('td');
        tdNote.className = 'rincgf-note-cell';

        if (editingId === row.gallery_id) {
            const textarea = el('textarea', {
                className : 'rincgf-inline-note',
                maxLength : 2000,
                rows      : 3,
            });
            textarea.value = row.note || '';

            const btnSave = el('button', { type: 'button', className: 'rincgf-btn rincgf-btn-save' });
            btnSave.textContent = 'Save';
            btnSave.addEventListener('click', () => handleSaveNote(row.gallery_id, textarea.value));

            const btnCancel = el('button', { type: 'button', className: 'rincgf-btn rincgf-btn-cancel' });
            btnCancel.textContent = 'Cancel';
            btnCancel.addEventListener('click', () => { editingId = null; render(); });

            tdNote.appendChild(textarea);
            tdNote.appendChild(document.createTextNode(' '));
            tdNote.appendChild(btnSave);
            tdNote.appendChild(document.createTextNode(' '));
            tdNote.appendChild(btnCancel);
        } else {
            const noteSpan = el('span', { className: 'rincgf-note-preview' });
            noteSpan.textContent = row.note || '';
            tdNote.appendChild(noteSpan);
        }
        tr.appendChild(tdNote);

        // Actions
        const tdActions = document.createElement('td');
        tdActions.className = 'rincgf-actions-cell';

        const btnEdit = el('button', { type: 'button', className: 'rincgf-btn rincgf-btn-edit' });
        btnEdit.textContent = 'Edit note';
        btnEdit.addEventListener('click', () => { editingId = row.gallery_id; render(); });
        tdActions.appendChild(btnEdit);

        tdActions.appendChild(document.createTextNode(' '));

        const btnRemove = el('button', { type: 'button', className: 'rincgf-btn rincgf-btn-remove' });
        btnRemove.textContent = 'Remove';
        btnRemove.addEventListener('click', () => handleRemove(row.gallery_id, row.title));
        tdActions.appendChild(btnRemove);

        tr.appendChild(tdActions);

        return tr;
    }

    function dateCell(value) {
        const td = document.createElement('td');
        td.className = 'rincgf-date-cell';
        td.textContent = value ? formatDate(value) : '—';
        return td;
    }

    // -------------------------------------------------------------------------
    // Action handlers
    // -------------------------------------------------------------------------

    async function handleSaveNote(galleryId, note) {
        try {
            const data = await apiFetch('PATCH', '/note', { gallery_id: galleryId, note });
            const saved = data.note !== undefined ? data.note : note;
            allRows = allRows.map(r =>
                r.gallery_id === galleryId
                    ? Object.assign({}, r, { note: saved, note_updated_at: data.note_updated_at || r.note_updated_at })
                    : r
            );
            editingId = null;
            render();
        } catch (err) {
            showError('Could not save note. Please try again.');
        }
    }

    async function handleRemove(galleryId, title) {
        if (!confirm('Remove "' + title + '" from your favorites?')) return;
        try {
            await apiFetch('DELETE', '/favorite', null, { gallery_id: galleryId });
            allRows = allRows.filter(r => r.gallery_id !== galleryId);
            if (editingId === galleryId) editingId = null;
            render();
        } catch (err) {
            showError('Could not remove favorite. Please try again.');
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function showError(msg) {
        container.textContent = '';
        const p = el('p', { className: 'rincgf-error' });
        p.textContent = msg;
        container.appendChild(p);
    }

    function formatDate(iso) {
        if (!iso) return '—';
        // iso is a MySQL DATETIME string: "2026-06-14 10:30:00"
        const d = new Date(iso.replace(' ', 'T') + 'Z');
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    async function apiFetch(method, endpoint, body, queryParams) {
        let url = cfg.restBase.replace(/\/$/, '') + endpoint;

        if (queryParams) {
            const qs = Object.keys(queryParams)
                .map(k => encodeURIComponent(k) + '=' + encodeURIComponent(queryParams[k]))
                .join('&');
            url += '?' + qs;
        }

        const opts = {
            method,
            headers: { 'X-WP-Nonce': cfg.nonce },
        };

        if (body && method !== 'GET' && method !== 'DELETE') {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }

        const res = await fetch(url, opts);
        if (!res.ok) {
            let msg = 'HTTP ' + res.status;
            try {
                const j = await res.json();
                if (j.message) msg = j.message;
            } catch (_) {}
            throw new Error(msg);
        }
        return res.json();
    }

    function el(tag, props) {
        const node = document.createElement(tag);
        if (props) {
            Object.keys(props).forEach(k => { node[k] = props[k]; });
        }
        return node;
    }
})();
