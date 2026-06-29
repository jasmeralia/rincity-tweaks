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

    $('#mobile-menu .menu-item-has-children > a').on('click', function (e) {
        e.preventDefault();
        $(this).siblings('.sub-menu-btn-icon').find('svg').toggleClass('fa-rotate-270');
        $(this).siblings('.sub-menu').slideToggle();
    });

});
