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

        // Swap to the full-resolution image if Envira has one.
        // data-envira-retina on the gallery link is normalised to enviraRetina by jQuery
        // when envirabox copies link data onto the slide object.
        var fullRes = current.enviraRetina;
        if (fullRes && $img[0].getAttribute('src') !== fullRes) {
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

        // Double-click: zoom toward cursor, or reset if already zoomed
        zoomShell.addEventListener('dblclick', function (e) {
            if (!pz) return;
            if (pz.getScale() > 1.2) {
                pz.reset({ animate: true });
            } else {
                pz.zoomToPoint(2.5, { clientX: e.clientX, clientY: e.clientY }, { animate: true });
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
        // Append to the envirabox container (position:fixed, full-viewport) rather than
        // document.body — body-level position:fixed breaks if any ancestor has a transform.
        var enviraContainer = current.$slide[0].closest('.envirabox-container') || document.body;
        enviraContainer.appendChild(zoomControls);
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
