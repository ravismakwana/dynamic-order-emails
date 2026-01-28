<?php
// Add custom Thank You Page settings tab
add_filter('woocommerce_settings_tabs_array', function ($tabs) {
    $tabs['thank_you_page'] = __('Thank You Page', 'dynamic-order-email');
    return $tabs;
}, 50);

add_action('woocommerce_settings_thank_you_page', function () {
    $settings = [
        [
            'title' => __('Thank You Page Settings', 'dynamic-order-email'),
            'type'  => 'title',
            'id'    => 'thank_you_page_options',
        ],
        [
            'title'    => __('Contact Phone Number', 'dynamic-order-email'),
            'desc'     => __('Enter the phone number to display on the thank you page (e.g., +1 505-672-5168). Used for WhatsApp and general contact.', 'dynamic-order-email'),
            'id'       => 'woocommerce_thank_you_page_phone',
            'type'     => 'text',
            'default'  => '+1 505-672-5168',
            'desc_tip' => true,
            'css'      => 'width: 300px;',
        ],
        [
            'type' => 'sectionend',
            'id'   => 'thank_you_page_options',
        ],
    ];
    woocommerce_admin_fields($settings);
});

add_action('woocommerce_update_options_thank_you_page', function () {
    woocommerce_update_options([
        [
            'id'       => 'woocommerce_thank_you_page_phone',
            'type'     => 'text',
            'default'  => '+1 505-672-5168',
        ],
    ]);
});