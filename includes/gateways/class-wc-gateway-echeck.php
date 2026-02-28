<?php
/**
 * WooCommerce eCheck Payment Gateway
 *
 * Extends WC_Payment_Gateway to provide an eCheck payment method
 * with inline checkout banking fields, admin order display, and
 * encrypted storage of sensitive data.
 *
 * @package DynamicOrderEmails
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

#[AllowDynamicProperties]
class WC_Gateway_ECheck extends WC_Payment_Gateway {

    // ── Explicit property declarations (PHP 8.2 compatibility) ─────────────

    /** @var string */
    public $instructions;

    /** @var string */
    public $payment_instructions;

    /** @var string */
    public $thank_you_page_text;

    /** @var string */
    public $email_header_text;

    /** @var array */
    public $enable_for_methods;

    /** @var bool */
    public $enable_for_virtual;

    /** @var string */
    public $doe_disable_emails;

    // ── Encryption key constant ─────────────────────────────────────────────

    const CIPHER = 'AES-256-CBC';

    // ── Constructor ─────────────────────────────────────────────────────────

    public function __construct() {
        $this->id                 = 'echeck_pay';
        $this->icon               = apply_filters( 'wc_echeck_pay_icon', '' );
        $this->has_fields         = true; // shows payment_fields() inline at checkout
        $this->method_title       = __( 'eCheck', 'dynamic-order-emails' );
        $this->method_description = __( 'Allow customers to pay via eCheck (electronic check) at checkout.', 'dynamic-order-emails' );
        $this->supports           = array( 'products' );

        $this->init_form_fields();
        $this->init_settings();

        $this->title              = $this->get_option( 'title' );
        $this->description        = $this->get_option( 'description' );
        $this->instructions       = $this->get_option( 'instructions' );
        $this->payment_instructions = $this->get_option( 'payment_instructions' );
        $this->thank_you_page_text  = $this->get_option( 'thank_you_page_text' );
        $this->email_header_text    = $this->get_option( 'email_header_text' );
        $this->enable_for_methods   = $this->get_option( 'enable_for_methods', array() );
        $this->enable_for_virtual   = $this->get_option( 'enable_for_virtual', 'yes' ) === 'yes';
        $this->doe_disable_emails   = $this->get_option( 'doe_disable_emails' );

        // ── Action hooks ────────────────────────────────────────────────────

        // Save admin gateway settings
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

        // Thank-you page
        add_action( 'woocommerce_thankyou_' . $this->id, array( $this, 'thankyou_page' ) );

        // Email footer text (customer — sensitive fields excluded)
        add_action( 'woocommerce_email_before_order_table', array( $this, 'email_instructions' ), 10, 3 );

        // Checkout field validation
        add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_fields' ) );

        // Save order meta after checkout
        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_order_meta' ) );

        // Admin order page meta box
        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'display_order_meta_in_admin' ), 10, 1 );

        // Enqueue checkout JS + CSS (only on checkout)
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );
    }

    // ── Admin Settings Form Fields ──────────────────────────────────────────

    public function init_form_fields() {
        $shipping_methods = array();
        foreach ( WC()->shipping()->load_shipping_methods() as $method ) {
            $shipping_methods[ $method->id ] = $method->get_method_title();
        }

        $this->form_fields = array(
            'enabled'       => array(
                'title'   => __( 'Enable/Disable', 'dynamic-order-emails' ),
                'type'    => 'checkbox',
                'label'   => __( 'Enable eCheck Payment', 'dynamic-order-emails' ),
                'default' => 'yes',
            ),
            'title'         => array(
                'title'       => __( 'Title', 'dynamic-order-emails' ),
                'type'        => 'text',
                'description' => __( 'This controls the title which the user sees during checkout.', 'dynamic-order-emails' ),
                'default'     => __( 'eCheck', 'dynamic-order-emails' ),
                'desc_tip'    => true,
            ),
            'description'   => array(
                'title'       => __( 'Description', 'dynamic-order-emails' ),
                'type'        => 'textarea',
                'description' => __( 'Payment method description that the customer will see on your checkout.', 'dynamic-order-emails' ),
                'default'     => __( 'Pay securely via electronic check.', 'dynamic-order-emails' ),
                'desc_tip'    => true,
            ),
            'instructions'  => array(
                'title'       => __( 'Instructions', 'dynamic-order-emails' ),
                'type'        => 'textarea',
                'description' => __( 'Instructions that will be added to the thank you page and emails.', 'dynamic-order-emails' ),
                'default'     => '',
                'desc_tip'    => true,
            ),
            'thank_you_page_text' => array(
                'title'       => __( 'Order Received Page Text (Thank you page)', 'dynamic-order-emails' ),
                'type'        => 'wpeditor',
                'description' => __( 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}', 'dynamic-order-emails' ),
                'default'     => '<div style="max-width:500px;margin:0 auto 20px;background:#ffffff;border:1px solid #e0e0e0;border-radius:12px;padding:20px;text-align:center;"><h2 style="color:#28a745;margin-bottom:15px;"><span style="color:#333333;font-size:15px;"><b>Thank you! Your eCheck payment details have been received. We will verify your payment within one working day.</b></span></h2><p style="font-size:15px;color:#333;margin-bottom:12px;">If you have any questions, feel free to reach out at <a href="mailto:{from_email}">{from_email}</a>. We\'re happy to assist!</p></div>',
                'desc_tip'    => false,
            ),
            'email_header_text' => array(
                'title'       => __( 'Email Header Text', 'dynamic-order-emails' ),
                'type'        => 'wpeditor',
                'description' => __( 'Use placeholders: {from_email}, {order_number}, {order_total}, {currency}, {customer_name}, {echeck_details}', 'dynamic-order-emails' ),
                'default'     => '<p>Thank you for shopping with us! Your eCheck payment is being reviewed.</p><strong>We will notify you about the transaction status within one working day. Delivery can take up to twenty to thirty working days.</strong>

{echeck_details}',
                'desc_tip'    => false,
            ),
            'doe_echeck_pay_usa_initial_reminder_time' => array(
                'title'       => __( 'Initial Reminder Time', 'dynamic-order-emails' ),
                'type'        => 'reminder_time',
                'description' => __( 'Select type and value for the initial reminder time.', 'dynamic-order-emails' ),
                'desc_tip'    => true,
            ),
            'doe_echeck_pay_usa_initial_subject' => array(
                'title'   => __( 'eCheck Initial Email Subject', 'dynamic-order-emails' ),
                'type'    => 'text',
                'default' => 'PAYMENT RECEIVED : [{company_name}]: New order #{order_id}',
            ),
            'doe_echeck_pay_usa_reminder_24_time' => array(
                'title'       => __( '24h Reminder Time', 'dynamic-order-emails' ),
                'type'        => 'reminder_time',
                'description' => __( 'Select type and value for the 24h reminder time.', 'dynamic-order-emails' ),
                'desc_tip'    => true,
            ),
            'doe_echeck_pay_usa_reminder_24_subject' => array(
                'title'   => __( 'eCheck 24h Reminder Email Subject', 'dynamic-order-emails' ),
                'type'    => 'text',
                'default' => 'REMINDER : [{company_name}]: New order #{order_id}',
            ),
            'doe_echeck_pay_usa_reminder_48_time' => array(
                'title'       => __( '48h Reminder Time', 'dynamic-order-emails' ),
                'type'        => 'reminder_time',
                'description' => __( 'Select type and value for the 48h reminder time.', 'dynamic-order-emails' ),
                'desc_tip'    => true,
            ),
            'doe_echeck_pay_usa_reminder_48_subject' => array(
                'title'   => __( 'eCheck 48h Reminder Email Subject', 'dynamic-order-emails' ),
                'type'    => 'text',
                'default' => 'KINDLY REMINDER : [{company_name}]: New order #{order_id}',
            ),
            'doe_echeck_pay_usa' => array(
                'title'       => __( 'eCheck Email Body', 'dynamic-order-emails' ),
                'type'        => 'wpeditor',
                'default'     => 'Hello {customer_name},<br><br>Thank you for placing your order with us!<br><br>Your eCheck payment for order #{order_id} has been received and is currently being verified. We will notify you once the payment has been confirmed and your order is being processed.<br><br>Please do not refresh the page or enter the e-checking information again. We will notify you about the transaction status within one working day.<br><br><strong>Delivery can take up to twenty to thirty working days.</strong><br><br>Warm Regards,<br>Team {company_name}',
                'description' => __( 'Use {customer_name}, {order_id}, {company_name}, {currency}, {order_total}, and {from_email} for dynamic content.', 'dynamic-order-emails' ),
                'desc_tip'    => false,
            ),
            'enable_for_methods' => array(
                'title'             => __( 'Enable for shipping methods', 'dynamic-order-emails' ),
                'type'              => 'multiselect',
                'class'             => 'wc-enhanced-select',
                'css'               => 'width: 400px;',
                'default'           => '',
                'description'       => __( 'If eCheck is only available for certain methods, set it up here. Leave blank to enable for all methods.', 'dynamic-order-emails' ),
                'options'           => $shipping_methods,
                'desc_tip'          => true,
                'custom_attributes' => array(
                    'data-placeholder' => __( 'Select shipping methods', 'dynamic-order-emails' ),
                ),
            ),
            'enable_for_virtual' => array(
                'title'   => __( 'Accept for virtual orders', 'dynamic-order-emails' ),
                'label'   => __( 'Accept eCheck if the order is virtual', 'dynamic-order-emails' ),
                'type'    => 'checkbox',
                'default' => 'yes',
            ),
        );
    }

    // ── Checkout – Render Payment Fields ───────────────────────────────────

    /**
     * Renders the eCheck banking form inside the gateway description area.
     * The outer wrapper is shown/hidden via JavaScript.
     */
    public function payment_fields() {
        // Show the gateway description if set
        if ( $this->description ) {
            echo '<p>' . wp_kses_post( $this->description ) . '</p>';
        }
        ?>
        <div id="doe-echeck-fields" class="doe-echeck-form" style="display:none;">
            <div class="doe-echeck-form-inner">

                <div class="doe-echeck-row">
                    <div class="doe-echeck-col">
                        <label for="echeck_first_name">
                            <?php esc_html_e( 'First Name', 'dynamic-order-emails' ); ?> <span class="required">*</span>
                        </label>
                        <input type="text"
                               id="echeck_first_name"
                               name="echeck_first_name"
                               class="input-text doe-echeck-input"
                               autocomplete="given-name"
                               value="<?php echo esc_attr( isset( $_POST['echeck_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['echeck_first_name'] ) ) : '' ); ?>" />
                    </div>
                    <div class="doe-echeck-col">
                        <label for="echeck_last_name">
                            <?php esc_html_e( 'Last Name', 'dynamic-order-emails' ); ?> <span class="required">*</span>
                        </label>
                        <input type="text"
                               id="echeck_last_name"
                               name="echeck_last_name"
                               class="input-text doe-echeck-input"
                               autocomplete="family-name"
                               value="<?php echo esc_attr( isset( $_POST['echeck_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['echeck_last_name'] ) ) : '' ); ?>" />
                    </div>
                </div>

                <div class="doe-echeck-row">
                    <div class="doe-echeck-col-full">
                        <label for="echeck_payment_type">
                            <?php esc_html_e( 'Payment Type', 'dynamic-order-emails' ); ?> <span class="required">*</span>
                        </label>
                        <select id="echeck_payment_type"
                                name="echeck_payment_type"
                                class="doe-echeck-select">
                            <option value=""><?php esc_html_e( '— Select —', 'dynamic-order-emails' ); ?></option>
                            <option value="personal_checking" <?php selected( isset( $_POST['echeck_payment_type'] ) ? sanitize_key( $_POST['echeck_payment_type'] ) : '', 'personal_checking' ); ?>>
                                <?php esc_html_e( 'Personal Checking', 'dynamic-order-emails' ); ?>
                            </option>
                            <option value="business_checking" <?php selected( isset( $_POST['echeck_payment_type'] ) ? sanitize_key( $_POST['echeck_payment_type'] ) : '', 'business_checking' ); ?>>
                                <?php esc_html_e( 'Business Checking', 'dynamic-order-emails' ); ?>
                            </option>
                        </select>
                    </div>
                </div>

                <div class="doe-echeck-row">
                    <div class="doe-echeck-col">
                        <label for="echeck_routing_number">
                            <?php esc_html_e( 'Routing Number', 'dynamic-order-emails' ); ?> <span class="required">*</span>
                        </label>
                        <input type="password"
                               id="echeck_routing_number"
                               name="echeck_routing_number"
                               class="input-text doe-echeck-input"
                               inputmode="numeric"
                               autocomplete="off" />
                    </div>
                    <div class="doe-echeck-col">
                        <label for="echeck_routing_number_confirm">
                            <?php esc_html_e( 'Confirm Routing Number', 'dynamic-order-emails' ); ?> <span class="required">*</span>
                        </label>
                        <input type="password"
                               id="echeck_routing_number_confirm"
                               name="echeck_routing_number_confirm"
                               class="input-text doe-echeck-input"
                               inputmode="numeric"
                               autocomplete="off" />
                    </div>
                </div>

                <div class="doe-echeck-row">
                    <div class="doe-echeck-col">
                        <label for="echeck_account_number">
                            <?php esc_html_e( 'Account Number', 'dynamic-order-emails' ); ?> <span class="required">*</span>
                        </label>
                        <input type="password"
                               id="echeck_account_number"
                               name="echeck_account_number"
                               class="input-text doe-echeck-input"
                               inputmode="numeric"
                               autocomplete="off" />
                    </div>
                    <div class="doe-echeck-col">
                        <label for="echeck_account_number_confirm">
                            <?php esc_html_e( 'Confirm Account Number', 'dynamic-order-emails' ); ?> <span class="required">*</span>
                        </label>
                        <input type="password"
                               id="echeck_account_number_confirm"
                               name="echeck_account_number_confirm"
                               class="input-text doe-echeck-input"
                               inputmode="numeric"
                               autocomplete="off" />
                    </div>
                </div>

                <p class="doe-echeck-notice">
                    <?php esc_html_e( 'Please do not refresh the page or enter the e-checking information again. We will notify you about the transaction status within one working day.', 'dynamic-order-emails' ); ?>
                </p>
                <p class="doe-echeck-highlight">
                    <?php esc_html_e( 'Delivery can take up to twenty to thirty working days.', 'dynamic-order-emails' ); ?>
                </p>

            </div><!-- /.doe-echeck-form-inner -->
        </div><!-- /#doe-echeck-fields -->
        <?php
    }

    // ── Checkout – Validation ───────────────────────────────────────────────

    /**
     * Validates eCheck fields only when eCheck gateway is selected.
     * Fires on woocommerce_checkout_process.
     */
    public function validate_checkout_fields() {
        // Only validate when this gateway is chosen
        $payment_method = isset( $_POST['payment_method'] ) ? sanitize_key( $_POST['payment_method'] ) : '';
        if ( $payment_method !== $this->id ) {
            return;
        }

        $first_name              = isset( $_POST['echeck_first_name'] )              ? sanitize_text_field( wp_unslash( $_POST['echeck_first_name'] ) )              : '';
        $last_name               = isset( $_POST['echeck_last_name'] )               ? sanitize_text_field( wp_unslash( $_POST['echeck_last_name'] ) )               : '';
        $payment_type            = isset( $_POST['echeck_payment_type'] )            ? sanitize_key( wp_unslash( $_POST['echeck_payment_type'] ) )                   : '';
        $routing_number          = isset( $_POST['echeck_routing_number'] )          ? sanitize_text_field( wp_unslash( $_POST['echeck_routing_number'] ) )          : '';
        $routing_number_confirm  = isset( $_POST['echeck_routing_number_confirm'] )  ? sanitize_text_field( wp_unslash( $_POST['echeck_routing_number_confirm'] ) )  : '';
        $account_number          = isset( $_POST['echeck_account_number'] )          ? sanitize_text_field( wp_unslash( $_POST['echeck_account_number'] ) )          : '';
        $account_number_confirm  = isset( $_POST['echeck_account_number_confirm'] )  ? sanitize_text_field( wp_unslash( $_POST['echeck_account_number_confirm'] ) )  : '';

        // Required: First Name
        if ( empty( $first_name ) ) {
            wc_add_notice( __( 'eCheck: First Name is required.', 'dynamic-order-emails' ), 'error' );
        }

        // Required: Last Name
        if ( empty( $last_name ) ) {
            wc_add_notice( __( 'eCheck: Last Name is required.', 'dynamic-order-emails' ), 'error' );
        }

        // Required: Payment Type
        if ( empty( $payment_type ) || ! in_array( $payment_type, array( 'personal_checking', 'business_checking' ), true ) ) {
            wc_add_notice( __( 'eCheck: Please select a valid Payment Type.', 'dynamic-order-emails' ), 'error' );
        }

        // Required: Routing Number – numeric
        if ( empty( $routing_number ) ) {
            wc_add_notice( __( 'eCheck: Routing Number is required.', 'dynamic-order-emails' ), 'error' );
        } elseif ( ! ctype_digit( $routing_number ) ) {
            wc_add_notice( __( 'eCheck: Routing Number must be numeric.', 'dynamic-order-emails' ), 'error' );
        }

        // Required: Confirm Routing Number – must match
        if ( empty( $routing_number_confirm ) ) {
            wc_add_notice( __( 'eCheck: Please confirm your Routing Number.', 'dynamic-order-emails' ), 'error' );
        } elseif ( $routing_number !== $routing_number_confirm ) {
            wc_add_notice( __( 'eCheck: Routing Numbers do not match.', 'dynamic-order-emails' ), 'error' );
        }

        // Required: Account Number – numeric
        if ( empty( $account_number ) ) {
            wc_add_notice( __( 'eCheck: Account Number is required.', 'dynamic-order-emails' ), 'error' );
        } elseif ( ! ctype_digit( $account_number ) ) {
            wc_add_notice( __( 'eCheck: Account Number must be numeric.', 'dynamic-order-emails' ), 'error' );
        }

        // Required: Confirm Account Number – must match
        if ( empty( $account_number_confirm ) ) {
            wc_add_notice( __( 'eCheck: Please confirm your Account Number.', 'dynamic-order-emails' ), 'error' );
        } elseif ( $account_number !== $account_number_confirm ) {
            wc_add_notice( __( 'eCheck: Account Numbers do not match.', 'dynamic-order-emails' ), 'error' );
        }
    }

    // ── Checkout – Process Payment ──────────────────────────────────────────

    /**
     * Process the payment: set order on-hold, reduce stock, empty cart.
     *
     * @param int $order_id
     * @return array|void
     */
    public function process_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $this->is_available_for_shipping( $order ) ) {
            wc_add_notice( __( 'eCheck is not available for the selected shipping method.', 'dynamic-order-emails' ), 'error' );
            return;
        }

        // Place order on-hold pending admin verification
        $order->update_status( 'on-hold', __( 'Awaiting eCheck verification.', 'dynamic-order-emails' ) );

        // Reduce stock
        wc_reduce_stock_levels( $order_id );

        // Empty cart
        WC()->cart->empty_cart();

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    // ── Save Order Meta ─────────────────────────────────────────────────────

    /**
     * Saves eCheck banking details to order meta, encrypted where sensitive.
     * Fires on woocommerce_checkout_update_order_meta.
     *
     * @param int $order_id
     */
    public function save_order_meta( $order_id ) {
        $payment_method = isset( $_POST['payment_method'] ) ? sanitize_key( $_POST['payment_method'] ) : '';
        if ( $payment_method !== $this->id ) {
            return;
        }

        $first_name     = isset( $_POST['echeck_first_name'] )     ? sanitize_text_field( wp_unslash( $_POST['echeck_first_name'] ) )     : '';
        $last_name      = isset( $_POST['echeck_last_name'] )      ? sanitize_text_field( wp_unslash( $_POST['echeck_last_name'] ) )      : '';
        $payment_type   = isset( $_POST['echeck_payment_type'] )   ? sanitize_key( wp_unslash( $_POST['echeck_payment_type'] ) )          : '';
        $routing_number = isset( $_POST['echeck_routing_number'] ) ? sanitize_text_field( wp_unslash( $_POST['echeck_routing_number'] ) ) : '';
        $account_number = isset( $_POST['echeck_account_number'] ) ? sanitize_text_field( wp_unslash( $_POST['echeck_account_number'] ) ) : '';

        // Store safe, non-sensitive fields in plain text
        update_post_meta( $order_id, '_echeck_first_name',    $first_name );
        update_post_meta( $order_id, '_echeck_last_name',     $last_name );
        update_post_meta( $order_id, '_echeck_payment_type',  $payment_type );

        // Encrypt sensitive banking numbers
        update_post_meta( $order_id, '_echeck_routing_number', $this->encrypt( $routing_number ) );
        update_post_meta( $order_id, '_echeck_account_number', $this->encrypt( $account_number ) );
    }

    // ── Admin Order Panel ───────────────────────────────────────────────────

    /**
     * Display eCheck details inside the admin order edit page.
     * Also renders the "Mark as eCheck Verified" action button.
     *
     * @param WC_Order $order
     */
    public function display_order_meta_in_admin( $order ) {
        if ( $order->get_payment_method() !== $this->id ) {
            return;
        }

        $order_id       = $order->get_id();
        $first_name     = get_post_meta( $order_id, '_echeck_first_name',    true );
        $last_name      = get_post_meta( $order_id, '_echeck_last_name',     true );
        $payment_type   = get_post_meta( $order_id, '_echeck_payment_type',  true );
        $routing_raw    = get_post_meta( $order_id, '_echeck_routing_number', true );
        $account_raw    = get_post_meta( $order_id, '_echeck_account_number', true );

        // Decrypt and show all digits
        $routing_masked = $routing_raw ? $this->decrypt( $routing_raw ) : '—';
        $account_masked = $account_raw ? $this->decrypt( $account_raw ) : '—';

        // Human-readable payment type label
        $type_labels = array(
            'personal_checking' => __( 'Personal Checking', 'dynamic-order-emails' ),
            'business_checking' => __( 'Business Checking', 'dynamic-order-emails' ),
        );
        $payment_type_label = isset( $type_labels[ $payment_type ] ) ? $type_labels[ $payment_type ] : esc_html( $payment_type );

        ?>
        <div class="doe-echeck-admin-box" style="margin-top:20px; padding:15px; background:#f9f9f9; border:1px solid #ddd; border-radius:6px;">
            <h3 style="margin-top:0; margin-bottom:12px; font-size:14px; color:#1d2327; border-bottom:1px solid #ddd; padding-bottom:8px;">
                🏦 <?php esc_html_e( 'eCheck Details', 'dynamic-order-emails' ); ?>
            </h3>
            <table class="doe-echeck-admin-table" style="width:100%; border-collapse:collapse; font-size:13px;">
                <tr>
                    <th style="text-align:left; padding:5px 10px 5px 0; color:#646970; font-weight:600; width:45%;">
                        <?php esc_html_e( 'First Name', 'dynamic-order-emails' ); ?>
                    </th>
                    <td style="padding:5px 0; color:#1d2327;">
                        <?php echo esc_html( $first_name ?: '—' ); ?>
                    </td>
                </tr>
                <tr>
                    <th style="text-align:left; padding:5px 10px 5px 0; color:#646970; font-weight:600;">
                        <?php esc_html_e( 'Last Name', 'dynamic-order-emails' ); ?>
                    </th>
                    <td style="padding:5px 0; color:#1d2327;">
                        <?php echo esc_html( $last_name ?: '—' ); ?>
                    </td>
                </tr>
                <tr>
                    <th style="text-align:left; padding:5px 10px 5px 0; color:#646970; font-weight:600;">
                        <?php esc_html_e( 'Payment Type', 'dynamic-order-emails' ); ?>
                    </th>
                    <td style="padding:5px 0; color:#1d2327;">
                        <?php echo esc_html( $payment_type_label ?: '—' ); ?>
                    </td>
                </tr>
                <tr>
                    <th style="text-align:left; padding:5px 10px 5px 0; color:#646970; font-weight:600;">
                        <?php esc_html_e( 'Routing Number', 'dynamic-order-emails' ); ?>
                    </th>
                    <td style="padding:5px 0; color:#1d2327; font-family:monospace;">
                        <?php echo esc_html( $routing_masked ); ?>
                    </td>
                </tr>
                <tr>
                    <th style="text-align:left; padding:5px 10px 5px 0; color:#646970; font-weight:600;">
                        <?php esc_html_e( 'Account Number', 'dynamic-order-emails' ); ?>
                    </th>
                    <td style="padding:5px 0; color:#1d2327; font-family:monospace;">
                        <?php echo esc_html( $account_masked ); ?>
                    </td>
                </tr>
            </table>

        </div>
        <?php
    }

    // ── Frontend Script / Style Enqueue ────────────────────────────────────

    /**
     * Enqueue eCheck checkout JS + CSS only on the checkout page.
     */
    public function enqueue_checkout_scripts() {
        if ( ! is_checkout() ) {
            return;
        }

        // Inline styles for the eCheck form
        $css = '
            /* ── eCheck Payment Form Styles ─────────────────────────── */
            #doe-echeck-fields {
                margin-top: 12px;
            }
            .doe-echeck-form-inner {
                border: 2px solid #c8e6c9;
                border-radius: 8px;
                padding: 20px;
                background: #f9fbe7;
            }
            .doe-echeck-row {
                display: flex;
                gap: 16px;
                margin-bottom: 14px;
                flex-wrap: wrap;
            }
            .doe-echeck-col {
                flex: 1 1 calc(50% - 8px);
                min-width: 200px;
                display: flex;
                flex-direction: column;
            }
            .doe-echeck-col-full {
                flex: 1 1 100%;
                display: flex;
                flex-direction: column;
            }
            .doe-echeck-col label,
            .doe-echeck-col-full label {
                font-size: 12px;
                font-weight: 600;
                color: #555;
                margin-bottom: 4px;
            }
            .doe-echeck-col label .required,
            .doe-echeck-col-full label .required {
                color: #e2401c;
            }
            .doe-echeck-input {
                width: 100% !important;
                padding: 8px 12px !important;
                border: 1px solid #ccc !important;
                border-radius: 4px !important;
                font-size: 14px !important;
                box-sizing: border-box;
            }
            .doe-echeck-input:focus {
                border-color: #96c8a2 !important;
                outline: none;
                box-shadow: 0 0 0 2px rgba(150, 200, 162, 0.3);
            }
            .doe-echeck-select {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-size: 14px;
                background: #fff;
                box-sizing: border-box;
            }
            .doe-echeck-select:focus {
                border-color: #96c8a2;
                outline: none;
            }
            .doe-echeck-notice {
                font-size: 13px;
                color: #555;
                margin: 12px 0 4px;
            }
            .doe-echeck-highlight {
                font-size: 14px;
                font-weight: 700;
                color: #0073aa;
                margin: 0;
            }
            @media (max-width: 640px) {
                .doe-echeck-row { flex-direction: column; gap: 10px; }
                .doe-echeck-col { flex: 1 1 100%; }
            }
        ';
        wp_register_style( 'doe-echeck-checkout', false );
        wp_enqueue_style( 'doe-echeck-checkout' );
        wp_add_inline_style( 'doe-echeck-checkout', $css );

        // Inline JavaScript for show/hide behaviour
        $js = '
            (function($){
                "use strict";

                var ECHECK_ID = "echeck_pay";

                function doECheckToggle() {
                    var selected = $( "input[name=\'payment_method\']:checked" ).val();
                    if ( selected === ECHECK_ID ) {
                        $( "#doe-echeck-fields" ).slideDown( 200 );
                    } else {
                        $( "#doe-echeck-fields" ).slideUp( 200 );
                    }
                }

                // Initial toggle on page load
                $( document ).ready( function() {
                    doECheckToggle();
                    $( document.body ).on( "change", "input[name=\'payment_method\']", doECheckToggle );
                });

                // Re-run after WooCommerce AJAX checkout refresh
                $( document.body ).on( "updated_checkout", function() {
                    doECheckToggle();
                    $( document.body ).on( "change", "input[name=\'payment_method\']", doECheckToggle );
                });

            })(jQuery);
        ';

        wp_register_script( 'doe-echeck-checkout', false, array( 'jquery', 'wc-checkout' ), null, true );
        wp_enqueue_script( 'doe-echeck-checkout' );
        wp_add_inline_script( 'doe-echeck-checkout', $js );
    }

    // ── Gateway Availability ────────────────────────────────────────────────

    public function is_available() {
        $is_available = parent::is_available();

        if ( WC()->cart && ! $this->enable_for_virtual ) {
            foreach ( WC()->cart->get_cart() as $cart_item ) {
                $product = $cart_item['data'];
                if ( $product->is_virtual() ) {
                    $is_available = false;
                    break;
                }
            }
        }

        return $is_available;
    }

    /**
     * Check if the gateway is available for the current shipping method.
     *
     * @param WC_Order $order
     * @return bool
     */
    private function is_available_for_shipping( $order ) {
        if ( empty( $this->enable_for_methods ) ) {
            return true;
        }

        $chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
        if ( empty( $chosen_shipping_methods ) ) {
            return false;
        }

        foreach ( $chosen_shipping_methods as $method ) {
            $method_id = explode( ':', $method )[0];
            if ( in_array( $method_id, $this->enable_for_methods, true ) ) {
                return true;
            }
        }

        return false;
    }

    // ── Thank You Page ──────────────────────────────────────────────────────

    public function thankyou_page( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            $this->log_debug( "eCheck: Invalid order ID #{$order_id}" );
            return;
        }

        $settings = get_option( 'woocommerce_' . $this->id . '_settings', array() );
        $content  = ! empty( $settings['thank_you_page_text'] ) ? $settings['thank_you_page_text'] : $this->instructions;

        if ( $content ) {
            $content = $this->replace_placeholders( $content, $order );
            echo wp_kses_post( wpautop( wptexturize( $content ) ) );
        } else {
            $this->log_debug( "eCheck: No thank_you_page_text found for order #{$order->get_id()}" );
        }
    }

    // ── Email Instructions (customer – no sensitive data) ───────────────────

    /**
     * Append email text to customer emails. Sensitive banking data is intentionally
     * excluded — only the gateway header/body text is appended.
     *
     * @param WC_Order $order
     * @param bool     $sent_to_admin
     * @param bool     $plain_text
     */
    public function email_instructions( $order, $sent_to_admin, $plain_text = false ) {
        // Skip admin emails and orders placed with other gateways
        if ( $sent_to_admin || $order->get_payment_method() !== $this->id ) {
            return;
        }

        $allowed_statuses = array( 'on-hold', 'processing' );
        $current_status   = $order->get_status();
        $show_header      = in_array( $current_status, $allowed_statuses, true );

        $header  = $show_header ? ( $this->email_header_text ?: '' ) : '';
        $body    = $this->payment_instructions ?: $this->instructions;

        $content = trim( $header . ( $header && $body ? "\n\n" : '' ) . $body );
        if ( ! $content ) {
            $this->log_debug( "eCheck: No email content for order #{$order->get_id()}" );
            return;
        }

        $content = $this->replace_placeholders( $content, $order );

        if ( $plain_text ) {
            echo wptexturize( wp_strip_all_tags( $content ) ) . PHP_EOL;
        } else {
            echo wpautop( wptexturize( $content ) ) . PHP_EOL;
        }
    }

    // ── Encryption Helpers ──────────────────────────────────────────────────

    /**
     * Encrypt a string using AES-256-CBC with WordPress SECURE_AUTH_KEY.
     *
     * @param string $data Plain-text string.
     * @return string      Base64-encoded cipher-text, or plain $data if OpenSSL unavailable.
     */
    private function encrypt( $data ) {
        if ( ! function_exists( 'openssl_encrypt' ) || empty( $data ) ) {
            return $data;
        }

        $key = substr( hash( 'sha256', defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : wp_salt( 'secure_auth' ) ), 0, 32 );
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );
        $enc = openssl_encrypt( $data, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        return base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
    }

    /**
     * Decrypt a string previously encrypted with self::encrypt().
     *
     * @param string $data Base64-encoded cipher-text.
     * @return string      Decrypted plain-text, or $data on failure.
     */
    public function decrypt( $data ) {
        if ( ! function_exists( 'openssl_decrypt' ) || empty( $data ) ) {
            return $data;
        }

        $raw    = base64_decode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
        $iv_len = openssl_cipher_iv_length( self::CIPHER );
        $iv     = substr( $raw, 0, $iv_len );
        $enc    = substr( $raw, $iv_len );
        $key    = substr( hash( 'sha256', defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : wp_salt( 'secure_auth' ) ), 0, 32 );
        $dec    = openssl_decrypt( $enc, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );

        return ( false !== $dec ) ? $dec : $data;
    }

    // ── Shared Helpers (mirroring Venmo/Zelle pattern) ──────────────────────

    /**
     * Replace template placeholders with real order data.
     *
     * @param string   $text
     * @param WC_Order $order
     * @return string
     */
    private function replace_placeholders( $text, $order ) {
        if ( ! $order ) {
            return $text;
        }

        $customer_name      = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $order_total        = wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) );
        $email_from_address = get_option( 'woocommerce_email_from_address' );

        // Build {echeck_details} replacement block
        $echeck_details = $this->build_echeck_details_html( $order );

        $replacements = array(
            '{customer_name}'  => $customer_name ?: __( 'Customer', 'dynamic-order-emails' ),
            '{order_id}'       => $order->get_id(),
            '{order_number}'   => $order->get_order_number(),
            '{order_total}'    => strip_tags( $order_total ),
            '{currency}'       => $order->get_currency(),
            '{company_name}'   => get_bloginfo( 'name' ),
            '{from_email}'     => $this->extract_domain( $email_from_address ),
            '{echeck_details}' => $echeck_details,
        );

        return strtr( $text, $replacements );
    }

    /**
     * Build a formatted HTML (or plain-text) block of the order's eCheck details
     * to be injected via the {echeck_details} placeholder.
     *
     * @param WC_Order $order
     * @return string
     */
    private function build_echeck_details_html( $order ) {
        if ( ! $order ) {
            return '';
        }

        $order_id     = $order->get_id();
        $first_name   = get_post_meta( $order_id, '_echeck_first_name',    true );
        $last_name    = get_post_meta( $order_id, '_echeck_last_name',     true );
        $payment_type = get_post_meta( $order_id, '_echeck_payment_type',  true );
        $routing_raw  = get_post_meta( $order_id, '_echeck_routing_number', true );
        $account_raw  = get_post_meta( $order_id, '_echeck_account_number', true );

        // Bail silently if no eCheck data is stored for this order
        if ( ! $first_name && ! $last_name && ! $routing_raw && ! $account_raw ) {
            return '';
        }

        $type_labels = array(
            'personal_checking' => __( 'Personal Checking', 'dynamic-order-emails' ),
            'business_checking' => __( 'Business Checking', 'dynamic-order-emails' ),
        );
        $payment_type_label = isset( $type_labels[ $payment_type ] ) ? $type_labels[ $payment_type ] : esc_html( $payment_type );

        $routing_number = $routing_raw ? $this->decrypt( $routing_raw ) : '—';
        $account_number = $account_raw ? $this->decrypt( $account_raw ) : '—';
        $full_name      = trim( $first_name . ' ' . $last_name ) ?: '—';

        $html  = '<div style="margin:16px 0;padding:14px 18px;background:#f7f7f7;border:1px solid #ddd;border-radius:6px;font-size:14px;line-height:1.6;">';
        $html .= '<strong style="display:block;margin-bottom:8px;font-size:15px;">' . esc_html__( 'eCheck Details', 'dynamic-order-emails' ) . '</strong>';
        $html .= '<table style="border-collapse:collapse;width:100%;">';
        $html .= '<tr><td style="padding:3px 10px 3px 0;color:#555;width:45%;">' . esc_html__( 'Account Holder', 'dynamic-order-emails' ) . '</td>';
        $html .= '<td style="padding:3px 0;color:#111;">' . esc_html( $full_name ) . '</td></tr>';
        $html .= '<tr><td style="padding:3px 10px 3px 0;color:#555;">' . esc_html__( 'Payment Type', 'dynamic-order-emails' ) . '</td>';
        $html .= '<td style="padding:3px 0;color:#111;">' . esc_html( $payment_type_label ?: '—' ) . '</td></tr>';
        $html .= '<tr><td style="padding:3px 10px 3px 0;color:#555;">' . esc_html__( 'Routing Number', 'dynamic-order-emails' ) . '</td>';
        $html .= '<td style="padding:3px 0;color:#111;font-family:monospace;">' . esc_html( $routing_number ) . '</td></tr>';
        $html .= '<tr><td style="padding:3px 10px 3px 0;color:#555;">' . esc_html__( 'Account Number', 'dynamic-order-emails' ) . '</td>';
        $html .= '<td style="padding:3px 0;color:#111;font-family:monospace;">' . esc_html( $account_number ) . '</td></tr>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Extract the domain from a URL or email address string.
     *
     * @param string $url
     * @return string
     */
    private function extract_domain( $url ) {
        $host = parse_url( trim( $url ), PHP_URL_HOST ) ?: trim( $url );

        if ( empty( $host ) ) {
            $host = preg_replace( '#^https?://#i', '', $url );
        }

        $host = preg_replace( '/^www\./i', '', $host );
        $host = rtrim( $host, '/' );

        return $host;
    }

    // ── Custom Field Renderers (matches Venmo/Zelle pattern) ────────────────

    /**
     * Generate HTML for the custom reminder_time composite field.
     *
     * @param string $key
     * @param array  $data
     * @return string
     */
    public function generate_reminder_time_html( $key, $data ) {
        $field_key  = $this->get_field_key( $key );
        $enable     = $this->get_option( $field_key . '_enable', 'yes' );
        $time_type  = $this->get_option( $field_key . '_type', 'hour' );
        $time_value = $this->get_option( $field_key . '_value', '24' );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo esc_html( $data['title'] ); ?></label>
            </th>
            <td class="forminp forminp-<?php echo sanitize_title( $data['type'] ); ?>">
                <label>
                    <input type="checkbox"
                           name="woocommerce_<?php echo $this->id; ?>_<?php echo $field_key; ?>_enable"
                           value="yes" <?php checked( $enable, 'yes' ); ?> />
                    <?php esc_html_e( 'Enable', 'dynamic-order-emails' ); ?>
                </label>
                <br><br>
                <select name="woocommerce_<?php echo $this->id; ?>_<?php echo $field_key; ?>_type"
                        style="width:120px; display:inline-block; margin-right:10px;">
                    <option value="second" <?php selected( $time_type, 'second' ); ?>><?php esc_html_e( 'Seconds', 'dynamic-order-emails' ); ?></option>
                    <option value="minute" <?php selected( $time_type, 'minute' ); ?>><?php esc_html_e( 'Minutes', 'dynamic-order-emails' ); ?></option>
                    <option value="hour"   <?php selected( $time_type, 'hour' ); ?>><?php esc_html_e( 'Hours',   'dynamic-order-emails' ); ?></option>
                </select>
                <input type="number"
                       min="1"
                       step="1"
                       name="woocommerce_<?php echo $this->id; ?>_<?php echo $field_key; ?>_value"
                       value="<?php echo esc_attr( $time_value ); ?>"
                       style="width:80px; display:inline-block;" />
                <p class="description"><?php echo esc_html( $data['description'] ); ?></p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Generate HTML for the custom wpeditor field.
     *
     * @param string $key
     * @param array  $data
     * @return string
     */
    public function generate_wpeditor_html( $key, $data ) {
        $field_key = $this->plugin_id . $this->id . '_' . $key;
        $defaults  = array(
            'title'       => '',
            'description' => '',
            'desc_tip'    => false,
        );
        $data = wp_parse_args( $data, $defaults );

        ob_start();
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?></label>
                <?php echo $this->get_tooltip_html( $data ); ?>
            </th>
            <td class="forminp forminp-textarea">
                <?php
                wp_editor(
                    $this->get_option( $key ),
                    $field_key,
                    array(
                        'textarea_name' => $this->plugin_id . $this->id . '_' . $key,
                        'textarea_rows' => 10,
                        'media_buttons' => true,
                    )
                );
                ?>
                <?php echo $this->get_description_html( $data ); ?>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Validate / save the custom reminder_time composite field.
     *
     * @param string $key
     * @param mixed  $value (unused — sub-values saved separately)
     */
    public function validate_reminder_time_field( $key, $value ) {
        $field_key = $this->get_field_key( $key );

        $enable = isset( $_POST[ 'woocommerce_' . $this->id . '_' . $field_key . '_enable' ] ) ? 'yes' : 'no';
        $this->update_option( $field_key . '_enable', $enable );

        $type = isset( $_POST[ 'woocommerce_' . $this->id . '_' . $field_key . '_type' ] )
            ? sanitize_text_field( wp_unslash( $_POST[ 'woocommerce_' . $this->id . '_' . $field_key . '_type' ] ) )
            : 'hour';
        $this->update_option( $field_key . '_type', $type );

        $val = isset( $_POST[ 'woocommerce_' . $this->id . '_' . $field_key . '_value' ] )
            ? absint( $_POST[ 'woocommerce_' . $this->id . '_' . $field_key . '_value' ] )
            : 1;
        $this->update_option( $field_key . '_value', $val );

        return; // WC expects no return value for this custom validator
    }

    // ── Debug Logger ────────────────────────────────────────────────────────

    /**
     * Write a debug message to the WooCommerce log when WP_DEBUG is on.
     *
     * @param string $message
     */
    public function log_debug( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $logger  = wc_get_logger();
            $context = array( 'source' => $this->id );
            $logger->debug( $message, $context );
        }
    }
}
