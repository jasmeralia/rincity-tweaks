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
    // The theme uses mouseenter/mouseleave for submenus; these don't fire on
    // touch. On first tap of a parent item, open the submenu instead of
    // following the link. On second tap, follow the link (or close if href="#").

    (function () {
        var $mainMenu = $('#main-menu');
        if (!$mainMenu.length) { return; }

        $mainMenu.find('.menu-item-has-children > a').on('touchend', function (e) {
            var $li  = $(this).parent();
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
            } else if ($(this).attr('href') === '#') {
                e.preventDefault();
                e.stopPropagation();
                $sub.stop().fadeOut(200);
                $li.removeClass('submenu-open');
            }
            // else: already open + real link → second tap navigates normally
        });

        $(document).on('touchend.main-nav', function (e) {
            if (!$(e.target).closest('#main-menu .menu-item-has-children').length) {
                $mainMenu.find('.menu-item-has-children.submenu-open').each(function () {
                    $(this).children('.sub-menu').stop().fadeOut(200);
                    $(this).removeClass('submenu-open');
                });
            }
        });
    }());

});
