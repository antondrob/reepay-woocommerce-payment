<?php

namespace Reepay\Checkout\Gateways;

use Exception;
use Reepay\Checkout\Tokens\TokenReepay;
use Reepay\Checkout\Tokens\TokenReepayMS;
use WC_Reepay_Gateway_Statistics;
use WC_Reepay_Instant_Settle;
use WC_Reepay_Order_Statuses;
use WP_Error;

defined( 'ABSPATH' ) || exit();

class ReepayCheckout extends ReepayGateway {

	/**
	 * Payment methods.
	 *
	 * @var array|null
	 */
	public $payment_methods = null;

	/**
	 * Init
	 */
	public function __construct() {
		$this->id           = 'reepay_checkout';
		$this->has_fields   = true;
		$this->method_title = __( 'Reepay Checkout', 'reepay-checkout-gateway' );
		$this->supports     = array(
			'products',
			'refunds',
			'add_payment_method',
			'tokenization',
			'subscriptions',
			'subscription_cancellation',
			'subscription_suspension',
			'subscription_reactivation',
			'subscription_amount_changes',
			'subscription_date_changes',
			'subscription_payment_method_change',
			'subscription_payment_method_change_customer',
			'subscription_payment_method_change_admin',
			'multiple_subscriptions',
			'woo_blocks_only_subscriptions_in_cart',
		);

		parent::__construct();

		// Define user set variables
		$this->private_key             = apply_filters( 'woocommerce_reepay_private_key', $this->settings['private_key'] ?? $this->private_key );
		$this->private_key_test        = apply_filters( 'woocommerce_reepay_private_key_test', $this->settings['private_key_test'] ?? $this->private_key_test );
		$this->test_mode               = $this->settings['test_mode'] ?? $this->test_mode;
		$this->settle                  = $this->settings['settle'] ?? $this->settle;
		$this->language                = $this->settings['language'] ?? $this->language;
		$this->save_cc                 = $this->settings['save_cc'] ?? $this->save_cc;
		$this->debug                   = $this->settings['debug'] ?? $this->debug;
		$this->logos                   = $this->settings['logos'] ?? $this->logos;
		$this->payment_type            = $this->settings['payment_type'] ?? $this->payment_type;
		$this->payment_methods         = $this->settings['payment_methods'] ?? $this->payment_methods;
		$this->skip_order_lines        = $this->settings['skip_order_lines'] ?? $this->skip_order_lines;
		$this->enable_order_autocancel = $this->settings['enable_order_autocancel'] ?? $this->enable_order_autocancel;
		$this->failed_webhooks_email   = $this->settings['failed_webhooks_email'] ?? $this->failed_webhooks_email;
		$this->is_webhook_configured   = $this->settings['is_webhook_configured'] ?? $this->is_webhook_configured;
		$this->handle_failover         = $this->settings['handle_failover'] ?? $this->handle_failover;

		// Disable "Add payment method" if the CC saving is disabled
		if ( $this->save_cc !== 'yes' && ( $key = array_search( 'add_payment_method', $this->supports ) ) !== false ) {
			unset( $this->supports[ $key ] );
		}

		// Action for "Add Payment Method"
		add_action( 'wp_ajax_reepay_card_store', array( $this, 'reepay_card_store' ) );
		add_action( 'wp_ajax_nopriv_reepay_card_store', array( $this, 'reepay_card_store' ) );
		add_action( 'wp_ajax_reepay_finalize', array( $this, 'reepay_finalize' ) );
		add_action( 'wp_ajax_nopriv_reepay_finalize', array( $this, 'reepay_finalize' ) );
	}

