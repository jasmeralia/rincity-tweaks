$(document).ready(function () {

    // Prevent href="#" parent items from scrolling to top in both navs
    $('#main-menu .menu-item-has-children > a[href="#"], #mobile-menu .menu-item-has-children > a[href="#"]').on('click', function (e) {
        e.preventDefault();
    });

    // ── Mobile nav (#mobile-menu) ─────────────────────────────────────────────
    // The theme injects .sub-menu-btn-icon chevrons but gives them no handler
    // (the .sub-menu-btn overlay it tries to use is dead, covered by the anchor's
    // z-index:5). Wire the chevrons and toggle submenus on parent anchor clicks.

    $('#mobile-menu .sub-menu-btn-icon').on('click', function (e) {
        e.stopPropagation();
        $(this).next('.sub-menu').slideToggle();
        $(this).find('svg').toggleClass('fa-rotate-270');
    });

    $('#mobile-menu .menu-item-has-children > a').on('click', function (e) {
        $(this).siblings('.sub-menu-btn-icon').find('svg').toggleClass('fa-rotate-270');
        $(this).siblings('.sub-menu').slideToggle();
    });

});
