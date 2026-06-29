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
    // The theme uses mouseenter/mouseleave only — no touch handling. On touch
    // devices the submenus never open. Intercept clicks on touch-capable devices
    // only (touchend + preventDefault is not reliable across browsers; click is).
    //
    // Behavior:
    //   - First tap on any parent item → open submenu, don't navigate
    //   - Second tap on href="#" items → close submenu
    //   - Second tap on real-link items → navigate normally

    if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
        var $mainMenu = $('#main-menu');

        $mainMenu.find('.menu-item-has-children > a').on('click', function (e) {
            var $li  = $(this).parent();
            var $sub = $li.children('.sub-menu');

            if (!$li.hasClass('submenu-open')) {
                e.preventDefault();
                $li.siblings('.menu-item-has-children.submenu-open').each(function () {
                    $(this).children('.sub-menu').stop().fadeOut(200);
                    $(this).removeClass('submenu-open');
                });
                $sub.stop().fadeIn(200);
                $li.addClass('submenu-open');
            } else if ($(this).attr('href') === '#') {
                e.preventDefault();
                $sub.stop().fadeOut(200);
                $li.removeClass('submenu-open');
            }
            // else: already open + real link → click navigates
        });

        $(document).on('click.main-nav', function (e) {
            if (!$(e.target).closest('#main-menu .menu-item-has-children').length) {
                $mainMenu.find('.menu-item-has-children.submenu-open').each(function () {
                    $(this).children('.sub-menu').stop().fadeOut(200);
                    $(this).removeClass('submenu-open');
                });
            }
        });
    }

});