	/**
	 * Initialise Settings Form Fields
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'     => array(
				'title'   => __( 'Enable/Disable', 'reepay-checkout-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable plugin', 'reepay-checkout-gateway' ),
				'default' => 'no',
			),
			'hr1'         => array(
				'type'    => 'separator',
				'id'      => 'separator_1',
				'title'   => __( 'Webhook status', 'reepay-checkout-gateway' ),
				'label'   => __( 'Webhook status', 'reepay-checkout-gateway' ),
				'default' => $this->test_mode,
			),
			'title'       => array(
				'title'       => __( 'Title', 'reepay-checkout-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout', 'reepay-checkout-gateway' ),
				'default'     => __( 'Reepay Checkout', 'reepay-checkout-gateway' ),
			),
			'description' => array(
				'title'       => __( 'Description', 'reepay-checkout-gateway' ),
				'type'        => 'text',
				'description' => __( 'This controls the description which the user sees during checkout', 'reepay-checkout-gateway' ),
				'default'     => __( 'Reepay Checkout', 'reepay-checkout-gateway' ),
			),
			'hr2'         => array(
				'type' => 'separator',
				'id'   => 'hr2',
			),
			'private_key' => array(
				'title'       => __( 'Live Private Key', 'reepay-checkout-gateway' ),
				'type'        => 'text',
				'description' => __( 'Insert your private key from your live account', 'reepay-checkout-gateway' ),
				'default'     => $this->private_key,
			),

		);

		if ( isset( $_POST['woocommerce_reepay_checkout_private_key'] ) ) {
			$this->settings['private_key'] = $_POST['woocommerce_reepay_checkout_private_key'];
		}

		if ( empty( $this->settings['private_key'] ) ) {
			$this->form_fields['verify_key'] = array(
				'title'       => '',
				'type'        => 'verify_key',
				'description' => '',
				'default'     => 'Save and verify',
			);
		} else {

			$this->private_key = ! empty( $this->settings['private_key'] ) ? $this->settings['private_key'] : $_POST['woocommerce_reepay_checkout_private_key'];

			$this->test_mode = 'no';
			$account_info    = $this->get_account_info( $this );

			if ( ! empty( $account_info ) && ! is_wp_error( $account_info ) ) {
				$this->form_fields['account'] = array(
					'title'       => __( 'Account', 'reepay-checkout-gateway' ),
					'type'        => 'account_info',
					'description' => '',
					'default'     => $account_info['name'],
				);
				$this->form_fields['state']   = array(
					'title'       => __( 'State', 'reepay-checkout-gateway' ),
					'type'        => 'account_info',
					'description' => '',
					'default'     => $account_info['state'],
				);

			}

			$this->form_fields['is_webhook_configured_live'] = array(
				'title'   => __( 'Webhook', 'reepay-checkout-gateway' ),
				'type'    => 'webhook_status',
				'label'   => __( 'Webhook', 'reepay-checkout-gateway' ),
				'default' => $this->is_webhook_configured(),
			);

			$this->test_mode = ! empty( $_POST['woocommerce_reepay_checkout_test_mode'] ) ? $_POST['woocommerce_reepay_checkout_test_mode'] : '';
			$this->test_mode = ! empty( $this->settings['test_mode'] ) ? $this->settings['test_mode'] : $this->test_mode;
		}

		$this->form_fields['hr10'] = array(
			'type' => 'separator',
			'id'   => 'hr10',
		);

		$this->form_fields['private_key_test'] = array(
			'title'       => __( 'Test Private Key', 'reepay-checkout-gateway' ),
			'type'        => 'text',
			'description' => __( 'Insert your private key from your Reepay test account', 'reepay-checkout-gateway' ),
			'default'     => $this->private_key_test,
		);

		if ( isset( $_POST['woocommerce_reepay_checkout_private_key_test'] ) ) {
			$this->settings['private_key_test'] = $_POST['woocommerce_reepay_checkout_private_key_test'];
		}

		if ( empty( $this->settings['private_key_test'] ) ) {
			$this->form_fields['verify_key_test'] = array(
				'title'       => '',
				'type'        => 'verify_key',
				'description' => '',
				'default'     => 'Save and verify',
			);
		} else {
			$this->private_key_test = $this->private_key_test = ! empty( $this->settings['private_key_test'] ) ? $this->settings['private_key_test'] : $_POST['woocommerce_reepay_checkout_private_key_test'];
			$this->test_mode        = 'yes';
			$account_info           = $this->get_account_info( $this, true );

			if ( ! empty( $account_info ) && ! is_wp_error( $account_info ) ) {
				$this->form_fields['account_test']               = array(
					'title'       => __( 'Account', 'reepay-checkout-gateway' ),
					'type'        => 'account_info',
					'description' => '',
					'default'     => $account_info['name'],
				);
				$this->form_fields['state_test']                 = array(
					'title'       => __( 'State', 'reepay-checkout-gateway' ),
					'type'        => 'account_info',
					'description' => '',
					'default'     => $account_info['state'],
				);
				$this->form_fields['is_webhook_configured_test'] = array(
					'title'   => __( 'Webhook', 'reepay-checkout-gateway' ),
					'type'    => 'webhook_status',
					'label'   => __( 'Webhook', 'reepay-checkout-gateway' ),
					'default' => $this->is_webhook_configured(),
				);
			}

			$this->test_mode = ! empty( $_POST['woocommerce_reepay_checkout_test_mode'] ) ? $_POST['woocommerce_reepay_checkout_test_mode'] : '';
			$this->test_mode = ! empty( $this->settings['test_mode'] ) ? $this->settings['test_mode'] : $this->test_mode;
		}

		$this->form_fields['hr9'] = array(
			'type' => 'separator',
			'id'   => 'hr9',
		);

		$this->form_fields['test_mode'] = array(
			'title'   => __( 'Test Mode', 'reepay-checkout-gateway' ),
			'type'    => 'checkbox',
			'label'   => __( 'Enable Test Mode', 'reepay-checkout-gateway' ),
			'default' => $this->test_mode,
		);

		$this->form_fields['hr3'] = array(
			'type' => 'separator',
			'id'   => 'hr3',
		);

		$this->form_fields['failed_webhooks_email'] = array(
			'title'             => __( 'Email address for notification about failed webhooks', 'reepay-checkout-gateway' ),
			'type'              => 'text',
			'description'       => __( 'Email address for notification about failed webhooks', 'reepay-checkout-gateway' ),
			'default'           => '',
			'sanitize_callback' => function ( $value ) {
				if ( ! empty( $value ) ) {
					if ( ! is_email( $value ) ) {
						throw new Exception( __( 'Email address is invalid.', 'reepay-checkout-gateway' ) );
					}
				}

				return $value;
			},
		);

		$this->form_fields['payment_type'] = array(
			'title'       => __( 'Payment Window Display', 'reepay-checkout-gateway' ),
			'description' => __( 'Choose between a redirect window or a overlay window', 'reepay-checkout-gateway' ),
			'type'        => 'select',
			'options'     => array(
				self::METHOD_WINDOW  => 'Window',
				self::METHOD_OVERLAY => 'Overlay',
			),
			'default'     => $this->payment_type,
		);

		$this->form_fields['payment_methods'] = array(
			'title'       => __( 'Payment Methods', 'reepay-checkout-gateway' ),
			'description' => __( 'Payment Methods', 'reepay-checkout-gateway' ),
			'type'        => 'multiselect',
			'css'         => 'height: 250px',
			'options'     => array(
				'card'             => 'All available debit / credit cards',
				'dankort'          => 'Dankort',
				'visa'             => 'VISA',
				'visa_dk'          => 'VISA/Dankort',
				'visa_elec'        => 'VISA Electron',
				'mc'               => 'MasterCard',
				'amex'             => 'American Express',
				'mobilepay'        => 'MobilePay',
				'viabill'          => 'ViaBill',
				'klarna_pay_later' => 'Klarna Pay Later',
				'klarna_pay_now'   => 'Klarna Pay Now',
				'klarna_slice_it'  => 'Klarna Slice It',
				'resurs'           => 'Resurs Bank',
				'swish'            => 'Swish',
				'diners'           => 'Diners Club',
				'maestro'          => 'Maestro',
				'laser'            => 'Laser',
				'discover'         => 'Discover',
				'jcb'              => 'JCB',
				'china_union_pay'  => 'China Union Pay',
				'ffk'              => 'Forbrugsforeningen',
				'paypal'           => 'PayPal',
				'applepay'         => 'Apple Pay',
				'googlepay'        => 'Google Pay',
				'vipps'            => 'Vipps',
			),
			'default'     => $this->payment_methods,
		);

		$this->form_fields['settle'] = array(
			'title'          => __( 'Instant Settle', 'reepay-checkout-gateway' ),
			'description'    => __( 'Instant Settle will charge your customers right away', 'reepay-checkout-gateway' ),
			'type'           => 'multiselect',
			'css'            => 'height: 150px',
			'options'        => array(
				WC_Reepay_Instant_Settle::SETTLE_VIRTUAL   => __( 'Instant Settle online / virtualproducts', 'reepay-checkout-gateway' ),
				WC_Reepay_Instant_Settle::SETTLE_PHYSICAL  => __( 'Instant Settle physical  products', 'reepay-checkout-gateway' ),
				WC_Reepay_Instant_Settle::SETTLE_RECURRING => __( 'Instant Settle recurring (subscription) products', 'reepay-checkout-gateway' ),
				WC_Reepay_Instant_Settle::SETTLE_FEE       => __( 'Instant Settle fees', 'reepay-checkout-gateway' ),
			),
			'select_buttons' => true,
			'default'        => array(),
		);

		$this->form_fields['language'] = array(
			'title'   => __( 'Language In Payment Window', 'reepay-checkout-gateway' ),
			'type'    => 'select',
			'options' => array(
				''      => __( 'Detect Automatically', 'reepay-checkout-gateway' ),
				'en_US' => __( 'English', 'reepay-checkout-gateway' ),
				'da_DK' => __( 'Danish', 'reepay-checkout-gateway' ),
				'sv_SE' => __( 'Swedish', 'reepay-checkout-gateway' ),
				'no_NO' => __( 'Norwegian', 'reepay-checkout-gateway' ),
				'de_DE' => __( 'German', 'reepay-checkout-gateway' ),
				'es_ES' => __( 'Spanish', 'reepay-checkout-gateway' ),
				'fr_FR' => __( 'French', 'reepay-checkout-gateway' ),
				'it_IT' => __( 'Italian', 'reepay-checkout-gateway' ),
				'nl_NL' => __( 'Netherlands', 'reepay-checkout-gateway' ),
			),
			'default' => $this->language,
		);

		$this->form_fields['save_cc'] = array(
			'title'   => __( 'Allow Credit Card saving', 'reepay-checkout-gateway' ),
			'type'    => 'checkbox',
			'label'   => __( 'Enable Save CC feature', 'reepay-checkout-gateway' ),
			'default' => 'no',
		);

		$this->form_fields['hr5'] = array(
			'type' => 'separator',
			'id'   => 'hr5',
		);

		$this->form_fields['debug'] = array(
			'title'   => __( 'Debug', 'reepay-checkout-gateway' ),
			'type'    => 'checkbox',
			'label'   => __( 'Enable logging', 'reepay-checkout-gateway' ),
			'default' => $this->debug,
		);

		$this->form_fields['hr7'] = array(
			'type' => 'separator',
			'id'   => 'hr7',
		);

		$this->form_fields['logos'] = array(
			'title'          => __( 'Payment Logos', 'reepay-checkout-gateway' ),
			'description'    => __( 'Choose the logos you would like to show in WooCommerce checkout. Make sure that they are enabled in Reepay Dashboard', 'reepay-checkout-gateway' ),
			'type'           => 'multiselect',
			'css'            => 'height: 250px',
			'options'        => array(
				'dankort'            => __( 'Dankort', 'reepay-checkout-gateway' ),
				'visa'               => __( 'Visa', 'reepay-checkout-gateway' ),
				'mastercard'         => __( 'MasterCard', 'reepay-checkout-gateway' ),
				'visa-electron'      => __( 'Visa Electron', 'reepay-checkout-gateway' ),
				'maestro'            => __( 'Maestro', 'reepay-checkout-gateway' ),
				'paypal'             => __( 'Paypal', 'reepay-checkout-gateway' ),
				'mobilepay'          => __( 'MobilePay Online', 'reepay-checkout-gateway' ),
				'applepay'           => __( 'ApplePay', 'reepay-checkout-gateway' ),
				'klarna'             => __( 'Klarna', 'reepay-checkout-gateway' ),
				'viabill'            => __( 'Viabill', 'reepay-checkout-gateway' ),
				'resurs'             => __( 'Resurs Bank', 'reepay-checkout-gateway' ),
				'forbrugsforeningen' => __( 'Forbrugsforeningen', 'reepay-checkout-gateway' ),
				'amex'               => __( 'AMEX', 'reepay-checkout-gateway' ),
				'jcb'                => __( 'JCB', 'reepay-checkout-gateway' ),
				'diners'             => __( 'Diners Club', 'reepay-checkout-gateway' ),
				'unionpay'           => __( 'Unionpay', 'reepay-checkout-gateway' ),
				'discover'           => __( 'Discover', 'reepay-checkout-gateway' ),
				'googlepay'          => __( 'Google pay', 'reepay-checkout-gateway' ),
				'vipps'              => __( 'Vipps', 'reepay-checkout-gateway' ),
			),
			'select_buttons' => true,
		);

		$this->form_fields['logo_height'] = array(
			'title'       => __( 'Logo Height', 'reepay-checkout-gateway' ),
			'type'        => 'text',
			'description' => __( 'Set the Logo height in pixels', 'reepay-checkout-gateway' ),
			'default'     => '',
		);

		$this->form_fields['order_hr'] = array(
			'type' => 'separator',
			'id'   => 'order_hr',
		);

		$this->form_fields['handle_failover'] = array(
			'title'       => __( 'Order handle failover', 'reepay-checkout-gateway' ),
			'type'        => 'checkbox',
			'label'       => __( 'Order handle failover', 'reepay-checkout-gateway' ),
			'description' => __( 'In case if invoice with current handle was settled before, plugin will generate unique handle', 'reepay-checkout-gateway' ),
			'default'     => 'yes',
		);

		$this->form_fields['skip_order_lines'] = array(
			'title'       => __( 'Skip order lines', 'reepay-checkout-gateway' ),
			'description' => __( 'Select if order lines should not be send to Reepay', 'reepay-checkout-gateway' ),
			'type'        => 'select',
			'options'     => array(
				'no'  => 'Include order lines',
				'yes' => 'Skip order lines',
			),
			'default'     => $this->skip_order_lines,
		);

		$this->form_fields['enable_order_autocancel'] = array(
			'title'       => __( 'The automatic order auto-cancel', 'reepay-checkout-gateway' ),
			'description' => __( 'The automatic order auto-cancel', 'reepay-checkout-gateway' ),
			'type'        => 'select',
			'options'     => array(
				'yes' => 'Enable auto-cancel',
				'no'  => 'Ignore / disable auto-cancel',
			),
			'default'     => $this->enable_order_autocancel,
		);

		$this->form_fields['payment_button_text'] = array(
			'title'       => __( 'Payment button text', 'reepay-checkout-gateway' ),
			'type'        => 'text',
			'description' => __( 'Text on button which will be displayed on payment page if subscription products is being purchased', 'reepay-checkout-gateway' ),
			'default'     => __( 'PAY AND SAVE CARD', 'reepay-checkout-gateway' ),
		);

	}

	/**
	 * Generate separator HTML
	 *
	 * @see WC_Settings_API::generate_settings_html
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 *
	 * @return string
	 */
	public function generate_separator_html( $key, $data ) {
		return '<tr valign="top" style="border-top: 1px solid #c3c4c7"></tr>';
	}

