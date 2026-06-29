<?php
add_action( 'wp_enqueue_scripts', function () {
    // Override the hardcoded background:#eee on the deeplinking breadcrumb bar
    wp_add_inline_style( 'envira-gallery-style',
        '.envira-breadcrumbs { background: transparent !important; }'
    );
}, 20 );
