<?php
/**
 * Plugin Name: Dynamic Order Emails
 * Description: Sends dynamic emails after WooCommerce orders based on payment method, using WooCommerce email format. Includes custom payment gateways.
 * Version: 2.3.2
 * Author: AP
 * Requires Plugins: woocommerce
 * Text Domain: dynamic-order-emails
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}
if ( ! defined( 'DOE_PLUGIN_URL' ) ) {
    define( 'DOE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p><strong>Dynamic Order Emails</strong> requires WooCommerce to be installed and active.</p></div>';
    });
    return;
}

// Load text domain
add_action('plugins_loaded', function() {
    load_plugin_textdomain('dynamic-order-emails', false, dirname(plugin_basename(__FILE__)) . '/languages');
});
// Override WooCommerce thank you page template
add_filter('wc_get_template', function ($template, $template_name, $args, $template_path, $default_path) {
    if ($template_name === 'checkout/thankyou.php') {
        $custom_template = plugin_dir_path(__FILE__) . 'templates/woocommerce/checkout/thankyou.php';
        if (file_exists($custom_template)) {
            return $custom_template;
        }
    }
    return $template;
}, 10, 5);
// Include the main email class
require_once plugin_dir_path(__FILE__) . 'includes/class-dynamic-order-emails.php';

// Thank you Tab under WooCommerce >> settings tabs
require_once plugin_dir_path(__FILE__) . 'includes/class-thankyou-tab.php';

// Load all gateway files after WooCommerce core classes (priority 11)
add_action('plugins_loaded', function() {
    $gateway_files = glob(plugin_dir_path(__FILE__) . 'includes/gateways/class-wc-gateway-*.php');
    foreach ($gateway_files as $file) {
        require_once $file;
    }
}, 11);

// Register custom gateways
add_filter('woocommerce_payment_gateways', function($gateways) {
    $gateways[] = 'WC_Gateway_DOE_BACS';
    $gateways[] = 'WC_Gateway_DOE_Cheque';
    $gateways[] = 'WC_Gateway_DOE_COD';
    $gateways[] = 'WC_Gateway_Cashapp';
    $gateways[] = 'WC_Gateway_Zelle';
    $gateways[] = 'WC_Gateway_Venmo';
    return $gateways;
});

// Activation hook for migration
register_activation_hook(__FILE__, 'doe_migrate_settings');

function doe_migrate_settings() {
    // Migrate BACS to doe_bacs
    if ( get_option( 'doe_disable_emails_bacs' ) !== false ) {
        $bacs_settings = get_option('woocommerce_bacs_settings', []);
        $doe_bacs_settings = $bacs_settings;
        $doe_bacs_settings['doe_disable_emails'] = get_option('doe_disable_emails_bacs', false);
        $doe_bacs_settings['doe_bank_transfer_settings'] = $bacs_settings['doe_bank_transfer_settings'] ?? [];
        update_option('woocommerce_doe_bacs_settings', $doe_bacs_settings);
        delete_option('doe_disable_emails_bacs');
    }

    // Migrate Cheque to doe_cheque
    if ( get_option( 'doe_disable_emails_cheque' ) !== false ) {
        $cheque_settings = get_option('woocommerce_cheque_settings', []);
        $doe_cheque_settings = $cheque_settings;
        $doe_cheque_settings['doe_disable_emails'] = get_option('doe_disable_emails_cheque', false);
        $doe_cheque_settings['doe_check_usa_initial_subject'] = $cheque_settings['doe_check_usa_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $doe_cheque_settings['doe_check_usa_reminder_24_subject'] = $cheque_settings['doe_check_usa_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $doe_cheque_settings['doe_check_usa_reminder_48_subject'] = $cheque_settings['doe_check_usa_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $doe_cheque_settings['doe_check_usa'] = $cheque_settings['doe_check_usa'] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Please send your crypto payment for order #{order_id} to the provided wallet address. Simply mention your order number in the transaction details. <strong>DO NOT</strong> reference anything related to medicine or website names.<br><br>After completion of transfer, please share a screenshot or transaction ID.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
        update_option('woocommerce_doe_cheque_settings', $doe_cheque_settings);
        delete_option('doe_disable_emails_cheque');
    }

    // Migrate COD to doe_cod
    if ( get_option( 'doe_disable_emails_cod' ) !== false ) {
        $cod_settings = get_option('woocommerce_cod_settings', []);
        $doe_cod_settings = $cod_settings;
        $doe_cod_settings['doe_disable_emails'] = get_option('doe_disable_emails_cod', false);
        $doe_cod_settings['doe_card_initial_subject'] = $cod_settings['doe_card_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $doe_cod_settings['doe_card_reminder_24_subject'] = $cod_settings['doe_card_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $doe_cod_settings['doe_card_reminder_48_subject'] = $cod_settings['doe_card_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $doe_cod_settings['doe_card'] = $cod_settings['doe_card'] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable Order with us!!!<br><br><p><strong>Your total payable amount is {order_total} for Order Number: #{order_id}.</strong></p><br><p>For card payments, we have the following options available. Please indicate your preferred option, and we will promptly provide you with the necessary details.</p><br><p><strong>Bank Details:</strong></p><br><p><strong>1. Zelle Pay (USA Only)</strong><br /><strong>2. Venmo (USA Only)</strong><br /><strong>3. Cash App (USA Only)</strong><br /><strong>4. USDT (Crypto Pay)</strong><br /><strong>5. Remitly</strong><br /><strong>6. Western Union</strong></p><br><p><strong>Warm Regards,</strong><br />Team {company_name}<br />Phone: +1 505-672-5168 (Call and Chat)<br /><a href="https://api.whatsapp.com/send?phone=15056725168&text=Hi {company_name},%20Team" target="_blank">WhatsApp us</a> (For chat only)</p>';
        update_option('woocommerce_doe_cod_settings', $doe_cod_settings);
        delete_option('doe_disable_emails_cod');
    }

    // Migrate Zelle Pay
    if ( get_option( 'doe_disable_emails_zelle_pay' ) !== false ) {
        $zelle_settings = get_option('woocommerce_zelle_pay_settings', []);
        $zelle_settings['doe_disable_emails'] = get_option('doe_disable_emails_zelle_pay', false);
        $zelle_settings['doe_zelle_pay_usa_initial_subject'] = $zelle_settings['doe_zelle_pay_usa_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $zelle_settings['doe_zelle_pay_usa_reminder_24_subject'] = $zelle_settings['doe_zelle_pay_usa_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $zelle_settings['doe_zelle_pay_usa_reminder_48_subject'] = $zelle_settings['doe_zelle_pay_usa_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $zelle_settings['doe_zelle_pay_usa'] = $zelle_settings['doe_zelle_pay_usa'] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Please send your Zelle payment for order #{order_id} to the email address: <strong>payment@company.com</strong>. Simply mention your order number in the transaction details. <strong>DO NOT</strong> reference anything related to medicine or website names.<br><br>After completion of transfer, please share a screenshot or transaction confirmation.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
        $zelle_settings['doe_zelle_pay_au_initial_subject'] = $zelle_settings['doe_zelle_pay_au_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $zelle_settings['doe_zelle_pay_au_reminder_24_subject'] = $zelle_settings['doe_zelle_pay_au_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $zelle_settings['doe_zelle_pay_au_reminder_48_subject'] = $zelle_settings['doe_zelle_pay_au_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $zelle_settings['doe_zelle_pay_au'] = $zelle_settings['doe_zelle_pay_au'] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Please send your Zelle payment for order #{order_id} to the email address: <strong>payment@company.com</strong>. Simply mention your order number in the transaction details. <strong>DO NOT</strong> reference anything related to medicine or website names.<br><br>After completion of transfer, please share a screenshot or transaction confirmation.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
        update_option('woocommerce_zelle_pay_settings', $zelle_settings);
        delete_option('doe_disable_emails_zelle_pay');
    }

    // Migrate Cash App
    if ( get_option( 'doe_disable_emails_cash_app' ) !== false ) {
        $cash_app_settings = get_option('woocommerce_cash_app_settings', []);
        $cash_app_settings['doe_disable_emails'] = get_option('doe_disable_emails_cash_app', false);
        $cash_app_settings['doe_cash_app_usa_initial_subject'] = $cash_app_settings['doe_cash_app_usa_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $cash_app_settings['doe_cash_app_usa_reminder_24_subject'] = $cash_app_settings['doe_cash_app_usa_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $cash_app_settings['doe_cash_app_usa_reminder_48_subject'] = $cash_app_settings['doe_cash_app_usa_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $cash_app_settings['doe_cash_app_usa'] = $cash_app_settings['doe_cash_app_usa'] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>When making the payment, please ensure you include your order number, #{order_id}, in the comment section. This will help us to quickly and accurately process your order. Please do <strong>NOT</strong> include any other details in the comment like medicine or the website name.<br><br>Your total payable amount is {order_total}<br><br>To complete your payment, please use the following link via Cash App: <a href="https://cash.app/$Rolandpaul36">https://cash.app/$Rolandpaul36</a><br><br>Thank you for your cooperation. We appreciate your business.<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
        $cash_app_settings['doe_cash_app_au_initial_subject'] = $cash_app_settings['doe_cash_app_au_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $cash_app_settings['doe_cash_app_au_reminder_24_subject'] = $cash_app_settings['doe_cash_app_au_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $cash_app_settings['doe_cash_app_au_reminder_48_subject'] = $cash_app_settings['doe_cash_app_au_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $cash_app_settings['doe_cash_app_au'] = $cash_app_settings['doe_cash_app_au'] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>When making the payment, please ensure you include your order number, #{order_id}, in the comment section. This will help us to quickly and accurately process your order. Please do <strong>NOT</strong> include any other details in the comment like medicine or the website name.<br><br>Your total payable amount is {order_total}<br><br>To complete your payment, please use the following link via Cash App: <a href="https://cash.app/$Rolandpaul36">https://cash.app/$Rolandpaul36</a><br><br>Thank you for your cooperation. We appreciate your business.<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
        update_option('woocommerce_cash_app_settings', $cash_app_settings);
        delete_option('doe_disable_emails_cash_app');
    }

    // Migrate Venmo
    if ( get_option( 'doe_disable_emails_venmo_pay' ) !== false ) {
        $venmo_settings = get_option('woocommerce_venmo_pay_settings', []);
        $venmo_settings['doe_disable_emails'] = get_option('doe_disable_emails_venmo_pay', false);
        $venmo_settings['doe_venmo_pay_usa_initial_subject'] = $venmo_settings['doe_venmo_pay_usa_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $venmo_settings['doe_venmo_pay_usa_reminder_24_subject'] = $venmo_settings['doe_venmo_pay_usa_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $venmo_settings['doe_venmo_pay_usa_reminder_48_subject'] = $venmo_settings['doe_venmo_pay_usa_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $venmo_settings['doe_venmo_pay_usa'] = $venmo_settings['doe_venmo_pay_usa'] ?? 'Dear {customer_name},<br><br>Kindly use the "friends and family" option to avoid a 28% tax. When making the payment, please only mention your name or "Gift" in the comment section and avoid any mention of medicine or our website name. Also let us know once the payment is done we will process your order accordingly<br><br>Your total payable amount is {order_total}<br><br>Venmo to <strong>@Mark-Overson-1</strong>.<br>Let us know once the payment is complete, and we will proceed with your order accordingly. If you need the last 4 digits to verify the payments it\'s "3863"<br><br>Thank you,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
        $venmo_settings['doe_venmo_pay_au_initial_subject'] = $venmo_settings['doe_venmo_pay_au_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $venmo_settings['doe_venmo_pay_au_reminder_24_subject'] = $venmo_settings['doe_venmo_pay_au_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
        $venmo_settings['doe_venmo_pay_au_reminder_48_subject'] = $venmo_settings['doe_venmo_pay_au_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
        $venmo_settings['doe_venmo_pay_au'] = $venmo_settings['doe_venmo_pay_au'] ?? 'Dear {customer_name},<br><br>Kindly use the "friends and family" option to avoid a 28% tax. When making the payment, please only mention your name or "Gift" in the comment section and avoid any mention of medicine or our website name. Also let us know once the payment is done we will process your order accordingly<br><br>Your total payable amount is {order_total}<br><br>Venmo to <strong>@Mark-Overson-1</strong>.<br>Let us know once the payment is complete, and we will proceed with your order accordingly. If you need the last 4 digits to verify the payments it\'s "3863"<br><br>Thank you,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';
        update_option('woocommerce_venmo_pay_settings', $venmo_settings);
        delete_option('doe_disable_emails_venmo_pay');
    }
}

// Initialize the plugin
Dynamic_Order_Emails::get_instance(); 