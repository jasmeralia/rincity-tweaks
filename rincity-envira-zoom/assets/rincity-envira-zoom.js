(function ($) {
    'use strict';

    var pz               = null;
    var zoomShell        = null;
    var zoomControls     = null;
    var savedWrapParent  = null;
    var savedWrapTransform = null;
    var wheelCleanup     = null;

    function destroyZoom() {
        if (wheelCleanup) {
            wheelCleanup();
            wheelCleanup = null;
        }

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

        // Swap to the true pre-scale original. Base = Envira's retina URL if set,
        // otherwise the current src (which is the WP-scaled version). Strip WP's
        // "-scaled" suffix to get the original file; the regex is extension-agnostic
        // (.jpg, .png, etc.) and a no-op when no "-scaled" is present (older galleries
        // where no scaling occurred are already at full res).
        // The error handler restores the scaled src if the original 404s (e.g. original
        // was deleted from disk), so the user always sees something.
        var prevSrc = $img[0].getAttribute('src');
        var base    = current.enviraRetina || prevSrc;
        var fullRes = base ? base.replace(/-scaled(\.[^.]+)$/, '$1') : null;
        if (fullRes && prevSrc !== fullRes) {
            $img[0].addEventListener('error', function () {
                if (prevSrc) { $img[0].src = prevSrc; }
            }, { once: true });
            // srcset takes precedence over src — clear it so the browser actually fetches fullRes.
            $img[0].removeAttribute('srcset');
            $img[0].src = fullRes;
        }

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

        // Show a spinner while the original (potentially large) file is loading.
        // Fires only when we actually kicked off a new fetch and it isn't already done.
        // clearSpinner is safe to call after destroyZoom: the classList.remove is a
        // no-op on a detached node, and { once:true } prevents double-fire.
        if (fullRes && prevSrc !== fullRes && !$img[0].complete) {
            zoomShell.classList.add('rin-zoom-loading');
            var clearSpinner = function () {
                if (zoomShell) { zoomShell.classList.remove('rin-zoom-loading'); }
            };
            $img[0].addEventListener('load',  clearSpinner, { once: true });
            $img[0].addEventListener('error', clearSpinner, { once: true });
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
            pz.zoomWithWheel(e);
        }
        slide.addEventListener('wheel', onWheel, { passive: false });
        wheelCleanup = function () {
            slide.removeEventListener('wheel', onWheel);
        };

        // Double-click: cycle through zoom steps toward cursor; reset after max
        var DBLCLICK_STEPS = [2, 3.5, 5];
        zoomShell.addEventListener('dblclick', function (e) {
            if (!pz) return;
            var scale = pz.getScale();
            var next = null;
            for (var i = 0; i < DBLCLICK_STEPS.length; i++) {
                if (DBLCLICK_STEPS[i] > scale + 0.15) { next = DBLCLICK_STEPS[i]; break; }
            }
            if (next !== null) {
                pz.zoomToPoint(next, { clientX: e.clientX, clientY: e.clientY }, { animate: true });
            } else {
                pz.reset({ animate: true });
            }
        });

        // +/- zoom buttons
        zoomControls = document.createElement('div');
        zoomControls.className = 'rin-zoom-controls';

        var btnIn  = document.createElement('button');
        btnIn.className  = 'rin-zoom-btn rin-zoom-in';
        btnIn.type       = 'button';
        btnIn.textContent = '+';
        btnIn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (pz) pz.zoomIn({ animate: true });
        });

        var btnOut = document.createElement('button');
        btnOut.className  = 'rin-zoom-btn rin-zoom-out';
        btnOut.type       = 'button';
        btnOut.textContent = '−';
        btnOut.addEventListener('click', function (e) {
            e.stopPropagation();
            if (pz) pz.zoomOut({ animate: true });
        });

        zoomControls.appendChild(btnIn);
        zoomControls.appendChild(btnOut);
        // Use instance.$refs.container (direct reference) rather than .closest() traversal.
        // Insert into .envirabox-toolbar so the buttons flow naturally with the other toolbar
        // buttons regardless of how many are shown; avoids a hardcoded pixel offset.
        // Falls back to container-level append (with --floating positioning) if the toolbar
        // was removed (toolbar:false gallery config).
        var $c = instance.$refs && instance.$refs.container;
        var enviraContainer = ($c && $c[0]) || current.$slide[0].closest('.envirabox-container') || document.body;
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
