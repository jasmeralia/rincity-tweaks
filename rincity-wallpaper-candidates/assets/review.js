/* global rincwcCfg, wp */
(function () {
    'use strict';

    wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( rincwcCfg.nonce ) );

    const base = rincwcCfg.restBase;
    const TARGET_W = 3840;
    const TARGET_H = 2160;

    let activeCropCard = null;
    let activeCropCfg = null;
    let cropState = null;
    let cropOverlayEl = null;
    let lightboxEl = null;
    let lightboxImg = null;
    let compareEl = null;

    function api( path, method, data ) {
        const opts = { url: base + path, method: method || 'GET' };
        if ( data ) opts.data = data;
        return wp.apiFetch( opts );
    }

    document.addEventListener( 'DOMContentLoaded', () => {
        buildLightbox();
        buildCompareOverlay();
        buildCropOverlay();
        document.querySelectorAll( '.rincwc-card' ).forEach( initCard );
        initBatchButtons();
        initExpandCollapse();
        initFilter();
        initCutoffButtons();
        initBackToTop();

        document.addEventListener( 'click', e => {
            const a = e.target.closest( '.rincwc-crop-dl a' );
            if ( ! a ) return;
            e.preventDefault();
            // Resolution-variant download links — no set navigation, just the one image.
            openLightbox( a.href, a.textContent.trim(), null );
        } );

        document.addEventListener( 'click', e => {
            const link = e.target.closest( '.rincwc-view-original' );
            if ( ! link ) return;
            e.preventDefault();
            // The true original, standalone — not part of the set's arrow navigation.
            openLightbox( link.dataset.url, link.dataset.alt || '', null );
        } );

        document.addEventListener( 'click', e => {
            const link = e.target.closest( '.rincwc-compare-link' );
            if ( ! link ) return;
            e.preventDefault();
            openCompare( link.dataset.original, link.dataset.selection );
        } );
    } );

    let lightboxContext = null;

    function galleryImagesFor( card ) {
        const gallery = card.closest( '.rincwc-gallery' );
        if ( ! gallery ) return [];
        return Array.from( gallery.querySelectorAll( '.rincwc-card' ) )
            .map( c => {
                try {
                    const c2 = JSON.parse( c.dataset.c || '{}' );
                    return { url: c2.scaledUrl || '', alt: c2.title || c2.fname || '', imageId: c2.imageId };
                } catch ( _ ) {
                    return null;
                }
            } )
            .filter( item => item && item.url );
    }

    function buildLightbox() {
        const lb = document.createElement( 'div' );
        lb.id = 'rincwc-lb';
        lb.innerHTML = '<button class="rincwc-lb-close" aria-label="Close">x</button>'
            + '<button class="rincwc-lb-prev" aria-label="Previous">‹</button>'
            + '<img class="rincwc-lb-img" src="" alt="">'
            + '<button class="rincwc-lb-next" aria-label="Next">›</button>';
        document.body.appendChild( lb );
        lightboxEl = lb;
        lightboxImg = lb.querySelector( '.rincwc-lb-img' );
        const prevBtn = lb.querySelector( '.rincwc-lb-prev' );
        const nextBtn = lb.querySelector( '.rincwc-lb-next' );

        const close = () => { lb.hidden = true; lightboxContext = null; };
        lb.querySelector( '.rincwc-lb-close' ).addEventListener( 'click', e => { e.stopPropagation(); close(); } );
        lb.addEventListener( 'click', close );
        lightboxImg.addEventListener( 'click', e => e.stopPropagation() );
        prevBtn.addEventListener( 'click', e => { e.stopPropagation(); showLightboxAt( lightboxContext.index - 1 ); } );
        nextBtn.addEventListener( 'click', e => { e.stopPropagation(); showLightboxAt( lightboxContext.index + 1 ); } );
        document.addEventListener( 'keydown', e => {
            if ( lb.hidden ) return;
            if ( e.key === 'Escape' ) { close(); return; }
            if ( ! lightboxContext ) return;
            if ( e.key === 'ArrowLeft' ) { showLightboxAt( lightboxContext.index - 1 ); }
            else if ( e.key === 'ArrowRight' ) { showLightboxAt( lightboxContext.index + 1 ); }
        } );
        lb.hidden = true;
    }

    function showLightboxAt( index ) {
        if ( ! lightboxContext || ! lightboxContext.images.length ) return;
        const n = lightboxContext.images.length;
        const i = ( ( index % n ) + n ) % n;
        lightboxContext.index = i;
        const img = lightboxContext.images[ i ];
        lightboxImg.src = img.url;
        lightboxImg.alt = img.alt;
    }

    function openLightbox( url, alt, context ) {
        if ( ! lightboxEl ) return;
        lightboxImg.src = url;
        lightboxImg.alt = alt || '';
        lightboxEl.hidden = false;
        lightboxContext = ( context && context.images && context.images.length > 1 ) ? context : null;
        lightboxEl.classList.toggle( 'has-nav', !! lightboxContext );
    }

    function buildCompareOverlay() {
        const cmp = document.createElement( 'div' );
        cmp.id = 'rincwc-compare';
        cmp.innerHTML = '<button class="rincwc-cmp-close" aria-label="Close">x</button>'
            + '<div class="rincwc-cmp-pane">'
            +   '<div class="rincwc-cmp-label">Original</div>'
            +   '<img class="rincwc-cmp-img rincwc-cmp-original" src="" alt="Original">'
            + '</div>'
            + '<div class="rincwc-cmp-pane">'
            +   '<div class="rincwc-cmp-label">Selection</div>'
            +   '<img class="rincwc-cmp-img rincwc-cmp-selection" src="" alt="Selection">'
            + '</div>';
        document.body.appendChild( cmp );
        compareEl = cmp;

        const close = () => { cmp.hidden = true; };
        cmp.querySelector( '.rincwc-cmp-close' ).addEventListener( 'click', e => { e.stopPropagation(); close(); } );
        cmp.addEventListener( 'click', close );
        cmp.querySelectorAll( '.rincwc-cmp-img' ).forEach( img => img.addEventListener( 'click', e => e.stopPropagation() ) );
        document.addEventListener( 'keydown', e => { if ( e.key === 'Escape' && ! cmp.hidden ) close(); } );
        cmp.hidden = true;
    }

    function openCompare( originalUrl, selectionUrl ) {
        if ( ! compareEl || ! originalUrl || ! selectionUrl ) return;
        compareEl.querySelector( '.rincwc-cmp-original' ).src = originalUrl;
        compareEl.querySelector( '.rincwc-cmp-selection' ).src = selectionUrl;
        compareEl.hidden = false;
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
          +   '<label class="rincwc-cov-range-wrap">Zoom'
          +     '<input type="range" class="rincwc-cov-scale" min="1" max="1" step="0.01" value="1">'
          +     '<span class="rincwc-cov-scaleval">1.00x</span>'
          +   '</label>'
          +   '<label class="rincwc-cov-range-wrap">X'
          +     '<input type="range" class="rincwc-cov-x" min="0" max="0" step="1" value="0">'
          +     '<span class="rincwc-cov-xval">0</span>'
          +   '</label>'
          +   '<label class="rincwc-cov-range-wrap">Y'
          +     '<input type="range" class="rincwc-cov-y" min="0" max="0" step="1" value="0">'
          +     '<span class="rincwc-cov-yval">0</span>'
          +   '</label>'
          +   '<button class="button button-primary rincwc-cov-save">Save crop</button>'
          +   '<button class="button rincwc-cov-reset">Reset</button>'
          + '</div>';
        document.body.appendChild( ov );
        cropOverlayEl = ov;

        const img = ov.querySelector( '.rincwc-cov-img' );
        const box = ov.querySelector( '.rincwc-cov-box' );
        const wrap = ov.querySelector( '.rincwc-cov-wrap' );
        const scaleSlider = ov.querySelector( '.rincwc-cov-scale' );
        const xSlider = ov.querySelector( '.rincwc-cov-x' );
        const ySlider = ov.querySelector( '.rincwc-cov-y' );

        scaleSlider.addEventListener( 'input', () => {
            if ( ! activeCropCfg || ! cropState ) return;
            const center = currentCenter();
            setScale( parseFloat( scaleSlider.value ), center );
            renderCropControls();
            renderCropBox();
        } );
        xSlider.addEventListener( 'input', () => {
            cropState.x = clamp( parseInt( xSlider.value, 10 ) || 0, 0, maxX() );
            renderCropControls();
            renderCropBox();
        } );
        ySlider.addEventListener( 'input', () => {
            cropState.y = clamp( parseInt( ySlider.value, 10 ) || 0, 0, maxY() );
            renderCropControls();
            renderCropBox();
        } );

        let dragging = false;
        let dragStart = null;
        const startDrag = ( clientX, clientY ) => {
            if ( ! activeCropCfg || ! cropState ) return;
            dragging = true;
            const scales = displayScales();
            dragStart = {
                clientX,
                clientY,
                x: cropState.x,
                y: cropState.y,
                sx: scales.sx || 1,
                sy: scales.sy || 1,
            };
        };
        const moveDrag = ( clientX, clientY ) => {
            if ( ! dragging || ! dragStart || ! cropState ) return;
            cropState.x = clamp( Math.round( dragStart.x + ( clientX - dragStart.clientX ) / dragStart.sx ), 0, maxX() );
            cropState.y = clamp( Math.round( dragStart.y + ( clientY - dragStart.clientY ) / dragStart.sy ), 0, maxY() );
            renderCropControls();
            renderCropBox();
        };
        const endDrag = () => { dragging = false; dragStart = null; };

        box.addEventListener( 'mousedown', e => {
            startDrag( e.clientX, e.clientY );
            e.preventDefault();
        } );
        document.addEventListener( 'mousemove', e => moveDrag( e.clientX, e.clientY ) );
        document.addEventListener( 'mouseup', endDrag );

        wrap.addEventListener( 'wheel', e => {
            if ( ! activeCropCfg || ! cropState ) return;
            e.preventDefault();
            const rect = wrap.getBoundingClientRect();
            const sx = rect.width / activeCropCfg.origW;
            const sy = rect.height / activeCropCfg.origH;
            const cx = e.clientX - rect.left;
            const cy = e.clientY - rect.top;
            const boxW = cropState.scale * TARGET_W * sx;
            const boxH = cropState.scale * TARGET_H * sy;
            const fx = boxW ? ( cx - cropState.x * sx ) / boxW : 0.5;
            const fy = boxH ? ( cy - cropState.y * sy ) / boxH : 0.5;
            const step = Math.max( 0.02, activeCropCfg.maxScale / 40 );
            const nextScale = cropState.scale + ( e.deltaY > 0 ? step : -step );
            const sourceX = cx / sx;
            const sourceY = cy / sy;
            setScale( nextScale, {
                x: sourceX - fx * clamp( nextScale, 1, activeCropCfg.maxScale ) * TARGET_W,
                y: sourceY - fy * clamp( nextScale, 1, activeCropCfg.maxScale ) * TARGET_H,
            }, true );
            renderCropControls();
            renderCropBox();
        }, { passive: false } );

        wrap.addEventListener( 'touchstart', e => {
            if ( e.touches.length !== 1 ) return;
            startDrag( e.touches[0].clientX, e.touches[0].clientY );
        }, { passive: true } );
        wrap.addEventListener( 'touchmove', e => {
            if ( e.touches.length !== 1 ) return;
            e.preventDefault();
            moveDrag( e.touches[0].clientX, e.touches[0].clientY );
        }, { passive: false } );
        wrap.addEventListener( 'touchend', endDrag );
        wrap.addEventListener( 'touchcancel', endDrag );

        ov.querySelector( '.rincwc-cov-save' ).addEventListener( 'click', saveCustomCrop );
        ov.querySelector( '.rincwc-cov-reset' ).addEventListener( 'click', () => {
            cropState = defaultCropState( activeCropCfg );
            renderCropControls();
            renderCropBox();
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
        if ( ! cropOverlayEl ) return;
        activeCropCard = card;
        activeCropCfg = cfg;
        cropState = normalizeCropState( cfg );

        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const wrap = cropOverlayEl.querySelector( '.rincwc-cov-wrap' );
        cropOverlayEl.querySelector( '.rincwc-cov-title' ).textContent = cfg.title || cfg.fname;

        cropOverlayEl.hidden = false;
        document.body.style.overflow = 'hidden';
        renderCropControls();

        const doUpdate = () => requestAnimationFrame( () => {
            sizeWrap( img, wrap );
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
        if ( cropOverlayEl ) cropOverlayEl.hidden = true;
        document.body.style.overflow = '';
        activeCropCard = null;
        activeCropCfg = null;
        cropState = null;
    }

    function saveCustomCrop() {
        if ( ! activeCropCfg || ! activeCropCard || ! cropState ) return;
        const cfg = activeCropCfg;
        const card = activeCropCard;
        const saveBtn = cropOverlayEl.querySelector( '.rincwc-cov-save' );
        saveBtn.disabled = true;
        api( 'crop-custom', 'POST', {
            image_id: cfg.imageId,
            gallery_id: cfg.gid,
            attach_id: cfg.aid,
            scale: cropState.scale,
            x: cropState.x,
            y: cropState.y,
        } ).then( () => {
            cfg.customScale = cropState.scale;
            cfg.customX = cropState.x;
            cfg.customY = cropState.y;
            cfg.selCrop = 'custom';
            cfg.status = 'SELECTED';
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            card.classList.add( 'is-selected', 'status-selected' );
            card.classList.remove( 'status-candidate', 'status-approved' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === 'custom' ) );
            ensureDeselectButton( card, cfg );
            showWmRow( card, true );
            updateBadge( card, cfg );
            updateApprovalRow( card, cfg );
            updateCropLinks( card, cfg, 'Custom crop generated.' );
            saveBtn.disabled = false;
            closeCropOverlay();
        } ).catch( e => {
            console.error( 'crop-custom failed', e );
            saveBtn.disabled = false;
        } );
    }

    function sizeWrap( img, wrap ) {
        if ( ! img.naturalWidth ) return;
        const preview = wrap.parentElement;
        const maxW = preview.clientWidth;
        const maxH = preview.clientHeight;
        if ( ! maxW || ! maxH ) return;
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

    function normalizeCropState( cfg ) {
        const scale = clamp( parseFloat( cfg.customScale || cfg.maxScale || 1 ), 1, parseFloat( cfg.maxScale || 1 ) );
        const boxW = scale * TARGET_W;
        const boxH = scale * TARGET_H;
        const maxXv = Math.max( 0, cfg.origW - boxW );
        const maxYv = Math.max( 0, cfg.origH - boxH );
        return {
            scale,
            x: clamp( parseInt( cfg.customX || 0, 10 ), 0, maxXv ),
            y: clamp( parseInt( cfg.customY || 0, 10 ), 0, maxYv ),
        };
    }

    function defaultCropState( cfg ) {
        const scale = parseFloat( cfg.maxScale || 1 );
        const boxW = scale * TARGET_W;
        const boxH = scale * TARGET_H;
        return {
            scale,
            x: Math.round( Math.max( 0, cfg.origW - boxW ) / 2 ),
            y: Math.round( Math.max( 0, cfg.origH - boxH ) / 2 ),
        };
    }

    function currentCenter() {
        return {
            x: cropState.x + cropState.scale * TARGET_W / 2,
            y: cropState.y + cropState.scale * TARGET_H / 2,
        };
    }

    function setScale( nextScale, anchor, anchorIsTopLeft ) {
        if ( ! activeCropCfg || ! cropState ) return;
        const oldScale = cropState.scale;
        cropState.scale = clamp( nextScale, 1, parseFloat( activeCropCfg.maxScale || 1 ) );
        if ( anchor ) {
            if ( anchorIsTopLeft ) {
                cropState.x = anchor.x;
                cropState.y = anchor.y;
            } else {
                cropState.x = anchor.x - cropState.scale * TARGET_W / 2;
                cropState.y = anchor.y - cropState.scale * TARGET_H / 2;
            }
        } else {
            cropState.x += ( oldScale - cropState.scale ) * TARGET_W / 2;
            cropState.y += ( oldScale - cropState.scale ) * TARGET_H / 2;
        }
        cropState.x = clamp( Math.round( cropState.x ), 0, maxX() );
        cropState.y = clamp( Math.round( cropState.y ), 0, maxY() );
    }

    function maxX() {
        return Math.max( 0, Math.round( activeCropCfg.origW - cropState.scale * TARGET_W ) );
    }
    function maxY() {
        return Math.max( 0, Math.round( activeCropCfg.origH - cropState.scale * TARGET_H ) );
    }

    function renderCropControls() {
        if ( ! cropOverlayEl || ! activeCropCfg || ! cropState ) return;
        const scale = cropOverlayEl.querySelector( '.rincwc-cov-scale' );
        const x = cropOverlayEl.querySelector( '.rincwc-cov-x' );
        const y = cropOverlayEl.querySelector( '.rincwc-cov-y' );
        scale.min = '1';
        scale.max = String( activeCropCfg.maxScale || 1 );
        scale.value = String( cropState.scale );
        x.max = String( maxX() );
        y.max = String( maxY() );
        cropState.x = clamp( cropState.x, 0, maxX() );
        cropState.y = clamp( cropState.y, 0, maxY() );
        x.value = String( cropState.x );
        y.value = String( cropState.y );
        x.disabled = maxX() === 0;
        y.disabled = maxY() === 0;
        cropOverlayEl.querySelector( '.rincwc-cov-scaleval' ).textContent = Number( cropState.scale ).toFixed( 2 ) + 'x';
        cropOverlayEl.querySelector( '.rincwc-cov-xval' ).textContent = String( Math.round( cropState.x ) );
        cropOverlayEl.querySelector( '.rincwc-cov-yval' ).textContent = String( Math.round( cropState.y ) );
    }

    function renderCropBox() {
        if ( ! cropOverlayEl || ! activeCropCfg || ! cropState ) return;
        const box = cropOverlayEl.querySelector( '.rincwc-cov-box' );
        const scales = displayScales();
        if ( ! scales.dispW || ! scales.dispH ) return;
        box.style.width = Math.round( cropState.scale * TARGET_W * scales.sx ) + 'px';
        box.style.height = Math.round( cropState.scale * TARGET_H * scales.sy ) + 'px';
        box.style.left = Math.round( cropState.x * scales.sx ) + 'px';
        box.style.top = Math.round( cropState.y * scales.sy ) + 'px';
    }

    function displayScales() {
        const img = cropOverlayEl.querySelector( '.rincwc-cov-img' );
        const dispW = img.clientWidth || 0;
        const dispH = img.clientHeight || 0;
        return {
            dispW,
            dispH,
            sx: dispW / ( activeCropCfg.origW || 1 ),
            sy: dispH / ( activeCropCfg.origH || 1 ),
        };
    }

    function initCard( card ) {
        const cfg = JSON.parse( card.dataset.c || '{}' );
        const thumb = card.querySelector( '.rincwc-thumb' );
        if ( thumb ) thumb.addEventListener( 'click', () => {
            const images = galleryImagesFor( card );
            const idx = images.findIndex( img => img.imageId === cfg.imageId );
            openLightbox( cfg.scaledUrl || thumb.src, cfg.title || cfg.fname, { images, index: Math.max( 0, idx ) } );
        } );

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
        if ( approveBtn ) approveBtn.addEventListener( 'click', () => approve( card, cfg ) );
        const unapproveBtn = card.querySelector( '.rincwc-unapprove-btn' );
        if ( unapproveBtn ) unapproveBtn.addEventListener( 'click', () => unapprove( card, cfg ) );

        initComments( card );
    }

    function selectVariant( card, cfg, btn ) {
        const variant = btn.dataset.v;
        api( 'select', 'POST', { image_id: cfg.imageId, gallery_id: cfg.gid, attach_id: cfg.aid, selected_crop: variant } ).then( () => {
            cfg.selCrop = variant;
            cfg.status = 'SELECTED';
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            card.classList.add( 'is-selected', 'status-selected' );
            card.classList.remove( 'status-candidate', 'status-approved' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.toggle( 'active', b.dataset.v === variant ) );
            ensureDeselectButton( card, cfg );
            showWmRow( card, true );
            updateBadge( card, cfg );
            updateApprovalRow( card, cfg );
            updateCropLinks( card, cfg, 'Crop pending. Click Generate pending crops.' );
        } ).catch( e => console.error( 'select failed', e ) );
    }

    function deselect( card, cfg ) {
        api( 'deselect', 'POST', { image_id: cfg.imageId, gallery_id: cfg.gid, attach_id: cfg.aid } ).then( () => {
            cfg.selCrop = '';
            cfg.status = 'CANDIDATE';
            cfg.wmCorner = '';
            cfg.wmApplied = false;
            card.dataset.c = JSON.stringify( cfg );
            card.classList.remove( 'is-selected', 'status-selected', 'status-approved' );
            card.classList.add( 'status-candidate' );
            card.querySelectorAll( '.rincwc-vbtn' ).forEach( b => b.classList.remove( 'active' ) );
            showWmRow( card, false );
            updateBadge( card, cfg );
            updateApprovalRow( card, cfg );
            const desel = card.querySelector( '.rincwc-desel' );
            if ( desel ) desel.remove();
            const dl = card.querySelector( '.rincwc-crop-dl' );
            if ( dl ) dl.innerHTML = '';
        } ).catch( e => console.error( 'deselect failed', e ) );
    }

    function setWatermark( card, cfg, corner ) {
        api( 'watermark', 'POST', { image_id: cfg.imageId, gallery_id: cfg.gid, attach_id: cfg.aid, wm_corner: corner } ).then( () => {
            cfg.wmCorner = corner;
            cfg.wmApplied = false;
            if ( cfg.status === 'APPROVED' ) {
                // Server auto-demotes: an APPROVED image can't sit with a stale watermark.
                cfg.status = 'SELECTED';
                card.classList.remove( 'status-approved' );
                card.classList.add( 'status-selected' );
            }
            card.dataset.c = JSON.stringify( cfg );
            const st = card.querySelector( '.rincwc-wm-status' );
            if ( st ) {
                st.textContent = corner ? 'pending' : '';
                st.className = 'rincwc-wm-status' + ( corner ? ' wm-pending' : '' );
            }
            updateBadge( card, cfg );
            updateApprovalRow( card, cfg );
        } ).catch( e => console.error( 'watermark failed', e ) );
    }

    function approve( card, cfg ) {
        api( 'approve', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( () => {
            cfg.status = 'APPROVED';
            card.dataset.c = JSON.stringify( cfg );
            card.classList.remove( 'status-selected', 'status-candidate' );
            card.classList.add( 'status-approved', 'is-selected' );
            updateBadge( card, cfg );
            updateApprovalRow( card, cfg );
        } ).catch( e => console.error( 'approve failed', e ) );
    }

    function unapprove( card, cfg ) {
        api( 'unapprove', 'POST', { gallery_id: cfg.gid, attach_id: cfg.aid } ).then( () => {
            cfg.status = 'SELECTED';
            card.dataset.c = JSON.stringify( cfg );
            card.classList.remove( 'status-approved', 'status-candidate' );
            card.classList.add( 'status-selected', 'is-selected' );
            updateBadge( card, cfg );
            updateApprovalRow( card, cfg );
        } ).catch( e => console.error( 'unapprove failed', e ) );
    }

    function ensureDeselectButton( card, cfg ) {
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

    function updateBadge( card, cfg ) {
        let badge = card.querySelector( '.rincwc-badge' );
        if ( ! cfg.selCrop ) {
            if ( badge ) badge.remove();
            return;
        }
        if ( ! badge ) {
            badge = document.createElement( 'span' );
            card.querySelector( '.rincwc-thumb-wrap' ).appendChild( badge );
        }
        if ( cfg.status === 'APPROVED' ) {
            badge.className = 'rincwc-badge badge-approved';
            badge.textContent = 'Approved';
        } else {
            badge.className = 'rincwc-badge ' + ( cfg.wmApplied ? 'badge-wm' : 'badge-sel' );
            badge.textContent = cfg.wmApplied ? 'WM applied' : ( cfg.wmCorner ? 'Selected' : 'Needs WM' );
        }
    }

    function updateApprovalRow( card, cfg ) {
        const row = card.querySelector( '.rincwc-approve-row' );
        if ( ! row ) return;
        if ( ! cfg.wmApplied && cfg.status !== 'APPROVED' ) {
            row.innerHTML = '';
            row.classList.add( 'hidden' );
            return;
        }
        row.classList.remove( 'hidden' );
        const disabled = ! rincwcCfg.approveAllowed || ! cfg.selCrop || ! cfg.wmCorner;
        row.innerHTML = cfg.status === 'APPROVED'
            ? '<button class="button rincwc-unapprove-btn"' + ( disabled ? ' disabled' : '' ) + '>Unapprove</button>'
            : '<button class="button button-secondary rincwc-approve-btn"' + ( disabled ? ' disabled' : '' ) + '>Approve</button>';
        if ( ! rincwcCfg.approveAllowed ) {
            row.innerHTML += '<span class="rincwc-approve-note">restricted</span>';
        }
        const approveBtn = row.querySelector( '.rincwc-approve-btn' );
        if ( approveBtn ) approveBtn.addEventListener( 'click', () => approve( card, cfg ) );
        const unapproveBtn = row.querySelector( '.rincwc-unapprove-btn' );
        if ( unapproveBtn ) unapproveBtn.addEventListener( 'click', () => unapprove( card, cfg ) );
    }

    function updateCropLinks( card, cfg, text ) {
        let dl = card.querySelector( '.rincwc-crop-dl' );
        if ( ! dl ) {
            dl = document.createElement( 'div' );
            dl.className = 'rincwc-crop-dl';
            const comments = card.querySelector( '.rincwc-comments' );
            card.insertBefore( dl, comments );
        }
        dl.innerHTML = '<em>' + esc( text || 'Pending.' ) + '</em>';
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
                    el.textContent = 'applied';
                    el.classList.replace( 'wm-pending', 'wm-done' );
                    const card = el.closest( '.rincwc-card' );
                    if ( card ) {
                        try {
                            const cfg = JSON.parse( card.dataset.c || '{}' );
                            cfg.wmApplied = true;
                            card.dataset.c = JSON.stringify( cfg );
                            updateBadge( card, cfg );
                            updateApprovalRow( card, cfg );
                        } catch ( _ ) {}
                    }
                } );
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; awm.disabled = false; } );
        } );

        if ( sync ) sync.addEventListener( 'click', () => {
            sync.disabled = true; msg.textContent = 'Publishing galleries...';
            api( 'sync-galleries', 'POST' ).then( r => {
                const parts = [];
                Object.keys( r.summary || {} ).forEach( tier => {
                    parts.push( `${tier}: ${r.summary[tier].added || 0} added` );
                } );
                msg.textContent = 'Published. ' + parts.join( ', ' );
                sync.disabled = false;
            } ).catch( e => { msg.textContent = 'Error: ' + e.message; sync.disabled = false; } );
        } );
    }

    let activeFilter = '';
    let searchText = '';

    // Filter selection (which galleries/cards exist at all) is handled entirely
    // server-side per ?filter= — PHP renders a different subset for each filter
    // value, so a client-side toggle can't correctly reveal content that was never
    // rendered under the previously-loaded filter. This only narrows by search text
    // over whatever the current page load already contains.
    function applyFilters() {
        document.querySelectorAll( '.rincwc-gallery' ).forEach( gal => {
            const show = ! searchText || ( gal.dataset.title || '' ).indexOf( searchText ) !== -1;
            gal.style.display = show ? '' : 'none';
        } );
    }

    function filterUrl( filter ) {
        const u = new URL( window.location.href );
        if ( filter ) { u.searchParams.set( 'filter', filter ); }
        else { u.searchParams.delete( 'filter' ); }
        return u.toString();
    }

    function goToFilter( filter ) {
        window.location.href = filterUrl( filter );
    }

    function updateFilterBtnStates() {
        const btnSel      = document.getElementById( 'rincwc-filter-sel' );
        const btnUnrev    = document.getElementById( 'rincwc-filter-unreviewed' );
        const btnApproved = document.getElementById( 'rincwc-filter-approved' );
        const btnExcluded = document.getElementById( 'rincwc-filter-excluded' );
        const btnComments = document.getElementById( 'rincwc-filter-comments' );
        if ( btnSel )      btnSel.classList.toggle( 'is-active', activeFilter === 'selected' );
        if ( btnUnrev )    btnUnrev.classList.toggle( 'is-active', activeFilter === 'unreviewed' );
        if ( btnApproved ) btnApproved.classList.toggle( 'is-active', activeFilter === 'approved' );
        if ( btnExcluded ) btnExcluded.classList.toggle( 'is-active', activeFilter === 'excluded' );
        if ( btnComments ) btnComments.classList.toggle( 'is-active', activeFilter === 'comments' );
    }

    function clearFilters() {
        // If a filter is active, its reload may have omitted galleries that belong
        // in the default view — only a fresh load can bring them back.
        if ( activeFilter !== '' ) {
            goToFilter( '' );
            return;
        }
        searchText = '';
        const search = document.getElementById( 'rincwc-review-search' );
        if ( search ) search.value = '';
        applyFilters();
    }

    function initFilter() {
        const btnSel      = document.getElementById( 'rincwc-filter-sel' );
        const btnUnrev    = document.getElementById( 'rincwc-filter-unreviewed' );
        const btnApproved = document.getElementById( 'rincwc-filter-approved' );
        const btnExcluded = document.getElementById( 'rincwc-filter-excluded' );
        const btnComments = document.getElementById( 'rincwc-filter-comments' );
        const btnClear    = document.getElementById( 'rincwc-clear-filters' );
        const search      = document.getElementById( 'rincwc-review-search' );

        activeFilter = rincwcCfg.initFilter || '';
        updateFilterBtnStates();

        if ( btnSel )      btnSel.addEventListener( 'click', () => goToFilter( activeFilter === 'selected' ? '' : 'selected' ) );
        if ( btnUnrev && ! btnUnrev.disabled ) btnUnrev.addEventListener( 'click', () => goToFilter( activeFilter === 'unreviewed' ? '' : 'unreviewed' ) );
        if ( btnApproved ) btnApproved.addEventListener( 'click', () => goToFilter( activeFilter === 'approved' ? '' : 'approved' ) );
        if ( btnExcluded ) btnExcluded.addEventListener( 'click', () => goToFilter( activeFilter === 'excluded' ? '' : 'excluded' ) );
        if ( btnComments ) btnComments.addEventListener( 'click', () => goToFilter( activeFilter === 'comments' ? '' : 'comments' ) );
        if ( btnClear )    btnClear.addEventListener( 'click', clearFilters );
        if ( search ) search.addEventListener( 'input', () => {
            searchText = search.value.trim().toLowerCase();
            applyFilters();
        } );

        // Collapse all other galleries when navigating to an anchor.
        const hash = window.location.hash;
        if ( hash && hash.startsWith( '#rincwc-gallery-' ) ) {
            document.querySelectorAll( '.rincwc-gallery' ).forEach( gal => {
                const details = gal.querySelector( '.rincwc-details' );
                if ( ! details ) return;
                details.open = ( '#' + gal.id === hash );
            } );
            const target = document.querySelector( hash );
            if ( target ) { setTimeout( () => target.scrollIntoView( { behavior: 'smooth', block: 'start' } ), 50 ); }
        }
    }

    function reloadWithFilter() {
        goToFilter( activeFilter );
    }

    function initCutoffButtons() {
        document.addEventListener( 'click', e => {
            const cutBtn = e.target.closest( '.rincwc-cutoff-btn' );
            if ( cutBtn ) {
                const gid = parseInt( cutBtn.dataset.gid, 10 );
                const pos = parseInt( cutBtn.dataset.pos, 10 );
                cutBtn.disabled = true;
                api( 'set-gallery-cutoff', 'POST', { gallery_id: gid, position: pos } )
                    .then( () => reloadWithFilter() )
                    .catch( err => { alert( 'Error: ' + err.message ); cutBtn.disabled = false; } );
            }

            const inclBtn = e.target.closest( '.rincwc-include-from-btn' );
            if ( inclBtn ) {
                const gid = parseInt( inclBtn.dataset.gid, 10 );
                const pos = parseInt( inclBtn.dataset.pos, 10 );
                inclBtn.disabled = true;
                // Raise the cutoff past this position — includes this image and
                // everything before it; anything after stays excluded.
                api( 'set-gallery-cutoff', 'POST', { gallery_id: gid, position: pos + 1 } )
                    .then( () => reloadWithFilter() )
                    .catch( err => { alert( 'Error: ' + err.message ); inclBtn.disabled = false; } );
            }

            const exclBtn = e.target.closest( '.rincwc-exclude-all-btn' );
            if ( exclBtn ) {
                const gid = parseInt( exclBtn.dataset.gid, 10 );
                exclBtn.disabled = true;
                api( 'set-gallery-cutoff', 'POST', { gallery_id: gid, position: -1 } )
                    .then( () => reloadWithFilter() )
                    .catch( err => { alert( 'Error: ' + err.message ); exclBtn.disabled = false; } );
            }

            const acceptBtn = e.target.closest( '.rincwc-accept-all-btn' );
            if ( acceptBtn ) {
                const gid = parseInt( acceptBtn.dataset.gid, 10 );
                acceptBtn.disabled = true;
                // A position no real gallery reaches (largest sets are under 200 images):
                // nothing gets excluded, but the stored cutoff still marks this set as
                // reviewed, distinct from one that's never been looked at.
                api( 'set-gallery-cutoff', 'POST', { gallery_id: gid, position: 999 } )
                    .then( () => reloadWithFilter() )
                    .catch( err => { alert( 'Error: ' + err.message ); acceptBtn.disabled = false; } );
            }
        } );
    }

    function initExpandCollapse() {
        const ea = document.getElementById( 'rincwc-expand-all' );
        const ca = document.getElementById( 'rincwc-collapse-all' );
        if ( ea ) ea.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => d.open = true ); } );
        if ( ca ) ca.addEventListener( 'click', e => { e.preventDefault(); document.querySelectorAll( '.rincwc-details' ).forEach( d => d.open = false ); } );
    }

    function initBackToTop() {
        document.addEventListener( 'click', e => {
            const link = e.target.closest( '.rincwc-scroll-top' );
            if ( ! link ) return;
            e.preventDefault();
            window.scrollTo( { top: 0, behavior: 'smooth' } );
        } );
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
        div.className = 'rincwc-comment';
        div.dataset.cid = c.id;
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
