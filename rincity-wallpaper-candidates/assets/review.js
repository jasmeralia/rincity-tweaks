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
        if ( data ) { opts.data = data; }
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
            if ( ! a ) return;
            e.preventDefault();
            openLightbox( a.href, a.textContent.trim() );
        } );
    } );

    function buildLightbox() {
        const lb = document.createElement( 'div' );
        lb.id = 'rincwc-lb';
        lb.innerHTML = '<button class="rincwc-lb-close" aria-label="Close">x</button><img class="rincwc-lb-img" src="" alt="">';
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
          +   '<label class="rincwc-cov-range-wrap">Zoom <input type="range" class="rincwc-cov-zoom" min="1" max="1" step="0.01" value="1"></label><span class="rincwc-cov-zoomval">1.00x</span>'
          +   '<label class="rincwc-cov-range-wrap">X <input type="range" class="rincwc-cov-x" min="0" max="0" step="1" value="0"></label><span class="rincwc-cov-xval">0</span>'
          +   '<label class="rincwc-cov-range-wrap">Y <input type="range" class="rincwc-cov-y" min="0" max="0" step="1" value="0"></label><span class="rincwc-cov-yval">0</span>'
          +   '<button class="button button-primary rincwc-cov-save">Save crop</button>'
          +   '<button class="button rincwc-cov-reset">Reset</button>'
          + '</div>';
        document.body.appendChild( ov );
        cropOverlayEl = ov;

        const img = ov.querySelector( '.rincwc-cov-img' );
        const box = ov.querySelector( '.rincwc-cov-box' );
        const wrap = ov.querySelector( '.rincwc-cov-wrap' );
        const zoom = ov.querySelector( '.rincwc-cov-zoom' );
        const xs = ov.querySelector( '.rincwc-cov-x' );
        const ys = ov.querySelector( '.rincwc-cov-y' );

        zoom.addEventListener( 'input', () => setScalePreserveCenter( parseFloat( zoom.value ) ) );
        xs.addEventListener( 'input', () => { cropState.x = parseInt( xs.value, 10 ) || 0; redrawCropControls(); updateCropBox(); } );
        ys.addEventListener( 'input', () => { cropState.y = parseInt( ys.value, 10 ) || 0; redrawCropControls(); updateCropBox(); } );

        let dragging = false;
        let startX = 0;
        let startY = 0;
        let startCropX = 0;
        let startCropY = 0;

        function beginDrag( clientX, clientY ) {
            if ( ! cropState ) return;
            dragging = true;
            startX = clientX;
            startY = clientY;
            startCropX = cropState.x;
            startCropY = cropState.y;
        }

        function moveDrag( clientX, clientY ) {
            if ( ! dragging || ! activeCropCfg ) return;
            const sx = img.clientWidth / activeCropCfg.origW;
            const sy = img.clientHeight / activeCropCfg.origH;
            cropState.x = startCropX + Math.round( ( clientX - startX ) / ( sx || 1 ) );
            cropState.y = startCropY + Math.round( ( clientY - startY ) / ( sy || 1 ) );
            clampCropState();
            redrawCropControls();
            updateCropBox();
        }

        box.addEventListener( 'mousedown', e => { beginDrag( e.clientX, e.clientY ); e.preventDefault(); } );
        document.addEventListener( 'mousemove', e => moveDrag( e.clientX, e.clientY ) );
        document.addEventListener( 'mouseup', () => { dragging = false; } );

        wrap.addEventListener( 'touchstart', e => {
            if ( e.touches.length !== 1 ) return;
            beginDrag( e.touches[0].clientX, e.touches[0].clientY );
        }, { passive: true } );
        wrap.addEventListener( 'touchmove', e => {
            if ( e.touches.length !== 1 ) return;
            e.preventDefault();
            moveDrag( e.touches[0].clientX, e.touches[0].clientY );
        }, { passive: false } );
        wrap.addEventListener( 'touchend', () => { dragging = false; } );

        wrap.addEventListener( 'wheel', e => {
            if ( ! cropState || ! activeCropCfg ) return;
            e.preventDefault();
            const rect = wrap.getBoundingClientRect();
            const cursorX = e.clientX - rect.left;
            const cursorY = e.clientY - rect.top;
            const sx = img.clientWidth / activeCropCfg.origW;
            const sy = img.clientHeight / activeCropCfg.origH;
            const oldBoxW = cropState.scale * 3840 * sx;
            const oldBoxH = cropState.scale * 2160 * sy;
            const oldDispX = cropState.x * sx;
            const oldDispY = cropState.y * sy;
            const fx = oldBoxW ? ( cursorX - oldDispX ) / oldBoxW : 0.5;
            const fy = oldBoxH ? ( cursorY - oldDispY ) / oldBoxH : 0.5;
            const dir = e.deltaY < 0 ? -1 : 1;
            cropState.scale = clamp( cropState.scale + dir * 0.08, 1, activeCropCfg.maxScale || 1 );
            const newBoxW = cropState.scale * 3840 * sx;
            const newBoxH = cropState.scale * 2160 * sy;
            cropState.x = Math.round( ( cursorX - fx * newBoxW ) / ( sx || 1 ) );
            cropState.y = Math.round( ( cursorY - fy * newBoxH ) / ( sy || 1 ) );
            clampCropState();
            redrawCropControls();
            updateCropBox();
        }, { passive: false } );

        ov.querySelector( '.rincwc-cov-save' ).addEventListener( 'click', () => {
            if ( ! activeCropCfg || ! activeCropCard || ! cropState ) return;
            const saveBtn = ov.querySelector( '.rincwc-cov-save' );
            saveBtn.disabled = true;
            api( 'crop-custom', 'POST', {
                gallery_id: activeCropCfg.gid,
                attach_id: activeCropCfg.aid,
                scale: cropState.scale,
                x: cropState.x,
                y: cropState.y,
            } ).then( () => {
                Object.assign( activeCropCfg, {
                    customScale: cropState.scale,
                    customX: cropState.x,
                    customY: cropState.y,
                    selCrop: 'custom',
                    status: 'SELECTED',
                    wmApplied: false,
                } );
                activeCropCard.dataset.c = JSON.stringify( activeCropCfg );
                activeCropCard.classList.add( 'is-selected', 'status-selected' );
                activeCropCard.classList.remove( 'status-candidate', 'is-approved', 'status-approved' );
                activeCropCard.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === 'custom' ) );
                showWmRow( activeCropCard, true );
                showApproveRow( activeCropCard, true );
                updateBadge( activeCropCard, activeCropCfg );
                ensureDeselect( activeCropCard, activeCropCfg );
                updateCropLinks( activeCropCard, activeCropCfg );
                saveBtn.disabled = false;
                closeCropOverlay();
            } ).catch( e => { console.error( 'crop-custom failed', e ); saveBtn.disabled = false; } );
        } );

        ov.querySelector( '.rincwc-cov-reset' ).addEventListener( 'click', () => {
            if ( ! activeCropCfg ) return;
            cropState = initialCropState( activeCropCfg, true );
            redrawCropControls();
            updateCropBox();
        } );

        ov.querySelector( '.rincwc-cov-close' ).addEventListener( 'click', closeCropOverlay );
        document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && ! ov.hidden ) closeCropOverlay(); } );
        window.addEventListener( 'resize', () => {
            if ( ov.hidden || ! activeCropCfg ) return;
            requestAnimationFrame( () => { sizeWrap(); redrawCropControls(); updateCropBox(); } );
        } );

        ov.hidden = true;
    }

    function openCropOverlay( card, cfg ) {
        activeCropCard = card;
        activeCropCfg = cfg;
        cropState = initialCropState( cfg, false );

        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        cropOverlayEl.querySelector( '.rincwc-cov-title' ).textContent = cfg.title || cfg.fname;
        cropOverlayEl.hidden = false;
        document.body.style.overflow = 'hidden';

        const loaded = () => requestAnimationFrame( () => { sizeWrap(); redrawCropControls(); updateCropBox(); } );
        if ( img.dataset.src !== cfg.scaledUrl ) {
            img.onload = () => { img.dataset.src = cfg.scaledUrl; loaded(); };
            img.src = cfg.scaledUrl;
        } else if ( img.complete && img.naturalWidth ) {
            loaded();
        } else {
            img.onload = loaded;
        }
    }

    function initialCropState( cfg, reset ) {
        const maxScale = Math.max( 1, parseFloat( cfg.maxScale ) || 1 );
        const scale = reset ? maxScale : clamp( parseFloat( cfg.customScale ) || maxScale, 1, maxScale );
        const boxW = Math.round( scale * 3840 );
        const boxH = Math.round( scale * 2160 );
        const maxX = Math.max( 0, cfg.origW - boxW );
        const maxY = Math.max( 0, cfg.origH - boxH );
        const x = reset ? Math.round( maxX / 2 ) : clamp( parseInt( cfg.customX, 10 ) || 0, 0, maxX );
        const y = reset ? Math.round( maxY / 2 ) : clamp( parseInt( cfg.customY, 10 ) || 0, 0, maxY );
        return { scale, x, y };
    }

    function closeCropOverlay() {
        cropOverlayEl.hidden = true;
        document.body.style.overflow = '';
        activeCropCard = null;
        activeCropCfg = null;
        cropState = null;
    }

    function sizeWrap() {
        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const wrap = cropOverlayEl.querySelector( '.rincwc-cov-wrap' );
        if ( ! img.naturalWidth ) return;
        const preview = wrap.parentElement;
        const maxW = preview.clientWidth;
        const maxH = preview.clientHeight;
        const ar = img.naturalWidth / img.naturalHeight;
        let w, h;
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

    function setScalePreserveCenter( nextScale ) {
        if ( ! cropState || ! activeCropCfg ) return;
        const oldW = cropState.scale * 3840;
        const oldH = cropState.scale * 2160;
        const cx = cropState.x + oldW / 2;
        const cy = cropState.y + oldH / 2;
        cropState.scale = clamp( nextScale, 1, activeCropCfg.maxScale || 1 );
        const newW = cropState.scale * 3840;
        const newH = cropState.scale * 2160;
        cropState.x = Math.round( cx - newW / 2 );
        cropState.y = Math.round( cy - newH / 2 );
        clampCropState();
        redrawCropControls();
        updateCropBox();
    }

    function clampCropState() {
        if ( ! cropState || ! activeCropCfg ) return;
        cropState.scale = clamp( cropState.scale, 1, activeCropCfg.maxScale || 1 );
        const boxW = Math.round( cropState.scale * 3840 );
        const boxH = Math.round( cropState.scale * 2160 );
        cropState.x = clamp( cropState.x, 0, Math.max( 0, activeCropCfg.origW - boxW ) );
        cropState.y = clamp( cropState.y, 0, Math.max( 0, activeCropCfg.origH - boxH ) );
    }

    function redrawCropControls() {
        if ( ! cropState || ! activeCropCfg ) return;
        const zoom = cropOverlayEl.querySelector( '.rincwc-cov-zoom' );
        const xs = cropOverlayEl.querySelector( '.rincwc-cov-x' );
        const ys = cropOverlayEl.querySelector( '.rincwc-cov-y' );
        const boxW = Math.round( cropState.scale * 3840 );
        const boxH = Math.round( cropState.scale * 2160 );
        zoom.max = activeCropCfg.maxScale || 1;
        zoom.value = cropState.scale;
        xs.max = Math.max( 0, activeCropCfg.origW - boxW );
        ys.max = Math.max( 0, activeCropCfg.origH - boxH );
        xs.value = cropState.x;
        ys.value = cropState.y;
        cropOverlayEl.querySelector( '.rincwc-cov-zoomval' ).textContent = cropState.scale.toFixed( 2 ) + 'x';
        cropOverlayEl.querySelector( '.rincwc-cov-xval' ).textContent = String( cropState.x );
        cropOverlayEl.querySelector( '.rincwc-cov-yval' ).textContent = String( cropState.y );
    }

    function updateCropBox() {
        if ( ! cropState || ! activeCropCfg ) return;
        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const box = cropOverlayEl.querySelector( '.rincwc-cov-box' );
        const sx = img.clientWidth / activeCropCfg.origW;
        const sy = img.clientHeight / activeCropCfg.origH;
        box.style.width = Math.round( cropState.scale * 3840 * sx ) + 'px';
        box.style.height = Math.round( cropState.scale * 2160 * sy ) + 'px';
        box.style.left = Math.round( cropState.x * sx ) + 'px';
        box.style.top = Math.round( cropState.y * sy ) + 'px';
    }

    function initCard( card ) {
        const cfg = JSON.parse( card.dataset.c || '{}' );
        const thumb = card.querySelector( '.rincwc-thumb' );
        if ( thumb ) thumb.addEventListener( 'click', () => openLightbox( cfg.scaledUrl || thumb.src, cfg.title || cfg.fname ) );

        card.querySelectorAll( '.rincwc-vbtn' ).forEach( btn => {
            if ( btn.classList.contains( 'rincwc-custbtn' ) ) {
                btn.addEventListener( 'click', () => openCropOverlay( card, cfg ) );
            } else {
                btn.addEventListener( 'click', () => selectVariant( card, cfg, btn ) );
            }
        } );

        const deselBtn = card.querySelector( '.rincwc-desel' );
        if ( deselBtn ) deselBtn.addEventListener( 'click', () => deselect( card, cfg ) );

        const wmSel = card.querySelector( '.rincwc-wm-sel' );
        if ( wmSel ) wmSel.addEventListener( 'change', () => setWatermark( card, cfg, wmSel.value ) );

        const approveBtn = card.querySelector( '.rincwc-approve-btn' );
        if ( approveBtn ) approveBtn.addEventListener( 'click', () => toggleApprove( card, cfg, approveBtn ) );

        initComments( card );
    }

    function selectVariant( card, cfg, btn ) {
        const variant = btn.dataset.v;
        api( 'select', 'POST', {
            gallery_id: cfg.gid,
            gallery_slug: cfg.gSlug,
            gallery_title: cfg.gTitle,
            attach_id: cfg.aid,
            position: cfg.pos,
            total: cfg.total,
            filename: cfg.fname,
            selected_crop: variant,
        } ).then( () => {
            cfg.selCrop = variant;
            cfg.status = 'SELECTED';
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            card.classList.add( 'is-selected', 'status-selected' );
            card.classList.remove( 'status-candidate', 'status-approved', 'is-approved' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === variant ) );
            showWmRow( card, true );
            showApproveRow( card, true );
            updateBadge( card, cfg );
            ensureDeselect( card, cfg );
            updateCropLinks( card, cfg );
        } ).catch( e => console.error( 'select failed', e ) );
    }

    function deselect( card, cfg ) {
        api( 'deselect', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( () => {
            cfg.selCrop = '';
            cfg.status = 'CANDIDATE';
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            card.classList.remove( 'is-selected', 'is-approved', 'status-selected', 'status-approved' );
            card.classList.add( 'status-candidate' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.remove( 'active' ) );
            showWmRow( card, false );
            showApproveRow( card, false );
            updateBadge( card, null );
            const desel = card.querySelector( '.rincwc-desel' );
            if ( desel ) desel.remove();
            const dl = card.querySelector( '.rincwc-crop-dl' );
            if ( dl ) dl.innerHTML = '';
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
            const approve = card.querySelector( '.rincwc-approve-btn' );
            if ( approve ) approve.disabled = ! approveAllowed || ! corner;
            updateBadge( card, cfg );
        } ).catch( e => console.error( 'watermark failed', e ) );
    }

    function toggleApprove( card, cfg, btn ) {
        const approved = btn.dataset.approved === '1';
        btn.disabled = true;
        api( approved ? 'unapprove' : 'approve', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( r => {
            if ( ! r.ok ) throw new Error( 'Approval rejected' );
            cfg.status = approved ? 'SELECTED' : 'APPROVED';
            card.dataset.c = JSON.stringify( cfg );
            card.classList.toggle( 'is-approved', ! approved );
            card.classList.toggle( 'status-approved', ! approved );
            card.classList.toggle( 'status-selected', approved );
            btn.dataset.approved = approved ? '0' : '1';
            btn.textContent = approved ? 'Approve' : 'Unapprove';
            btn.disabled = ! approveAllowed;
            updateBadge( card, cfg );
        } ).catch( e => { console.error( 'approval failed', e ); btn.disabled = ! approveAllowed; } );
    }

    function showWmRow( card, visible ) {
        const row = card.querySelector( '.rincwc-wm-row' );
        if ( row ) row.classList.toggle( 'hidden', ! visible );
    }

    function showApproveRow( card, visible ) {
        const row = card.querySelector( '.rincwc-approve-row' );
        if ( row ) row.classList.toggle( 'hidden', ! visible );
    }

    function updateBadge( card, cfg ) {
        let badge = card.querySelector( '.rincwc-badge' );
        if ( cfg === null ) { if ( badge ) badge.remove(); return; }
        if ( ! badge ) {
            badge = document.createElement( 'span' );
            card.querySelector( '.rincwc-thumb-wrap' ).appendChild( badge );
        }
        if ( cfg.status === 'APPROVED' && ! cfg.wmApplied ) {
            badge.className = 'rincwc-badge badge-pending';
            badge.textContent = 'Approved; WM pending';
        } else if ( cfg.status === 'APPROVED' ) {
            badge.className = 'rincwc-badge badge-approved';
            badge.textContent = 'Approved';
        } else if ( cfg.wmApplied ) {
            badge.className = 'rincwc-badge badge-wm';
            badge.textContent = 'WM applied';
        } else {
            badge.className = 'rincwc-badge badge-sel';
            badge.textContent = 'Selected';
        }
    }

    function ensureDeselect( card, cfg ) {
        if ( card.querySelector( '.rincwc-desel' ) ) return;
        const desel = document.createElement( 'span' );
        desel.className = 'rincwc-desel';
        desel.title = 'Remove selection';
        desel.textContent = 'x';
        desel.addEventListener( 'click', () => deselect( card, cfg ) );
        card.querySelector( '.rincwc-variants' ).appendChild( desel );
    }

    function updateCropLinks( card, cfg ) {
        let dl = card.querySelector( '.rincwc-crop-dl' );
        if ( ! dl ) {
            dl = document.createElement( 'div' );
            dl.className = 'rincwc-crop-dl';
            const comments = card.querySelector( '.rincwc-comments' );
            card.insertBefore( dl, comments );
        }
        dl.innerHTML = '<em>Crops pending - click Generate pending crops.</em>';
    }

    function initBatchButtons() {
        const gc = document.getElementById( 'rincwc-generate-crops' );
        const awm = document.getElementById( 'rincwc-apply-wm' );
        const sync = document.getElementById( 'rincwc-sync-galleries' );
        const msg = document.getElementById( 'rincwc-batch-msg' );

        if ( gc ) gc.addEventListener( 'click', () => {
            gc.disabled = true; msg.textContent = 'Generating crops...';
            api( 'generate-crops', 'POST' ).then( r => {
                const ok = r.results.filter( x => x.status === 'ok' ).length;
                const err = r.results.filter( x => x.status === 'error' ).length;
                msg.textContent = `Done: ${ok} ok, ${err} errors.`;
                gc.disabled = false;
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; gc.disabled = false; } );
        } );

        if ( awm ) awm.addEventListener( 'click', () => {
            awm.disabled = true; msg.textContent = 'Applying watermarks...';
            api( 'apply-watermarks', 'POST' ).then( r => {
                const ok = r.results.filter( x => x.status === 'ok' ).length;
                msg.textContent = `Done: ${ok} watermarks applied.`;
                awm.disabled = false;
                document.querySelectorAll( '.rincwc-wm-status.wm-pending' ).forEach( el => {
                    el.textContent = 'applied'; el.classList.replace( 'wm-pending', 'wm-done' );
                } );
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; awm.disabled = false; } );
        } );

        if ( sync ) sync.addEventListener( 'click', () => {
            sync.disabled = true; msg.textContent = 'Publishing galleries...';
            api( 'sync-galleries', 'POST' ).then( r => {
                const s = r.summary || {};
                msg.textContent = `Published: ${s.added || 0} added, ${s.skipped || 0} skipped, ${( s.errors || [] ).length} errors.`;
                sync.disabled = false;
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; sync.disabled = false; } );
        } );
    }

    function initFilter() {
        const btn = document.getElementById( 'rincwc-filter-sel' );
        if ( ! btn ) return;
        btn.addEventListener( 'click', () => applyFilter( btn.classList.toggle( 'is-active' ) ) );
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
        if ( ea ) ea.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => { d.open = true; } ); } );
        if ( ca ) ca.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => { d.open = false; } ); } );
    }

    function initComments( card ) {
        const box = card.querySelector( '.rincwc-comments' );
        if ( ! box ) return;
        const key = box.dataset.key;
        const ta = box.querySelector( '.rincwc-comment-ta' );
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
            const el = e.target;
            const cDiv = el.closest( '.rincwc-comment' );
            if ( ! cDiv ) return;
            const cid = cDiv.dataset.cid;

            if ( el.classList.contains( 'rincwc-del-btn' ) ) {
                if ( ! confirm( 'Delete this comment?' ) ) return;
                api( 'comments/' + cid, 'DELETE' ).then( r => { if ( r.ok ) cDiv.remove(); } ).catch( err => console.error( 'delete failed', err ) );
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
                        bodyEl.innerHTML = escNl( newBody );
                        cDiv.querySelector( '.rincwc-edit-ta' ).value = newBody;
                        bodyEl.hidden = false;
                        cDiv.querySelector( '.rincwc-edit-form' ).hidden = true;
                        cDiv.querySelector( '.rincwc-edit-btn' ).hidden = false;
                    }
                    el.disabled = false;
                } ).catch( err => { console.error( 'edit failed', err ); el.disabled = false; } );
            }
        } );
    }

    function buildComment( c ) {
        const uid = parseInt( rincwcCfg.userId, 10 );
        const isMe = c.user_id === uid || c.user_id === String( uid );
        const name = c.display_name || c.user_login || 'Unknown';
        const date = new Date( c.created_at ).toLocaleString( undefined, {
            month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit',
        } );
        const div = document.createElement( 'div' );
        div.className = 'rincwc-comment';
        div.dataset.cid = c.id;
        div.innerHTML =
            '<div class="rincwc-comment-meta"><strong>' + esc( name ) + '</strong> &middot; ' + esc( date )
            + ( isMe ? ' <button class="rincwc-edit-btn button-link">Edit</button> <button class="rincwc-del-btn button-link">Delete</button>' : '' )
            + '</div><div class="rincwc-comment-body">' + escNl( c.body ) + '</div>'
            + ( isMe ? '<div class="rincwc-edit-form" hidden><textarea class="rincwc-edit-ta" rows="2">' + esc( c.body ) + '</textarea> <button class="button rincwc-save-edit-btn">Save</button> <button class="button-link rincwc-cancel-edit-btn">Cancel</button></div>' : '' );
        return div;
    }

    function clamp( val, min, max ) {
        return Math.max( min, Math.min( max, val ) );
    }

    function esc( s ) {
        return String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );
    }

    function escNl( s ) {
        return esc( s ).replace( /\n/g, '<br>' );
    }
})();
