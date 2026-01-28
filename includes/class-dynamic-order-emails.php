<?php
// File:- includes/class-dynamic-order-emails.php

if (!defined('ABSPATH')) {
    exit;
}

class Dynamic_Order_Emails {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function init() {
        // Hook into WooCommerce order status changes
        add_action('woocommerce_order_status_on-hold', [$this, 'schedule_initial_emails']);
        add_action('woocommerce_order_status_shipped', [$this, 'cancel_scheduled_emails']);
        add_action('woocommerce_order_status_changed', [$this, 'handle_order_status_change'], 10, 4);

        // Register scheduled email actions
//         add_action('doe_credit_card_email', [$this, 'send_credit_card_email'], 10, 1);
        add_action('doe_bank_transfer_initial', [$this, 'send_bank_transfer_email'], 10, 1);
        add_action('doe_bank_transfer_reminder_24', [$this, 'send_bank_transfer_email'], 10, 1);
        add_action('doe_bank_transfer_reminder_48', [$this, 'send_bank_transfer_email'], 10, 1);
        add_action('doe_check_initial', [$this, 'send_check_email'], 10, 1);
        add_action('doe_check_reminder_24', [$this, 'send_check_email'], 10, 1);
        add_action('doe_check_reminder_48', [$this, 'send_check_email'], 10, 1);
        add_action('doe_zelle_pay_initial', [$this, 'send_zelle_pay_email'], 10, 1);
        add_action('doe_zelle_pay_reminder_24', [$this, 'send_zelle_pay_email'], 10, 1);
        add_action('doe_zelle_pay_reminder_48', [$this, 'send_zelle_pay_email'], 10, 1);
        add_action('doe_cash_app_initial', [$this, 'send_cash_app_email'], 10, 1);
        add_action('doe_cash_app_reminder_24', [$this, 'send_cash_app_email'], 10, 1);
        add_action('doe_cash_app_reminder_48', [$this, 'send_cash_app_email'], 10, 1);
        add_action('doe_card_initial', [$this, 'send_card_email'], 10, 1);
        add_action('doe_card_reminder_24', [$this, 'send_card_email'], 10, 1);
        add_action('doe_card_reminder_48', [$this, 'send_card_email'], 10, 1);
        add_action('doe_venmo_pay_initial', [$this, 'send_venmo_pay_email'], 10, 1);
        add_action('doe_venmo_pay_reminder_24', [$this, 'send_venmo_pay_email'], 10, 1);
        add_action('doe_venmo_pay_reminder_48', [$this, 'send_venmo_pay_email'], 10, 1);

        // Capture Message-ID for WooCommerce order confirmation email
        add_filter('wp_mail', [$this, 'capture_initial_email_message_id'], 10, 1);

        // Add custom text before order table
//         add_action('woocommerce_email_before_order_table', [$this, 'add_custom_text_before_order_table'], 10, 4);

        // Add filters for WooCommerce email headings
        add_filter('woocommerce_email_heading_customer_processing_order', [$this, 'doe_email_heading_processing'], 10, 2);
        add_filter('woocommerce_email_heading_customer_completed_order', [$this, 'doe_email_heading_completed'], 10, 2);
        add_filter('woocommerce_email_heading_customer_on_hold_order', [$this, 'doe_email_heading_on_hold'], 10, 2);
        add_filter('woocommerce_email_heading_customer_cancelled_order', [$this, 'doe_email_heading_cancelled'], 10, 2);
        add_filter('woocommerce_email_heading_customer_failed_order', [$this, 'doe_email_heading_failed'], 10, 2);
        add_filter('woocommerce_email_heading_customer_refunded_order', [$this, 'doe_email_heading_refunded'], 10, 2);
        add_filter('woocommerce_email_heading_customer_invoice', [$this, 'doe_email_heading_invoice'], 10, 2);

        add_filter('woocommerce_available_payment_gateways', [$this, 'show_zelle_and_cashapp_to_usa'], 10, 2);
    }

    /**
     * Log debugging information to a file
     */
    private function log_debug($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_file = WP_CONTENT_DIR . '/doe-debug.log';
            $timestamp = current_time('mysql');
            file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
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
	/**
     * Capture the Message-ID of the initial WooCommerce order confirmation email
     */
    public function capture_initial_email_message_id($args) {
        if (!isset($args['headers']) || !isset($args['to']) || !isset($args['subject'])) {
            return $args;
        }

        if (strpos($args['subject'], 'Order #') !== false && strpos($args['subject'], 'has been received') !== false) {
            $order_id = $this->extract_order_id_from_subject($args['subject']);
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order && $order->get_status() === 'on-hold') {
                    $headers = is_array($args['headers']) ? $args['headers'] : explode("\n", $args['headers']);
                    foreach ($headers as $header) {
                        if (stripos($header, 'Message-ID:') === 0) {
                            $message_id = trim(str_replace('Message-ID:', '', $header));
                            $order->update_meta_data('_initial_email_message_id', $message_id);
                            $order->save();
                            $this->log_debug("Captured Message-ID for order #$order_id: $message_id");
                            break;
                        }
                    }
                }
            }
        }

