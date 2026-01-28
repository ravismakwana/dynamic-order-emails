<?php
/**
 * Thankyou page
 *
 * This template is loaded from the plugin to override WooCommerce's default thankyou.php.
 *
 * @package YourPlugin/WooCommerce
 */


defined('ABSPATH') || exit;
$order_id = isset($order) ? $order->get_id() : $order_id; // Get the order ID dynamically
$order = wc_get_order($order_id); // Fetch the order object
$dvm_image_url = DOE_PLUGIN_URL . 'includes/assets/images/dvm.png';

function extractDomain($url) {
	// Get the host part from the URL (if no scheme, parse_url may return null)
	$host = parse_url(trim($url), PHP_URL_HOST) ?: trim($url);

	// If still empty, try removing scheme manually
	if (empty($host)) {
		$host = preg_replace('#^https?://#i', '', $url);
	}

	// Remove 'www.' if present
	$host = preg_replace('/^www\./i', '', $host);

	// Remove any trailing slash
	$host = rtrim($host, '/');

	return $host;
}

// Fetch order details
$order_total = $order->get_total();
$order_number = $order->get_order_number();
$order_date = $order->get_date_created()->format('F j, Y');
$payment_method = $order->get_payment_method_title();
$last_updated = $order->get_date_modified() ? $order->get_date_modified()->format('F j, Y') : 'N/A';
$payment_method_id = $order->get_payment_method();
$email_from_address = get_option('woocommerce_email_from_address');
$admin_email = extractDomain($email_from_address);
// Fetch order items
$order_items = '';
foreach ($order->get_items() as $item_id => $item) {
    $order_items .= $item->get_name() . ' x ' . $item->get_quantity() . '<br>';
}

// Fetch thank_you_page_text from payment gateway settings
$thank_you_page_text = '';
$gateway_settings_map = [
    'zelle_pay' => 'woocommerce_zelle_pay_settings',
    'cash_app' => 'woocommerce_cash_app_settings',
    'doe_cod' => 'woocommerce_doe_cod_settings',
    'venmo_pay' => 'woocommerce_venmo_pay_settings',
    'doe_bacs' => 'woocommerce_doe_bacs_settings',
    'doe_cheque' => 'woocommerce_doe_cheque_settings',
];

if (isset($gateway_settings_map[$payment_method_id])) {
    $settings = get_option($gateway_settings_map[$payment_method_id], []);
    $thank_you_page_text = !empty($settings['thank_you_page_text']) ? $settings['thank_you_page_text'] : '';
}

// Fallback default note if thank_you_page_text is empty
if (empty($thank_you_page_text)) {
    $thank_you_page_text = 'We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, your order will be shipped within 24 hours and you will be provided the tracking number.';
}

// Replace placeholders
$replacements = [
    '{order_number}' => $order_number,
    '{order_total}' => $order_total,
    '{currency}' => get_woocommerce_currency(),
    '{customer_name}' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
    '{from_email}' => $admin_email,
];
$thank_you_page_text = str_replace(
    array_keys($replacements),
    array_values($replacements),
    $thank_you_page_text
);
function doe_print_site_logo( $width = 230, $height = 50 ) {
    $custom_logo_id = get_theme_mod( 'custom_logo' );
    $logo = wp_get_attachment_image_src( $custom_logo_id, 'full' );

    if ( $logo ) {
        return '<img src="' . esc_url( $logo[0] ) . '" alt="' . get_bloginfo( 'name' ) . '" width="' . intval( $width ) . '" height="' . intval( $height ) . '" />';
    } else {
        return '<h1>' . esc_html( get_bloginfo( 'name' ) ) . '</h1>';
    }
}
// Fetch phone number from settings
$contact_phone = get_option('woocommerce_thank_you_page_phone', '+1 505-672-5168');
$whatAppMsg = 'Hi,%20'.esc_html( get_bloginfo( 'name' ) ).'%20Team';
$whatsapp_url = 'https://api.whatsapp.com/send?phone=' . preg_replace('/[^0-9]/', '', $contact_phone) . '&text='.$whatAppMsg;
?>

