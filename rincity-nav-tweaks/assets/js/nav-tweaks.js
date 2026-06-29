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
    // The theme uses mouseenter/mouseleave only. On touch devices submenus never
    // open intentionally. Must use native addEventListener with { passive: false }
    // — jQuery's .on('touchstart') does not pass this option, so the browser
    // silently ignores preventDefault() calls from those handlers.
    //
    // Behavior:
    //   First tap  → open submenu, don't navigate
    //   Second tap, href="#"  → close submenu
    //   Second tap, real link → fall through, browser navigates normally

    (function () {
        var mainMenu = document.getElementById('main-menu');
        if (!mainMenu) { return; }

        var anchors = mainMenu.querySelectorAll('.menu-item-has-children > a');

        anchors.forEach(function (el) {
            el.addEventListener('touchstart', function (e) {
                var $li  = $(el).parent();
                var $sub = $li.children('.sub-menu');

                if (!$li.hasClass('submenu-open')) {
                    e.preventDefault();
                    e.stopPropagation();
                    $li.siblings('.menu-item-has-children.submenu-open').each(function () {
                        $(this).children('.sub-menu').stop().fadeOut(200);
                        $(this).removeClass('submenu-open');
                    });
                    $sub.stop().fadeIn(200);
                    $li.addClass('submenu-open');
                } else if (el.getAttribute('href') === '#') {
                    e.preventDefault();
                    e.stopPropagation();
                    $sub.stop().fadeOut(200);
                    $li.removeClass('submenu-open');
                }
                // else: already open + real link → no preventDefault, browser navigates
            }, { passive: false });
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
