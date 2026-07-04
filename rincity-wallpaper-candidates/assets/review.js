/* global rincwcCfg, wp */
(function () {
    'use strict';

    wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( rincwcCfg.nonce ) );

    const base = rincwcCfg.restBase;
    let activeCropCard = null;
    let activeCropCfg = null;
    let cropState = null;
    let cropOverlayEl = null;
    let lightboxEl = null;
    let lightboxImg = null;

    function api( path, method, data ) {
        const opts = { url: base + path, method: method || 'GET' };
        if ( data ) {
            opts.data = data;
        }
        return wp.apiFetch( opts );
    }

    document.addEventListener( 'DOMContentLoaded', () => {
        buildLightbox();
        buildCropOverlay();
        document.querySelectorAll( '.rincwc-card' ).forEach( initCard );
        initBatchButtons();
        initExpandCollapse();
        initFilter();

        document.addEventListener( 'click', e => {
            const a = e.target.closest( '.rincwc-crop-dl a' );
            if ( ! a ) {
                return;
            }
            e.preventDefault();
            openLightbox( a.href, a.textContent.trim() );
        } );
    } );

    function buildLightbox() {
        const lb = document.createElement( 'div' );
        lb.id = 'rincwc-lb';
        lb.innerHTML = '<button class="rincwc-lb-close" aria-label="Close">x</button>'
            + '<img class="rincwc-lb-img" src="" alt="">';
        document.body.appendChild( lb );
        lightboxEl = lb;
        lightboxImg = lb.querySelector( '.rincwc-lb-img' );
        lb.hidden = true;

        const close = () => { lb.hidden = true; };
        lb.querySelector( '.rincwc-lb-close' ).addEventListener( 'click', e => { e.stopPropagation(); close(); } );
        lb.addEventListener( 'click', close );
        lightboxImg.addEventListener( 'click', e => e.stopPropagation() );
        document.addEventListener( 'keydown', e => {
            if ( e.key === 'Escape' && ! lb.hidden ) {
                close();
            }
        } );
    }

    function openLightbox( url, alt ) {
        lightboxImg.src = url;
        lightboxImg.alt = alt || '';
        lightboxEl.hidden = false;
    }

    function buildCropOverlay() {
        const ov = document.createElement( 'div' );
        ov.id = 'rincwc-cov';
        ov.innerHTML =
            '<div class="rincwc-cov-bar">'
          +   '<span class="rincwc-cov-title"></span>'
          +   '<button class="rincwc-cov-close button-link">x Close</button>'
          + '</div>'
          + '<div class="rincwc-cov-preview">'
          +   '<div class="rincwc-cov-wrap">'
          +     '<img class="rincwc-cov-img" src="" alt="">'
          +     '<div class="rincwc-cov-box"></div>'
          +   '</div>'
          + '</div>'
          + '<div class="rincwc-cov-ctrl">'
          +   '<label class="rincwc-cov-range-wrap">Zoom <input type="range" class="rincwc-cov-zoom" min="1" max="1" step="0.01" value="1"></label>'
          +   '<span class="rincwc-cov-zoomval">1.00x</span>'
          +   '<label class="rincwc-cov-range-wrap">X <input type="range" class="rincwc-cov-x" min="0" max="0" step="1" value="0"></label>'
          +   '<span class="rincwc-cov-xval">0</span>'
          +   '<label class="rincwc-cov-range-wrap">Y <input type="range" class="rincwc-cov-y" min="0" max="0" step="1" value="0"></label>'
          +   '<span class="rincwc-cov-yval">0</span>'
          +   '<button class="button button-primary rincwc-cov-save">Save crop</button>'
          +   '<button class="button rincwc-cov-reset">Reset</button>'
          + '</div>';
        document.body.appendChild( ov );
        cropOverlayEl = ov;
        ov.hidden = true;

        const img = ov.querySelector( '.rincwc-cov-img' );
        const box = ov.querySelector( '.rincwc-cov-box' );
        const wrap = ov.querySelector( '.rincwc-cov-wrap' );
        const zoom = ov.querySelector( '.rincwc-cov-zoom' );
        const xs = ov.querySelector( '.rincwc-cov-x' );
        const ys = ov.querySelector( '.rincwc-cov-y' );

        zoom.addEventListener( 'input', () => {
            preserveCenterScale( parseFloat( zoom.value ) );
            syncCropControls();
            updateCropBox();
        } );
        xs.addEventListener( 'input', () => {
            cropState.x = parseInt( xs.value, 10 ) || 0;
            syncCropControls();
            updateCropBox();
        } );
        ys.addEventListener( 'input', () => {
            cropState.y = parseInt( ys.value, 10 ) || 0;
            syncCropControls();
            updateCropBox();
        } );

        let dragging = false;
        let startClient = null;
        let startCrop = null;
        box.addEventListener( 'mousedown', e => {
            if ( ! cropState ) {
                return;
            }
            dragging = true;
            startClient = { x: e.clientX, y: e.clientY };
            startCrop = { x: cropState.x, y: cropState.y };
            e.preventDefault();
        } );
        document.addEventListener( 'mousemove', e => {
            if ( ! dragging || ! cropState ) {
                return;
            }
            panFromDelta( e.clientX - startClient.x, e.clientY - startClient.y, startCrop );
        } );
        document.addEventListener( 'mouseup', () => { dragging = false; } );

        wrap.addEventListener( 'wheel', e => {
            if ( ! cropState ) {
                return;
            }
            e.preventDefault();
            const rect = wrap.getBoundingClientRect();
            const disp = displayMetrics();
            if ( ! disp ) {
                return;
            }
            const cursorX = e.clientX - rect.left;
            const cursorY = e.clientY - rect.top;
            const fx = ( cursorX - cropState.x * disp.sx ) / ( cropState.scale * 3840 * disp.sx );
            const fy = ( cursorY - cropState.y * disp.sy ) / ( cropState.scale * 2160 * disp.sy );
            const delta = e.deltaY < 0 ? -0.08 : 0.08;
            const oldScale = cropState.scale;
            cropState.scale = clamp( cropState.scale + delta, 1, activeCropCfg.maxScale );
            if ( cropState.scale === oldScale ) {
                return;
            }
            const newBoxW = cropState.scale * 3840 * disp.sx;
            const newBoxH = cropState.scale * 2160 * disp.sy;
            cropState.x = Math.round( clamp( ( cursorX - fx * newBoxW ) / disp.sx, 0, maxX() ) );
            cropState.y = Math.round( clamp( ( cursorY - fy * newBoxH ) / disp.sy, 0, maxY() ) );
            syncCropControls();
            updateCropBox();
        }, { passive: false } );

        let touchStart = null;
        let touchCrop = null;
        wrap.addEventListener( 'touchstart', e => {
            if ( ! cropState || e.touches.length !== 1 ) {
                return;
            }
            const t = e.touches[0];
            touchStart = { x: t.clientX, y: t.clientY };
            touchCrop = { x: cropState.x, y: cropState.y };
        }, { passive: true } );
        wrap.addEventListener( 'touchmove', e => {
            if ( ! touchStart || ! cropState || e.touches.length !== 1 ) {
                return;
            }
            e.preventDefault();
            const t = e.touches[0];
            panFromDelta( t.clientX - touchStart.x, t.clientY - touchStart.y, touchCrop );
        }, { passive: false } );
        wrap.addEventListener( 'touchend', () => { touchStart = null; touchCrop = null; } );

        ov.querySelector( '.rincwc-cov-save' ).addEventListener( 'click', saveCustomCrop );
        ov.querySelector( '.rincwc-cov-reset' ).addEventListener( 'click', () => {
            cropState = initialCropState( activeCropCfg, true );
            syncCropControls();
            updateCropBox();
        } );
        ov.querySelector( '.rincwc-cov-close' ).addEventListener( 'click', closeCropOverlay );
        document.addEventListener( 'keydown', e => {
            if ( e.key === 'Escape' && ! ov.hidden ) {
                closeCropOverlay();
            }
        } );
        window.addEventListener( 'resize', () => {
            if ( ov.hidden ) {
                return;
            }
            requestAnimationFrame( () => {
                sizeWrap( img, wrap );
                syncCropControls();
                updateCropBox();
            } );
        } );
    }

    function openCropOverlay( card, cfg ) {
        activeCropCard = card;
        activeCropCfg = cfg;
        cropState = initialCropState( cfg, false );

        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const wrap = cropOverlayEl.querySelector( '.rincwc-cov-wrap' );
        cropOverlayEl.querySelector( '.rincwc-cov-title' ).textContent = cfg.title || cfg.fname;
        cropOverlayEl.hidden = false;
        document.body.style.overflow = 'hidden';

        const ready = () => requestAnimationFrame( () => {
            sizeWrap( img, wrap );
            syncCropControls();
            updateCropBox();
        } );

        if ( img.dataset.src !== cfg.scaledUrl ) {
            img.onload = () => { img.dataset.src = cfg.scaledUrl; ready(); };
            img.src = cfg.scaledUrl;
        } else if ( img.complete && img.naturalWidth ) {
            ready();
        } else {
            img.onload = ready;
        }
    }

    function closeCropOverlay() {
        cropOverlayEl.hidden = true;
        document.body.style.overflow = '';
        activeCropCard = null;
        activeCropCfg = null;
        cropState = null;
    }

    function initialCropState( cfg, reset ) {
        const scale = reset ? cfg.maxScale : clamp( parseFloat( cfg.customScale || cfg.maxScale || 1 ), 1, cfg.maxScale );
        const state = {
            scale,
            x: reset ? 0 : parseInt( cfg.customX || 0, 10 ),
            y: reset ? 0 : parseInt( cfg.customY || 0, 10 ),
        };
        state.x = Math.round( clamp( state.x, 0, maxXFor( cfg, state.scale ) ) );
        state.y = Math.round( clamp( state.y, 0, maxYFor( cfg, state.scale ) ) );
        return state;
    }

    function sizeWrap( img, wrap ) {
        if ( ! img.naturalWidth ) {
            return;
        }
        const preview = wrap.parentElement;
        const maxW = preview.clientWidth;
        const maxH = preview.clientHeight;
        const ar = img.naturalWidth / img.naturalHeight;
        let w;
        let h;
        if ( ar >= maxW / maxH ) {
            w = maxW;
            h = Math.round( maxW / ar );
        } else {
            h = maxH;
            w = Math.round( maxH * ar );
        }
        wrap.style.width = w + 'px';
        wrap.style.height = h + 'px';
    }

    function displayMetrics() {
        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const dispW = img.clientWidth;
        const dispH = img.clientHeight;
        if ( ! dispW || ! dispH || ! activeCropCfg ) {
            return null;
        }
        return { dispW, dispH, sx: dispW / activeCropCfg.origW, sy: dispH / activeCropCfg.origH };
    }

    function syncCropControls() {
        if ( ! cropState || ! activeCropCfg ) {
            return;
        }
        cropState.scale = clamp( cropState.scale, 1, activeCropCfg.maxScale );
        cropState.x = Math.round( clamp( cropState.x, 0, maxX() ) );
        cropState.y = Math.round( clamp( cropState.y, 0, maxY() ) );

        const zoom = cropOverlayEl.querySelector( '.rincwc-cov-zoom' );
        const xs = cropOverlayEl.querySelector( '.rincwc-cov-x' );
        const ys = cropOverlayEl.querySelector( '.rincwc-cov-y' );
        zoom.max = activeCropCfg.maxScale;
        zoom.value = cropState.scale;
        xs.max = maxX();
        ys.max = maxY();
        xs.value = cropState.x;
        ys.value = cropState.y;
        cropOverlayEl.querySelector( '.rincwc-cov-zoomval' ).textContent = cropState.scale.toFixed( 2 ) + 'x';
        cropOverlayEl.querySelector( '.rincwc-cov-xval' ).textContent = String( cropState.x );
        cropOverlayEl.querySelector( '.rincwc-cov-yval' ).textContent = String( cropState.y );
    }

    function updateCropBox() {
        const box = cropOverlayEl.querySelector( '.rincwc-cov-box' );
        const disp = displayMetrics();
        if ( ! disp || ! cropState ) {
            return;
        }
        box.style.width = Math.round( cropState.scale * 3840 * disp.sx ) + 'px';
        box.style.height = Math.round( cropState.scale * 2160 * disp.sy ) + 'px';
        box.style.left = Math.round( cropState.x * disp.sx ) + 'px';
        box.style.top = Math.round( cropState.y * disp.sy ) + 'px';
    }

    function preserveCenterScale( nextScale ) {
        const oldW = cropState.scale * 3840;
        const oldH = cropState.scale * 2160;
        const cx = cropState.x + oldW / 2;
        const cy = cropState.y + oldH / 2;
        cropState.scale = clamp( nextScale, 1, activeCropCfg.maxScale );
        cropState.x = Math.round( clamp( cx - cropState.scale * 3840 / 2, 0, maxX() ) );
        cropState.y = Math.round( clamp( cy - cropState.scale * 2160 / 2, 0, maxY() ) );
    }

    function panFromDelta( dx, dy, start ) {
        const disp = displayMetrics();
        if ( ! disp ) {
            return;
        }
        cropState.x = Math.round( clamp( start.x + dx / disp.sx, 0, maxX() ) );
        cropState.y = Math.round( clamp( start.y + dy / disp.sy, 0, maxY() ) );
        syncCropControls();
        updateCropBox();
    }

    function maxX() {
        return maxXFor( activeCropCfg, cropState.scale );
    }

    function maxY() {
        return maxYFor( activeCropCfg, cropState.scale );
    }

    function maxXFor( cfg, scale ) {
        return Math.max( 0, Math.round( cfg.origW - scale * 3840 ) );
    }

    function maxYFor( cfg, scale ) {
        return Math.max( 0, Math.round( cfg.origH - scale * 2160 ) );
    }

    function saveCustomCrop() {
        if ( ! activeCropCard || ! activeCropCfg || ! cropState ) {
            return;
        }
        const btn = cropOverlayEl.querySelector( '.rincwc-cov-save' );
        btn.disabled = true;
        api( 'crop-custom', 'POST', {
            gallery_id: activeCropCfg.gid,
            attach_id: activeCropCfg.aid,
            scale: cropState.scale,
            x: cropState.x,
            y: cropState.y,
        } ).then( r => {
            activeCropCfg.selCrop = 'custom';
            activeCropCfg.status = r.status || 'SELECTED';
            activeCropCfg.customScale = cropState.scale;
            activeCropCfg.customX = cropState.x;
            activeCropCfg.customY = cropState.y;
            activeCropCfg.wmApplied = false;
            activeCropCard.dataset.c = JSON.stringify( activeCropCfg );
            markSelected( activeCropCard, activeCropCfg, 'custom' );
            btn.disabled = false;
            closeCropOverlay();
        } ).catch( e => {
            console.error( 'crop-custom failed', e );
            btn.disabled = false;
        } );
    }

    function initCard( card ) {
        const cfg = JSON.parse( card.dataset.c || '{}' );
        const thumb = card.querySelector( '.rincwc-thumb' );
        if ( thumb ) {
            thumb.addEventListener( 'click', () => openLightbox( cfg.scaledUrl || thumb.src, cfg.title || cfg.fname ) );
        }

        card.querySelectorAll( '.rincwc-vbtn' ).forEach( btn => {
            if ( btn.classList.contains( 'rincwc-custbtn' ) ) {
                btn.addEventListener( 'click', () => openCropOverlay( card, cfg ) );
            } else {
                btn.addEventListener( 'click', () => selectVariant( card, cfg, btn.dataset.v ) );
            }
        } );

        const desel = card.querySelector( '.rincwc-desel' );
        if ( desel ) {
            desel.addEventListener( 'click', () => deselect( card, cfg ) );
        }

        const wmSel = card.querySelector( '.rincwc-wm-sel' );
        if ( wmSel ) {
            wmSel.addEventListener( 'change', () => setWatermark( card, cfg, wmSel.value ) );
        }

        const approve = card.querySelector( '.rincwc-approve-btn' );
        const unapprove = card.querySelector( '.rincwc-unapprove-btn' );
        if ( approve ) {
            approve.addEventListener( 'click', () => setApproval( card, cfg, true ) );
        }
        if ( unapprove ) {
            unapprove.addEventListener( 'click', () => setApproval( card, cfg, false ) );
        }

        initComments( card );
    }

    function selectVariant( card, cfg, variant ) {
        api( 'select', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid, selected_crop: variant } ).then( r => {
            cfg.selCrop = variant;
            cfg.status = r.status || 'SELECTED';
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            markSelected( card, cfg, variant );
        } ).catch( e => console.error( 'select failed', e ) );
    }

    function markSelected( card, cfg, variant ) {
        card.classList.add( 'is-selected' );
        card.classList.remove( 'status-candidate', 'status-approved' );
        card.classList.add( 'status-selected' );
        card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === variant ) );
        showWmRow( card, true );
        updateBadge( card, 'Selected', 'badge-sel' );
        setStatusLine( card, 'Selected' );
        ensureDeselectButton( card, cfg );
        updateApprovalButtons( card, cfg );
        updateCropLinks( card, cfg );
    }

    function deselect( card, cfg ) {
        api( 'deselect', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( r => {
            cfg.selCrop = '';
            cfg.status = r.status || 'CANDIDATE';
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            card.classList.remove( 'is-selected', 'status-selected', 'status-approved' );
            card.classList.add( 'status-candidate' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.remove( 'active' ) );
            showWmRow( card, false );
            updateBadge( card, null, null );
            setStatusLine( card, 'Candidate' );
            const desel = card.querySelector( '.rincwc-desel' );
            if ( desel ) {
                desel.remove();
            }
            const dl = card.querySelector( '.rincwc-crop-dl' );
            if ( dl ) {
                dl.innerHTML = '';
            }
            updateApprovalButtons( card, cfg );
        } ).catch( e => console.error( 'deselect failed', e ) );
    }

    function setWatermark( card, cfg, corner ) {
        api( 'watermark', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid, wm_corner: corner } ).then( () => {
            cfg.wmCorner = corner;
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            const st = card.querySelector( '.rincwc-wm-status' );
            if ( st ) {
                st.textContent = corner ? 'pending' : '';
                st.className = 'rincwc-wm-status' + ( corner ? ' wm-pending' : '' );
            }
            updateApprovalButtons( card, cfg );
        } ).catch( e => console.error( 'watermark failed', e ) );
    }

    function setApproval( card, cfg, approve ) {
        const path = approve ? 'approve' : 'unapprove';
        api( path, 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( r => {
            cfg.status = r.status || ( approve ? 'APPROVED' : 'SELECTED' );
            card.dataset.c = JSON.stringify( cfg );
            card.classList.toggle( 'status-approved', approve );
            card.classList.toggle( 'status-selected', ! approve );
            updateBadge( card, approve ? 'Approved' : 'Selected', approve ? 'badge-approved' : 'badge-sel' );
            setStatusLine( card, approve ? 'Approved' : 'Selected' );
            updateApprovalButtons( card, cfg );
        } ).catch( e => console.error( path + ' failed', e ) );
    }

    function showWmRow( card, visible ) {
        const row = card.querySelector( '.rincwc-wm-row' );
        if ( row ) {
            row.classList.toggle( 'hidden', ! visible );
        }
    }

    function updateBadge( card, text, cls ) {
        let badge = card.querySelector( '.rincwc-badge' );
        if ( ! text ) {
            if ( badge ) {
                badge.remove();
            }
            return;
        }
        if ( ! badge ) {
            badge = document.createElement( 'span' );
            card.querySelector( '.rincwc-thumb-wrap' ).appendChild( badge );
        }
        badge.className = 'rincwc-badge ' + cls;
        badge.textContent = text;
    }

    function setStatusLine( card, text ) {
        const line = card.querySelector( '.rincwc-status-line' );
        if ( line ) {
            line.textContent = text;
        }
    }

    function ensureDeselectButton( card, cfg ) {
        if ( card.querySelector( '.rincwc-desel' ) ) {
            return;
        }
        const desel = document.createElement( 'span' );
        desel.className = 'rincwc-desel';
        desel.title = 'Remove selection';
        desel.textContent = 'x';
        desel.addEventListener( 'click', () => deselect( card, cfg ) );
        card.querySelector( '.rincwc-variants' ).appendChild( desel );
    }

    function updateApprovalButtons( card, cfg ) {
        const approve = card.querySelector( '.rincwc-approve-btn' );
        const unapprove = card.querySelector( '.rincwc-unapprove-btn' );
        const approved = cfg.status === 'APPROVED';
        const disabled = ! rincwcCfg.canApprove || ! cfg.selCrop || ! cfg.wmCorner;
        if ( approve ) {
            approve.hidden = approved;
            approve.disabled = disabled;
        }
        if ( unapprove ) {
            unapprove.hidden = ! approved;
            unapprove.disabled = disabled;
        }
    }

    function updateCropLinks( card, cfg ) {
        let dl = card.querySelector( '.rincwc-crop-dl' );
        if ( ! dl ) {
            dl = document.createElement( 'div' );
            dl.className = 'rincwc-crop-dl';
            const comments = card.querySelector( '.rincwc-comments' );
            card.insertBefore( dl, comments );
        }
        dl.innerHTML = cfg.selCrop ? '<em>Crop files pending. Run Generate pending crops.</em>' : '';
    }

    function initBatchButtons() {
        const gc = document.getElementById( 'rincwc-generate-crops' );
        const awm = document.getElementById( 'rincwc-apply-wm' );
        const sync = document.getElementById( 'rincwc-sync-galleries' );
        const msg = document.getElementById( 'rincwc-batch-msg' );

        if ( gc ) {
            gc.addEventListener( 'click', () => {
                gc.disabled = true;
                msg.textContent = 'Generating crops...';
                api( 'generate-crops', 'POST' ).then( r => {
                    const ok = r.results.filter( x => x.status === 'ok' ).length;
                    const err = r.results.filter( x => x.status === 'error' ).length;
                    msg.textContent = `Done: ${ok} ok, ${err} errors.`;
                    gc.disabled = false;
                } ).catch( e => { msg.textContent = 'Error: ' + e.message; gc.disabled = false; } );
            } );
        }

        if ( awm ) {
            awm.addEventListener( 'click', () => {
                awm.disabled = true;
                msg.textContent = 'Applying watermarks...';
                api( 'apply-watermarks', 'POST' ).then( r => {
                    const ok = r.results.filter( x => x.status === 'ok' ).length;
                    msg.textContent = `Done: ${ok} watermarks applied.`;
                    awm.disabled = false;
                    document.querySelectorAll( '.rincwc-wm-status.wm-pending' ).forEach( el => {
                        el.textContent = 'applied';
                        el.classList.replace( 'wm-pending', 'wm-done' );
                    } );
                } ).catch( e => { msg.textContent = 'Error: ' + e.message; awm.disabled = false; } );
            } );
        }

        if ( sync ) {
            sync.addEventListener( 'click', () => {
                sync.disabled = true;
                msg.textContent = 'Publishing galleries...';
                api( 'sync-galleries', 'POST' ).then( r => {
                    msg.textContent = `Published: ${r.added || 0} added, ${r.skipped || 0} skipped, ${r.errors || 0} errors.`;
                    sync.disabled = false;
                } ).catch( e => { msg.textContent = 'Error: ' + e.message; sync.disabled = false; } );
            } );
        }
    }

    function initFilter() {
        const btn = document.getElementById( 'rincwc-filter-sel' );
        if ( ! btn ) {
            return;
        }
        btn.addEventListener( 'click', () => {
            applyFilter( btn.classList.toggle( 'is-active' ) );
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

    function initExpandCollapse() {
        const ea = document.getElementById( 'rincwc-expand-all' );
        const ca = document.getElementById( 'rincwc-collapse-all' );
        if ( ea ) {
            ea.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => { d.open = true; } ); } );
        }
        if ( ca ) {
            ca.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => { d.open = false; } ); } );
        }
    }

    function initComments( card ) {
        const box = card.querySelector( '.rincwc-comments' );
        if ( ! box ) {
            return;
        }
        const key = box.dataset.key;
        const ta = box.querySelector( '.rincwc-comment-ta' );
        const postBtn = box.querySelector( '.rincwc-post-btn' );

        if ( postBtn ) {
            postBtn.addEventListener( 'click', () => {
                const body = ta.value.trim();
                if ( ! body ) {
                    return;
                }
                postBtn.disabled = true;
                api( 'comments', 'POST', { image_key: key, body } ).then( c => {
                    box.insertBefore( buildComment( c ), box.querySelector( '.rincwc-add-comment' ) );
                    ta.value = '';
                    postBtn.disabled = false;
                } ).catch( e => { console.error( 'post comment failed', e ); postBtn.disabled = false; } );
            } );
        }

        box.addEventListener( 'click', e => {
            const el = e.target;
            const cDiv = el.closest( '.rincwc-comment' );
            if ( ! cDiv ) {
                return;
            }
            const cid = cDiv.dataset.cid;
            if ( el.classList.contains( 'rincwc-del-btn' ) ) {
                if ( ! confirm( 'Delete this comment?' ) ) {
                    return;
                }
                api( 'comments/' + cid, 'DELETE' ).then( r => { if ( r.ok ) { cDiv.remove(); } } );
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
                if ( ! newBody ) {
                    return;
                }
                el.disabled = true;
                api( 'comments/' + cid, 'PUT', { body: newBody } ).then( r => {
                    if ( r.ok ) {
                        const bodyEl = cDiv.querySelector( '.rincwc-comment-body' );
                        bodyEl.innerHTML = escNl( newBody );
                        cDiv.querySelector( '.rincwc-edit-ta' ).value = newBody;
                        bodyEl.hidden = false;
                        cDiv.querySelector( '.rincwc-edit-form' ).hidden = true;
                        cDiv.querySelector( '.rincwc-edit-btn' ).hidden = false;
                    }
                    el.disabled = false;
                } ).catch( () => { el.disabled = false; } );
            }
        } );
    }

    function buildComment( c ) {
        const uid = parseInt( rincwcCfg.userId, 10 );
        const isMe = c.user_id === uid || c.user_id === String( uid );
        const name = c.display_name || c.user_login || 'Unknown';
        const div = document.createElement( 'div' );
        div.className = 'rincwc-comment';
        div.dataset.cid = c.id;
        div.innerHTML =
            '<div class="rincwc-comment-meta"><strong>' + esc( name ) + '</strong> - ' + esc( c.created_at )
            + ( isMe ? ' <button class="rincwc-edit-btn button-link">Edit</button> <button class="rincwc-del-btn button-link">Delete</button>' : '' )
            + '</div><div class="rincwc-comment-body">' + escNl( c.body ) + '</div>'
            + ( isMe ? '<div class="rincwc-edit-form" hidden><textarea class="rincwc-edit-ta" rows="2">' + esc( c.body ) + '</textarea> <button class="button rincwc-save-edit-btn">Save</button> <button class="button-link rincwc-cancel-edit-btn">Cancel</button></div>' : '' );
        return div;
    }

    function clamp( value, min, max ) {
        return Math.max( min, Math.min( max, value ) );
    }

    function esc( s ) {
        return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    function escNl( s ) {
        return esc( s ).replace( /\n/g, '<br>' );
    }
})();
