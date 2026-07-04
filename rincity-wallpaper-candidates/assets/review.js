/* global rincwcCfg, wp */
(function () {
    'use strict';

    wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( rincwcCfg.nonce ) );

    const base = rincwcCfg.restBase;
    const approveAllowed = !! ( rincwcCfg.approveAllowed || rincwcCfg.canApprove );

    function api( path, method, data ) {
        const opts = { url: base + path, method: method || 'GET' };
        if ( data ) { opts.data = data; }
        return wp.apiFetch( opts );
    }

    let activeCropCard = null;
    let activeCropCfg = null;
    let activeCrop = null;
    let cropOverlayEl = null;
    let lightboxEl = null;
    let lightboxImg = null;

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
          +   '<button class="rincwc-cov-close button-link">&#x2715; Close</button>'
          + '</div>'
          + '<div class="rincwc-cov-preview">'
          +   '<div class="rincwc-cov-wrap">'
          +     '<img class="rincwc-cov-img" src="" alt="">'
          +     '<div class="rincwc-cov-box"></div>'
          +   '</div>'
          + '</div>'
          + '<div class="rincwc-cov-ctrl">'
          +   '<label class="rincwc-cov-range-wrap">Zoom <input type="range" class="rincwc-cov-scale" min="1" max="1" step="0.01" value="1"></label>'
          +   '<span class="rincwc-cov-scaleval">1.00x</span>'
          +   '<label class="rincwc-cov-range-wrap">X <input type="range" class="rincwc-cov-x" min="0" max="0" step="1" value="0"></label>'
          +   '<span class="rincwc-cov-xval">0</span>'
          +   '<label class="rincwc-cov-range-wrap">Y <input type="range" class="rincwc-cov-y" min="0" max="0" step="1" value="0"></label>'
          +   '<span class="rincwc-cov-yval">0</span>'
          +   '<button class="button button-primary rincwc-cov-save">Save crop</button>'
          +   '<button class="button rincwc-cov-reset">Reset</button>'
          + '</div>';
        document.body.appendChild( ov );
        cropOverlayEl = ov;

        const img = ov.querySelector( '.rincwc-cov-img' );
        const box = ov.querySelector( '.rincwc-cov-box' );
        const wrap = ov.querySelector( '.rincwc-cov-wrap' );
        const preview = ov.querySelector( '.rincwc-cov-preview' );
        const scale = ov.querySelector( '.rincwc-cov-scale' );
        const x = ov.querySelector( '.rincwc-cov-x' );
        const y = ov.querySelector( '.rincwc-cov-y' );

        scale.addEventListener( 'input', () => {
            const center = cropCenter();
            setCrop( { scale: parseFloat( scale.value ) }, center );
        } );
        x.addEventListener( 'input', () => setCrop( { x: parseInt( x.value, 10 ) || 0 } ) );
        y.addEventListener( 'input', () => setCrop( { y: parseInt( y.value, 10 ) || 0 } ) );

        let dragging = false;
        let touchId = null;
        let start = null;

        const startPan = ( clientX, clientY, id ) => {
            if ( ! activeCropCfg ) return;
            dragging = true;
            touchId = id;
            start = { clientX, clientY, x: activeCrop.x, y: activeCrop.y };
        };
        const movePan = ( clientX, clientY ) => {
            if ( ! dragging || ! start ) return;
            const sx = img.clientWidth / activeCropCfg.origW;
            const sy = img.clientHeight / activeCropCfg.origH;
            setCrop( {
                x: Math.round( start.x + ( clientX - start.clientX ) / sx ),
                y: Math.round( start.y + ( clientY - start.clientY ) / sy ),
            } );
        };

        box.addEventListener( 'mousedown', e => {
            startPan( e.clientX, e.clientY, null );
            e.preventDefault();
        } );
        document.addEventListener( 'mousemove', e => movePan( e.clientX, e.clientY ) );
        document.addEventListener( 'mouseup', () => { dragging = false; start = null; touchId = null; } );

        preview.addEventListener( 'wheel', e => {
            if ( ! activeCropCfg ) return;
            e.preventDefault();
            const rect = img.getBoundingClientRect();
            const cursorX = e.clientX - rect.left;
            const cursorY = e.clientY - rect.top;
            const sx = img.clientWidth / activeCropCfg.origW;
            const sy = img.clientHeight / activeCropCfg.origH;
            const oldBoxW = activeCrop.scale * 3840;
            const oldBoxH = activeCrop.scale * 2160;
            const fx = ( cursorX / sx - activeCrop.x ) / oldBoxW;
            const fy = ( cursorY / sy - activeCrop.y ) / oldBoxH;
            const nextScale = activeCrop.scale + ( e.deltaY > 0 ? 0.08 : -0.08 );
            const nextBoxW = nextScale * 3840;
            const nextBoxH = nextScale * 2160;
            setCrop( {
                scale: nextScale,
                x: Math.round( cursorX / sx - fx * nextBoxW ),
                y: Math.round( cursorY / sy - fy * nextBoxH ),
            } );
        }, { passive: false } );

        preview.addEventListener( 'touchstart', e => {
            if ( e.touches.length !== 1 ) return;
            const t = e.touches[0];
            startPan( t.clientX, t.clientY, t.identifier );
        }, { passive: true } );
        preview.addEventListener( 'touchmove', e => {
            if ( touchId === null ) return;
            const t = Array.from( e.touches ).find( item => item.identifier === touchId );
            if ( ! t ) return;
            e.preventDefault();
            movePan( t.clientX, t.clientY );
        }, { passive: false } );
        preview.addEventListener( 'touchend', () => { dragging = false; start = null; touchId = null; } );

        ov.querySelector( '.rincwc-cov-reset' ).addEventListener( 'click', () => {
            const scaleValue = activeCropCfg.maxScale || 1;
            const bounds = cropBounds( scaleValue );
            setCrop( {
                scale: scaleValue,
                x: Math.round( bounds.maxX / 2 ),
                y: Math.round( bounds.maxY / 2 ),
            } );
        } );

        ov.querySelector( '.rincwc-cov-save' ).addEventListener( 'click', () => {
            if ( ! activeCropCfg || ! activeCropCard ) return;
            const cfg = activeCropCfg;
            const saveBtn = ov.querySelector( '.rincwc-cov-save' );
            saveBtn.disabled = true;
            api( 'crop-custom', 'POST', {
                gallery_id: cfg.gid,
                attach_id: cfg.aid,
                scale: activeCrop.scale,
                x: activeCrop.x,
                y: activeCrop.y,
            } ).then( () => {
                cfg.customScale = activeCrop.scale;
                cfg.customX = activeCrop.x;
                cfg.customY = activeCrop.y;
                cfg.selCrop = 'custom';
                cfg.status = 'SELECTED';
                activeCropCard.dataset.c = JSON.stringify( cfg );
                activeCropCard.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === 'custom' ) );
                activeCropCard.classList.add( 'is-selected' );
                showWmRow( activeCropCard, true );
                showApprovalRow( activeCropCard, true );
                updateBadge( activeCropCard, false );
                updateCropLinks( activeCropCard, cfg );
                ensureDeselect( activeCropCard, cfg );
                saveBtn.disabled = false;
                closeCropOverlay();
            } ).catch( e => { console.error( 'crop-custom failed', e ); saveBtn.disabled = false; } );
        } );

        ov.querySelector( '.rincwc-cov-close' ).addEventListener( 'click', closeCropOverlay );
        document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && ! ov.hidden ) closeCropOverlay(); } );
        window.addEventListener( 'resize', () => {
            if ( ov.hidden || ! activeCropCfg ) return;
            requestAnimationFrame( () => {
                sizeWrap( img, wrap );
                renderCropBox();
            } );
        } );

        ov.hidden = true;
    }

    function openCropOverlay( card, cfg ) {
        activeCropCard = card;
        activeCropCfg = cfg;
        activeCrop = {
            scale: parseFloat( cfg.customScale || cfg.maxScale || 1 ),
            x: parseInt( cfg.customX || 0, 10 ),
            y: parseInt( cfg.customY || 0, 10 ),
        };
        activeCrop = clampCrop( activeCrop );

        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const wrap = cropOverlayEl.querySelector( '.rincwc-cov-wrap' );
        cropOverlayEl.querySelector( '.rincwc-cov-title' ).textContent = cfg.title || cfg.fname;
        cropOverlayEl.hidden = false;
        document.body.style.overflow = 'hidden';

        const doUpdate = () => requestAnimationFrame( () => {
            sizeWrap( img, wrap );
            syncSliders();
            renderCropBox();
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
        cropOverlayEl.hidden = true;
        document.body.style.overflow = '';
        activeCropCard = null;
        activeCropCfg = null;
        activeCrop = null;
    }

    function sizeWrap( img, wrap ) {
        if ( ! img.naturalWidth ) return;
        const preview = wrap.parentElement;
        const maxW = preview.clientWidth;
        const maxH = preview.clientHeight;
        const ar = img.naturalWidth / img.naturalHeight;
        let w, h;
        if ( ar >= maxW / maxH ) {
            w = maxW; h = Math.round( maxW / ar );
        } else {
            h = maxH; w = Math.round( maxH * ar );
        }
        wrap.style.width = w + 'px';
        wrap.style.height = h + 'px';
    }

    function cropBounds( scale ) {
        const boxW = Math.round( scale * 3840 );
        const boxH = Math.round( scale * 2160 );
        return {
            boxW,
            boxH,
            maxX: Math.max( 0, activeCropCfg.origW - boxW ),
            maxY: Math.max( 0, activeCropCfg.origH - boxH ),
        };
    }

    function clampCrop( crop ) {
        const min = parseFloat( activeCropCfg.minScale || 1 );
        const max = Math.max( min, parseFloat( activeCropCfg.maxScale || min ) );
        const scale = Math.max( min, Math.min( max, parseFloat( crop.scale || min ) ) );
        const bounds = cropBounds( scale );
        return {
            scale,
            x: Math.max( 0, Math.min( bounds.maxX, parseInt( crop.x || 0, 10 ) ) ),
            y: Math.max( 0, Math.min( bounds.maxY, parseInt( crop.y || 0, 10 ) ) ),
        };
    }

    function cropCenter() {
        if ( ! activeCrop ) return null;
        const bounds = cropBounds( activeCrop.scale );
        return {
            x: activeCrop.x + bounds.boxW / 2,
            y: activeCrop.y + bounds.boxH / 2,
        };
    }

    function setCrop( next, center ) {
        const merged = Object.assign( {}, activeCrop, next );
        if ( center && next.scale !== undefined && next.x === undefined && next.y === undefined ) {
            const bounds = cropBounds( parseFloat( next.scale ) );
            merged.x = Math.round( center.x - bounds.boxW / 2 );
            merged.y = Math.round( center.y - bounds.boxH / 2 );
        }
        activeCrop = clampCrop( merged );
        syncSliders();
        renderCropBox();
    }

    function syncSliders() {
        const scale = cropOverlayEl.querySelector( '.rincwc-cov-scale' );
        const x = cropOverlayEl.querySelector( '.rincwc-cov-x' );
        const y = cropOverlayEl.querySelector( '.rincwc-cov-y' );
        const bounds = cropBounds( activeCrop.scale );
        scale.min = activeCropCfg.minScale || 1;
        scale.max = activeCropCfg.maxScale || 1;
        scale.value = activeCrop.scale;
        x.max = bounds.maxX; x.value = activeCrop.x; x.disabled = bounds.maxX === 0;
        y.max = bounds.maxY; y.value = activeCrop.y; y.disabled = bounds.maxY === 0;
        cropOverlayEl.querySelector( '.rincwc-cov-scaleval' ).textContent = activeCrop.scale.toFixed( 2 ) + 'x';
        cropOverlayEl.querySelector( '.rincwc-cov-xval' ).textContent = activeCrop.x;
        cropOverlayEl.querySelector( '.rincwc-cov-yval' ).textContent = activeCrop.y;
    }

    function renderCropBox() {
        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const box = cropOverlayEl.querySelector( '.rincwc-cov-box' );
        if ( ! img.clientWidth || ! activeCrop ) return;
        const sx = img.clientWidth / activeCropCfg.origW;
        const sy = img.clientHeight / activeCropCfg.origH;
        const bounds = cropBounds( activeCrop.scale );
        box.style.width = Math.round( bounds.boxW * sx ) + 'px';
        box.style.height = Math.round( bounds.boxH * sy ) + 'px';
        box.style.left = Math.round( activeCrop.x * sx ) + 'px';
        box.style.top = Math.round( activeCrop.y * sy ) + 'px';
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
            attach_id: cfg.aid,
            selected_crop: variant,
        } ).then( () => {
            card.classList.add( 'is-selected' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === variant ) );
            cfg.selCrop = variant;
            cfg.status = 'SELECTED';
            card.dataset.c = JSON.stringify( cfg );
            showWmRow( card, true );
            showApprovalRow( card, true );
            updateBadge( card, false );
            updateCropLinks( card, cfg );
            ensureDeselect( card, cfg );
        } ).catch( e => console.error( 'select failed', e ) );
    }

    function deselect( card, cfg ) {
        api( 'deselect', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( () => {
            card.classList.remove( 'is-selected', 'is-approved' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.remove( 'active' ) );
            cfg.selCrop = '';
            cfg.status = 'CANDIDATE';
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
            const approveBtn = card.querySelector( '.rincwc-approve-btn' );
            if ( approveBtn && approveBtn.dataset.approved !== '1' ) {
                approveBtn.disabled = ! ( approveAllowed && cfg.selCrop && corner );
            }
        } ).catch( e => console.error( 'watermark failed', e ) );
    }

    function toggleApprove( card, cfg, btn ) {
        if ( btn.disabled ) return;
        const approved = btn.dataset.approved === '1';
        btn.disabled = true;
        api( approved ? 'unapprove' : 'approve', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( r => {
            if ( ! r.ok ) { btn.disabled = false; return; }
            const nowApproved = ! approved;
            btn.dataset.approved = nowApproved ? '1' : '0';
            btn.textContent = nowApproved ? 'Unapprove' : 'Approve';
            card.classList.toggle( 'is-approved', nowApproved );
            cfg.status = nowApproved ? 'APPROVED' : 'SELECTED';
            card.dataset.c = JSON.stringify( cfg );
            updateBadge( card, nowApproved ? 'approved' : false );
            btn.disabled = ! approveAllowed;
        } ).catch( e => { console.error( 'approve toggle failed', e ); btn.disabled = false; } );
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
        badge.textContent = wmApplied ? 'WM ready' : 'Selected';
    }

    function updateCropLinks( card, cfg ) {
        let dl = card.querySelector( '.rincwc-crop-dl' );
        if ( ! dl ) { dl = document.createElement( 'div' ); dl.className = 'rincwc-crop-dl'; card.appendChild( dl ); }
        dl.innerHTML = cfg.selCrop === 'custom' ? '<em>Custom crop generated. Reapply watermark if needed.</em>' : '';
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
                const counts = r.results || {};
                msg.textContent = `Published: ${counts.added || 0} added, ${counts.skipped || 0} skipped, ${counts.errors || 0} errors.`;
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
        if ( ea ) ea.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => d.open = true ); } );
        if ( ca ) ca.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => d.open = false ); } );
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
                api( 'comments/' + cid, 'DELETE' ).then( r => { if ( r.ok ) cDiv.remove(); } )
                    .catch( err => console.error( 'delete failed', err ) );
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
        div.className = 'rincwc-comment'; div.dataset.cid = c.id;
        div.innerHTML =
            '<div class="rincwc-comment-meta"><strong>' + esc( name ) + '</strong> - ' + esc( date )
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
