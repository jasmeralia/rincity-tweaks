/* global rincwcCfg, wp */
(function () {
    'use strict';

    wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( rincwcCfg.nonce ) );

    const base = rincwcCfg.restBase;

    function api( path, method, data ) {
        const opts = { path: base + path, method: method || 'GET' };
        if ( data ) { opts.data = data; }
        return wp.apiFetch( opts );
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    document.addEventListener( 'DOMContentLoaded', () => {
        document.querySelectorAll( '.rincwc-card' ).forEach( initCard );
        initBatchButtons();
        initExpandCollapse();
        initFilter();
    } );

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
        // Hide gallery blocks that have no selected cards when filtering.
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
                // Refresh wm-status badges.
                document.querySelectorAll( '.rincwc-wm-status.wm-pending' ).forEach( el => {
                    el.textContent = '✓ applied'; el.classList.replace( 'wm-pending', 'wm-done' );
                } );
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; awm.disabled = false; } );
        } );
    }

    // ── Per-card init ─────────────────────────────────────────────────────────

    function initCard( card ) {
        const cfg = JSON.parse( card.dataset.c || '{}' );

        // Variant buttons.
        card.querySelectorAll( '.rincwc-vbtn' ).forEach( btn => {
            if ( btn.classList.contains( 'rincwc-custbtn' ) ) {
                btn.addEventListener( 'click', () => toggleCropTool( card, cfg ) );
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

        // Crop tool.
        initCropTool( card, cfg );

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
            updateBadge( card, false );
            updateCropLinks( card, cfg );

            // Deselect button.
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
                if ( corner ) {
                    st.textContent = '⏳ pending';
                    st.className = 'rincwc-wm-status wm-pending';
                } else {
                    st.textContent = '';
                    st.className = 'rincwc-wm-status';
                }
            }
        } ).catch( e => console.error( 'watermark failed', e ) );
    }

    // ── Custom crop tool ──────────────────────────────────────────────────────

    function toggleCropTool( card, cfg ) {
        const tool = card.querySelector( '.rincwc-crop-tool' );
        if ( ! tool ) return;
        const open = tool.style.display !== 'none';
        tool.style.display = open ? 'none' : '';
        if ( ! open ) {
            const img = tool.querySelector( '.rincwc-preview-img' );
            const off = () => parseInt( tool.querySelector( '.rincwc-slider' )?.value || cfg.customOff );
            const doUpdate = () => requestAnimationFrame( () => updateCropBox( tool, cfg, off() ) );
            if ( img ) {
                if ( ! img.src || img.src === window.location.href ) {
                    img.onload = doUpdate;
                    img.src = img.dataset.src;
                } else if ( img.complete && img.naturalWidth ) {
                    doUpdate();
                } else {
                    img.onload = doUpdate;
                }
            }
        }
    }

    function initCropTool( card, cfg ) {
        const tool = card.querySelector( '.rincwc-crop-tool' );
        if ( ! tool ) return;

        const slider  = tool.querySelector( '.rincwc-slider' );
        const offVal  = tool.querySelector( '.rincwc-off-val' );
        const saveBtn = tool.querySelector( '.rincwc-save-off' );

        if ( slider ) {
            slider.addEventListener( 'input', () => {
                offVal.textContent = slider.value;
                updateCropBox( tool, cfg, parseInt( slider.value ) );
            } );
        }

        if ( saveBtn ) {
            saveBtn.addEventListener( 'click', () => {
                const offset = parseInt( slider?.value ?? cfg.customOff );
                saveBtn.disabled = true;
                const payload = {
                    gallery_id: cfg.gid, attach_id: cfg.aid, offset,
                    gallery_slug: cfg.gSlug, gallery_title: cfg.gTitle,
                    position: cfg.pos, total: cfg.total, filename: cfg.fname,
                };
                api( 'crop-offset', 'POST', payload ).then( () => {
                    cfg.customOff = offset;
                    cfg.selCrop   = 'custom';
                    card.dataset.c = JSON.stringify( cfg );
                    card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === 'custom' ) );
                    card.classList.add( 'is-selected' );
                    showWmRow( card, true );
                    updateBadge( card, false );
                    saveBtn.disabled = false;
                    saveBtn.textContent = 'Saved ✓';
                    setTimeout( () => { saveBtn.textContent = 'Save'; }, 2000 );
                } ).catch( e => { console.error( 'crop-offset failed', e ); saveBtn.disabled = false; } );
            } );
        }

        // Drag on the crop box.
        const cropBox = tool.querySelector( '.rincwc-crop-box' );
        if ( cropBox && slider ) {
            let dragging = false, startX = 0, startY = 0, startOff = 0;
            cropBox.addEventListener( 'mousedown', e => {
                dragging = true; startX = e.clientX; startY = e.clientY;
                startOff = parseInt( slider.value );
                e.preventDefault();
            } );
            document.addEventListener( 'mousemove', e => {
                if ( ! dragging ) return;
                const img = tool.querySelector( '.rincwc-preview-img' );
                const dx = e.clientX - startX;
                const dy = e.clientY - startY;
                let newOff;
                // Convert display-pixel drag into resized-image-pixel offset.
                if ( cfg.cropAxis === 'x' ) {
                    newOff = Math.round( startOff + dx * 2160 / ( img.clientHeight || 1 ) );
                } else {
                    newOff = Math.round( startOff + dy * 3840 / ( img.clientWidth || 1 ) );
                }
                newOff = Math.max( 0, Math.min( cfg.cropRange, newOff ) );
                slider.value  = newOff;
                offVal.textContent = newOff;
                updateCropBox( tool, cfg, newOff );
            } );
            document.addEventListener( 'mouseup', () => { dragging = false; } );
        }
    }

    function updateCropBox( tool, cfg, offset ) {
        const img     = tool.querySelector( '.rincwc-preview-img' );
        const cropBox = tool.querySelector( '.rincwc-crop-box' );
        if ( ! img || ! cropBox ) return;
        const dispW = img.clientWidth;
        const dispH = img.clientHeight;
        if ( ! dispW || ! dispH ) return;

        const targetAR = 3840 / 2160;
        if ( cfg.cropAxis === 'x' ) {
            // Resized to height 2160; offset is horizontal in resized-image px.
            const boxW = Math.round( dispH * targetAR );
            const boxX = Math.round( offset * dispH / 2160 );
            cropBox.style.width  = boxW + 'px';
            cropBox.style.height = dispH + 'px';
            cropBox.style.left   = Math.min( boxX, Math.max( 0, dispW - boxW ) ) + 'px';
            cropBox.style.top    = '0';
        } else {
            // Resized to width 3840; offset is vertical in resized-image px.
            const boxH = Math.round( dispW / targetAR );
            const boxY = Math.round( offset * dispW / 3840 );
            cropBox.style.width  = dispW + 'px';
            cropBox.style.height = boxH + 'px';
            cropBox.style.left   = '0';
            cropBox.style.top    = Math.min( boxY, Math.max( 0, dispH - boxH ) ) + 'px';
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    function showWmRow( card, visible ) {
        const row = card.querySelector( '.rincwc-wm-row' );
        if ( row ) row.classList.toggle( 'hidden', ! visible );
    }

    function updateBadge( card, wmApplied ) {
        let badge = card.querySelector( '.rincwc-badge' );
        if ( wmApplied === null ) {
            if ( badge ) badge.remove();
            return;
        }
        if ( ! badge ) {
            badge = document.createElement( 'span' );
            card.querySelector( '.rincwc-thumb-wrap' ).appendChild( badge );
        }
        badge.className = 'rincwc-badge ' + ( wmApplied ? 'badge-wm' : 'badge-sel' );
        badge.textContent = wmApplied ? 'WM ✓' : 'Selected';
    }

    function updateCropLinks( card, cfg ) {
        let dl = card.querySelector( '.rincwc-crop-dl' );
        if ( ! dl ) { dl = document.createElement( 'div' ); dl.className = 'rincwc-crop-dl'; card.appendChild( dl ); }
        dl.innerHTML = '';
        if ( ! cfg.selCrop ) return;
        // Links are shown server-side; on client we show a "pending" note for custom.
        if ( cfg.selCrop === 'custom' ) {
            dl.innerHTML = '<em>Custom crop pending — click Generate crops.</em>';
        }
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

        // Delegate edit/delete.
        box.addEventListener( 'click', e => {
            const el = e.target;
            const cDiv = el.closest( '.rincwc-comment' );
            if ( ! cDiv ) return;
            const cid = cDiv.dataset.cid;

            if ( el.classList.contains( 'rincwc-del-btn' ) ) {
                if ( ! confirm( 'Delete this comment?' ) ) return;
                api( 'comments/' + cid, 'DELETE' ).then( r => {
                    if ( r.ok ) cDiv.remove();
                } ).catch( e => console.error( 'delete failed', e ) );
            }

            if ( el.classList.contains( 'rincwc-edit-btn' ) ) {
                cDiv.querySelector( '.rincwc-comment-body' ).hidden = true;
                cDiv.querySelector( '.rincwc-edit-form' ).hidden = false;
                el.hidden = true;
            }

            if ( el.classList.contains( 'rincwc-cancel-edit-btn' ) ) {
                cDiv.querySelector( '.rincwc-comment-body' ).hidden = false;
                cDiv.querySelector( '.rincwc-edit-form' ).hidden = true;
                cDiv.querySelector( '.rincwc-edit-btn' ).hidden = false;
            }

            if ( el.classList.contains( 'rincwc-save-edit-btn' ) ) {
                const newBody = cDiv.querySelector( '.rincwc-edit-ta' ).value.trim();
                if ( ! newBody ) return;
                el.disabled = true;
                api( 'comments/' + cid, 'PUT', { body: newBody } ).then( r => {
                    if ( r.ok ) {
                        const bodyEl = cDiv.querySelector( '.rincwc-comment-body' );
                        bodyEl.innerHTML = newBody.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( /\n/g, '<br>' );
                        cDiv.querySelector( '.rincwc-edit-ta' ).value = newBody;
                        bodyEl.hidden = false;
                        cDiv.querySelector( '.rincwc-edit-form' ).hidden = true;
                        cDiv.querySelector( '.rincwc-edit-btn' ).hidden = false;
                    }
                    el.disabled = false;
                } ).catch( e => { console.error( 'edit failed', e ); el.disabled = false; } );
            }
        } );
    }

    function buildComment( c ) {
        const uid = parseInt( rincwcCfg.userId );
        const isMe = c.user_id === uid || c.user_id === String( uid );
        const name = c.display_name || c.user_login || 'Unknown';
        const date = new Date( c.created_at ).toLocaleString( undefined, { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' } );

        const div = document.createElement( 'div' );
        div.className = 'rincwc-comment'; div.dataset.cid = c.id;
        div.innerHTML = `<div class="rincwc-comment-meta"><strong>${esc(name)}</strong> · ${esc(date)}`
            + ( isMe ? ' <button class="rincwc-edit-btn button-link">Edit</button> <button class="rincwc-del-btn button-link">Delete</button>' : '' )
            + `</div><div class="rincwc-comment-body">${escNl(c.body)}</div>`
            + ( isMe ? `<div class="rincwc-edit-form" hidden><textarea class="rincwc-edit-ta" rows="2">${esc(c.body)}</textarea> <button class="button rincwc-save-edit-btn">Save</button> <button class="button-link rincwc-cancel-edit-btn">Cancel</button></div>` : '' );
        return div;
    }

    function esc( s ) { return String(s).replace( /&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function escNl( s ) { return esc(s).replace(/\n/g,'<br>'); }

})();