        return $args;
    }

    /**
     * Extract order ID from email subject
     */
    private function extract_order_id_from_subject($subject) {
        if (preg_match('/Order #(\d+)/', $subject, $matches)) {
            return (int) $matches[1];
        }
        return false;
    }

    /**
     * Handle order status changes to cancel scheduled emails when status changes to Processing
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        if ($new_status === 'processing') {
            $this->cancel_scheduled_emails($order_id);
            $this->log_debug("Order #$order_id status changed to processing, cancelling scheduled emails");
        }
    }

    // === Heading Callbacks ===
    public function doe_email_heading_processing($heading, $order) {
        return get_option('woocommerce_customer_processing_order_heading', 'Your Order Ready!');
    }

    public function doe_email_heading_completed($heading, $order) {
        return get_option('woocommerce_customer_completed_order_heading', 'Order is Complete!');
    }

    public function doe_email_heading_on_hold($heading, $order) {
        return get_option('woocommerce_customer_on_hold_order_heading', 'Order On Hold');
    }

    public function doe_email_heading_cancelled($heading, $order) {
        return get_option('woocommerce_customer_cancelled_order_heading', 'Order Cancelled');
    }

    public function doe_email_heading_failed($heading, $order) {
        return get_option('woocommerce_customer_failed_order_heading', 'Payment Failed');
    }

    public function doe_email_heading_refunded($heading, $order) {
        return get_option('woocommerce_customer_refunded_order_heading', 'Refund Being Processed');
    }

    public function doe_email_heading_invoice($heading, $order) {
        return get_option('woocommerce_customer_invoice_heading', 'Invoice is Ready');
    }

    public function schedule_initial_emails($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_debug("Order #$order_id not found for scheduling emails");
            return;
        }

        $payment_method = $order->get_payment_method();
        $billing_country = $order->get_billing_country();
        $settings_key = "woocommerce_{$payment_method}_settings";
        $settings = get_option($settings_key, []);
        if (isset($settings['doe_disable_emails']) && $settings['doe_disable_emails'] === 'yes') {
            $this->log_debug("Emails disabled for payment method {$payment_method} on order #{$order_id}");
            return;
        }

        $this->log_debug("Scheduling emails for order #$order_id, payment method: $payment_method, country: $billing_country");

        $country_suffix = ($billing_country === 'AU') ? '_au' : '_usa';
        $country_key = strtolower($billing_country);
        switch ($payment_method) {
            case 'doe_bacs':
			// Initial Reminder - Check both prefixed and non-prefixed keys for compatibility
			$initial_key = 'doe_dbt_initial_reminder_time';
			$initial_key_prefixed = 'woocommerce_' . $payment_method . '_' . $initial_key;
			$initial_enable = $settings[$initial_key_prefixed . '_enable'] ?? $settings[$initial_key . '_enable'] ?? 'yes';
			$this->log_debug("Order #$order_id - BACS Initial Enable: $initial_enable");

			if ($initial_enable === 'yes') {
				$initial_type = $settings[$initial_key_prefixed . '_type'] ?? $settings[$initial_key . '_type'] ?? 'minute';
				$initial_value = $settings[$initial_key_prefixed . '_value'] ?? $settings[$initial_key . '_value'] ?? 20;
				$this->log_debug("Order #$order_id - BACS Initial: $initial_value $initial_type");

				$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$initial_type] ?? 60;
				$initial_delay = $initial_value * $multiplier;

				$scheduled = wp_schedule_single_event(time() + $initial_delay, 'doe_bank_transfer_initial', [$order_id]);
				$this->log_debug("Order #$order_id - BACS initial scheduled: " . ($scheduled !== false ? 'Success' : 'Failed'));
				$this->log_debug("Order #$order_id - Scheduled BACS initial email in $initial_value $initial_type(s)");
			}

			// 24h Reminder
			$reminder24_key = 'doe_dbt_reminder_24_time';
			$reminder24_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder24_key;
			$reminder24_enable = $settings[$reminder24_key_prefixed . '_enable'] ?? $settings[$reminder24_key . '_enable'] ?? 'yes';
			$this->log_debug("Order #$order_id - BACS 24hr Enable: $reminder24_enable");

			if ($reminder24_enable === 'yes') {
				$reminder24_type = $settings[$reminder24_key_prefixed . '_type'] ?? $settings[$reminder24_key . '_type'] ?? 'hour';
				$reminder24_value = $settings[$reminder24_key_prefixed . '_value'] ?? $settings[$reminder24_key . '_value'] ?? 24;
				$this->log_debug("Order #$order_id - BACS 24hr: $reminder24_value $reminder24_type");

				$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder24_type] ?? 3600;
				$reminder24_delay = $reminder24_value * $multiplier;

				$scheduled = wp_schedule_single_event(time() + $reminder24_delay, 'doe_bank_transfer_reminder_24', [$order_id]);
				$this->log_debug("Order #$order_id - BACS 24hr scheduled: " . ($scheduled !== false ? 'Success' : 'Failed'));
				$this->log_debug("Order #$order_id - Scheduled BACS 24hr reminder in $reminder24_value $reminder24_type(s)");
			}

			// 48h Reminder
			$reminder48_key = 'doe_dbt_reminder_48_time';
			$reminder48_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder48_key;
			$reminder48_enable = $settings[$reminder48_key_prefixed . '_enable'] ?? $settings[$reminder48_key . '_enable'] ?? 'yes';
			$this->log_debug("Order #$order_id - BACS 48hr Enable: $reminder48_enable");

			if ($reminder48_enable === 'yes') {
				$reminder48_type = $settings[$reminder48_key_prefixed . '_type'] ?? $settings[$reminder48_key . '_type'] ?? 'hour';
				$reminder48_value = $settings[$reminder48_key_prefixed . '_value'] ?? $settings[$reminder48_key . '_value'] ?? 48;
				$this->log_debug("Order #$order_id - BACS 48hr: $reminder48_value $reminder48_type");

				$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder48_type] ?? 3600;
				$reminder48_delay = $reminder48_value * $multiplier;

				$scheduled = wp_schedule_single_event(time() + $reminder48_delay, 'doe_bank_transfer_reminder_48', [$order_id]);
				$this->log_debug("Order #$order_id - BACS 48hr scheduled: " . ($scheduled !== false ? 'Success' : 'Failed'));
				$this->log_debug("Order #$order_id - Scheduled BACS 48hr reminder in $reminder48_value $reminder48_type(s)");
			}

			// Debug: Log all settings keys
			$this->log_debug("Order #$order_id - BACS Settings Keys: " . implode(', ', array_keys($settings)));
			break;
            case 'doe_cheque':
				// Initial reminder - Check both prefixed and non-prefixed keys for compatibility
				$initial_key = 'doe_check_initial_reminder_time';
				$initial_key_prefixed = 'woocommerce_' . $payment_method . '_' . $initial_key;
				$initial_enable = $settings[$initial_key_prefixed . '_enable'] ?? $settings[$initial_key . '_enable'] ?? 'yes';
				$this->log_debug("Order #$order_id - Check Initial Enable: $initial_enable");

				if ($initial_enable === 'yes') {
					$initial_type = $settings[$initial_key_prefixed . '_type'] ?? $settings[$initial_key . '_type'] ?? 'minute';
					$initial_value = $settings[$initial_key_prefixed . '_value'] ?? $settings[$initial_key . '_value'] ?? 20;
					$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$initial_type] ?? 60;
					$initial_delay = $initial_value * $multiplier;
					wp_schedule_single_event(time() + $initial_delay, 'doe_check_initial', [$order_id]);
					$this->log_debug("Order #$order_id - Scheduled check initial in $initial_value $initial_type(s) for country: $billing_country");
				}

				// 24h reminder
				$reminder24_key = 'doe_check_reminder_24_time';
				$reminder24_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder24_key;
				$reminder24_enable = $settings[$reminder24_key_prefixed . '_enable'] ?? $settings[$reminder24_key . '_enable'] ?? 'yes';
				$this->log_debug("Order #$order_id - Check 24hr Enable: $reminder24_enable");

				if ($reminder24_enable === 'yes') {
					$reminder24_type = $settings[$reminder24_key_prefixed . '_type'] ?? $settings[$reminder24_key . '_type'] ?? 'hour';
					$reminder24_value = $settings[$reminder24_key_prefixed . '_value'] ?? $settings[$reminder24_key . '_value'] ?? 24;
					$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder24_type] ?? 3600;
					$reminder24_delay = $reminder24_value * $multiplier;
					wp_schedule_single_event(time() + $reminder24_delay, 'doe_check_reminder_24', [$order_id]);
					$this->log_debug("Order #$order_id - Scheduled check 24hr in $reminder24_value $reminder24_type(s) for country: $billing_country");
				}

				// 48h reminder
				$reminder48_key = 'doe_check_reminder_48_time';
				$reminder48_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder48_key;
				$reminder48_enable = $settings[$reminder48_key_prefixed . '_enable'] ?? $settings[$reminder48_key . '_enable'] ?? 'yes';
				$this->log_debug("Order #$order_id - Check 48hr Enable: $reminder48_enable");

				if ($reminder48_enable === 'yes') {
					$reminder48_type = $settings[$reminder48_key_prefixed . '_type'] ?? $settings[$reminder48_key . '_type'] ?? 'hour';
					$reminder48_value = $settings[$reminder48_key_prefixed . '_value'] ?? $settings[$reminder48_key . '_value'] ?? 48;
					$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder48_type] ?? 3600;
					$reminder48_delay = $reminder48_value * $multiplier;
					wp_schedule_single_event(time() + $reminder48_delay, 'doe_check_reminder_48', [$order_id]);
					$this->log_debug("Order #$order_id - Scheduled check 48hr in $reminder48_value $reminder48_type(s) for country: $billing_country");
				}

				// Debug: Log all settings keys
				$this->log_debug("Order #$order_id - Check Settings Keys: " . implode(', ', array_keys($settings)));
				break;
            case 'doe_cod':
            // Initial reminder - Check both prefixed and non-prefixed keys for compatibility
            $initial_key = 'doe_card_initial_reminder_time';
            $initial_key_prefixed = 'woocommerce_' . $payment_method . '_' . $initial_key;
            $initial_enable = $settings[$initial_key_prefixed . '_enable'] ?? $settings[$initial_key . '_enable'] ?? 'yes';
            if ($initial_enable === 'yes') {
                $initial_type = $settings[$initial_key_prefixed . '_type'] ?? $settings[$initial_key . '_type'] ?? 'minute';
                $initial_value = $settings[$initial_key_prefixed . '_value'] ?? $settings[$initial_key . '_value'] ?? 20;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$initial_type] ?? 60;
                $initial_delay = $initial_value * $multiplier;
                wp_schedule_single_event(time() + $initial_delay, 'doe_card_initial', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled card initial in $initial_value $initial_type(s)");
            }
            
            // 24h reminder
            $reminder24_key = 'doe_card_reminder_24_time';
            $reminder24_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder24_key;
            $reminder24_enable = $settings[$reminder24_key_prefixed . '_enable'] ?? $settings[$reminder24_key . '_enable'] ?? 'yes';
            if ($reminder24_enable === 'yes') {
                $reminder24_type = $settings[$reminder24_key_prefixed . '_type'] ?? $settings[$reminder24_key . '_type'] ?? 'hour';
                $reminder24_value = $settings[$reminder24_key_prefixed . '_value'] ?? $settings[$reminder24_key . '_value'] ?? 24;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder24_type] ?? 3600;
                $reminder24_delay = $reminder24_value * $multiplier;
                wp_schedule_single_event(time() + $reminder24_delay, 'doe_card_reminder_24', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled card 24hr in $reminder24_value $reminder24_type(s)");
            }
            
            // 48h reminder
            $reminder48_key = 'doe_card_reminder_48_time';
            $reminder48_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder48_key;
            $reminder48_enable = $settings[$reminder48_key_prefixed . '_enable'] ?? $settings[$reminder48_key . '_enable'] ?? 'yes';
            if ($reminder48_enable === 'yes') {
                $reminder48_type = $settings[$reminder48_key_prefixed . '_type'] ?? $settings[$reminder48_key . '_type'] ?? 'hour';
                $reminder48_value = $settings[$reminder48_key_prefixed . '_value'] ?? $settings[$reminder48_key . '_value'] ?? 48;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder48_type] ?? 3600;
                $reminder48_delay = $reminder48_value * $multiplier;
                wp_schedule_single_event(time() + $reminder48_delay, 'doe_card_reminder_48', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled card 48hr in $reminder48_value $reminder48_type(s)");
            }
            break;
            case 'zelle_pay':
            $country_suffix = ($billing_country === 'AU') ? '_au' : '_usa';
            
            // Initial reminder - Check both prefixed and non-prefixed keys for compatibility
            $initial_key = 'doe_zelle_pay' . $country_suffix . '_initial_reminder_time';
            $initial_key_prefixed = 'woocommerce_' . $payment_method . '_' . $initial_key;
            $initial_enable = $settings[$initial_key_prefixed . '_enable'] ?? $settings[$initial_key . '_enable'] ?? 'yes';
            if ($initial_enable === 'yes') {
                $initial_type = $settings[$initial_key_prefixed . '_type'] ?? $settings[$initial_key . '_type'] ?? 'minute';
                $initial_value = $settings[$initial_key_prefixed . '_value'] ?? $settings[$initial_key . '_value'] ?? 20;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$initial_type] ?? 60;
                $initial_delay = $initial_value * $multiplier;
                wp_schedule_single_event(time() + $initial_delay, 'doe_zelle_pay_initial', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled zelle initial in $initial_value $initial_type(s)");
            }
            
            // 24h reminder
            $reminder24_key = 'doe_zelle_pay' . $country_suffix . '_reminder_24_time';
            $reminder24_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder24_key;
            $reminder24_enable = $settings[$reminder24_key_prefixed . '_enable'] ?? $settings[$reminder24_key . '_enable'] ?? 'yes';
            if ($reminder24_enable === 'yes') {
                $reminder24_type = $settings[$reminder24_key_prefixed . '_type'] ?? $settings[$reminder24_key . '_type'] ?? 'hour';
                $reminder24_value = $settings[$reminder24_key_prefixed . '_value'] ?? $settings[$reminder24_key . '_value'] ?? 24;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder24_type] ?? 3600;
                $reminder24_delay = $reminder24_value * $multiplier;
                wp_schedule_single_event(time() + $reminder24_delay, 'doe_zelle_pay_reminder_24', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled zelle 24hr in $reminder24_value $reminder24_type(s)");
            }
            
            // 48h reminder
            $reminder48_key = 'doe_zelle_pay' . $country_suffix . '_reminder_48_time';
            $reminder48_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder48_key;
            $reminder48_enable = $settings[$reminder48_key_prefixed . '_enable'] ?? $settings[$reminder48_key . '_enable'] ?? 'yes';
            if ($reminder48_enable === 'yes') {
                $reminder48_type = $settings[$reminder48_key_prefixed . '_type'] ?? $settings[$reminder48_key . '_type'] ?? 'hour';
                $reminder48_value = $settings[$reminder48_key_prefixed . '_value'] ?? $settings[$reminder48_key . '_value'] ?? 48;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder48_type] ?? 3600;
                $reminder48_delay = $reminder48_value * $multiplier;
                wp_schedule_single_event(time() + $reminder48_delay, 'doe_zelle_pay_reminder_48', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled zelle 48hr in $reminder48_value $reminder48_type(s)");
            }
            break;
            case 'cash_app':
            $country_suffix = ($billing_country === 'AU') ? '_au' : '_usa';
            
            // Initial reminder - Check both prefixed and non-prefixed keys for compatibility
            $initial_key = 'doe_cash_app_pay' . $country_suffix . '_initial_reminder_time';
            $initial_key_prefixed = 'woocommerce_' . $payment_method . '_' . $initial_key;
            $initial_enable = $settings[$initial_key_prefixed . '_enable'] ?? $settings[$initial_key . '_enable'] ?? 'yes';
            if ($initial_enable === 'yes') {
                $initial_type = $settings[$initial_key_prefixed . '_type'] ?? $settings[$initial_key . '_type'] ?? 'minute';
                $initial_value = $settings[$initial_key_prefixed . '_value'] ?? $settings[$initial_key . '_value'] ?? 20;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$initial_type] ?? 60;
                $initial_delay = $initial_value * $multiplier;
                wp_schedule_single_event(time() + $initial_delay, 'doe_cash_app_initial', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled cash_app initial in $initial_value $initial_type(s)");
            }
            
            // 24h reminder
            $reminder24_key = 'doe_cash_app_pay' . $country_suffix . '_reminder_24_time';
            $reminder24_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder24_key;
            $reminder24_enable = $settings[$reminder24_key_prefixed . '_enable'] ?? $settings[$reminder24_key . '_enable'] ?? 'yes';
            if ($reminder24_enable === 'yes') {
                $reminder24_type = $settings[$reminder24_key_prefixed . '_type'] ?? $settings[$reminder24_key . '_type'] ?? 'hour';
                $reminder24_value = $settings[$reminder24_key_prefixed . '_value'] ?? $settings[$reminder24_key . '_value'] ?? 24;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder24_type] ?? 3600;
                $reminder24_delay = $reminder24_value * $multiplier;
                wp_schedule_single_event(time() + $reminder24_delay, 'doe_cash_app_reminder_24', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled cash_app 24hr in $reminder24_value $reminder24_type(s)");
            }
            
            // 48h reminder
            $reminder48_key = 'doe_cash_app_pay' . $country_suffix . '_reminder_48_time';
            $reminder48_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder48_key;
            $reminder48_enable = $settings[$reminder48_key_prefixed . '_enable'] ?? $settings[$reminder48_key . '_enable'] ?? 'yes';
            if ($reminder48_enable === 'yes') {
                $reminder48_type = $settings[$reminder48_key_prefixed . '_type'] ?? $settings[$reminder48_key . '_type'] ?? 'hour';
                $reminder48_value = $settings[$reminder48_key_prefixed . '_value'] ?? $settings[$reminder48_key . '_value'] ?? 48;
                $multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder48_type] ?? 3600;
                $reminder48_delay = $reminder48_value * $multiplier;
                wp_schedule_single_event(time() + $reminder48_delay, 'doe_cash_app_reminder_48', [$order_id]);
                $this->log_debug("Order #$order_id - Scheduled cash_app 48hr in $reminder48_value $reminder48_type(s)");
            }
            break;

            case 'venmo_pay':
				$country_suffix = ($billing_country === 'AU') ? '_au' : '_usa';

				// INITIAL REMINDER - Check both prefixed and non-prefixed keys for compatibility
				$initial_key = 'doe_venmo_pay' . $country_suffix . '_initial_reminder_time';
				$initial_key_prefixed = 'woocommerce_' . $payment_method . '_' . $initial_key;
				$initial_enable = $settings[$initial_key_prefixed . '_enable'] ?? $settings[$initial_key . '_enable'] ?? 'yes';
				if ($initial_enable === 'yes') {
					$initial_type = $settings[$initial_key_prefixed . '_type'] ?? $settings[$initial_key . '_type'] ?? 'minute';
					$initial_value = $settings[$initial_key_prefixed . '_value'] ?? $settings[$initial_key . '_value'] ?? 20;
					$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$initial_type] ?? 60;
					$initial_delay = $initial_value * $multiplier;
					wp_schedule_single_event(time() + $initial_delay, 'doe_venmo_pay_initial', [$order_id]);
					$this->log_debug("Order #$order_id - Scheduled venmo_pay initial email in $initial_value $initial_type(s)");
				}

				// 24 HOUR REMINDER
				$reminder24_key = 'doe_venmo_pay' . $country_suffix . '_reminder_24_time';
				$reminder24_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder24_key;
				$reminder24_enable = $settings[$reminder24_key_prefixed . '_enable'] ?? $settings[$reminder24_key . '_enable'] ?? 'yes';
				if ($reminder24_enable === 'yes') {
					$reminder24_type = $settings[$reminder24_key_prefixed . '_type'] ?? $settings[$reminder24_key . '_type'] ?? 'hour';
					$reminder24_value = $settings[$reminder24_key_prefixed . '_value'] ?? $settings[$reminder24_key . '_value'] ?? 24;
					$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder24_type] ?? 3600;
					$reminder24_delay = $reminder24_value * $multiplier;
					wp_schedule_single_event(time() + $reminder24_delay, 'doe_venmo_pay_reminder_24', [$order_id]);
					$this->log_debug("Order #$order_id - Scheduled venmo_pay 24hr reminder in $reminder24_value $reminder24_type(s)");
				}

				// 48 HOUR REMINDER
				$reminder48_key = 'doe_venmo_pay' . $country_suffix . '_reminder_48_time';
				$reminder48_key_prefixed = 'woocommerce_' . $payment_method . '_' . $reminder48_key;
				$reminder48_enable = $settings[$reminder48_key_prefixed . '_enable'] ?? $settings[$reminder48_key . '_enable'] ?? 'yes';
				if ($reminder48_enable === 'yes') {
					$reminder48_type = $settings[$reminder48_key_prefixed . '_type'] ?? $settings[$reminder48_key . '_type'] ?? 'hour';
					$reminder48_value = $settings[$reminder48_key_prefixed . '_value'] ?? $settings[$reminder48_key . '_value'] ?? 48;
					$multiplier = ['second' => 1, 'minute' => 60, 'hour' => 3600][$reminder48_type] ?? 3600;
					$reminder48_delay = $reminder48_value * $multiplier;
					wp_schedule_single_event(time() + $reminder48_delay, 'doe_venmo_pay_reminder_48', [$order_id]);
					$this->log_debug("Order #$order_id - Scheduled venmo_pay 48hr reminder in $reminder48_value $reminder48_type(s)");
				}
				
				// Debug: Print all Venmo settings for this country
				$this->log_debug("Order #$order_id - All Venmo Settings: " . print_r($settings, true));
                break;
            default:
                $this->log_debug("No email scheduling for payment method $payment_method for order #$order_id");
                break;
        }
    }

    public function cancel_scheduled_emails($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            $this->log_debug("Order #$order_id not found for cancelling emails");
            return;
        }

        $payment_method = $order->get_payment_method();
        $actions = [];

        switch ($payment_method) {
            case 'doe_cod':
                $actions = [
                    'doe_card_initial',
                    'doe_card_reminder_24',
                    'doe_card_reminder_48',
                ];
                break;
            case 'doe_bacs':
                $actions = [
                    'doe_bank_transfer_initial',
                    'doe_bank_transfer_reminder_24',
                    'doe_bank_transfer_reminder_48',
                ];
                break;
            case 'doe_cheque':
                $actions = [
                    'doe_check_initial',
                    'doe_check_reminder_24',
                    'doe_check_reminder_48',
                ];
                break;
            case 'zelle_pay':
                $actions = [
                    'doe_zelle_pay_initial',
                    'doe_zelle_pay_reminder_24',
                    'doe_zelle_pay_reminder_48',
                ];
                break;
            case 'cash_app':
                $actions = [
                    'doe_cash_app_initial',
                    'doe_cash_app_reminder_24',
                    'doe_cash_app_reminder_48',
                ];
                break;
            case 'venmo_pay':
                $actions = [
                    'doe_venmo_pay_initial',
                    'doe_venmo_pay_reminder_24',
                    'doe_venmo_pay_reminder_48',
                ];
                break;
        }

        foreach ($actions as $action) {
            $result = wp_clear_scheduled_hook($action, [$order_id]);
            $this->log_debug("Cleared scheduled hook $action for order #$order_id: " . ($result !== false ? "$result events cleared" : 'Failed'));
        }
    }

    private function replace_placeholders($content, $order) {
		$billing_country = $order->get_billing_country();
		$currency_map = [
			'AU' => 'AUD', // Australia - Australian Dollar
			'GB' => 'GBP', // United Kingdom - British Pound
			'SG' => 'SGD', // Singapore - Singapore Dollar
			'MY' => 'MYR', // Malaysia - Malaysian Ringgit
			'TW' => 'TWD', // Taiwan - New Taiwan Dollar
		];
		$currency = isset($currency_map[$billing_country]) ? $currency_map[$billing_country] : 'USD';
        $order_total = wc_price($order->get_total(), ['currency' => $currency]);
        
		$email_from_address = get_option('woocommerce_email_from_address');
		$from_email = $this->extractDomain($email_from_address);
        $replacements = [
            '{customer_name}' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            '{order_id}' => $order->get_order_number(),
            '{company_name}' => get_bloginfo('name'),
            '{currency}' => $currency,
            '{order_total}' => $order_total,
            '{from_email}' => $from_email,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $content);
    }

    private function get_wc_email_content($subject, $content, $order) {
        $mailer = WC()->mailer();
        $content = $this->replace_placeholders($content, $order);
        $heading = $this->doe_email_heading_on_hold('', $order);

        ob_start();
        wc_get_template('emails/email-header.php', [
            'email_heading' => $heading,
        ]);
        wc_get_template('emails/email-order-details.php', [
            'order' => $order,
            'sent_to_admin' => false,
            'plain_text' => false,
            'email' => '',
        ]);
        wc_get_template('emails/email-addresses.php', [
            'order' => $order,
            'sent_to_admin' => false,
            'plain_text' => false,
            'email' => '',
        ]);
        wc_get_template('emails/email-footer.php');
        $email_content = ob_get_clean();

        return $email_content;
    }

    private function get_email_headers($order) {
		$site_title = get_bloginfo( 'name' );
        $from_name = get_option('woocommerce_email_from_name', $site_title);
        $email_from_address = get_option('woocommerce_email_from_address');
		$from_email = $this->extractDomain($email_from_address);
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: {$from_name} <{$from_email}>",
            "Reply-To: {$from_name} <{$from_email}>",
            "Bcc: {$from_email}",
        ];

        $message_id = $order->get_meta('_initial_email_message_id');
        if ($message_id) {
            $headers[] = "In-Reply-To: {$message_id}";
            $headers[] = "References: {$message_id}";
        }

        return $headers;
    }

    public function send_credit_card_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'on-hold') {
            $this->log_debug("Credit card email not sent for order #$order_id: Invalid order or status is not on-hold");
            return;
        }

        $this->log_debug("Sending credit card email for order #$order_id");

        $subject = get_theme_mod('doe_credit_card_subject', 'PAYMENT LINK : [{company_name}]: New order #{order_id}');
        $subject = $this->replace_placeholders($subject, $order);
        $content = get_theme_mod('doe_credit_card_email', 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>We have received your card information and will charge your card soon. Please ensure sufficient funds are available.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)');

        $email_content = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body>
            ' . wpautop( $this->replace_placeholders($content, $order)) . $this->get_wc_email_content($subject, $content, $order) . '
        </body>
        </html>';
        $headers = $this->get_email_headers($order);

        $mailer = WC()->mailer();
        $result = $mailer->send(
            $order->get_billing_email(),
            $subject,
            $email_content,
            $headers
        );

        $this->log_debug("Credit card email for order #$order_id sent: " . ($result ? 'Success' : 'Failed'));
    }

    public function send_bank_transfer_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'on-hold') {
            $this->log_debug("Bank transfer email not sent for order #$order_id: Invalid order or status is not on-hold");
            return;
        }

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
    
		$payment_method = $order->get_payment_method();
		$settings_key = $payment_method === 'doe_bacs' ? 'woocommerce_doe_bacs_settings' : 'woocommerce_bacs_settings';
		$settings = get_option($settings_key, []);

		$current_action = current_action();
		$this->log_debug("Sending bank transfer email for order #$order_id, action: $current_action, country: $billing_country");

		// Get subject based on action
		if ($current_action === 'doe_bank_transfer_initial') {
			$subject = $settings['doe_dbt_initial_subject'] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
		} elseif ($current_action === 'doe_bank_transfer_reminder_24') {
			$subject = $settings['doe_dbt_reminder_24_subject'] ?? 'REMINDER : [{company_name}]: New order #{order_id}';
		} elseif ($current_action === 'doe_bank_transfer_reminder_48') {
			$subject = $settings['doe_dbt_reminder_48_subject'] ?? 'KINDLY REMINDER : [{company_name}]: New order #{order_id}';
		} else {
			$this->log_debug("Unexpected action $current_action for order #$order_id");
			$subject = 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
		}

        $subject = $this->replace_placeholders($subject, $order);
        // Get country-specific email body
		$email_body_key = 'direct_bank_transfer_email_body_' . $country_key;
		$content = $settings[$email_body_key] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Use the below details to transfer the net amount, and simply mention your order number in the comment section. <strong>DO NOT</strong> reference anything related to medicine or website names. Just mention your order number:<br><br><strong>Account Holder Name:</strong> GAJANAND ENTERPRISE<br><strong>Account Number:</strong> 8339589472<br><strong>ACH Routing Number:</strong> 026073150<br><strong>Account Type:</strong> Checking<br><strong>Bank Name and Address:</strong> Community Federal Savings Bank, 5 Penn Plaza, 14th Floor, New York, NY 10001, US<br><br>After completion of transfer, please share a screenshot or receipt.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';

		// Remove default BACS instructions if payment method is DOE BACS or BACS
		if ($payment_method === 'doe_bacs' || $payment_method === 'bacs') {
			$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
			if (isset($available_gateways['bacs']) || isset($available_gateways['doe_bacs'])) {
				remove_action('woocommerce_email_before_order_table', [$available_gateways['bacs'] ?? $available_gateways['doe_bacs'], 'email_instructions'], 10);
			}
		}
        $email_content = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body>
            ' . wpautop($this->replace_placeholders($content, $order)) . $this->get_wc_email_content($subject, $content, $order) . '
        </body>
        </html>';
        $headers = $this->get_email_headers($order);

        $mailer = WC()->mailer();
        $result = $mailer->send(
            $order->get_billing_email(),
            $subject,
            $email_content,
            $headers
        );

        $this->log_debug("Bank transfer email ($current_action) for order #$order_id sent: " . ($result ? 'Success' : 'Failed'));
    }

    public function send_check_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'on-hold') {
            $this->log_debug("Check email not sent for order #$order_id: Invalid order, status, or country");
            return;
        }

        $current_action = current_action();
        $this->log_debug("Sending check email for order #$order_id, action: $current_action");

        $payment_method = $order->get_payment_method();
        $settings_key = $payment_method === 'doe_cheque' ? 'woocommerce_doe_cheque_settings' : 'woocommerce_cheque_settings';
        $settings = get_option($settings_key, []);

        $subject_key = 'doe_check_usa_initial_subject';
        if ($current_action === 'doe_check_initial') {
            $subject_key = 'doe_check_usa_initial_subject';
        } elseif ($current_action === 'doe_check_reminder_24') {
            $subject_key = 'doe_check_usa_reminder_24_subject';
        } elseif ($current_action === 'doe_check_reminder_48') {
            $subject_key = 'doe_check_usa_reminder_48_subject';
        }

        $subject = $settings[$subject_key] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $subject = $this->replace_placeholders($subject, $order);
        // Check for both keys for backward compatibility
        $content = $settings['doe_check_email'] ?? $settings['doe_check_usa'] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Please send your crypto payment for order #{order_id} to the provided wallet address. Simply mention your order number in the transaction details. <strong>DO NOT</strong> reference anything related to medicine or website names.<br><br>After completion of transfer, please share a screenshot or transaction ID.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy.<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';

        $email_content = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body>
            ' . wpautop($this->replace_placeholders($content, $order)) . $this->get_wc_email_content($subject, $content, $order) . '
        </body>
        </html>';
        $headers = $this->get_email_headers($order);

        $mailer = WC()->mailer();
        $result = $mailer->send(
            $order->get_billing_email(),
            $subject,
            $email_content,
            $headers
        );

        $this->log_debug("Check email ($current_action) for order #$order_id sent: " . ($result ? 'Success' : 'Failed'));
    }

    public function send_zelle_pay_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'on-hold') {
            $this->log_debug("Zelle Pay email not sent for order #$order_id: Invalid order or status is not on-hold");
            return;
        }

        $billing_country = $order->get_billing_country();
        $content_key = $billing_country === 'AU' ? 'doe_zelle_pay_au' : 'doe_zelle_pay_usa';
        $subject_key = 'doe_zelle_pay_usa_initial_subject';

        $current_action = current_action();
        $this->log_debug("Sending Zelle Pay email for order #$order_id, action: $current_action, country: $billing_country");

        $settings = get_option('woocommerce_zelle_pay_settings', []);

        if ($billing_country === 'AU') {
            if ($current_action === 'doe_zelle_pay_initial') {
                $subject_key = 'doe_zelle_pay_au_initial_subject';
            } elseif ($current_action === 'doe_zelle_pay_reminder_24') {
                $subject_key = 'doe_zelle_pay_au_reminder_24_subject';
            } elseif ($current_action === 'doe_zelle_pay_reminder_48') {
                $subject_key = 'doe_zelle_pay_au_reminder_48_subject';
            }
        } else {
            if ($current_action === 'doe_zelle_pay_initial') {
                $subject_key = 'doe_zelle_pay_usa_initial_subject';
            } elseif ($current_action === 'doe_zelle_pay_reminder_24') {
                $subject_key = 'doe_zelle_pay_usa_reminder_24_subject';
            } elseif ($current_action === 'doe_zelle_pay_reminder_48') {
                $subject_key = 'doe_zelle_pay_usa_reminder_48_subject';
            }
        }

        $subject = $settings[$subject_key] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $subject = $this->replace_placeholders($subject, $order);
        $content = $settings[$content_key] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>Your total payable amount is {order_total}<br><br>Please send your Zelle payment for order #{order_id} to the email address: <strong>payment@company.com</strong>. Simply mention your order number in the transaction details. <strong>DO NOT</strong> reference anything related to medicine or website names.<br><br>After completion of transfer, please share a screenshot or transaction confirmation.<br><br><strong>Note:</strong> Your items will ship from India only. Delivery will take approximately 15-20 days (up to 30 days from dispatch due to postal disruptions, weather issues, or natural disasters).<br><br><strong>CHARGEBACK-DISPUTE POLICY:</strong><br><br>We kindly ask you not to make chargebacks without contacting us. If you are not satisfied with our service/Product you have purchased, please contact us at <a href="mailto:{from_email}">{from_email}</a> and we will try to do everything possible to resolve the problem in your favor.<br><br>We appreciate your patience and also your patronage of our pharmacy<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';

        $email_content = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body>
            ' . wpautop($this->replace_placeholders($content, $order)) . $this->get_wc_email_content($subject, $content, $order) . '
        </body>
        </html>';
        $headers = $this->get_email_headers($order);

        $mailer = WC()->mailer();
        $result = $mailer->send(
            $order->get_billing_email(),
            $subject,
            $email_content,
            $headers
        );

        $this->log_debug("Zelle Pay email ($current_action) for order #$order_id sent: " . ($result ? 'Success' : 'Failed'));
    }

    public function send_cash_app_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'on-hold') {
            $this->log_debug("Cash App email not sent for order #$order_id: Invalid order or status is not on-hold");
            return;
        }

        $billing_country = $order->get_billing_country();
        $content_key = $billing_country === 'AU' ? 'doe_cash_app_au' : 'doe_cash_app_usa';
        $subject_key = 'doe_cash_app_usa_initial_subject';

        $current_action = current_action();
        $this->log_debug("Sending Cash App email for order #$order_id, action: $current_action, country: $billing_country");

        $settings = get_option('woocommerce_cash_app_settings', []);

        if ($billing_country === 'AU') {
            if ($current_action === 'doe_cash_app_initial') {
                $subject_key = 'doe_cash_app_au_initial_subject';
            } elseif ($current_action === 'doe_cash_app_reminder_24') {
                $subject_key = 'doe_cash_app_au_reminder_24_subject';
            } elseif ($current_action === 'doe_cash_app_reminder_48') {
                $subject_key = 'doe_cash_app_au_reminder_48_subject';
            }
        } else {
            if ($current_action === 'doe_cash_app_initial') {
                $subject_key = 'doe_cash_app_usa_initial_subject';
            } elseif ($current_action === 'doe_cash_app_reminder_24') {
                $subject_key = 'doe_cash_app_usa_reminder_24_subject';
            } elseif ($current_action === 'doe_cash_app_reminder_48') {
                $subject_key = 'doe_cash_app_usa_reminder_48_subject';
            }
        }

        $subject = $settings[$subject_key] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $subject = $this->replace_placeholders($subject, $order);
        $content = $settings[$content_key] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable order with us!!!<br><br>When making the payment, please ensure you include your order number, #{order_id}, in the comment section. This will help us to quickly and accurately process your order. Please do <strong>NOT</strong> include any other details in the comment like medicine or the website name.<br><br>Your total payable amount is {order_total}<br><br>To complete your payment, please use the following link via Cash App: <a href="https://cash.app/$Rolandpaul36">https://cash.app/$Rolandpaul36</a><br><br>Thank you for your cooperation. We appreciate your business.<br><br>Warm Regards,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';

        $email_content = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body>
            ' . wpautop($this->replace_placeholders($content, $order)) . $this->get_wc_email_content($subject, $content, $order) . '
        </body>
        </html>';
        $headers = $this->get_email_headers($order);

        $mailer = WC()->mailer();
        $result = $mailer->send(
            $order->get_billing_email(),
            $subject,
            $email_content,
            $headers
        );

        $this->log_debug("Cash App email ($current_action) for order #$order_id sent: " . ($result ? 'Success' : 'Failed'));
    }

    public function send_card_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'on-hold') {
            $this->log_debug("Card email not sent for order #$order_id: Invalid order or status is not on-hold");
            return;
        }

        $current_action = current_action();
        $this->log_debug("Sending Card email for order #$order_id, action: $current_action");

        $payment_method = $order->get_payment_method();
        $settings_key = $payment_method === 'doe_cod' ? 'woocommerce_doe_cod_settings' : 'woocommerce_cod_settings';
        $settings = get_option($settings_key, []);

        $content_key = 'doe_card_usa';
        
        $subject_key = 'doe_card_initial_subject';
        if ($current_action === 'doe_card_initial') {
            $subject_key = 'doe_card_initial_subject';
        } elseif ($current_action === 'doe_card_reminder_24') {
            $subject_key = 'doe_card_reminder_24_subject';
        } elseif ($current_action === 'doe_card_reminder_48') {
            $subject_key = 'doe_card_reminder_48_subject';
        }

        $subject = $settings[$subject_key] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $subject = $this->replace_placeholders($subject, $order);
        $content = $settings[$content_key] ?? 'Hello {customer_name},<br><br>Thank you for placing a valuable Order with us!!!<br><br><p><strong>Your total payable amount is {order_total} for Order Number: #{order_id}.</strong></p><br><p>For card payments, we have the following options available. Please indicate your preferred option, and we will promptly provide you with the necessary details.</p><br><p><strong>Bank Details:</strong></p><br><p><strong>1. Zelle Pay (USA Only)</strong><br /><strong>2. Venmo (USA Only)</strong><br /><strong>3. Cash App (USA Only)</strong><br /><strong>4. USDT (Crypto Pay)</strong><br /><strong>5. Remitly</strong><br /><strong>6. Western Union</strong></p><br><p><strong>Warm Regards,</strong><br />Team {company_name}<br />Phone: +1 505-672-5168 (Call and Chat)<br /><a href="https://api.whatsapp.com/send?phone=15056725168&text=Hi {company_name},%20Team" target="_blank">WhatsApp us</a> (For chat only)</p>';

        $email_content = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body>
            ' . wpautop($this->replace_placeholders($content, $order)) . $this->get_wc_email_content($subject, $content, $order) . '
        </body>
        </html>';
        $headers = $this->get_email_headers($order);

        $mailer = WC()->mailer();
        $result = $mailer->send(
            $order->get_billing_email(),
            $subject,
            $email_content,
            $headers
        );

        $this->log_debug("Card email ($current_action) for order #$order_id sent: " . ($result ? 'Success' : 'Failed'));
    }

    public function send_venmo_pay_email($order_id) {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_status() !== 'on-hold') {
            $this->log_debug("Venmo Pay email not sent for order #$order_id: Invalid order or status is not on-hold");
            return;
        }

        $billing_country = $order->get_billing_country();
        $content_key = $billing_country === 'AU' ? 'doe_venmo_pay_au' : 'doe_venmo_pay_usa';

        $current_action = current_action();
        $this->log_debug("Sending Venmo Pay email for order #$order_id, action: $current_action, country: $billing_country");

        $settings = get_option('woocommerce_venmo_pay_settings', []);

        $subject_key = '';
        if ($billing_country === 'AU') {
            if ($current_action === 'doe_venmo_pay_initial') {
                $subject_key = 'doe_venmo_pay_au_initial_subject';
            } elseif ($current_action === 'doe_venmo_pay_reminder_24') {
                $subject_key = 'doe_venmo_pay_au_reminder_24_subject';
            } elseif ($current_action === 'doe_venmo_pay_reminder_48') {
                $subject_key = 'doe_venmo_pay_au_reminder_48_subject';
            }
        } else {
            if ($current_action === 'doe_venmo_pay_initial') {
                $subject_key = 'doe_venmo_pay_usa_initial_subject';
            } elseif ($current_action === 'doe_venmo_pay_reminder_24') {
                $subject_key = 'doe_venmo_pay_usa_reminder_24_subject';
            } elseif ($current_action === 'doe_venmo_pay_reminder_48') {
                $subject_key = 'doe_venmo_pay_usa_reminder_48_subject';
            }
        }

        $subject = $settings[$subject_key] ?? 'PAYMENT LINK : [{company_name}]: New order #{order_id}';
        $subject = $this->replace_placeholders($subject, $order);
        $content = $settings[$content_key] ?? 'Dear {customer_name},<br><br>Kindly use the "friends and family" option to avoid a 28% tax. When making the payment, please only mention your name or "Gift" in the comment section and avoid any mention of medicine or our website name. Also let us know once the payment is done we will process your order accordingly<br><br>Your total payable amount is {order_total}<br><br>Venmo to <strong>@Mark-Overson-1</strong>.<br>Let us know once the payment is complete, and we will proceed with your order accordingly. If you need the last 4 digits to verify the payments it\'s "3863"<br><br>Thank you,<br>Team {company_name}<br>Phone: +1 877-925-1112 (Call and Chat)<br><a href="https://wa.me/18779251112" target="_blank">WhatsApp us</a> (For chat only)';

        $email_content = '
        <html>
        <head>
            <meta charset="UTF-8">
        </head>
        <body>
            ' . wpautop($this->replace_placeholders($content, $order)) . $this->get_wc_email_content($subject, $content, $order) . '
        </body>
        </html>';
        $headers = $this->get_email_headers($order);

        $mailer = WC()->mailer();
        $result = $mailer->send(
            $order->get_billing_email(),
            $subject,
            $email_content,
            $headers
        );

        $this->log_debug("Venmo Pay email ($current_action) for order #$order_id sent: " . ($result ? 'Success' : 'Failed'));
    }

    public function show_zelle_and_cashapp_to_usa($available_gateways) {
        if (is_admin() || !function_exists('WC') || !WC()->customer) {
            return $available_gateways;
        }

        $customer_country = WC()->customer->get_billing_country() ?: WC()->customer->get_shipping_country();

        if ('US' !== $customer_country) {
            unset($available_gateways['zelle_pay']);
            unset($available_gateways['cash_app']);
            unset($available_gateways['venmo_pay']);
        }
        if ('GB' === $customer_country || 'AU' === $customer_country) {
            unset($available_gateways['doe_cod']);
            unset($available_gateways['cod']);
        }

        return $available_gateways;
    }
}
?>