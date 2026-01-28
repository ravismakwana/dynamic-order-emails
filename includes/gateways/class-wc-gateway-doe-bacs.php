<?php
if (!defined('ABSPATH')) {
    exit;
}

#[AllowDynamicProperties]
class WC_Gateway_DOE_BACS extends WC_Payment_Gateway {
    public $instructions;
    public $thank_you_page_text;
    public $email_header_text;
    public $plugin_id;

    public function __construct() {
        $this->id = 'doe_bacs';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = __('Bank Transfer (doe_bacs)', 'dynamic-order-emails');
        $this->method_description = __('Allows payments by bank transfer with dynamic email instructions.', 'dynamic-order-emails');

        // Ensure plugin_id exists for consistent field keys
        $this->plugin_id = 'woocommerce_';

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->thank_you_page_text = $this->get_option('thank_you_page_text');
        $this->email_header_text = $this->get_option('email_header_text');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
    }

    private function get_global_field_keys() {
        return [
            'enabled',
            'title',
            'description',
            'payment_instructions',
            'doe_dbt_initial_subject',
            'doe_dbt_initial_reminder_time',
            'doe_dbt_reminder_24_subject',
            'doe_dbt_reminder_24_time',
            'doe_dbt_reminder_48_subject',
            'doe_dbt_reminder_48_time',
        ];
    }

    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title'   => __('Enable/Disable', 'dynamic-order-emails'),
                'type'    => 'checkbox',
                'label'   => __('Enable DOE Bank Transfer', 'dynamic-order-emails'),
                'default' => 'yes',
            ],
            'title' => [
                'title'   => __('Default Title', 'dynamic-order-emails'),
                'type'    => 'text',
                'default' => __('Bank Transfer', 'dynamic-order-emails'),
                'description' => __('This controls the title which the user sees during checkout.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'dynamic-order-emails'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'dynamic-order-emails'),
                'default' => __('Pay via bank transfer using the provided details.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ],
            'instructions' => array(
                'title' => __('Instructions', 'dynamic-order-emails'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails (After header text).', 'dynamic-order-emails'),
                'default' => __('', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'doe_dbt_initial_reminder_time' => array(
                'title' => __('Initial Reminder Time', 'dynamic-order-emails'),
                'type' => 'reminder_time',
                'description' => __('Select type and value for the initial reminder time.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
			'doe_dbt_initial_subject' => [
                'title'       => __('Initial Email Subject', 'dynamic-order-emails'),
                'type'        => 'text',
                'default'     => __('PAYMENT LINK : [{company_name}]: New order #{order_id}', 'dynamic-order-emails'),
            ],
            'doe_dbt_reminder_24_time' => array(
                'title' => __('24h Reminder Time', 'dynamic-order-emails'),
                'type' => 'reminder_time',
                'description' => __('Select type and value for the 24h reminder time.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'doe_dbt_reminder_24_subject' => [
                'title'       => __('24h Reminder Email Subject', 'dynamic-order-emails'),
                'type'        => 'text',
                'default'     => __('REMINDER : [{company_name}]: New order #{order_id}', 'dynamic-order-emails'),
            ],
			'doe_dbt_reminder_48_time' => array(
                'title' => __('48h Reminder Time', 'dynamic-order-emails'),
                'type' => 'reminder_time',
                'description' => __('Select type and value for the 48h reminder time.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'doe_dbt_reminder_48_subject' => [
                'title'       => __('48h Reminder Email Subject', 'dynamic-order-emails'),
                'type'        => 'text',
                'default'     => __('KINDLY REMINDER : [{company_name}]: New order #{order_id}', 'dynamic-order-emails'),
            ],
			'thank_you_page_text_us' => array(
                'title' => __('Direct Bank Transfer – Order Received Page Text (US)', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'description' => __('Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails'),
                'default' => __('<div style="max-width: 500px; margin: 0 auto 20px; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center;"><h2 style="color: #28a745; margin-bottom: 15px;"><span style="color: #333333; font-size: 15px;"><b>We will send your payment link via email shortly. Please check your spam or junk folder if you do not receive it.</b></span></h2><p style="font-size: 15px; color: #333; margin-bottom: 12px;">If you have any questions in the meantime, feel free to reach out at
<a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>', 'dynamic-order-emails'),
                'desc_tip' => false,
            ),
			'email_header_text_us' => [
                'title'       => __('Direct Bank Transfer – Email Header (US)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}',
                'default'     => '<p>Thank you for shopping with us! Your order has been successfully placed.</p><strong>We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, Your order will be shipped within 24 hours and provide you the tracking number.</strong>',
            ],
            // Country-specific payment email bodies
            'direct_bank_transfer_email_body_us' => [
                'title'       => __('Direct Bank Transfer – Payment Email (United States)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => __('Full email body with HTML support. Placeholders: {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, {from_email}', 'dynamic-order-emails'),
                'default'     => __('<p>Hello {customer_name},</p><p>Thank you for placing a valuable Order with us!!! </p><p><strong>Your total payable amount is {order_total}</strong></p><p>Use the below given details to transfer the net amount, and simply mention your order number in the comment section. DO NOT reference anything related to <strong>medicine</strong> or website names. Just mention your order/number<span style="font-weight: 400"><br /></span><span style="font-weight: 400"><br /></span> Account Holder Name: <strong>GAJANAND ENTERPRISE</strong><br /><br /> Account Number: <strong>8339589472</strong><br /><br /> ACH Routing Number: <strong>026073150</strong></p><p>Account Type: <strong>Checking</strong></p><p>Bank name and address :<br /> Community Federal Savings Bank <br />5 Penn Plaza, 14th Floor, New York, NY 10001, US</p><p><span style="color: #3366ff"><em><span style="font-weight: 400">After completion of transfer</span></em></span> Please share a screenshot or receipt.</p><p><strong>Note</strong>: Your items will ship from India only. Delivery will take approximately 15-20 (Delivery may take up to 48 days from date of dispatch due to any disruption in postal services due to disruptions, weather issues or disruptions).</p><br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br /><br />We kindly ask you to not make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br /><br />We appreciate your patience and also your patronage of our pharmacy<br /><br />Warm Regards,<br />Team {company_name}<br />Phone: +1 877-925-1112 (Call and Chat)<br /><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)</p>', 'dynamic-order-emails'),
                'desc_tip'    =>false,
            ],
			'thank_you_page_text_uk' => array(
                'title' => __('Direct Bank Transfer – Order Received Page Text (UK)', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'description' => __('Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails'),
                'default' => __('<div style="max-width: 500px; margin: 0 auto 20px; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center;"><h2 style="color: #28a745; margin-bottom: 15px;"><span style="color: #333333; font-size: 15px;"><b>We will send your payment link via email shortly. Please check your spam or junk folder if you do not receive it.</b></span></h2><p style="font-size: 15px; color: #333; margin-bottom: 12px;">If you have any questions in the meantime, feel free to reach out at
<a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>', 'dynamic-order-emails'),
                'desc_tip' => false,
            ),
			'email_header_text_uk' => [
                'title'       => __('Direct Bank Transfer – Email Header (UK)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}',
                'default'     => '<p>Thank you for shopping with us! Your order has been successfully placed.</p><strong>We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, Your order will be shipped within 24 hours and provide you the tracking number.</strong>',
            ],
            'direct_bank_transfer_email_body_uk' => [
                'title'   => __('Direct Bank Transfer – Payment Email (United Kingdom)', 'dynamic-order-emails'),
                'type'    => 'wpeditor',
				'description' => __('Full email body with HTML support. Placeholders: {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, {from_email}', 'dynamic-order-emails'),
                'default' => __('Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Use the below given BANK DETAILS to transfer the net amount, and mention GIFT in the comment section. <strong>DO NOT</strong> reference anything related to medicine or website names. Just mention your order number pay us and share with us the photo of payment without photo we will not ship your order:<br><br><strong>Beneficiary:</strong> Nikunj Dobariya<br><strong>Sort Code:</strong> 23-01-20<br><strong>Account:</strong> 21506692<br><br>After completion of transfer, please share a screenshot or receipt.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br /><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)', 'dynamic-order-emails'),
            ],
			'thank_you_page_text_au' => array(
                'title' => __('Direct Bank Transfer – Order Received Page Text (Australia)', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'description' => __('Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails'),
                'default' => __('<div style="max-width: 500px; margin: 0 auto 20px; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center;"><h2 style="color: #28a745; margin-bottom: 15px;"><span style="color: #333333; font-size: 15px;"><b>We will send your payment link via email shortly. Please check your spam or junk folder if you do not receive it.</b></span></h2><p style="font-size: 15px; color: #333; margin-bottom: 12px;">If you have any questions in the meantime, feel free to reach out at
<a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>', 'dynamic-order-emails'),
                'desc_tip' => false,
            ),
			'email_header_text_au' => [
                'title'       => __('Direct Bank Transfer – Email Header (Australia)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}',
                'default'     => '<p>Thank you for shopping with us! Your order has been successfully placed.</p><strong>We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, Your order will be shipped within 24 hours and provide you the tracking number.</strong>',
            ],
			'direct_bank_transfer_email_body_au' => [
                'title'       => __('Direct Bank Transfer – Payment Email (Australia)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => __('Full email body with HTML support. Placeholders: {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, {from_email}', 'dynamic-order-emails'),
                'default'     => __('Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Use the below details to transfer the net amount, and simply mention your order number in the comment section. <strong>DO NOT</strong> reference anything related to medicine or website names. Just mention your order number:<br><br><strong>Account Holder Name:</strong> GAJANAND ENTERPRISE<br><strong>Account Number:</strong> 8339589472<br><strong>ACH Routing Number:</strong> 026073150<br><strong>Account Type:</strong> Checking<br><strong>Bank Name and Address:</strong> Community Federal Savings Bank, 5 Penn Plaza, 14th Floor, New York, NY 10001, US<br><br>After completion of transfer, please share a screenshot or receipt.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)', 'dynamic-order-emails'),
                'desc_tip'    => false,
            ],
			'thank_you_page_text_sg' => array(
                'title' => __('Direct Bank Transfer – Order Received Page Text (Singapore)', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'description' => __('Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails'),
                'default' => __('<div style="max-width: 500px; margin: 0 auto 20px; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center;"><h2 style="color: #28a745; margin-bottom: 15px;"><span style="color: #333333; font-size: 15px;"><b>We will send your payment link via email shortly. Please check your spam or junk folder if you do not receive it.</b></span></h2><p style="font-size: 15px; color: #333; margin-bottom: 12px;">If you have any questions in the meantime, feel free to reach out at
<a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>', 'dynamic-order-emails'),
                'desc_tip' => false,
            ),
			'email_header_text_sg' => [
                'title'       => __('Direct Bank Transfer – Email Header (Singapore)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}',
                'default'     => '<p>Thank you for shopping with us! Your order has been successfully placed.</p><strong>We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, Your order will be shipped within 24 hours and provide you the tracking number.</strong>',
            ],
            'direct_bank_transfer_email_body_sg' => [
                'title'   => __('Direct Bank Transfer – Payment Email (Singapore)', 'dynamic-order-emails'),
                'type'    => 'wpeditor',
				'description' => __('Full email body with HTML support. Placeholders: {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, {from_email}', 'dynamic-order-emails'),
                'default' => __('Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Use the below details to transfer the net amount, and simply mention your order number in the comment section. <strong>DO NOT</strong> reference anything related to medicine or website names. Just mention your order number:<br><br><strong>Account Holder Name:</strong> GAJANAND ENTERPRISE<br><strong>Account Number:</strong> 8339589472<br><strong>ACH Routing Number:</strong> 026073150<br><strong>Account Type:</strong> Checking<br><strong>Bank Name and Address:</strong> Community Federal Savings Bank, 5 Penn Plaza, 14th Floor, New York, NY 10001, US<br><br>After completion of transfer, please share a screenshot or receipt.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)', 'dynamic-order-emails'),
            ],
			'thank_you_page_text_my' => array(
                'title' => __('Direct Bank Transfer – Order Received Page Text (Malaysia)', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'description' => __('Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails'),
                'default' => __('<div style="max-width: 500px; margin: 0 auto 20px; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center;"><h2 style="color: #28a745; margin-bottom: 15px;"><span style="color: #333333; font-size: 15px;"><b>We will send your payment link via email shortly. Please check your spam or junk folder if you do not receive it.</b></span></h2><p style="font-size: 15px; color: #333; margin-bottom: 12px;">If you have any questions in the meantime, feel free to reach out at
<a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>', 'dynamic-order-emails'),
                'desc_tip' => false,
            ),
			'email_header_text_my' => [
                'title'       => __('Direct Bank Transfer – Email Header (Malaysia)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}',
                'default'     => '<p>Thank you for shopping with us! Your order has been successfully placed.</p><strong>We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, Your order will be shipped within 24 hours and provide you the tracking number.</strong>',
            ],
            'direct_bank_transfer_email_body_my' => [
                'title'   => __('Direct Bank Transfer – Payment Email (Malaysia)', 'dynamic-order-emails'),
                'type'    => 'wpeditor',
				'description' => __('Full email body with HTML support. Placeholders: {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, {from_email}', 'dynamic-order-emails'),
                'default' => __('Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Use the below details to transfer the net amount, and simply mention your order number in the comment section. <strong>DO NOT</strong> reference anything related to medicine or website names. Just mention your order number:<br><br><strong>Account Holder Name:</strong> GAJANAND ENTERPRISE<br><strong>Account Number:</strong> 8339589472<br><strong>ACH Routing Number:</strong> 026073150<br><strong>Account Type:</strong> Checking<br><strong>Bank Name and Address:</strong> Community Federal Savings Bank, 5 Penn Plaza, 14th Floor, New York, NY 10001, US<br><br>After completion of transfer, please share a screenshot or receipt.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)', 'dynamic-order-emails'),
            ],
			'thank_you_page_text_tw' => array(
                'title' => __('Direct Bank Transfer – Order Received Page Text (Taiwan)', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'description' => __('Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails'),
                'default' => __('<div style="max-width: 500px; margin: 0 auto 20px; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center;"><h2 style="color: #28a745; margin-bottom: 15px;"><span style="color: #333333; font-size: 15px;"><b>We will send your payment link via email shortly. Please check your spam or junk folder if you do not receive it.</b></span></h2><p style="font-size: 15px; color: #333; margin-bottom: 12px;">If you have any questions in the meantime, feel free to reach out at
<a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>', 'dynamic-order-emails'),
                'desc_tip' => false,
            ),
			'email_header_text_tw' => [
                'title'       => __('Direct Bank Transfer – Email Header (Taiwan)', 'dynamic-order-emails'),
                'type'        => 'wpeditor',
                'description' => 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}',
                'default'     => '<p>Thank you for shopping with us! Your order has been successfully placed.</p><strong>We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, Your order will be shipped within 24 hours and provide you the tracking number.</strong>',
            ],
            'direct_bank_transfer_email_body_tw' => [
                'title'   => __('Direct Bank Transfer – Payment Email (Taiwan)', 'dynamic-order-emails'),
                'type'    => 'wpeditor',
				'description' => __('Full email body with HTML support. Placeholders: {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, {from_email}', 'dynamic-order-emails'),
                'default' => __('Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Use the below details to transfer the net amount, and simply mention your order number in the comment section. <strong>DO NOT</strong> reference anything related to medicine or website names. Just mention your order number:<br><br><strong>Account Holder Name:</strong> GAJANAND ENTERPRISE<br><strong>Account Number:</strong> 8339589472<br><strong>ACH Routing Number:</strong> 026073150<br><strong>Account Type:</strong> Checking<br><strong>Bank Name and Address:</strong> Community Federal Savings Bank, 5 Penn Plaza, 14th Floor, New York, NY 10001, US<br><br>After completion of transfer, please share a screenshot or receipt.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)', 'dynamic-order-emails'),
            ],
        ];
    }

    public function generate_reminder_time_html($key, $data) {
		$field_key = $this->get_field_key($key);
		$enable = $this->get_option($field_key . '_enable', 'yes');
		$time_type = $this->get_option($field_key . '_type', 'hour');
		$time_value = $this->get_option($field_key . '_value', '24');

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr($field_key); ?>"><?php echo esc_html($data['title']); ?></label>
			</th>
			<td class="forminp forminp-<?php echo sanitize_title($data['type']); ?>">
				<label>
					<!-- Match COD pattern: woocommerce_{id}_{field_key}_enable -->
					<input type="checkbox" name="woocommerce_<?php echo $this->id; ?>_<?php echo $field_key; ?>_enable" value="yes" <?php checked($enable, 'yes'); ?> />
					<?php _e('Enable', 'dynamic-order-emails'); ?>
				</label>
				<br><br>
				<select name="woocommerce_<?php echo $this->id; ?>_<?php echo $field_key; ?>_type" style="width:120px; display:inline-block; margin-right:10px;">
					<option value="second" <?php selected($time_type, 'second'); ?>><?php _e('Seconds', 'dynamic-order-emails'); ?></option>
					<option value="minute" <?php selected($time_type, 'minute'); ?>><?php _e('Minutes', 'dynamic-order-emails'); ?></option>
					<option value="hour" <?php selected($time_type, 'hour'); ?>><?php _e('Hours', 'dynamic-order-emails'); ?></option>
				</select>
				<input type="number" min="1" step="1" name="woocommerce_<?php echo $this->id; ?>_<?php echo $field_key; ?>_value" value="<?php echo esc_attr($time_value); ?>" style="width:80px; display:inline-block;" />
				<p class="description"><?php echo esc_html($data['description'] ?? ''); ?></p>
			</td>
		</tr>
		<?php
		return ob_get_clean();
	}

	public function validate_reminder_time_field($key, $value) {
		$field_key = $this->get_field_key($key);

		// Match COD pattern
		$enable = isset($_POST['woocommerce_' . $this->id . '_' . $field_key . '_enable']) ? 'yes' : 'no';
		$this->update_option($field_key . '_enable', $enable);

		$type = isset($_POST['woocommerce_' . $this->id . '_' . $field_key . '_type']) 
			? sanitize_text_field($_POST['woocommerce_' . $this->id . '_' . $field_key . '_type']) 
			: 'hour';
		$this->update_option($field_key . '_type', $type);

		$val = isset($_POST['woocommerce_' . $this->id . '_' . $field_key . '_value']) 
			? absint($_POST['woocommerce_' . $this->id . '_' . $field_key . '_value']) 
			: 1;
		$this->update_option($field_key . '_value', $val);

		return;
	}

    public function generate_wpeditor_html($key, $data) {
        $field_key = $this->plugin_id . $this->id . '_' . $key;
        $defaults = array(
            'title' => '',
            'description' => '',
            'desc_tip' => false,
        );
        $data = wp_parse_args($data, $defaults);

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field_key); ?>"><?php echo wp_kses_post($data['title']); ?></label>
                <?php echo $this->get_tooltip_html($data); ?>
            </th>
            <td class="forminp forminp-textarea">
                <?php
                wp_editor(
                    $this->get_option($key),
                    $field_key,
                    array(
                        'textarea_name' => $this->plugin_id . $this->id . '_' . $key,
                        'textarea_rows' => 10,
                        'media_buttons' => false,
                    )
                );
                ?>
                <?php echo $this->get_description_html($data); ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    public function validate_wpeditor_field($key, $value) {
        return wp_kses_post($value);
    }

    public function admin_options() {
        ?>
        <h2><?php echo esc_html($this->get_method_title()); ?></h2>
        <p><?php echo esc_html($this->get_method_description()); ?></p>
		<table class="form-table">
            <?php $this->generate_settings_html( array_intersect_key( $this->form_fields, array_flip([
                'enabled',
				'title',
				'description',
				'instructions',
				'thank_you_page_text',
				'email_header_text',
				'doe_dbt_initial_subject',
				'doe_dbt_initial_reminder_time',
				'doe_dbt_reminder_24_subject',
				'doe_dbt_reminder_24_time',
				'doe_dbt_reminder_48_subject',
				'doe_dbt_reminder_48_time',
            ]) ) ); ?>
        </table>

					
        <div class="doe-tabs-wrapper">
            <nav class="nav-tab-wrapper">
                <a href="#tab-us" class="nav-tab nav-tab-active">United States</a>
                <a href="#tab-uk" class="nav-tab">United Kingdom</a>
				<a href="#tab-au" class="nav-tab">Australia</a>
                <a href="#tab-sg" class="nav-tab">Singapore</a>
                <a href="#tab-my" class="nav-tab">Malaysia</a>
                <a href="#tab-tw" class="nav-tab">Taiwan</a>
            </nav>
			<div id="tab-us" class="doe-tab-content postbox" style="display: block;">
                <div class="form-table">
                    <?php echo $this->generate_wpeditor_html('thank_you_page_text_us', $this->form_fields['thank_you_page_text_us']); ?>
				</div>
				<div class="form-table">
					<?php echo $this->generate_wpeditor_html('email_header_text_us', $this->form_fields['email_header_text_us']); ?>
				</div>
				<div class="form-table">
					<?php echo $this->generate_wpeditor_html('direct_bank_transfer_email_body_us', $this->form_fields['direct_bank_transfer_email_body_us']); ?>
                </div>
            </div>

            <div id="tab-uk" class="doe-tab-content postbox" style="display: none;">
				<div class="form-table">
                    <?php echo $this->generate_wpeditor_html('thank_you_page_text_uk', $this->form_fields['thank_you_page_text_uk']); ?>
				</div>
				<div class="form-table">
					<?php echo $this->generate_wpeditor_html('email_header_text_uk', $this->form_fields['email_header_text_uk']); ?>
				</div>
                <div class="form-table">
                    <?php echo $this->generate_wpeditor_html('direct_bank_transfer_email_body_uk', $this->form_fields['direct_bank_transfer_email_body_uk']); ?>
                </div>
            </div>
			<div id="tab-au" class="doe-tab-content postbox" style="display: none;">
				<div class="form-table">
                    <?php echo $this->generate_wpeditor_html('thank_you_page_text_au', $this->form_fields['thank_you_page_text_au']); ?>
				</div>
				<div class="form-table">
					<?php echo $this->generate_wpeditor_html('email_header_text_au', $this->form_fields['email_header_text_au']); ?>
				</div>
                <div class="form-table">
                    <?php echo $this->generate_wpeditor_html('direct_bank_transfer_email_body_au', $this->form_fields['direct_bank_transfer_email_body_au']); ?>
                </div>
            </div>
            <div id="tab-sg" class="doe-tab-content postbox" style="display: none;">
				<div class="form-table">
                    <?php echo $this->generate_wpeditor_html('thank_you_page_text_sg', $this->form_fields['thank_you_page_text_sg']); ?>
				</div>
				<div class="form-table">
					<?php echo $this->generate_wpeditor_html('email_header_text_sg', $this->form_fields['email_header_text_sg']); ?>
				</div>
                <div class="form-table">
                    <?php echo $this->generate_wpeditor_html('direct_bank_transfer_email_body_sg', $this->form_fields['direct_bank_transfer_email_body_sg']); ?>
                </div>
            </div>

            <div id="tab-my" class="doe-tab-content postbox" style="display: none;">
				<div class="form-table">
                    <?php echo $this->generate_wpeditor_html('thank_you_page_text_my', $this->form_fields['thank_you_page_text_my']); ?>
				</div>
				<div class="form-table">
					<?php echo $this->generate_wpeditor_html('email_header_text_my', $this->form_fields['email_header_text_my']); ?>
				</div>
                <div class="form-table">
                    <?php echo $this->generate_wpeditor_html('direct_bank_transfer_email_body_my', $this->form_fields['direct_bank_transfer_email_body_my']); ?>
                </div>
            </div>

            <div id="tab-tw" class="doe-tab-content postbox" style="display: none;">
				<div class="form-table">
                    <?php echo $this->generate_wpeditor_html('thank_you_page_text_tw', $this->form_fields['thank_you_page_text_tw']); ?>
				</div>
				<div class="form-table">
					<?php echo $this->generate_wpeditor_html('email_header_text_tw', $this->form_fields['email_header_text_tw']); ?>
				</div>
                <div class="form-table">
                    <?php echo $this->generate_wpeditor_html('direct_bank_transfer_email_body_tw', $this->form_fields['direct_bank_transfer_email_body_tw']); ?>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $tabs = $('.doe-tabs-wrapper .nav-tab-wrapper');
            $tabs.on('click', 'a', function(e) {
                e.preventDefault();
                var $tab = $(this);
                var target = $tab.attr('href');

                $tabs.find('a').removeClass('nav-tab-active');
                $tab.addClass('nav-tab-active');

                $('.doe-tab-content').hide();
                $(target).show();
            });
        });
        </script>
		<style>
		.doe-tab-content.postbox { padding: 15px;}
		.doe-tab-content .form-table { margin-bottom: 50px; }
		</style>
        <?php
    }

    public function process_admin_options() {
        parent::process_admin_options();
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        $order->update_status('on-hold', __('Awaiting bank transfer payment', 'dynamic-order-emails'));
        wc_reduce_stock_levels($order_id);
        WC()->cart->empty_cart();
        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        ];
    }

    public function get_reminder_time($key) {
        $enabled = $this->get_option($key . '_enable', 'no');
        $type    = $this->get_option($key . '_type', 'hour');
        $value   = intval($this->get_option($key . '_value', 1));

        return [
            'enable' => $enabled === 'yes' ? true : false,
            'type'   => $type,
            'value'  => $value,
        ];
    }
	/**
	 * Display thank you page content based on customer's billing country
	 */
	public function thankyou_page($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			$this->log_debug("Order #{$order_id} - Invalid order ID");
			return;
		}

		// Get billing country
		$billing_country = $order->get_billing_country();
		$country_map = [
			'US' => 'us',
			'GB' => 'uk',
			'AU' => 'au',
			'SG' => 'sg',
			'MY' => 'my',
			'TW' => 'tw',
		];
		$country_key = $country_map[$billing_country] ?? 'us';

		// Fetch settings for this gateway
		$settings = get_option('woocommerce_' . $this->id . '_settings', []);

		// Try to get country-specific thank you page text
		$thank_you_key = 'thank_you_page_text_' . $country_key;
		$content = !empty($settings[$thank_you_key]) ? $settings[$thank_you_key] : '';

		// Fallback to instructions if no country-specific text
		if (empty($content)) {
			$content = $this->instructions;
			$this->log_debug("Order #{$order->get_id()} - Using fallback instructions for country: $billing_country");
		} else {
			$this->log_debug("Order #{$order->get_id()} - Using thank_you_page_text_{$country_key} for country: $billing_country");
		}

		if ($content) {
			$content = $this->replace_placeholders($content, $order);
			echo wp_kses_post(wpautop(wptexturize($content)));
		} else {
			$this->log_debug("Order #{$order->get_id()} - No thank_you_page_text or instructions found for {$this->id}");
		}
	}
	 /**
     * Log debug messages if debugging is enabled
     */
    private function log_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logger = wc_get_logger();
            $logger->debug($message, ['source' => 'wc-gateway-doe-bacs']);
        }
    }
	/**
	 * Display email instructions based on customer's billing country
	 * Show header ONLY on 'on-hold' or 'processing' status
	 */
	public function email_instructions($order, $sent_to_admin, $plain_text = false) {
		if ($sent_to_admin || $order->get_payment_method() !== $this->id) {
			return;
		}

		// Only show header for these statuses
		$allowed_statuses = ['on-hold', 'processing'];
		$current_status = $order->get_status();
		$show_header = in_array($current_status, $allowed_statuses, true);

		// Get billing country
		$billing_country = $order->get_billing_country();
		$country_map = [
			'US' => 'us',
			'GB' => 'uk',
			'AU' => 'au',
			'SG' => 'sg',
			'MY' => 'my',
			'TW' => 'tw',
		];
		$country_key = $country_map[$billing_country] ?? 'us';

		// Fetch settings
		$settings = get_option('woocommerce_' . $this->id . '_settings', []);

		// Try country-specific header
		$email_header_key = 'email_header_text_' . $country_key;
		$header = !empty($settings[$email_header_key]) ? $settings[$email_header_key] : '';

		// Fallback to generic header
		if (empty($header)) {
			$header = $this->email_header_text ?: '';
			$this->log_debug("Order #{$order->get_id()} - Using fallback email_header_text for country: $billing_country");
		} else {
			$this->log_debug("Order #{$order->get_id()} - Using email_header_text_{$country_key} for country: $billing_country");
		}

		// Only include header if status allows
		if (!$show_header) {
			$header = '';
			$this->log_debug("Order #{$order->get_id()} - Header suppressed (status: $current_status)");
		}

		// Body is always instructions
		$body = $this->instructions ?: '';

		$content = trim($header . ($header && $body ? "\n\n" : '') . $body);
		if (!$content) {
			$this->log_debug("Order #{$order->get_id()} - No email content found");
			return;
		}

		$content = $this->replace_placeholders($content, $order);

		if ($plain_text) {
			echo wptexturize(wp_strip_all_tags($content)) . PHP_EOL;
		} else {
			echo wpautop(wptexturize($content)) . PHP_EOL;
		}
	}
	private function extractDomain($url) {
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
	private function replace_placeholders($text, $order) {
        if (!$order) return $text;
        $customer_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        $order_total = wc_price($order->get_total(), array('currency' => $order->get_currency()));
		$email_from_address = get_option('woocommerce_email_from_address');
        $replacements = array(
            '{customer_name}' => $customer_name ?: __('Customer', 'dynamic-order-emails'),
            '{order_id}' => $order->get_id(),
            '{order_number}' => $order->get_order_number(),
            '{order_total}' => strip_tags($order_total),
            '{currency}' => $order->get_currency(),
            '{company_name}' => get_bloginfo('name'),
            '{from_email}' => $this->extractDomain($email_from_address),
        );
        return strtr($text, $replacements);
    }
}
?>