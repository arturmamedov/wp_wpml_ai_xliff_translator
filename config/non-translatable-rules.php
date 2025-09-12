<?php

return [
    'patterns' => [
        'url' => '/^https?:\/\//',
        'email' => '/^[^\s@]+@[^\s@]+\.[^\s@]+$/',
        'shortcode' => '/^\[[\w_-]+.*\]$/',
        'phone' => '/^\+?\d[\d\s-]{8,}$/',
        'coordinate' => '/^-?\d+\.\d+$/',
        'json_ld' => '/^\s*\{\s*"@context"/',
        'iframe' => '/<iframe.*<\/iframe>/i',
        'postal_code' => '/^\d{5}(-\d{4})?$/',
        'youtube_url' => '/youtube\.be\/|youtu\.be\//',
        'whatsapp_url' => '/wa\.me\//',
        'google_maps' => '/google\.com\/maps\/embed/',
        'wordpress_comment' => '/<!--.*-->/s' // TODO: #1 XLIFFParaser.php -> applyNonTranslatableRules()
    ],

    'exact_matches' => [
        // Hostel names and locations
        'duque-nest',
        'Duque Nest',
        'nestshostels.cloudbeds.com',
        'Tenerife',
        'Teneriffa',
        'Costa Adeje',
        'Playa del Duque',
        'Santa Cruz de Tenerife',

        // Contact details that shouldn't change
        'duquenesthostel@gmail.com',
        '+34 655 01 20 55',
        '+34 670 01 20 55',
        '38660',
        '38679',

        // Technical identifiers
        'ES', // Country code
        'EUR', // Currency
        'Mo-Su 08:00-23:00',
        '13:00:00',
        '10:30:00',

        // Brand elements
        'NEST PASS',
        'Nests Hostels',
        'Medano Nest'
    ],

    'content_patterns' => [
        // WordPress/technical content
        'gutenberg_comment' => '/<!-- \/wp:/',  // TODO: #1 XLIFFParaser.php -> applyNonTranslatableRules()
        'cdata_section' => '/<!\[CDATA\[.*\]\]>/s',
        'html_entity' => '/&[a-zA-Z]+;/',
        'css_style' => '/style\s*=\s*["\'].*["\']/i',
        'html_attributes' => '/(width|height|src|href|alt|title)\s*=\s*["\'].*["\']/i'
    ]
];