	/**
	 * Generate WebHook Status HTML.
	 *
	 * @see WC_Settings_API::generate_settings_html
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 *
	 * @return string
	 */
	public function generate_account_info_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'type'        => 'account_info',
			'desc_tip'    => false,
			'description' => '',
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?>
									   <?php
										echo $this->get_tooltip_html( $data ); // WPCS: XSS ok.
										?>
					</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>

					<?php if ( ! empty( $data['default'] ) ) : ?>
						<span>
							<?php echo $data['default']; ?>
						</span>
					<?php endif; ?>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate WebHook Status HTML.
	 *
	 * @see WC_Settings_API::generate_settings_html
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 *
	 * @return string
	 */
	public function generate_verify_key_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'type'        => 'verify_key',
			'desc_tip'    => false,
			'description' => '',
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?>
									   <?php
										echo $this->get_tooltip_html( $data ); // WPCS: XSS ok.
										?>
					</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>

					<button name="save" class="button-primary woocommerce-save-button" type="submit"
							value="Save changes">Save and verify
					</button>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Generate WebHook Status HTML.
	 *
	 * @see WC_Settings_API::generate_settings_html
	 *
	 * @param string $key Field key.
	 * @param array  $data Field data.
	 *
	 * @return string
	 */
	public function generate_webhook_status_html( $key, $data ) {
		$field_key = $this->get_field_key( $key );
		$defaults  = array(
			'title'       => '',
			'type'        => 'webhook_status',
			'desc_tip'    => false,
			'description' => '',
		);

		$data = wp_parse_args( $data, $defaults );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc">
				<label for="<?php echo esc_attr( $field_key ); ?>"><?php echo wp_kses_post( $data['title'] ); ?>
									   <?php
										echo $this->get_tooltip_html( $data ); // WPCS: XSS ok.
										?>
					</label>
			</th>
			<td class="forminp">
				<fieldset>
					<legend class="screen-reader-text"><span><?php echo wp_kses_post( $data['title'] ); ?></span>
					</legend>

					<?php if ( $data['default'] ) : ?>
						<span style="color: green;">
							<?php esc_html_e( 'Active', 'reepay-checkout-gateway' ); ?>
						</span>
					<?php else : ?>
						<span style="color: red;">
							<?php esc_html_e( 'Configuration is required.', 'reepay-checkout-gateway' ); ?>
						</span>
						<p>
							<?php esc_html_e( 'Please check api credentials and save the settings. Webhook will be installed automatically.', 'reepay-checkout-gateway' ); ?>
						</p>
					<?php endif; ?>

					<input type="hidden" name="<?php echo esc_attr( $field_key ); ?>"
						   id="<?php echo esc_attr( $field_key ); ?>"
						   value="
						   <?php
							echo esc_attr( $this->get_option( $key ) ); // WPCS: XSS ok.
							?>
						   "/>
				</fieldset>
			</td>
		</tr>
		<?php

		return ob_get_clean();
	}

