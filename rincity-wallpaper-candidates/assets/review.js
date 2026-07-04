/* global rincwcCfg, wp */
(function () {
    'use strict';

    wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( rincwcCfg.nonce ) );

    const base = rincwcCfg.restBase;

    function api( path, method, data ) {
        const opts = { url: base + path, method: method || 'GET' };
        if ( data ) { opts.data = data; }
        return wp.apiFetch( opts );
    }

    // Shared state for the crop overlay.
    let activeCropCard = null;
    let activeCropCfg  = null;
    let cropOverlayEl  = null;
    let lightboxEl     = null;
    let lightboxImg    = null;

    // ── Init ──────────────────────────────────────────────────────────────────

    document.addEventListener( 'DOMContentLoaded', () => {
        buildLightbox();
        buildCropOverlay();
        document.querySelectorAll( '.rincwc-card' ).forEach( initCard );
        initBatchButtons();
        initExpandCollapse();
        initFilter();

        // Lightbox all crop download links.
        document.addEventListener( 'click', e => {
            const a = e.target.closest( '.rincwc-crop-dl a' );
            if ( ! a ) return;
            e.preventDefault();
            openLightbox( a.href, a.textContent.trim() );
        } );
    } );

    // ── Lightbox ──────────────────────────────────────────────────────────────

    function buildLightbox() {
        const lb = document.createElement( 'div' );
        lb.id = 'rincwc-lb';
        lb.innerHTML = '<button class="rincwc-lb-close" aria-label="Close">&#x2715;</button>'
                     + '<img class="rincwc-lb-img" src="" alt="">';
        document.body.appendChild( lb );
        lightboxEl = lb;
        lightboxImg = lb.querySelector( '.rincwc-lb-img' );

        const close = () => { lb.hidden = true; };
        lb.querySelector( '.rincwc-lb-close' ).addEventListener( 'click', e => { e.stopPropagation(); close(); } );
        lb.addEventListener( 'click', close );
        lightboxImg.addEventListener( 'click', e => e.stopPropagation() );
        document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && ! lb.hidden ) close(); } );
        lb.hidden = true;
    }

    function openLightbox( url, alt ) {
        if ( ! lightboxEl ) return;
        lightboxImg.src = url;
        lightboxImg.alt = alt || '';
        lightboxEl.hidden = false;
    }

    // ── Crop overlay ──────────────────────────────────────────────────────────

    function buildCropOverlay() {
        const ov = document.createElement( 'div' );
        ov.id = 'rincwc-cov';
        ov.innerHTML =
            '<div class="rincwc-cov-bar">'
          +   '<span class="rincwc-cov-title"></span>'
          +   '<button class="rincwc-cov-close button-link">&#x2715; Close</button>'
          + '</div>'
          + '<div class="rincwc-cov-preview">'
          +   '<div class="rincwc-cov-wrap">'
          +     '<img class="rincwc-cov-img" src="" alt="">'
          +     '<div class="rincwc-cov-box"></div>'
          +   '</div>'
          + '</div>'
          + '<div class="rincwc-cov-ctrl">'
          +   '<label class="rincwc-cov-range-wrap">Offset'
          +     '<input type="range" class="rincwc-cov-slider" min="0" max="0" value="0">'
          +   '</label>'
          +   '<span class="rincwc-cov-offval">0</span>px'
          +   '<button class="button button-primary rincwc-cov-save">Save crop</button>'
          + '</div>';
        document.body.appendChild( ov );
        cropOverlayEl = ov;

        const img    = ov.querySelector( '.rincwc-cov-img' );
        const box    = ov.querySelector( '.rincwc-cov-box' );
        const wrap   = ov.querySelector( '.rincwc-cov-wrap' );
        const slider = ov.querySelector( '.rincwc-cov-slider' );
        const offVal = ov.querySelector( '.rincwc-cov-offval' );

        slider.addEventListener( 'input', () => {
            offVal.textContent = slider.value;
            updateCropBox( img, box, activeCropCfg, parseInt( slider.value ) );
        } );

        // Drag on crop box.
        let dragging = false, startX = 0, startY = 0, startOff = 0;
        box.addEventListener( 'mousedown', e => {
            if ( ! activeCropCfg ) return;
            dragging = true; startX = e.clientX; startY = e.clientY;
            startOff = parseInt( slider.value );
            e.preventDefault();
        } );
        document.addEventListener( 'mousemove', e => {
            if ( ! dragging || ! activeCropCfg ) return;
            const cfg = activeCropCfg;
            const dx  = e.clientX - startX;
            const dy  = e.clientY - startY;
            let newOff;
            if ( cfg.cropAxis === 'x' ) {
                newOff = Math.round( startOff + dx * 2160 / ( img.clientHeight || 1 ) );
            } else {
                newOff = Math.round( startOff + dy * 3840 / ( img.clientWidth || 1 ) );
            }
            newOff = Math.max( 0, Math.min( cfg.cropRange, newOff ) );
            slider.value = newOff;
            offVal.textContent = newOff;
            updateCropBox( img, box, cfg, newOff );
        } );
        document.addEventListener( 'mouseup', () => { dragging = false; } );

        // Save.
        ov.querySelector( '.rincwc-cov-save' ).addEventListener( 'click', () => {
            if ( ! activeCropCfg || ! activeCropCard ) return;
            const offset  = parseInt( slider.value );
            const cfg     = activeCropCfg;
            const card    = activeCropCard;
            const saveBtn = ov.querySelector( '.rincwc-cov-save' );
            saveBtn.disabled = true;
            api( 'crop-offset', 'POST', {
                gallery_id: cfg.gid,  attach_id:     cfg.aid,    offset,
                gallery_slug: cfg.gSlug, gallery_title: cfg.gTitle,
                position:   cfg.pos,  total:         cfg.total,  filename: cfg.fname,
            } ).then( () => {
                cfg.customOff = offset;
                cfg.selCrop   = 'custom';
                card.dataset.c = JSON.stringify( cfg );
                card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === 'custom' ) );
                card.classList.add( 'is-selected' );
                showWmRow( card, true );
                updateBadge( card, false );
                saveBtn.disabled = false;
                closeCropOverlay();
            } ).catch( e => { console.error( 'crop-offset failed', e ); saveBtn.disabled = false; } );
        } );

        // Close / Escape.
        ov.querySelector( '.rincwc-cov-close' ).addEventListener( 'click', closeCropOverlay );
        document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && ! ov.hidden ) closeCropOverlay(); } );

        // Resize: recalculate wrap + box.
        window.addEventListener( 'resize', () => {
            if ( ov.hidden || ! activeCropCfg ) return;
            requestAnimationFrame( () => {
                sizeWrap( img, wrap );
                updateCropBox( img, box, activeCropCfg, parseInt( slider.value ) );
            } );
        } );

        ov.hidden = true;
    }

    function openCropOverlay( card, cfg ) {
        if ( ! cropOverlayEl ) return;
        activeCropCard = card;
        activeCropCfg  = cfg;

        const img    = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const box    = cropOverlayEl.querySelector( '.rincwc-cov-box' );
        const wrap   = cropOverlayEl.querySelector( '.rincwc-cov-wrap' );
        const slider = cropOverlayEl.querySelector( '.rincwc-cov-slider' );
        const offVal = cropOverlayEl.querySelector( '.rincwc-cov-offval' );

        cropOverlayEl.querySelector( '.rincwc-cov-title' ).textContent = cfg.title || cfg.fname;
        slider.max   = cfg.cropRange;
        slider.value = cfg.customOff || 0;
        offVal.textContent = slider.value;

        cropOverlayEl.hidden = false;
        document.body.style.overflow = 'hidden';

        const doUpdate = () => requestAnimationFrame( () => {
            sizeWrap( img, wrap );
            updateCropBox( img, box, cfg, parseInt( slider.value ) );
        } );

        if ( img.dataset.src !== cfg.scaledUrl ) {
            img.onload = () => { img.dataset.src = cfg.scaledUrl; doUpdate(); };
            img.src = cfg.scaledUrl;
        } else if ( img.complete && img.naturalWidth ) {
            doUpdate();
        } else {
            img.onload = doUpdate;
        }
    }

    function closeCropOverlay() {
        if ( cropOverlayEl ) cropOverlayEl.hidden = true;
        document.body.style.overflow = '';
        activeCropCard = null;
        activeCropCfg  = null;
    }

    // Size the wrap to fill available space while preserving image aspect ratio.
    function sizeWrap( img, wrap ) {
        if ( ! img.naturalWidth ) return;
        const preview = wrap.parentElement;
        const maxW    = preview.clientWidth;
        const maxH    = preview.clientHeight;
        if ( ! maxW || ! maxH ) return;
        const ar      = img.naturalWidth / img.naturalHeight;
        let w, h;
        if ( ar >= maxW / maxH ) {
            w = maxW; h = Math.round( maxW / ar );
        } else {
            h = maxH; w = Math.round( maxH * ar );
        }
        wrap.style.width  = w + 'px';
        wrap.style.height = h + 'px';
    }

    // ── Crop box math ─────────────────────────────────────────────────────────

    function updateCropBox( img, box, cfg, offset ) {
        if ( ! img || ! box || ! cfg ) return;
        const dispW = img.clientWidth;
        const dispH = img.clientHeight;
        if ( ! dispW || ! dispH ) return;

        const ar = 3840 / 2160;
        if ( cfg.cropAxis === 'x' ) {
            const boxW = Math.round( dispH * ar );
            const boxX = Math.round( offset * dispH / 2160 );
            box.style.width  = boxW + 'px';
            box.style.height = dispH + 'px';
            box.style.left   = Math.min( boxX, Math.max( 0, dispW - boxW ) ) + 'px';
            box.style.top    = '0';
        } else {
            const boxH = Math.round( dispW / ar );
            const boxY = Math.round( offset * dispW / 3840 );
            box.style.width  = dispW + 'px';
            box.style.height = boxH + 'px';
            box.style.left   = '0';
            box.style.top    = Math.min( boxY, Math.max( 0, dispH - boxH ) ) + 'px';
        }
    }

    // ── Per-card init ─────────────────────────────────────────────────────────

    function initCard( card ) {
        const cfg = JSON.parse( card.dataset.c || '{}' );

        // Thumbnail → lightbox.
        const thumb = card.querySelector( '.rincwc-thumb' );
        if ( thumb ) thumb.addEventListener( 'click', () => openLightbox( cfg.scaledUrl || thumb.src, cfg.title || cfg.fname ) );

        // Variant buttons.
        card.querySelectorAll( '.rincwc-vbtn' ).forEach( btn => {
            if ( btn.classList.contains( 'rincwc-custbtn' ) ) {
                btn.addEventListener( 'click', () => openCropOverlay( card, cfg ) );
            } else {
                btn.addEventListener( 'click', () => selectVariant( card, cfg, btn ) );
            }
        } );

        // Deselect.
        const deselBtn = card.querySelector( '.rincwc-desel' );
        if ( deselBtn ) deselBtn.addEventListener( 'click', () => deselect( card, cfg ) );

        // Watermark corner dropdown.
        const wmSel = card.querySelector( '.rincwc-wm-sel' );
        if ( wmSel ) wmSel.addEventListener( 'change', () => setWatermark( card, cfg, wmSel.value ) );

        const approveBtn = card.querySelector( '.rincwc-approve-btn' );
        if ( approveBtn ) approveBtn.addEventListener( 'click', () => toggleApprove( card, cfg, approveBtn ) );

        // Comments.
        initComments( card );
    }

    // ── Variant select ────────────────────────────────────────────────────────

    function selectVariant( card, cfg, btn ) {
        const variant = btn.dataset.v;
        const data = {
            gallery_id: cfg.gid, gallery_slug: cfg.gSlug, gallery_title: cfg.gTitle,
            attach_id: cfg.aid, position: cfg.pos, total: cfg.total,
            filename: cfg.fname, selected_crop: variant,
        };
        api( 'select', 'POST', data ).then( () => {
            card.classList.add( 'is-selected' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === variant ) );
            cfg.selCrop = variant;
            card.dataset.c = JSON.stringify( cfg );
            showWmRow( card, true );
            showApprovalRow( card, true );
            updateBadge( card, false );
            updateCropLinks( card, cfg );

            if ( ! card.querySelector( '.rincwc-desel' ) ) {
                const desel = document.createElement( 'span' );
                desel.className = 'rincwc-desel'; desel.title = 'Remove selection'; desel.textContent = '✕';
                desel.addEventListener( 'click', () => deselect( card, cfg ) );
                card.querySelector( '.rincwc-variants' ).appendChild( desel );
            }
        } ).catch( e => console.error( 'select failed', e ) );
    }

    function deselect( card, cfg ) {
        api( 'deselect', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( () => {
            card.classList.remove( 'is-selected' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.remove( 'active' ) );
            cfg.selCrop = '';
            card.dataset.c = JSON.stringify( cfg );
            showWmRow( card, false );
            showApprovalRow( card, false );
            updateBadge( card, null );
            const desel = card.querySelector( '.rincwc-desel' );
            if ( desel ) desel.remove();
            const dl = card.querySelector( '.rincwc-crop-dl' );
            if ( dl ) dl.innerHTML = '';
        } ).catch( e => console.error( 'deselect failed', e ) );
    }

    // ── Watermark ─────────────────────────────────────────────────────────────

    function setWatermark( card, cfg, corner ) {
        api( 'watermark', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid, wm_corner: corner } ).then( () => {
            cfg.wmCorner = corner;
            card.dataset.c = JSON.stringify( cfg );
            const st = card.querySelector( '.rincwc-wm-status' );
            if ( st ) {
                st.textContent = corner ? '⏳ pending' : '';
                st.className   = 'rincwc-wm-status' + ( corner ? ' wm-pending' : '' );
            }
        } ).catch( e => console.error( 'watermark failed', e ) );
    }

    function toggleApprove( card, cfg, btn ) {
        if ( btn.disabled ) return;
        const approved = btn.dataset.approved === '1';
        btn.disabled = true;
        api( approved ? 'unapprove' : 'approve', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( r => {
            if ( ! r.ok ) {
                btn.disabled = false;
                return;
            }
            const nowApproved = ! approved;
            btn.dataset.approved = nowApproved ? '1' : '0';
            btn.textContent = nowApproved ? 'Unapprove' : 'Approve';
            card.classList.toggle( 'is-approved', nowApproved );
            cfg.status = nowApproved ? 'APPROVED' : 'SELECTED';
            card.dataset.c = JSON.stringify( cfg );
            updateBadge( card, nowApproved ? 'approved' : false );
            btn.disabled = ! rincwcCfg.approveAllowed;
        } ).catch( e => { console.error( 'approve toggle failed', e ); btn.disabled = false; } );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function showWmRow( card, visible ) {
        const row = card.querySelector( '.rincwc-wm-row' );
        if ( row ) row.classList.toggle( 'hidden', ! visible );
    }

    function showApprovalRow( card, visible ) {
        const row = card.querySelector( '.rincwc-approval-row' );
        if ( row ) row.classList.toggle( 'hidden', ! visible );
    }

    function updateBadge( card, wmApplied ) {
        let badge = card.querySelector( '.rincwc-badge' );
        if ( wmApplied === null ) { if ( badge ) badge.remove(); return; }
        if ( ! badge ) {
            badge = document.createElement( 'span' );
            card.querySelector( '.rincwc-thumb-wrap' ).appendChild( badge );
        }
        if ( wmApplied === 'approved' ) {
            badge.className = 'rincwc-badge badge-approved';
            badge.textContent = 'Approved';
            return;
        }
        badge.className = 'rincwc-badge ' + ( wmApplied ? 'badge-wm' : 'badge-sel' );
        badge.textContent = wmApplied ? 'WM applied' : 'Selected';
    }

    function updateCropLinks( card, cfg ) {
        let dl = card.querySelector( '.rincwc-crop-dl' );
        if ( ! dl ) { dl = document.createElement( 'div' ); dl.className = 'rincwc-crop-dl'; card.appendChild( dl ); }
        dl.innerHTML = cfg.selCrop === 'custom' ? '<em>Custom crop pending — click Generate crops.</em>' : '';
    }

    // ── Batch buttons ─────────────────────────────────────────────────────────

    function initBatchButtons() {
        const gc  = document.getElementById( 'rincwc-generate-crops' );
        const awm = document.getElementById( 'rincwc-apply-wm' );
        const msg = document.getElementById( 'rincwc-batch-msg' );

        if ( gc ) gc.addEventListener( 'click', () => {
            gc.disabled = true; msg.textContent = 'Generating crops…';
            api( 'generate-crops', 'POST' ).then( r => {
                const ok  = r.results.filter( x => x.status === 'ok' ).length;
                const err = r.results.filter( x => x.status === 'error' ).length;
                msg.textContent = `Done: ${ok} ok, ${err} errors.`;
                gc.disabled = false;
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; gc.disabled = false; } );
        } );

        if ( awm ) awm.addEventListener( 'click', () => {
            awm.disabled = true; msg.textContent = 'Applying watermarks…';
            api( 'apply-watermarks', 'POST' ).then( r => {
                const ok = r.results.filter( x => x.status === 'ok' ).length;
                msg.textContent = `Done: ${ok} watermarks applied.`;
                awm.disabled = false;
                document.querySelectorAll( '.rincwc-wm-status.wm-pending' ).forEach( el => {
                    el.textContent = '✓ applied'; el.classList.replace( 'wm-pending', 'wm-done' );
                } );
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; awm.disabled = false; } );
        } );
    }

    // ── Selections filter ─────────────────────────────────────────────────────

    function initFilter() {
        const btn = document.getElementById( 'rincwc-filter-sel' );
        if ( ! btn ) return;
        btn.addEventListener( 'click', () => {
            const active = btn.classList.toggle( 'is-active' );
            applyFilter( active );
        } );
    }

    function applyFilter( selectionsOnly ) {
        document.querySelectorAll( '.rincwc-card' ).forEach( card => {
            card.style.display = ( selectionsOnly && ! card.classList.contains( 'is-selected' ) ) ? 'none' : '';
        } );
        document.querySelectorAll( '.rincwc-gallery' ).forEach( gal => {
            const hasSel = !! gal.querySelector( '.rincwc-card.is-selected' );
            gal.style.display = ( selectionsOnly && ! hasSel ) ? 'none' : '';
        } );
    }

    // ── Expand/collapse all ───────────────────────────────────────────────────

    function initExpandCollapse() {
        const ea = document.getElementById( 'rincwc-expand-all' );
        const ca = document.getElementById( 'rincwc-collapse-all' );
        if ( ea ) ea.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => d.open = true ); } );
        if ( ca ) ca.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => d.open = false ); } );
    }

    // ── Comments ──────────────────────────────────────────────────────────────

    function initComments( card ) {
        const box     = card.querySelector( '.rincwc-comments' );
        if ( ! box ) return;
        const key     = box.dataset.key;
        const ta      = box.querySelector( '.rincwc-comment-ta' );
        const postBtn = box.querySelector( '.rincwc-post-btn' );

        if ( postBtn ) {
            postBtn.addEventListener( 'click', () => {
                const body = ta.value.trim();
                if ( ! body ) return;
                postBtn.disabled = true;
                api( 'comments', 'POST', { image_key: key, body } ).then( c => {
                    box.insertBefore( buildComment( c ), box.querySelector( '.rincwc-add-comment' ) );
                    ta.value = '';
                    postBtn.disabled = false;
                } ).catch( e => { console.error( 'post comment failed', e ); postBtn.disabled = false; } );
            } );
        }

        box.addEventListener( 'click', e => {
            const el   = e.target;
            const cDiv = el.closest( '.rincwc-comment' );
            if ( ! cDiv ) return;
            const cid  = cDiv.dataset.cid;

            if ( el.classList.contains( 'rincwc-del-btn' ) ) {
                if ( ! confirm( 'Delete this comment?' ) ) return;
                api( 'comments/' + cid, 'DELETE' ).then( r => { if ( r.ok ) cDiv.remove(); } )
                    .catch( e => console.error( 'delete failed', e ) );
            }
            if ( el.classList.contains( 'rincwc-edit-btn' ) ) {
                cDiv.querySelector( '.rincwc-comment-body' ).hidden = true;
                cDiv.querySelector( '.rincwc-edit-form' ).hidden    = false;
                el.hidden = true;
            }
            if ( el.classList.contains( 'rincwc-cancel-edit-btn' ) ) {
                cDiv.querySelector( '.rincwc-comment-body' ).hidden = false;
                cDiv.querySelector( '.rincwc-edit-form' ).hidden    = true;
                cDiv.querySelector( '.rincwc-edit-btn' ).hidden     = false;
            }
            if ( el.classList.contains( 'rincwc-save-edit-btn' ) ) {
                const newBody = cDiv.querySelector( '.rincwc-edit-ta' ).value.trim();
                if ( ! newBody ) return;
                el.disabled = true;
                api( 'comments/' + cid, 'PUT', { body: newBody } ).then( r => {
                    if ( r.ok ) {
                        const bodyEl = cDiv.querySelector( '.rincwc-comment-body' );
                        bodyEl.innerHTML = escNl( newBody );
                        cDiv.querySelector( '.rincwc-edit-ta' ).value = newBody;
                        bodyEl.hidden = false;
                        cDiv.querySelector( '.rincwc-edit-form' ).hidden = true;
                        cDiv.querySelector( '.rincwc-edit-btn' ).hidden  = false;
                    }
                    el.disabled = false;
                } ).catch( e => { console.error( 'edit failed', e ); el.disabled = false; } );
            }
        } );
    }

    function buildComment( c ) {
        const uid  = parseInt( rincwcCfg.userId );
        const isMe = c.user_id === uid || c.user_id === String( uid );
        const name = c.display_name || c.user_login || 'Unknown';
        const date = new Date( c.created_at ).toLocaleString( undefined, {
            month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
        } );
        const div = document.createElement( 'div' );
        div.className = 'rincwc-comment'; div.dataset.cid = c.id;
        div.innerHTML =
            '<div class="rincwc-comment-meta"><strong>' + esc( name ) + '</strong> · ' + esc( date )
            + ( isMe ? ' <button class="rincwc-edit-btn button-link">Edit</button>'
                     + ' <button class="rincwc-del-btn button-link">Delete</button>' : '' )
            + '</div>'
            + '<div class="rincwc-comment-body">' + escNl( c.body ) + '</div>'
            + ( isMe
                ? '<div class="rincwc-edit-form" hidden>'
                + '<textarea class="rincwc-edit-ta" rows="2">' + esc( c.body ) + '</textarea>'
                + ' <button class="button rincwc-save-edit-btn">Save</button>'
                + ' <button class="button-link rincwc-cancel-edit-btn">Cancel</button>'
                + '</div>'
                : '' );
        return div;
    }

    function esc( s ) {
        return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }
    function escNl( s ) { return esc( s ).replace( /\n/g, '<br>' ); }

})();
