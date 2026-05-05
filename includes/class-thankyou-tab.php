<?php
// Add custom Thank You Page settings tab
add_filter('woocommerce_settings_tabs_array', function ($tabs) {
    $tabs['thank_you_page'] = __('Thank You Page', 'dynamic-order-email');
    return $tabs;
}, 50);

add_action('woocommerce_admin_field_doe_wysiwyg', function ($value) {
    $option_value = get_option($value['id'], $value['default']);
    echo '<tr valign="top">';
    echo '<th scope="row" class="titledesc"><label for="' . esc_attr($value['id']) . '">' . esc_html($value['title']) . '</label></th>';
    echo '<td class="forminp">';
    wp_editor($option_value, $value['id'], [
        'textarea_name' => $value['id'],
        'textarea_rows' => 10,
        'media_buttons' => true,
    ]);
    if (!empty($value['desc'])) {
        echo '<p class="description" style="margin-top: 10px;">' . wp_kses_post($value['desc']) . '</p>';
    }
    echo '</td>';
    echo '</tr>';
});

add_filter('woocommerce_admin_settings_sanitize_option_doe_global_email_footer', function ($value, $option, $raw_value) {
    return wp_kses_post($raw_value);
}, 10, 3);

add_action('woocommerce_settings_thank_you_page', function () {
    $default_footer = 'Warm Regards, <br>Team {company_name} <br>Phone: +1 877-925-1112 (Call and Chat) <br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
    $settings = [
        [
            'title' => __('Thank You Page Settings', 'dynamic-order-email'),
            'type'  => 'title',
            'id'    => 'thank_you_page_options',
        ],
        [
            'title'    => __('Contact Phone Number', 'dynamic-order-email'),
            'desc'     => __('Enter the phone number to display on the thank you page (e.g., +1 505-672-5168) after placing the order. Used for WhatsApp and general contact.', 'dynamic-order-email'),
            'id'       => 'woocommerce_thank_you_page_phone',
            'type'     => 'text',
            'default'  => '+1 505-672-5168',
            'desc_tip' => true,
            'css'      => 'width: 300px;',
        ],
        [
            'title'    => __('Global Email Footer', 'dynamic-order-email'),
            'desc'     => __('This email footer message will be automatically appended to all order emails. You can use placeholders like {company_name}.', 'dynamic-order-email'),
            'id'       => 'doe_global_email_footer',
            'type'     => 'doe_wysiwyg',
            'default'  => $default_footer,
        ],
        [
            'type' => 'sectionend',
            'id'   => 'thank_you_page_options',
        ],
    ];
    woocommerce_admin_fields($settings);
});

add_action('woocommerce_update_options_thank_you_page', function () {
    $default_footer = 'Warm Regards, <br>Team {company_name} <br>Phone: +1 877-925-1112 (Call and Chat) <br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
    woocommerce_update_options([
        [
            'id'       => 'woocommerce_thank_you_page_phone',
            'type'     => 'text',
            'default'  => '+1 505-672-5168',
        ],
        [
            'id'       => 'doe_global_email_footer',
            'type'     => 'doe_wysiwyg',
            'default'  => $default_footer,
        ],
    ]);
});