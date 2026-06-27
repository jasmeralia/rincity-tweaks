(function ($) {
    'use strict';

    var pz                 = null;
    var zoomShell          = null;
    var zoomControls       = null;
    var imageInfo          = null;
    var savedWrapParent    = null;
    var savedWrapTransform = null;
    var wheelCleanup       = null;
    var wrapObserver       = null;

    // --- Full-res background-load state ---
    // bgLoader : detached Image() preloading the original off-screen
    var bgLoader = null;

    function setHdReady() {
        if (!zoomControls) { return; }
        zoomControls.classList.remove('rin-zoom-pending');
        var ind = zoomControls.querySelector('.rin-zoom-hd-indicator');
        if (ind) {
            ind.textContent = 'HD';
            ind.className = 'rin-zoom-hd-indicator rin-zoom-hd-ready';
        }
    }
    function removeHdIndicator() {
        if (!zoomControls) { return; }
        zoomControls.classList.remove('rin-zoom-pending');
        var ind = zoomControls.querySelector('.rin-zoom-hd-indicator');
        if (ind && ind.parentNode) { ind.parentNode.removeChild(ind); }
    }

    function rincityImageInfoText(index, total, w, h) {
        return '(Image ' + index + ' of ' + total + ') [' + w + '\xd7' + h + ']';
    }

    function destroyZoom() {
        if (wrapObserver) {
            wrapObserver.disconnect();
            wrapObserver = null;
        }

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

        if (imageInfo) {
            if (imageInfo.parentNode) {
                imageInfo.parentNode.removeChild(imageInfo);
            }
            imageInfo = null;
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

        // Resolve the true pre-scale original. Use the retina URL saved by the
        // before_load hook (_rcRetina), falling back to enviraRetina if the hook
        // didn't fire (e.g. already-open lightbox on first show), then to prevSrc.
        // Strip WP's "-scaled" suffix to get the original file; the regex is
        // extension-agnostic (.jpg, .png, etc.) and a no-op when no "-scaled" is
        // present (older galleries that were never scaled are already at full res).
        var prevSrc   = img.getAttribute('src');
        var base      = current._rcRetina || current.enviraRetina || prevSrc;
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
        // Envira sometimes re-sets the centering transform asynchronously after after_show
        // fires (race on first open, timing varies). A MutationObserver re-clears it the
        // instant Envira writes it back, regardless of how many times or how late it fires.
        wrapObserver = new MutationObserver(function () {
            if ($wrap[0].style.transform !== '') {
                $wrap[0].style.transform = '';
            }
        });
        wrapObserver.observe($wrap[0], { attributes: true, attributeFilter: ['style'] });

        // --- Background load of the original ---
        // The visible <img> shows the scaled version while the original downloads.
        // Zoom operates on the scaled image immediately; when the original is decoded
        // it swaps in at whatever zoom/pan the user is already at, revealing detail.
        if (needsSwap) {
            bgLoader = new Image();
            bgLoader.onload = function () {
                // Read original dimensions before nulling bgLoader and swapping src.
                var origW = bgLoader.naturalWidth;
                var origH = bgLoader.naturalHeight;
                bgLoader = null;
                img.removeAttribute('srcset');
                img.src = fullRes;
                setHdReady();
                if (imageInfo) {
                    imageInfo.textContent = rincityImageInfoText(
                        instance.currIndex + 1, instance.group.length, origW, origH
                    );
                }
            };
            bgLoader.onerror = function () {
                bgLoader = null;
                // Give up on the original (e.g. deleted from disk); keep scaled.
                removeHdIndicator();
            };
            bgLoader.src = fullRes;
        }

        // Apply Panzoom to the shell (which fills the slide).
        // disablePan starts true: at scale=1 we want Panzoom to stay out of the way so
        // Envira's swipe-nav can work. It is toggled false in panzoomzoom once scale > 1.
        pz = Panzoom(zoomShell, {
            maxScale:   15,
            minScale:   1,
            step:       0.3,
            disablePan: true,
        });

        // Wheel zoom on the slide (parent of shell); stopPropagation prevents
        // envirabox-wheel from treating the scroll as slide navigation
        var slide = current.$slide[0];
        function onWheel(e) {
            e.preventDefault();
            e.stopPropagation();
            if (!pz) return;
            var ev = e;
            if (pz) pz.zoomWithWheel(ev);
        }
        slide.addEventListener('wheel', onWheel, { passive: false });
        wheelCleanup = function () {
            slide.removeEventListener('wheel', onWheel);
        };

        // Touch propagation guard. Panzoom uses pointer events, so blocking touch
        // events does not affect zoom or pan. We block ALL touch propagation to
        // Envira's parent handlers unconditionally, at every scale.
        //
        // Why unconditionally (changed in 0.6.16): Envira's guestures module
        // (envirabox-guestures.js) routes any content touch that ends without a
        // >10px swipe to onTap -> clickContent:'toggleControls', which hides the
        // toolbar — including the +/−/HD/zoom controls we inject there. Previously
        // we let touch through at scale=1 so Envira's own swipe-nav would work, but
        // that is exactly the path that toggles the toolbar on short drags
        // (task 310). Instead we swallow all touch here and reimplement horizontal
        // swipe-nav ourselves (see the single-touch touchend handler below) via
        // Envira's public instance.next()/previous() API.
        //
        // touchStartX/Y: used by the swipe-nav and double-tap touchend handlers to
        // tell taps from drags and measure swipe distance/direction.
        var touchStartX = 0;
        var touchStartY = 0;
        zoomShell.addEventListener('touchstart', function (e) {
            if (e.touches.length === 1) {
                touchStartX = e.touches[0].clientX;
                touchStartY = e.touches[0].clientY;
            } else {
                lastTap = 0; // multi-touch (pinch) resets any pending double-tap
            }
            e.stopPropagation();
        }, { passive: true });
        zoomShell.addEventListener('touchmove', function (e) {
            e.stopPropagation();
        }, { passive: true });
        zoomShell.addEventListener('touchend', function (e) {
            e.stopPropagation();
        }, { passive: true });

        // Double-click (desktop) and double-tap (mobile): cycle zoom steps toward cursor; reset after max.
        // dblclick is not synthesised from touch events when touch-action:none is set, so mobile
        // needs its own touchend-based handler. preventDefault() on the double-tap touchend suppresses
        // the synthetic click/dblclick, so the two handlers never fire for the same gesture.
        var DBLCLICK_STEPS = [2, 4, 7, 11, 15];
        function doZoomStep(clientX, clientY) {
            if (!pz) return;
            var scale = pz.getScale();
            var next = null;
            for (var i = 0; i < DBLCLICK_STEPS.length; i++) {
                if (DBLCLICK_STEPS[i] > scale + 0.15) { next = DBLCLICK_STEPS[i]; break; }
            }
            if (next !== null) {
                pz.zoomToPoint(next, { clientX: clientX, clientY: clientY }, { animate: true });
            } else {
                pz.reset({ animate: true });
            }
        }
        zoomShell.addEventListener('dblclick', function (e) {
            doZoomStep(e.clientX, e.clientY);
        });
        // Self-implemented horizontal swipe-nav. Because we now block all touch from
        // reaching Envira's guestures (see the touch guard above), Envira's built-in
        // swipe navigation no longer fires — so we re-create it here. Only active at
        // scale<=1 (when zoomed in, a horizontal drag is a pan handled by Panzoom).
        // SWIPE_THRESHOLD is well above Envira's 10px tap cutoff to avoid accidental
        // flips, and we require horizontal dominance (|dx|>|dy|) to mirror Envira's
        // 45° angle test in guestures' onSwipe.
        var SWIPE_THRESHOLD = 40;
        var lastTap = 0;
        var lastTapX = 0;
        var lastTapY = 0;
        zoomShell.addEventListener('touchend', function (e) {
            if (e.changedTouches.length !== 1) { lastTap = 0; return; }
            var touch = e.changedTouches[0];
            // If the finger moved more than 10px from where it landed, this is a drag-end,
            // not a tap. Reset lastTap so the drag-end doesn't poison the double-tap sequence.
            var mdx = touch.clientX - touchStartX;
            var mdy = touch.clientY - touchStartY;
            if (mdx * mdx + mdy * mdy > 100) {
                lastTap = 0;
                // Horizontal swipe at scale=1 navigates the gallery via Envira's public
                // API. Guard on group length so single-image galleries don't navigate.
                if (pz && pz.getScale() <= 1.05 &&
                    Math.abs(mdx) > SWIPE_THRESHOLD && Math.abs(mdx) > Math.abs(mdy) &&
                    instance && instance.group && instance.group.length > 1) {
                    if (mdx < 0) { instance.next(); } else { instance.previous(); }
                }
                return;
            }
            var now = Date.now();
            var dx = touch.clientX - lastTapX;
            var dy = touch.clientY - lastTapY;
            if (now - lastTap < 300 && (dx * dx + dy * dy) < 900) {
                // Double-tap confirmed (within 300ms and 30px radius)
                e.preventDefault();
                e.stopPropagation();
                lastTap = 0;
                doZoomStep(touch.clientX, touch.clientY);
            } else {
                lastTap = now;
                lastTapX = touch.clientX;
                lastTapY = touch.clientY;
            }
        }, { passive: false });

        // Zoom-level indicator ("2x", "3x" …). Built here so the panzoomzoom listener
        // below can reference it; inserted into zoomControls as its first child later.
        var zoomIndicator = document.createElement('span');
        zoomIndicator.className = 'rin-zoom-scale-indicator';
        zoomIndicator.setAttribute('aria-hidden', 'true');
        zoomIndicator.style.display = 'none';

        zoomShell.addEventListener('panzoomzoom', function (e) {
            var scale = e.detail.scale;
            // Pan is only useful when zoomed in; at scale=1 keep it disabled so
            // Panzoom doesn't capture pointer events and block Envira's swipe-nav.
            if (pz) { pz.setOptions({ disablePan: scale <= 1.05 }); }
            if (scale <= 1.05) {
                zoomIndicator.style.display = 'none';
            } else {
                zoomIndicator.style.display = '';
                zoomIndicator.textContent = parseFloat(scale.toFixed(1)) + 'x';
            }
        });
        zoomShell.addEventListener('panzoomreset', function () {
            if (pz) { pz.setOptions({ disablePan: true }); }
            zoomIndicator.style.display = 'none';
        });

        // +/- zoom buttons.
        // NOTE: this wrapper is a <span>, not a <div>, on purpose. The base_dark
        // lightbox theme hijacks every *direct child <div>* of .envirabox-toolbar
        // (.envirabox-toolbar > div { width:16px; height:16px; text-indent:-9999px }),
        // which is what made the 0.6.1 controls vanish. A <span> sits in the toolbar
        // row alongside the native buttons but is not matched by that rule.
        zoomControls = document.createElement('span');
        zoomControls.className = 'rin-zoom-controls';

        // Stop touch events on controls from reaching Envira's tap-to-toggle-toolbar handler.
        // On mobile, tapping a button emits touchstart+touchend before the synthetic click;
        // if those propagate, Envira hides the toolbar and the controls disappear.
        ['touchstart', 'touchend'].forEach(function (evtName) {
            zoomControls.addEventListener(evtName, function (e) {
                e.stopPropagation();
            }, { passive: true });
        });

        var btnIn  = document.createElement('button');
        btnIn.className  = 'rin-zoom-btn rin-zoom-in';
        btnIn.type       = 'button';
        btnIn.setAttribute('aria-label', 'Zoom in');
        btnIn.textContent = '+';
        btnIn.addEventListener('click', function (e) {
            e.stopPropagation();
            if (pz) pz.zoomIn({ animate: true });
        });

        var btnOut = document.createElement('button');
        btnOut.className  = 'rin-zoom-btn rin-zoom-out';
        btnOut.type       = 'button';
        btnOut.setAttribute('aria-label', 'Zoom out');
        btnOut.textContent = '−';
        btnOut.addEventListener('click', function (e) {
            e.stopPropagation();
            if (pz) pz.zoomOut({ animate: true });
        });

        zoomControls.appendChild(zoomIndicator);
        zoomControls.appendChild(btnIn);
        zoomControls.appendChild(btnOut);

        // Pending-state indicator: dim buttons and show a spinning ring while the
        // full-res original is loading; switches to a brief "HD" badge on load.
        if (needsSwap) {
            zoomControls.classList.add('rin-zoom-pending');
            var hdIndicator = document.createElement('span');
            hdIndicator.className = 'rin-zoom-hd-indicator rin-zoom-hd-loading';
            hdIndicator.setAttribute('aria-hidden', 'true');
            zoomControls.appendChild(hdIndicator);
        }

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

        // Image info: "(Image X of Y) [WxH]".
        // Preferred: inject into .envirabox-image-counter, replacing Envira's native
        // "Image X of Y" text (rendered by image_counter:1 in gallery config into the
        // caption area). The native [data-envirabox-index/count] spans are removed;
        // Envira's update calls become no-ops since initZoom recreates the text fresh
        // on every after_show. Falls back to floating overlay when image_counter is off.
        // NOTE: .envirabox-infobar is removed from the DOM entirely when infobar:false
        // (Envira calls self.$refs.infobar.remove()), so that element cannot be targeted.
        imageInfo = document.createElement('span');
        imageInfo.className = 'rin-image-info';
        imageInfo.setAttribute('aria-hidden', 'true');
        imageInfo.textContent = rincityImageInfoText(
            instance.currIndex + 1, instance.group.length,
            img.naturalWidth, img.naturalHeight
        );
        ['touchstart', 'touchend'].forEach(function (evtName) {
            imageInfo.addEventListener(evtName, function (e) {
                e.stopPropagation();
            }, { passive: true });
        });
        var imageCounter = enviraContainer.querySelector('.envirabox-image-counter');
        if (imageCounter) {
            imageCounter.innerHTML = '';
            imageCounter.appendChild(imageInfo);
        } else {
            imageInfo.classList.add('rin-image-info--floating');
            enviraContainer.appendChild(imageInfo);
        }
    }

    // Intercept before envirabox's setImage() runs.  On retina devices (DPR ≥ 2)
    // envirabox builds srcset as "-scaled.png 1x, original.png 2x", which causes
    // the browser to skip the scaled version and download the full original immediately.
    // We save enviraRetina on a private property and clear it so envirabox only gets
    // a 1x srcset; initZoom then background-loads the original itself.
    $(document).on('envirabox_api_before_load', function (e, obj, instance, current) {
        if (current.enviraRetina) {
            current._rcRetina   = current.enviraRetina;
            current.enviraRetina = '';
        }
    });

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