<div class="container">
    <div class="thank-you-container py-5">
        <h4 class="text-success fw-bold">Congratulations 🎉</h4>
        <p class="bg-primary-subtle p-3 mb-5">Thank you. Your order has been received. <br/>Please check your email for payment process.<br/>
If you do not receive email, Kindly check your spam folder.</p>
        <div class="row align-items-center">
            <!-- Order Details -->
            <div class="col-md-6">
                <div class="order-details shadow rounded-4">
                    <div class="order-logo text-center p-3 border-bottom">
                        <?php echo doe_print_site_logo('230', '53'); ?>
                    </div>
                    <h4 class="text-black fw-bold text-center fs-2 py-3 px-2"><?php echo esc_html(get_woocommerce_currency_symbol() . number_format($order_total, 2)); ?></h4>
                    <div class="order-details-table-wrap px-3 mx-auto pb-5 mb-md-5 mb-0 table-responsive">
                        <table class="">
                            <tbody>
                                <tr>
                                    <td class="title pb-3"><span class="text-primary">Order Number:</span></td>
                                    <td class="pb-3"><strong class="text-black"><?php echo esc_html($order_number); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="title pb-3"><span class="text-primary">Date:</span></td>
                                    <td class="pb-3"><strong class="text-black"><?php echo esc_html($order_date); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="title pb-3"><span class="text-primary">Payment Method:</span></td>
                                    <td class="pb-3"><strong class="text-black"><?php echo esc_html(strip_tags($payment_method)); ?></strong></td>
                                </tr>
                                <tr>
                                    <td class="title pb-3"><span class="text-primary">Order Contains:</span></td>
                                    <td class="pb-3"><strong class="text-black"><?php echo wp_kses_post($order_items); ?></strong></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="bg-primary text-center p-2 text-white rounded-bottom-4 d-flex flex-lg-row flex-column align-items-center gap-1 justify-content-center"><span class="text-white fs-5"><?php echo bloginfo( 'name' ); ?></span> • Last Updated <?php echo esc_html($last_updated); ?></p>
                </div>
            </div>

            <!-- Payment Details -->
            <div class="col-md-6">
                <div class="payment-details">
                    <div class="pb-2 payment-details-text mx-auto"><strong><?php do_action('woocommerce_thankyou_' . $payment_method_id, $order_id);?></strong></div>
                    <?php if (in_array($payment_method_id, ['zelle_pay', 'venmo_pay', 'cash_app', 'doe_cod', 'doe_cheque'])) : ?>
                        <div class="my-3 d-flex justify-content-center">
                            <img width="400" height="89" src="<?php echo esc_url($dvm_image_url); ?>" alt="Payment Options" style="max-width: 400px; width: 100%; height: auto;" class="payment-img">
                        </div>
					<?php endif; ?>
                </div>
            </div>
        </div>
        <div class="bottom-note-wrap py-1 border border-end-0 border-start-0">
            <p class="note mb-0 py-1 border border-end-0 border-start-0 fs-12 fw-bold">
                <strong class="text-black">NOTE:</strong><br> The average shipping time is 15-22 days. Please note that delivery may take up to 30 days from the date of dispatch due to potential disruptions in postal services caused by weather issues or natural disasters.
            </p>
        </div>

        <!-- Contact Section -->
        <ul class="contact-section d-flex flex-column flex-lg-row justify-content-between list-unstyled m-0 mb-0 pt-3 gap-2">
            <li class="align-items-center d-flex gap-2 py-0 justify-content-lg-center px-lg-2">
                <div class="align-items-center d-flex justify-content-center">
                    <svg id="icon-whatsapp-green" width="25px" height="25px" viewBox="-5.52 -5.52 35.04 35.04" fill="none" xmlns="http://www.w3.org/2000/svg" stroke="">
                        <g id="SVGRepo_bgCarrier" stroke-width="0">
                            <rect x="-5.52" y="-5.52" width="35.04" height="35.04" rx="17.52" fill="#42D741" stroke-width="0"></rect>
                        </g>
                        <g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g>
                        <g id="SVGRepo_iconCarrier">
                            <path fill-rule="evenodd" clip-rule="evenodd" d="M3.50002 12C3.50002 7.30558 7.3056 3.5 12 3.5C16.6944 3.5 20.5 7.30558 20.5 12C20.5 16.6944 16.6944 20.5 12 20.5C10.3278 20.5 8.77127 20.0182 7.45798 19.1861C7.21357 19.0313 6.91408 18.9899 6.63684 19.0726L3.75769 19.9319L4.84173 17.3953C4.96986 17.0955 4.94379 16.7521 4.77187 16.4751C3.9657 15.176 3.50002 13.6439 3.50002 12ZM12 1.5C6.20103 1.5 1.50002 6.20101 1.50002 12C1.50002 13.8381 1.97316 15.5683 2.80465 17.0727L1.08047 21.107C0.928048 21.4637 0.99561 21.8763 1.25382 22.1657C1.51203 22.4552 1.91432 22.5692 2.28599 22.4582L6.78541 21.1155C8.32245 21.9965 10.1037 22.5 12 22.5C17.799 22.5 22.5 17.799 22.5 12C22.5 6.20101 17.799 1.5 12 1.5ZM14.2925 14.1824L12.9783 15.1081C12.3628 14.7575 11.6823 14.2681 10.9997 13.5855C10.2901 12.8759 9.76402 12.1433 9.37612 11.4713L10.2113 10.7624C10.5697 10.4582 10.6678 9.94533 10.447 9.53028L9.38284 7.53028C9.23954 7.26097 8.98116 7.0718 8.68115 7.01654C8.38113 6.96129 8.07231 7.046 7.84247 7.24659L7.52696 7.52195C6.76823 8.18414 6.3195 9.2723 6.69141 10.3741C7.07698 11.5163 7.89983 13.314 9.58552 14.9997C11.3991 16.8133 13.2413 17.5275 14.3186 17.8049C15.1866 18.0283 16.008 17.7288 16.5868 17.2572L17.1783 16.7752C17.4313 16.5691 17.5678 16.2524 17.544 15.9269C17.5201 15.6014 17.3389 15.308 17.0585 15.1409L15.3802 14.1409C15.0412 13.939 14.6152 13.9552 14.2925 14.1824Z" fill="#fff"></path>
                        </g>
                    </svg>
                </div>
                <a href="<?php echo esc_url($whatsapp_url); ?>" target="_blank" class="fw-bold text-black text-decoration-none">Contact Us: <?php echo esc_html($contact_phone); ?></a>
            </li>
            <li class="align-items-center d-flex gap-2 justify-content-lg-center border border-2 border-bottom-0 border-top-0 d-flex gap-2 px-lg-2 py-0">
                <div class="align-items-center bg-black d-flex justify-content-center p-1 rounded-2 svg-icon-wrap">
                    <svg class="" width="16px" height="16px" fill="#fff">
                        <use href="#icon-email"></use>
                    </svg>
                </div>
                <a href="mailto:<?php echo $admin_email; ?>" class="fw-bold text-black text-decoration-none">Email: <?php echo $admin_email; ?></a>
            </li>
            <li class="align-items-center d-flex gap-2 py-0 justify-content-lg-center px-lg-2">
                <div class="align-items-center bg-black d-flex justify-content-center p-1 rounded-2 svg-icon-wrap">
                    <svg class="" width="16px" height="16px" fill="#fff">
                        <use href="#icon-support"></use>
                    </svg>
                </div>
                <a href="#" class="fw-bold text-black text-decoration-none">Customer Support </a>
                <span>(Available 24/7)</span>
            </li>
        </ul>
    </div>
</div>