	/**
	 * Output the gateway settings screen
	 *
	 * @return void
	 */
	public function admin_options() {
		$this->display_errors();

		// Check that WebHook was installed
		$token = $this->test_mode ? md5( $this->private_key_test ) : md5( $this->private_key );

		reepay()->get_template(
			'admin/admin-options.php',
			array(
				'gateway'           => $this,
				'webhook_installed' => get_option( 'woocommerce_reepay_webhook_' . $token ) === 'installed',
			)
		);
	}

	/**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the erroring field out.
	 *
	 * @return bool was anything saved?
	 */
	public function process_admin_options() {
		parent::process_admin_options();

		$current_key = $this->settings['private_key'] ?? '';
		if ( $current_key != $_POST['woocommerce_reepay_checkout_private_key'] ) {
			WC_Reepay_Gateway_Statistics::private_key_activated();
		}

		$this->init_settings();
		$this->private_key      = $this->settings['private_key'] ?? $this->private_key;
		$this->private_key_test = $this->settings['private_key_test'] ?? $this->private_key_test;
		$this->test_mode        = $this->settings['test_mode'] ?? $this->test_mode;

		parent::is_webhook_configured();

		return true;
	}

	/**
	 * If There are no payment fields show the description if set.
	 *
	 * @return void
	 */
	public function payment_fields() {
		reepay()->get_template(
			'checkout/payment-fields.php',
			array(
				'gateway' => $this,
			)
		);

		// The "Save card or use existed" form should be appeared when active or when the cart has a subscription
		if ( ( $this->save_cc === 'yes' && ! is_add_payment_method_page() ) ||
			 ( wcs_cart_have_subscription() || wcs_is_payment_change() )
		) {
			$this->tokenization_script();
			if ( $this->save_cc === 'yes' ) {
				$this->saved_payment_methods();
			}

			$this->save_payment_method_checkbox();
		}
	}

