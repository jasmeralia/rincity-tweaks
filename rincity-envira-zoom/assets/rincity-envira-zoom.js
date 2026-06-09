(function ($) {
    'use strict';

    var pz                 = null;
    var zoomShell          = null;
    var zoomControls       = null;
    var savedWrapParent    = null;
    var savedWrapTransform = null;
    var wheelCleanup       = null;

    // --- Full-res background-load state ---
    // bgLoader      : detached Image() preloading the original off-screen
    // originalReady : true once the original has loaded (or errored — in which case
    //                 we keep the scaled image and just let zoom act on it)
    // pendingZoom   : the most recent zoom gesture requested before the original was
    //                 ready; replayed once it arrives
    var bgLoader      = null;
    var originalReady = false;
    var pendingZoom   = null;

    function showSpinner() {
        if (zoomShell) { zoomShell.classList.add('rin-zoom-loading'); }
    }
    function clearSpinner() {
        if (zoomShell) { zoomShell.classList.remove('rin-zoom-loading'); }
    }

    // Run a zoom action now if the original is ready; otherwise queue it and show the
    // spinner over the (still-visible) scaled image. We never wipe the scaled image —
    // it stays in place until the original has decoded and can be swapped in cleanly.
    function runWhenReady(action) {
        if (originalReady) {
            action();
        } else {
            pendingZoom = action;
            showSpinner();
        }
    }

    function destroyZoom() {
        if (wheelCleanup) {
            wheelCleanup();
            wheelCleanup = null;
        }

        // Cancel any in-flight background load and drop its handlers so a late
        // load/error can't swap an image into the wrong (next) slide.
        if (bgLoader) {
            bgLoader.onload = bgLoader.onerror = null;
            bgLoader.src = '';
            bgLoader = null;
        }
        originalReady = false;
        pendingZoom   = null;

        if (pz) {
            pz.destroy();
            pz = null;
        }

        // Restore image-wrap to its original place in the slide before removing shell.
        // This matters for beforeShow: Envira needs the wrap in the slide to animate out.
        if (zoomShell) {
            var wrap = zoomShell.querySelector('.envirabox-image-wrap');
            if (wrap && savedWrapParent) {
                // Restore Envira's centering transform
                wrap.style.transform = savedWrapTransform || '';
                savedWrapParent.appendChild(wrap);
            }
            if (zoomShell.parentNode) {
                zoomShell.parentNode.removeChild(zoomShell);
            }
            zoomShell = null;
        }

        if (zoomControls) {
            if (zoomControls.parentNode) {
                zoomControls.parentNode.removeChild(zoomControls);
            }
            zoomControls = null;
        }

        savedWrapParent    = null;
        savedWrapTransform = null;
    }

    function initZoom(instance, current) {
        destroyZoom();

        var $wrap = current.$content;
        if (!$wrap || !$wrap.length) return;

        var $img = $wrap.find('img.envirabox-image');
        if (!$img.length) return;
        var img = $img[0];

        // Resolve the true pre-scale original. Base = Envira's retina URL if set,
        // otherwise the current src (the WP-scaled version). Strip WP's "-scaled"
        // suffix to get the original file; the regex is extension-agnostic (.jpg,
        // .png, etc.) and a no-op when no "-scaled" is present (older galleries that
        // were never scaled are already at full res).
        var prevSrc   = img.getAttribute('src');
        var base      = current.enviraRetina || prevSrc;
        var fullRes   = base ? base.replace(/-scaled(\.[^.]+)$/, '$1') : null;
        var needsSwap = !!(fullRes && prevSrc !== fullRes);

        // --- Shell setup ---
        // Envira sizes .envirabox-image-wrap to the image dimensions and centres it via
        // transform: translate3d(X, Y, 0) from transform-origin: top left.
        // Panzoom's zoomToPoint assumes dims.elem === effectiveArea (parent = slide),
        // which is only true if the panzoom target fills the slide.  We create a shell
        // div that fills the slide, move the image-wrap inside it, and apply Panzoom
        // to the shell instead of the image-wrap directly.

        savedWrapParent    = $wrap[0].parentNode;          // .envirabox-slide
        savedWrapTransform = $wrap[0].style.transform;     // Envira's translate3d centering

        zoomShell = document.createElement('div');
        zoomShell.className = 'rin-zoom-shell';
        current.$slide[0].appendChild(zoomShell);

        // Move image-wrap into shell; CSS re-centres it via flexbox
        zoomShell.appendChild($wrap[0]);
        $wrap[0].style.transform = '';  // clear Envira's translate so flexbox takes over

        // --- Background load of the original (no spinner by default) ---
        // The visible <img> keeps showing the scaled version (via its srcset/src) the
        // whole time the original downloads in the background. When the original is
        // decoded we swap it into the visible <img> in one step — no flash, no blanking,
        // no spinner. The spinner only ever appears if the user tries to zoom *before*
        // the original is ready (see runWhenReady), and even then it overlays the scaled
        // image rather than replacing it.
        if (!needsSwap) {
            // Scaled === original (older gallery): zoom can act immediately, no fetch.
            originalReady = true;
        } else {
            originalReady = false;
            bgLoader = new Image();
            bgLoader.onload = function () {
                bgLoader = null;
                originalReady = true;
                // srcset takes precedence over src, so clear it before swapping.
                // fullRes is already in the browser cache, so this is instant.
                img.removeAttribute('srcset');
                img.src = fullRes;
                clearSpinner();
                if (pendingZoom) { var f = pendingZoom; pendingZoom = null; f(); }
            };
            bgLoader.onerror = function () {
                bgLoader = null;
                // Give up on the original (e.g. deleted from disk); keep the scaled
                // image in place and let zoom operate on it.
                originalReady = true;
                clearSpinner();
                if (pendingZoom) { var f = pendingZoom; pendingZoom = null; f(); }
            };
            bgLoader.src = fullRes;
        }

        // Apply Panzoom to the shell (which fills the slide)
        pz = Panzoom(zoomShell, {
            maxScale: 5,
            minScale: 1,
            step:     0.3,
        });

        // Wheel zoom on the slide (parent of shell); stopPropagation prevents
        // envirabox-wheel from treating the scroll as slide navigation
        var slide = current.$slide[0];
        function onWheel(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!pz) return;
            var ev = e;
            runWhenReady(function () { if (pz) pz.zoomWithWheel(ev); });
        }
        slide.addEventListener('wheel', onWheel, { passive: false });
        wheelCleanup = function () {
            slide.removeEventListener('wheel', onWheel);
        };

        // Double-click: cycle through zoom steps toward cursor; reset after max
        var DBLCLICK_STEPS = [2, 3.5, 5];
        zoomShell.addEventListener('dblclick', function (e) {
            var ev = e;
            runWhenReady(function () {
                if (!pz) return;
                var scale = pz.getScale();
                var next = null;
                for (var i = 0; i < DBLCLICK_STEPS.length; i++) {
                    if (DBLCLICK_STEPS[i] > scale + 0.15) { next = DBLCLICK_STEPS[i]; break; }
                }
                if (next !== null) {
                    pz.zoomToPoint(next, { clientX: ev.clientX, clientY: ev.clientY }, { animate: true });
                } else {
                    pz.reset({ animate: true });
                }
            });
        });

        // +/- zoom buttons.
        // NOTE: this wrapper is a <span>, not a <div>, on purpose. The base_dark
        // lightbox theme hijacks every *direct child <div>* of .envirabox-toolbar
        // (.envirabox-toolbar > div { width:16px; height:16px; text-indent:-9999px }),
        // which is what made the 0.6.1 controls vanish. A <span> sits in the toolbar
        // row alongside the native buttons but is not matched by that rule.
        zoomControls = document.createElement('span');
        zoomControls.className = 'rin-zoom-controls';

        var btnIn  = document.createElement('button');
        btnIn.className  = 'rin-zoom-btn rin-zoom-in';
        btnIn.type       = 'button';
        btnIn.setAttribute('aria-label', 'Zoom in');
        btnIn.textContent = '+';
        btnIn.addEventListener('click', function (e) {
            e.stopPropagation();
            runWhenReady(function () { if (pz) pz.zoomIn({ animate: true }); });
        });

        var btnOut = document.createElement('button');
        btnOut.className  = 'rin-zoom-btn rin-zoom-out';
        btnOut.type       = 'button';
        btnOut.setAttribute('aria-label', 'Zoom out');
        btnOut.textContent = '−';
        btnOut.addEventListener('click', function (e) {
            e.stopPropagation();
            runWhenReady(function () { if (pz) pz.zoomOut({ animate: true }); });
        });

        zoomControls.appendChild(btnIn);
        zoomControls.appendChild(btnOut);

        // Insert into .envirabox-toolbar as the first item so the +/- buttons flow
        // inline with Envira's native controls (thumbnails / fullscreen / slideshow /
        // close). Uses instance.$refs.container (direct reference) rather than a
        // .closest() traversal. Falls back to a floating overlay on the container if
        // the toolbar is absent (toolbar:false gallery config).
        var $c = instance.$refs && instance.$refs.container;
        var enviraContainer = ($c && $c[0]) ||
            (current.$slide[0].closest && current.$slide[0].closest('.envirabox-container')) ||
            current.$slide[0];
        var toolbar = enviraContainer.querySelector('.envirabox-toolbar');
        if (toolbar) {
            toolbar.insertBefore(zoomControls, toolbar.firstChild);
        } else {
            zoomControls.classList.add('rin-zoom-controls--floating');
            enviraContainer.appendChild(zoomControls);
        }
    }

    $(document).on('envirabox_api_before_show', function (e, obj, instance, current) {
        // Fires before the next image animates in; tear down zoom so Envira can
        // animate the outgoing slide with the image-wrap back in its original place
        destroyZoom();
    });

    $(document).on('envirabox_api_after_show', function (e, obj, instance, current) {
        initZoom(instance, current);
    });

    $(document).on('envirabox_api_after_close', function () {
        destroyZoom();
    });

}(jQuery));
