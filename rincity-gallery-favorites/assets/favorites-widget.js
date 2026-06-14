/* globals rincgf */
(function () {
    'use strict';

    const widget = document.querySelector('.rin-gallery-favorite-widget[data-gallery-id]');
    if (!widget) return;

    const cfg = window.rincgf || {};
    if (!cfg.restBase || !cfg.nonce) return;

    const galleryId   = parseInt(widget.dataset.galleryId, 10);
    const favoritesUrl = widget.dataset.favoritesUrl || cfg.favoritesUrl || '';

    if (!galleryId) return;

    let state = {
        isFavorited : widget.dataset.isFavorited === '1',
        note        : widget.dataset.initialNote || '',
        saving      : false,
        error       : null,
    };

    // -------------------------------------------------------------------------
    // Rendering — DOM only, no innerHTML
    // -------------------------------------------------------------------------

    function render() {
        widget.textContent = '';

        if (state.isFavorited) {
            const status = el('p', { className: 'rincgf-status rincgf-favorited' });
            status.textContent = '★ Favorited';
            widget.appendChild(status);
        } else {
            const btn = el('button', { type: 'button', className: 'rincgf-btn rincgf-btn-favorite' });
            btn.textContent = '☆ Favorite this gallery';
            btn.addEventListener('click', handleFavorite);
            widget.appendChild(btn);
        }

        const noteSection = el('div', { className: 'rincgf-note-section' });
        const label       = el('label', { className: 'rincgf-note-label' });
        const labelText   = document.createTextNode('Private note');
        const textarea    = el('textarea', {
            className : 'rincgf-note-textarea',
            rows      : 4,
            maxLength : 2000,
        });
        textarea.value = state.note;

        label.appendChild(labelText);
        label.appendChild(document.createElement('br'));
        label.appendChild(textarea);
        noteSection.appendChild(label);
        widget.appendChild(noteSection);

        const actions = el('div', { className: 'rincgf-actions' });

        const btnSave = el('button', {
            type      : 'button',
            className : 'rincgf-btn rincgf-btn-save',
            disabled  : state.saving,
        });
        btnSave.textContent = state.isFavorited ? 'Save note' : 'Favorite & save note';
        btnSave.addEventListener('click', handleSaveNote);
        actions.appendChild(btnSave);

        if (state.isFavorited) {
            actions.appendChild(document.createTextNode(' '));
            const btnRemove = el('button', {
                type      : 'button',
                className : 'rincgf-btn rincgf-btn-remove',
                disabled  : state.saving,
            });
            btnRemove.textContent = 'Remove favorite';
            btnRemove.addEventListener('click', handleRemove);
            actions.appendChild(btnRemove);
        }

        widget.appendChild(actions);

        if (state.error) {
            const errP = el('p', { className: 'rincgf-error' });
            errP.textContent = state.error;
            widget.appendChild(errP);
        }

        if (favoritesUrl) {
            const linkP = el('p', { className: 'rincgf-link' });
            const a     = el('a', { href: favoritesUrl });
            a.textContent = 'View my favorite galleries';
            linkP.appendChild(a);
            widget.appendChild(linkP);
        }
    }

    // -------------------------------------------------------------------------
    // Event handlers
    // -------------------------------------------------------------------------

    async function handleFavorite() {
        setState({ saving: true, error: null });
        try {
            const data = await apiFetch('POST', '/favorite', { gallery_id: galleryId });
            setState({ saving: false, isFavorited: true, note: data.note || '' });
        } catch (err) {
            setState({ saving: false, error: 'Could not save favorite. Please try again.' });
        }
    }

    async function handleSaveNote() {
        const textarea = widget.querySelector('.rincgf-note-textarea');
        const note     = textarea ? textarea.value : '';

        setState({ saving: true, error: null });
        try {
            if (state.isFavorited) {
                const data = await apiFetch('PATCH', '/note', { gallery_id: galleryId, note });
                setState({ saving: false, note: data.note !== undefined ? data.note : note });
            } else {
                const data = await apiFetch('POST', '/favorite', { gallery_id: galleryId, note });
                setState({ saving: false, isFavorited: true, note: data.note !== undefined ? data.note : note });
            }
        } catch (err) {
            setState({ saving: false, error: 'Could not save note. Please try again.' });
        }
    }

    async function handleRemove() {
        if (!confirm('Remove this gallery from your favorites?')) return;
        setState({ saving: true, error: null });
        try {
            await apiFetch('DELETE', '/favorite', null, { gallery_id: galleryId });
            setState({ saving: false, isFavorited: false, note: '' });
        } catch (err) {
            setState({ saving: false, error: 'Could not remove favorite. Please try again.' });
        }
    }

    function setState(patch) {
        state = Object.assign({}, state, patch);
        render();
    }

    // -------------------------------------------------------------------------
    // API helper
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Tiny DOM helper — sets plain properties, never innerHTML
    // -------------------------------------------------------------------------

    function el(tag, props) {
        const node = document.createElement(tag);
        if (props) {
            Object.keys(props).forEach(k => {
                node[k] = props[k];
            });
        }
        return node;
    }

    // Boot
    render();
})();