	/**
	 * Add Payment Method.
	 */
	public function add_payment_method() {
		$user            = get_userdata( get_current_user_id() );
		$customer_handle = rp_get_customer_handle( $user->ID );

		$accept_url = add_query_arg( 'action', 'reepay_card_store', admin_url( 'admin-ajax.php' ) );
		$accept_url = apply_filters( 'woocommerce_reepay_payment_accept_url', $accept_url );
		$cancel_url = wc_get_account_endpoint_url( 'payment-methods' );
		$cancel_url = apply_filters( 'woocommerce_reepay_payment_cancel_url', $cancel_url );

		$location = wc_get_base_location();

		if ( empty( $customer_handle ) ) {
			// Create reepay customer
			$params = array(
				'locale'          => $this->get_language(),
				'button_text'     => __( 'Add card', 'reepay-checkout-gateway' ),
				'create_customer' => array(
					'test'        => $this->test_mode === 'yes',
					'handle'      => $customer_handle,
					'email'       => $user->user_email,
					'address'     => '',
					'address2'    => '',
					'city'        => '',
					'country'     => $location['country'],
					'phone'       => '',
					'company'     => '',
					'vat'         => '',
					'first_name'  => $user->first_name,
					'last_name'   => $user->last_name,
					'postal_code' => '',
				),
				'accept_url'      => $accept_url,
				'cancel_url'      => $cancel_url,
			);
		} else {
			// Use customer who exists
			$params = array(
				'locale'      => $this->get_language(),
				'button_text' => __( 'Add card', 'reepay-checkout-gateway' ),
				'customer'    => $customer_handle,
				'accept_url'  => $accept_url,
				'cancel_url'  => $cancel_url,
			);
		}

		if ( $this->payment_methods && count( $this->payment_methods ) > 0 ) {
			$params['payment_methods'] = $this->payment_methods;
		}

		$result = reepay()->api( $this )->request( 'POST', 'https://checkout-api.reepay.com/v1/session/recurring', $params );
		if ( is_wp_error( $result ) ) {
			/** @var WP_Error $result */

			if ( $result->get_error_code() == 71 || $result->get_error_code() == 9 ) {
				$params = array(
					'locale'          => $this->get_language(),
					'button_text'     => __( 'Add card', 'reepay-checkout-gateway' ),
					'create_customer' => array(
						'test'        => $this->test_mode === 'yes',
						'handle'      => $customer_handle,
						'email'       => $user->user_email,
						'address'     => '',
						'address2'    => '',
						'city'        => '',
						'country'     => $location['country'],
						'phone'       => '',
						'company'     => '',
						'vat'         => '',
						'first_name'  => $user->first_name,
						'last_name'   => $user->last_name,
						'postal_code' => '',
					),
					'accept_url'      => $accept_url,
					'cancel_url'      => $cancel_url,
				);

				$result = reepay()->api( $this )->request( 'POST', 'https://checkout-api.reepay.com/v1/session/recurring', $params );

				if ( is_wp_error( $result ) ) {
					/** @var WP_Error $result */
					wc_add_notice( $result->get_error_message(), 'error' );
					parent::add_payment_method();
				}
			} else {
				/** @var WP_Error $result */
				wc_add_notice( $result->get_error_message(), 'error' );
				parent::add_payment_method();
			}
		}

		$this->log( sprintf( '%s Result %s', __METHOD__, var_export( $result, true ) ) );

		wp_redirect( $result['url'] );
		exit();
	}

