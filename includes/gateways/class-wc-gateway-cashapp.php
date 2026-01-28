<?php
if (!defined('ABSPATH')) {
    exit;
}

class WC_Gateway_Cashapp extends WC_Payment_Gateway {
    public $instructions;
    public $payment_instructions;
    public $thank_you_page_text;
    public $email_header_text;
    public $enable_for_methods;
    public $enable_for_virtual;
    public $doe_disable_emails;

    public function __construct() {
        $this->id = 'cash_app';
        $this->icon = apply_filters('wc_cash_app_icon', '');
        $this->has_fields = false;
        $this->method_title = __('Cash App', 'dynamic-order-emails');
        $this->method_description = __('Allow customers to pay via Cash App at checkout.', 'dynamic-order-emails');

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->instructions = $this->get_option('instructions');
        $this->payment_instructions = $this->get_option('payment_instructions');
        $this->thank_you_page_text = $this->get_option('thank_you_page_text');
        $this->email_header_text = $this->get_option('email_header_text');
        $this->enable_for_methods = $this->get_option('enable_for_methods', array());
        $this->enable_for_virtual = $this->get_option('enable_for_virtual', 'yes') === 'yes';
        $this->doe_disable_emails = $this->get_option('doe_disable_emails');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);

        // Email instructions
        add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
    }

    public function init_form_fields() {
        $shipping_methods = array();
        foreach (WC()->shipping()->load_shipping_methods() as $method) {
            $shipping_methods[$method->id] = $method->get_method_title();
        }

        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'dynamic-order-emails'),
                'type' => 'checkbox',
                'label' => __('Enable Cash App Payment', 'dynamic-order-emails'),
                'default' => 'yes'
            ),
            'title' => array(
                'title' => __('Title', 'dynamic-order-emails'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'dynamic-order-emails'),
                'default' => __('Cash App', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'description' => array(
                'title' => __('Description', 'dynamic-order-emails'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your checkout.', 'dynamic-order-emails'),
                'default' => __('Pay with Cash App upon order completion.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'instructions' => array(
                'title' => __('Instructions', 'dynamic-order-emails'),
                'type' => 'textarea',
                'description' => __('Instructions that will be added to the thank you page and emails (After header text).', 'dynamic-order-emails'),
                'default' => __('', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
			'thank_you_page_text' => array(
                'title' => __('Order Received Page Text (Thank you page)', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'description' => __('Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails'),
                'default' => __('<div style="max-width: 500px; margin: 0 auto 20px; background: #ffffff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 20px; text-align: center;"><h2 style="color: #28a745; margin-bottom: 15px;"><span style="color: #333333; font-size: 15px;"><b>We will send your payment link via email shortly. Please check your spam or junk folder if you do not receive it.</b></span></h2><p style="font-size: 15px; color: #333; margin-bottom: 12px;">If you have any questions in the meantime, feel free to reach out at
<a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>', 'dynamic-order-emails'),
                'desc_tip' => false,
            ),
			'email_header_text' => [
                'title'       => 'Email Header Text',
                'type'        => 'wpeditor',
                'description' => 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}',
                'default'     => '<p>Thank you for shopping with us! Your order has been successfully placed.</p><strong>We will send you the PAYMENT LINK within 12 hours to your email. After your payment confirmation, Your order will be shipped within 24 hours and provide you the tracking number.</strong>',
            ],
             // Reminder time composite fields
            'doe_cash_app_pay_initial_reminder_time' => array(
                'title' => __('Initial Reminder Time', 'dynamic-order-emails'),
                'type' => 'reminder_time',
                'description' => __('Select type and value for the initial reminder time.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'doe_cash_app_pay_initial_subject' => array(
                'title' => __('Cash App Pay Initial Email Subject', 'dynamic-order-emails'),
                'type' => 'text',
                'default' => 'PAYMENT LINK : [{company_name}]: New order #{order_id}'
            ),
			'doe_cash_app_pay_reminder_24_time' => array(
                'title' => __('24h Reminder Time', 'dynamic-order-emails'),
                'type' => 'reminder_time',
                'description' => __('Select type and value for the 24h reminder time.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'doe_cash_app_pay_reminder_24_subject' => array(
                'title' => __('Cash App Pay 24h Reminder Email Subject', 'dynamic-order-emails'),
                'type' => 'text',
                'default' => 'REMINDER : [{company_name}]: New order #{order_id}'
            ),
			'doe_cash_app_pay_reminder_48_time' => array(
                'title' => __('48h Reminder Time', 'dynamic-order-emails'),
                'type' => 'reminder_time',
                'description' => __('Select type and value for the 48h reminder time.', 'dynamic-order-emails'),
                'desc_tip' => true,
            ),
            'doe_cash_app_pay_reminder_48_subject' => array(
                'title' => __('Cash App Pay 48h Reminder Email Subject', 'dynamic-order-emails'),
                'type' => 'text',
                'default' => 'KINDLY REMINDER : [{company_name}]: New order #{order_id}'
            ),
            'doe_cash_app_usa' => array(
                'title' => __('Cash App Email', 'dynamic-order-emails'),
                'type' => 'wpeditor',
                'default' => 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>When making the payment, please ensure you include your order number, #{order_id}, in the comment section. This will help us to quickly and accurately process your order. Please do <strong>NOT</strong> include any other details in the comment like medicine or the website name.<br><br>Your total payable amount is {order_total}<br><br>To complete your payment, please use the following link via Cash App: <a href="https://cash.app/$Rolandpaul36">https://cash.app/$Rolandpaul36</a><br><br>Thank you for your cooperation. We appreciate your business.<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)',
				'description'=> __('Use for bold, for italic, for underline, and for line breaks. Use {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, and {from_email} for dynamic content.', 'dynamic-order-emails'),
				'desc_tip' => false,
            ),
            'enable_for_methods' => array(
                'title' => __('Enable for shipping methods', 'dynamic-order-emails'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'css' => 'width: 400px;',
                'default' => '',
                'description' => __('If Cash App is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'dynamic-order-emails'),
                'options' => $shipping_methods,
                'desc_tip' => true,
                'custom_attributes' => array(
                    'data-placeholder' => __('Select shipping methods', 'dynamic-order-emails')
                )
            ),
            'enable_for_virtual' => array(
                'title' => __('Accept for virtual orders', 'dynamic-order-emails'),
                'label' => __('Accept Cash App if the order is virtual', 'dynamic-order-emails'),
                'type' => 'checkbox',
                'default' => 'yes'
            )
        );
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        // Check if payment method is available for selected shipping method
        if (!$this->is_available_for_shipping($order)) {
            wc_add_notice(__('Cash App is not available for the selected shipping method.', 'dynamic-order-emails'), 'error');
            return;
        }

        // Mark as on-hold (waiting for payment)
        $order->update_status('on-hold', __('Awaiting Cash App payment', 'dynamic-order-emails'));

        // Reduce stock levels
        wc_reduce_stock_levels($order_id);

        // Remove cart
        WC()->cart->empty_cart();

        // Return thank you page redirect
        return array(
            'result' => 'success',
            'redirect' => $this->get_return_url($order)
        );
    }

    private function is_available_for_shipping($order) {
        if (empty($this->enable_for_methods)) {
            return true;
        }

        $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
        if (empty($chosen_shipping_methods)) {
            return false;
        }

        foreach ($chosen_shipping_methods as $method) {
            $method_id = explode(':', $method)[0];
            if (in_array($method_id, $this->enable_for_methods, true)) {
                return true;
            }
        }

        return false;
    }

    public function thankyou_page($order_id) {
		$order = wc_get_order($order_id);
		if (!$order) {
			$this->log_debug("Order #{$order_id} - Invalid order ID");
			return;
		}

		// Fetch settings for this gateway
		$settings = get_option('woocommerce_' . $this->id . '_settings', []);
		$content = !empty($settings['thank_you_page_text']) ? $settings['thank_you_page_text'] : $this->instructions;

		if ($content) {
			$content = $this->replace_placeholders($content, $order);
			echo wp_kses_post(wpautop(wptexturize($content)));
		} else {
			$this->log_debug("Order #{$order->get_id()} - No thank_you_page_text or instructions found for {$this->id}");
		}
	}

    /**
	 * Display email instructions
	 * Show header ONLY on 'on-hold' or 'processing' status
	 */
	public function email_instructions($order, $sent_to_admin, $plain_text = false) {
		if ($sent_to_admin || $order->get_payment_method() !== $this->id) {
			return;
		}

		// Only show header if status is on-hold or processing
		$allowed_statuses = ['on-hold', 'processing'];
		$current_status = $order->get_status();
		$show_header = in_array($current_status, $allowed_statuses, true);

		$header = '';
		if ($show_header) {
			$header = $this->email_header_text ?: '';
			$this->log_debug("Order #{$order->get_id()} - Header shown (status: $current_status)");
		} else {
			$this->log_debug("Order #{$order->get_id()} - Header hidden (status: $current_status)");
		}

		$body = $this->payment_instructions ?: $this->instructions;
		$content = trim($header . ($header && $body ? "\n\n" : '') . $body);

		if (!$content) {
			$this->log_debug("Order #{$order->get_id()} - No email content");
			return;
		}

		$content = $this->replace_placeholders($content, $order);

		if ($plain_text) {
			echo wptexturize(wp_strip_all_tags($content)) . PHP_EOL;
		} else {
			echo wpautop(wptexturize($content)) . PHP_EOL;
		}
	}

    public function is_available() {
        $is_available = parent::is_available();

        if (WC()->cart && !$this->enable_for_virtual) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                $product = $cart_item['data'];
                if ($product->is_virtual()) {
                    $is_available = false;
                    break;
                }
            }
        }

        return $is_available;
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

    // Custom renderer for composite reminder_time field
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
                <p class="description"><?php echo esc_html($data['description']); ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    // Custom renderer for wp_editor field
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
                        'media_buttons' => true,
                    )
                );
                ?>
                <?php echo $this->get_description_html($data); ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    // Save custom reminder_time composite field
    public function validate_reminder_time_field($key, $value) {
        $field_key = $this->get_field_key($key);
        $enable = isset($_POST['woocommerce_' . $this->id . '_' . $field_key . '_enable']) ? 'yes' : 'no';
        $this->update_option($field_key . '_enable', $enable);
        $type = isset($_POST['woocommerce_' . $this->id . '_' . $field_key . '_type']) ? sanitize_text_field($_POST['woocommerce_' . $this->id . '_' . $field_key . '_type']) : 'hour';
        $this->update_option($field_key . '_type', $type);
        $val = isset($_POST['woocommerce_' . $this->id . '_' . $field_key . '_value']) ? absint($_POST['woocommerce_' . $this->id . '_' . $field_key . '_value']) : 1;
        $this->update_option($field_key . '_value', $val);
        return;
    }
}
?>