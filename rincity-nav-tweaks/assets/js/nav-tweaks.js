$(document).ready(function () {

    // ── Mobile nav (#mobile-menu) ─────────────────────────────────────────────
    // The theme injects .sub-menu-btn-icon chevrons but gives them no handler
    // (the .sub-menu-btn overlay it tries to use is dead, covered by the anchor's
    // z-index:5). Wire the chevrons and intercept href="#" anchors.

    $('#mobile-menu .sub-menu-btn-icon').on('click', function (e) {
        e.stopPropagation();
        $(this).next('.sub-menu').slideToggle();
        $(this).find('svg').toggleClass('fa-rotate-270');
    });

    $('#mobile-menu .menu-item-has-children > a[href="#"]').on('click', function (e) {
        e.preventDefault();
        $(this).siblings('.sub-menu-btn-icon').find('svg').toggleClass('fa-rotate-270');
        $(this).siblings('.sub-menu').slideToggle();
    });

    // ── Desktop nav (#main-menu) touch support ────────────────────────────────
    // Strategy: touchstart opens the submenu immediately (passive, no preventDefault
    // needed). Navigation is blocked by a capture-phase click handler that always
    // calls preventDefault() — click is consistently interceptable unlike touchstart.
    // A per-element justOpened flag distinguishes "this click came from the tap that
    // just opened the menu" (don't navigate) vs "submenu already open, second tap"
    // (navigate for real links, close for href="#").

    (function () {
        var mainMenu = document.getElementById('main-menu');
        if (!mainMenu) { return; }

        var lastTouchTime = 0;
        document.addEventListener('touchstart', function () {
            lastTouchTime = Date.now();
        }, { passive: true });

        mainMenu.querySelectorAll('.menu-item-has-children > a').forEach(function (el) {
            var justOpened = false;

            el.addEventListener('touchstart', function (e) {
                lastTouchTime = Date.now(); // update before stopPropagation blocks document listener
                var $li  = $(el).parent();
                var $sub = $li.children('.sub-menu');

                if (!$li.hasClass('submenu-open')) {
                    justOpened = true;
                    e.stopPropagation();
                    $li.siblings('.menu-item-has-children.submenu-open').each(function () {
                        $(this).children('.sub-menu').stop().fadeOut(200);
                        $(this).removeClass('submenu-open');
                    });
                    $sub.stop().fadeIn(200);
                    $li.addClass('submenu-open');
                } else {
                    justOpened = false;
                }
            }, { passive: true });

            // Capture-phase click: intercept before the browser acts on the href.
            // Only active for touch-derived clicks (skip desktop mouse clicks so
            // keyboard/mouse nav of the desktop hover menu is unaffected).
            el.addEventListener('click', function (e) {
                if (Date.now() - lastTouchTime > 700) { return; }

                e.preventDefault();
                e.stopImmediatePropagation();

                if (justOpened) {
                    justOpened = false;
                    return; // Submenu just opened this tap — don't navigate
                }

                var $li  = $(el).parent();
                var $sub = $li.children('.sub-menu');
                var href = el.getAttribute('href');

                if (href === '#') {
                    $sub.stop().fadeOut(200);
                    $li.removeClass('submenu-open');
                } else {
                    window.location.href = href; // Second tap on real link: navigate
                }
            }, true); // capture phase
        });

        // Close all open submenus on tap outside the nav
        document.addEventListener('touchstart', function (e) {
            if (!$(e.target).closest('#main-menu .menu-item-has-children').length) {
                $(mainMenu).find('.menu-item-has-children.submenu-open').each(function () {
                    $(this).children('.sub-menu').stop().fadeOut(200);
                    $(this).removeClass('submenu-open');
                });
            }
        }, { passive: true });
    }());

});