	/**
	 * @param $order_id
	 *
	 * @return void
	 */
	public function thankyou_page( $order_id ) {
		try {
			$this->add_subscription_card_id( $order_id );
		} catch ( Exception $e ) {
			$this->log( sprintf( 'add_subscription_card_id error: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Clone Card ID when Subscription created
	 *
	 * @param $order_id
	 *
	 * @throws Exception
	 */
	public function add_subscription_card_id( $order_id ) {
		if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
			return;
		}

		// Get subscriptions
		$subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );
		foreach ( $subscriptions as $subscription ) {
			$token = self::get_payment_token_order( $subscription );
			if ( ! $token ) {
				// Copy tokens from parent order
				$order = wc_get_order( $order_id );
				$token = self::get_payment_token_order( $order );

				if ( $token ) {
					self::assign_payment_token( $subscription, $token );
				}
			}
		}
	}

	/**
	 * Ajax: Add Payment Method
	 *
	 * @return void
	 */
	public function reepay_card_store() {
		$id              = wc_clean( $_GET['id'] );
		$customer_handle = wc_clean( $_GET['customer'] );
		$reepay_token    = wc_clean( $_GET['payment_method'] );

		try {
			// Create Payment Token
			$source = reepay()->api( $this )->get_reepay_cards( $customer_handle, $reepay_token );

			if ( 'ms_' == substr( $source['id'], 0, 3 ) ) {
				$token = new TokenReepayMS();
				$token->set_gateway_id( $this->id );
				$token->set_token( $reepay_token );
				$token->set_user_id( get_current_user_id() );
			} else {
				$expiryDate = explode( '-', $source['exp_date'] );

				$token = new TokenReepay();
				$token->set_gateway_id( $this->id );
				$token->set_token( $reepay_token );
				$token->set_last4( substr( $source['masked_card'], - 4 ) );
				$token->set_expiry_year( 2000 + $expiryDate[1] );
				$token->set_expiry_month( $expiryDate[0] );
				$token->set_card_type( $source['card_type'] );
				$token->set_user_id( get_current_user_id() );
				$token->set_masked_card( $source['masked_card'] );

				update_post_meta( $id, 'reepay_masked_card', $source['masked_card'] );
				update_post_meta( $id, 'reepay_card_type', $source['card_type'] );
			}

			// Save Credit Card
			$token->save();
			if ( ! $token->get_id() ) {
				throw new Exception( __( 'There was a problem adding the card.', 'reepay-checkout-gateway' ) );
			}

			do_action( 'woocommerce_reepay_payment_method_added', $token );

			wc_add_notice( __( 'Payment method successfully added.', 'reepay-checkout-gateway' ) );
			wp_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
			exit();
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( wc_get_account_endpoint_url( 'add-payment-method' ) );
			exit();
		}
	}

	/**
	 * Ajax: Finalize Payment
	 *
	 * @throws Exception
	 */
	public function reepay_finalize() {
		$reepay_token = wc_clean( $_GET['payment_method'] );

		try {
			if ( empty( $_GET['key'] ) ) {
				throw new Exception( 'Order key is undefined' );
			}

			if ( ! $order_id = wc_get_order_id_by_order_key( $_GET['key'] ) ) {
				throw new Exception( 'Can not get order' );
			}

			if ( ! $order = wc_get_order( $order_id ) ) {
				throw new Exception( 'Can not get order' );
			}

			if ( $order->get_payment_method() !== $this->id ) {
				throw new Exception( 'Unable to use this order' );
			}

			$this->log( sprintf( '%s Incoming data %s', __METHOD__, var_export( $_GET, true ) ) );

			// Save Token
			$token = $this->reepay_save_token( $order, $reepay_token );

			// Add note
			$order->add_order_note( sprintf( __( 'Payment method changed to "%s"', 'reepay-checkout-gateway' ), $token->get_display_name() ) );

			// Complete payment if zero amount
			if ( abs( $order->get_total() ) < 0.01 ) {
				$order->payment_complete();
			}

			if ( ! empty( $_GET['invoice'] ) ) {
				$handle = wc_clean( $_GET['invoice'] );
				if ( $handle !== rp_get_order_handle( $order ) ) {
					throw new Exception( 'Invoice ID doesn\'t match the order.' );
				}

				$result = reepay()->api( $this )->get_invoice_by_handle( wc_clean( $_GET['invoice'] ) );
				if ( is_wp_error( $result ) ) {
					/** @var WP_Error $result */
					throw new Exception( $result->get_error_message() );
				}

				$result = reepay()->api( $this )->get_invoice_by_handle( wc_clean( $_GET['invoice'] ) );
				if ( is_wp_error( $result ) ) {
					/** @var WP_Error $result */
					throw new Exception( $result->get_error_message() );
				}

				switch ( $result['state'] ) {
					case 'authorized':
						WC_Reepay_Order_Statuses::set_authorized_status(
							$order,
							sprintf(
								__( 'Payment has been authorized. Amount: %s.', 'reepay-checkout-gateway' ),
								wc_price( rp_make_initial_amount( $result['amount'], $order->get_currency() ) )
							),
							null
						);

						// Settle an authorized payment instantly if possible
						do_action( 'reepay_instant_settle', $order );
						break;
					case 'settled':
						WC_Reepay_Order_Statuses::set_settled_status(
							$order,
							sprintf(
								__( 'Payment has been settled. Amount: %s.', 'reepay-checkout-gateway' ),
								wc_price( rp_make_initial_amount( $result['amount'], $order->get_currency() ) )
							),
							null
						);
						break;
					default:
				}
			}

			wp_redirect( $this->get_return_url( $order ) );
		} catch ( Exception $e ) {
			wc_add_notice( $e->getMessage(), 'error' );
			wp_redirect( $this->get_return_url() );
		}

		exit();
	}

}
