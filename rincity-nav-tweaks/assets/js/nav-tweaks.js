$(document).ready(function () {
    // Chevron click → toggle submenu
    $('#mobile-menu .sub-menu-btn-icon').on('click', function (e) {
        e.stopPropagation();
        $(this).next('.sub-menu').slideToggle();
        $(this).find('svg').toggleClass('fa-rotate-270');
    });

    // href="#" parent anchor click → prevent scroll, toggle submenu
    $('#mobile-menu .menu-item-has-children > a[href="#"]').on('click', function (e) {
        e.preventDefault();
        $(this).siblings('.sub-menu-btn-icon').find('svg').toggleClass('fa-rotate-270');
        $(this).siblings('.sub-menu').slideToggle();
    });
});
