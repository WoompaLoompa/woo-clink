<?php
/**
 * WooCommerce CLINK Payment Gateway
 *
 * @package WooCommerce_CLINK_Gateway
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_Gateway_CLINK
 *
 * Core payment gateway that processes Lightning payments
 * via the CLINK protocol (clinkme.dev).
 */
class WC_Gateway_CLINK extends WC_Payment_Gateway {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * CoinGecko API URL for BTC price.
	 */
	private const CLINK_API_URL = 'https://api.coingecko.com/api/v3/simple/price?ids=bitcoin&vs_currencies=';

	/**
	 * Satoshis per Bitcoin.
	 */
	private const SATS_PER_BTC = 100_000_000;

	/**
	 * Constructor.
	 */
	public function __construct() {
		self::$instance = $this;

		$this->id                 = 'clink';
		$this->icon               = apply_filters( 'woocommerce_clink_icon', WC_CLINK_PLUGIN_URL . 'assets/images/lightning-icon.svg' );
		$this->has_fields         = false;
		$this->method_title       = __( 'CLINK (Lightning)', 'woocommerce-clink-gateway' );
		$this->method_description = __( 'Accept Bitcoin Lightning payments via the CLINK protocol (clinkme.dev). Customers pay with <a href="https://ShockWallet.app">ShockWallet.app</a>, ZEUS, Amethyst, or any other CLINK-compatible wallet. All transmitted privately and anonymously via relays of the Nostr protocol.' );
		$this->supports           = array(
			'products',
			'refunds',
		);

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title', __( 'Lightning (CLINK)', 'woocommerce-clink-gateway' ) );
		$this->description = $this->get_option( 'description', __( 'Pay with your Lightning wallet via the CLINK protocol.', 'woocommerce-clink-gateway' ) );
		$this->enabled     = $this->get_option( 'enabled', 'no' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		if ( $this->is_subscriptions_active() ) {
			$this->supports = array_merge(
				$this->supports,
				array(
					'subscriptions',
					'subscription_cancellation',
					'subscription_suspension',
					'subscription_reactivation',
					'multiple_subscriptions',
					'subscription_date_changes',
				)
			);
		}
	}

	/**
	 * Get the singleton instance.
	 *
	 * @return self|null
	 */
	public static function get_instance() {
		return self::$instance;
	}

	/**
	 * Initialize gateway settings form fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'         => array(
				'title'   => __( 'Enable/Disable', 'woocommerce-clink-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable CLINK Lightning Payments', 'woocommerce-clink-gateway' ),
				'default' => 'no',
			),
			'title'           => array(
				'title'       => __( 'Title', 'woocommerce-clink-gateway' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown at checkout.', 'woocommerce-clink-gateway' ),
				'default'     => __( 'Lightning (CLINK)', 'woocommerce-clink-gateway' ),
				'desc_tip'    => true,
			),
			'description'     => array(
				'title'       => __( 'Description', 'woocommerce-clink-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown at checkout.', 'woocommerce-clink-gateway' ),
				'default'     => __( 'Pay with your Lightning wallet via the CLINK protocol. Download <a href="https://shockwallet.app">SHOCKWALLET.app</a> or any other CLINK compatible bitcoin wallet (ZEUS, Amethyst, etc.).', 'woocommerce-clink-gateway' ),
			),
			'noffer'          => array(
				'title'       => __( 'CLINK Offer String (noffer)', 'woocommerce-clink-gateway' ),
				'type'        => 'text',
				'description' => __( 'Your CLINK offer string (starts with noffer1...). Generate this from ShockWallet, Lightning.Pub, or any CLINK-compatible wallet.', 'woocommerce-clink-gateway' ),
				'default'     => '',
				'placeholder' => 'noffer1...',
				'desc_tip'    => false,
			),
			'fixed_fiat_rate'  => array(
				'title'       => __( 'Fixed BTC Rate (optional)', 'woocommerce-clink-gateway' ),
				'type'        => 'text',
				'description' => __( 'Optionally set a fixed BTC price in your WooCommerce store currency (e.g., 75000.00). Leave empty to use live CoinGecko rate.', 'woocommerce-clink-gateway' ),
				'default'     => '',
				'placeholder' => __( 'Live rate', 'woocommerce-clink-gateway' ),
			),
			'invoice_timeout'  => array(
				'title'             => __( 'Invoice Timeout (seconds)', 'woocommerce-clink-gateway' ),
				'type'              => 'number',
				'description'       => __( 'How long the customer has to pay the invoice before it expires.', 'woocommerce-clink-gateway' ),
				'default'           => 600,
				'custom_attributes' => array( 'min' => 60, 'max' => 3600 ),
			),
			'poll_interval'    => array(
				'title'             => __( 'Poll Interval (ms)', 'woocommerce-clink-gateway' ),
				'type'              => 'number',
				'description'       => __( 'How often to check if the payment has been confirmed (in milliseconds).', 'woocommerce-clink-gateway' ),
				'default'           => 5000,
				'custom_attributes' => array( 'min' => 2000, 'max' => 30000 ),
			),
		);
	}

	/**
	 * Process admin options and validate noffer.
	 *
	 * @return bool
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		$noffer = $this->get_option( 'noffer' );

		if ( ! empty( $noffer ) && ! $this->validate_noffer( $noffer ) ) {
			$this->add_settings_error(
				'noffer',
				'invalid-noffer',
				__( 'The CLINK offer string appears invalid. It should start with "noffer1".', 'woocommerce-clink-gateway' ),
				'error'
			);
		}

		return $saved;
	}

	/**
	 * Validate a CLINK offer string format.
	 *
	 * @param  string $noffer The offer string.
	 * @return bool
	 */
	private function validate_noffer( string $noffer ): bool {
		return 0 === strpos( $noffer, 'noffer1' ) && strlen( $noffer ) > 60;
	}

	/**
	 * Add target="_blank" and rel="noopener noreferrer" to external links in HTML.
	 *
	 * @param  string $html The HTML content.
	 * @return string
	 */
	public static function external_linkify( string $html ): string {
		return preg_replace_callback(
			'/<a\s([^>]*?)href=(["\'])((?:https?:)?\/\/[^\s"\']+)\2([^>]*)>/i',
			function ( $m ) {
				$attrs = trim( $m[1] . ' ' . $m[4] );
				if ( false === strpos( $attrs, 'target=' ) ) {
					$attrs .= ' target="_blank"';
				}
				if ( false === strpos( $attrs, 'rel=' ) ) {
					$attrs .= ' rel="noopener noreferrer"';
				}
				return '<a ' . $attrs . ' href=' . $m[2] . $m[3] . $m[2] . '>';
			},
			$html
		);
	}

	/**
	 * Get the gateway icon with loading="lazy" for performance.
	 *
	 * @return string
	 */
	public function get_icon(): string {
		$icon = $this->icon ? '<img src="' . esc_url( $this->icon ) . '" alt="' . esc_attr( $this->get_title() ) . '" loading="lazy" />' : '';
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}

	/**
	 * Process the payment for a given order.
	 *
	 * @param  int $order_id The order ID.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found.', 'woocommerce-clink-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$noffer = $this->get_option( 'noffer' );

		if ( empty( $noffer ) ) {
			wc_add_notice( __( 'Payment gateway not configured. Please contact the store owner.', 'woocommerce-clink-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$total    = (float) $order->get_total();
		$currency = $order->get_currency();
		$amount_sats = $this->convert_to_sats( $total, $currency );

		if ( $amount_sats <= 0 ) {
			wc_add_notice( __( 'Could not calculate Lightning amount. Please try again.', 'woocommerce-clink-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_clink_noffer', $noffer );
		$order->update_meta_data( '_clink_amount_sats', $amount_sats );
		$order->update_meta_data( '_clink_amount_fiat', $total );
		$order->update_meta_data( '_clink_currency', $currency );
		$order->update_meta_data( '_clink_created', time() );
		$order->save();

		$order->update_status( 'pending', __( 'Awaiting CLINK payment.', 'woocommerce-clink-gateway' ) );

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Complete the payment for an order.
	 *
	 * @param WC_Order|int $order  The order object or ID.
	 * @param string       $txn_id Optional transaction ID.
	 */
	public function payment_complete( $order, $txn_id = '' ) {
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}

		if ( ! $order ) {
			return;
		}

		$receipt = ! empty( $txn_id ) ? $txn_id : __( 'CLINK protocol receipt', 'woocommerce-clink-gateway' );
		$order->add_order_note(
			sprintf(
			/* translators: %s: receipt identifier */
				__( 'CLINK payment completed. Receipt: %s', 'woocommerce-clink-gateway' ),
				$receipt
			)
		);

		$order->payment_complete( $txn_id );
		$order->update_meta_data( '_clink_paid', time() );
		$order->save();
	}

	/**
	 * Process a refund request.
	 *
	 * @param  int        $order_id The order ID.
	 * @param  float|null $amount   Refund amount.
	 * @param  string     $reason   Refund reason.
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		$order->add_order_note(
			sprintf(
			/* translators: 1: formatted amount, 2: reason */
				__( 'Refund requested: %1$s (Reason: %2$s). Manual Lightning refund required - CLINK does not support automatic refunds.', 'woocommerce-clink-gateway' ),
				wc_price( $amount, array( 'currency' => $order->get_currency() ) ),
				$reason
			)
		);

		return true;
	}

	/**
	 * Convert a fiat amount to satoshis.
	 *
	 * @param  float  $amount   The fiat amount.
	 * @param  string $currency The currency code.
	 * @return int
	 */
	public function convert_to_sats( float $amount, string $currency ): int {
		if ( 'BTC' === $currency ) {
			return (int) round( $amount * self::SATS_PER_BTC );
		}

		$fixed_rate = $this->get_option( 'fixed_fiat_rate' );

		if ( ! empty( $fixed_rate ) && is_numeric( $fixed_rate ) && (float) $fixed_rate > 0 ) {
			$btc_price = (float) $fixed_rate;
		} else {
			$btc_price = $this->fetch_btc_price( $currency );
		}

		if ( ! $btc_price || $btc_price <= 0 ) {
			return 0;
		}

		$btc_amount = $amount / $btc_price;
		return (int) round( $btc_amount * self::SATS_PER_BTC );
	}

	/**
	 * Fetch the current BTC price from CoinGecko.
	 *
	 * @param  string $currency The currency code.
	 * @return float|null
	 */
	private function fetch_btc_price( string $currency ): ?float {
		$currency  = strtolower( $currency );
		$cache_key = 'wc_clink_btc_price_' . $currency;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (float) $cached;
		}

		$url      = self::CLINK_API_URL . $currency;
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'CoinGecko API error: ' . $response->get_error_message() );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! isset( $data['bitcoin'][ $currency ] ) ) {
			$this->log_error( 'CoinGecko: unexpected response format' );
			return null;
		}

		$price = (float) $data['bitcoin'][ $currency ];
		set_transient( $cache_key, (string) $price, 5 * MINUTE_IN_SECONDS );

		return $price;
	}

	/**
	 * Check if WooCommerce Subscriptions is active.
	 *
	 * @return bool
	 */
	private function is_subscriptions_active(): bool {
		return class_exists( 'WC_Subscriptions' ) && class_exists( 'WC_Subscriptions_Order' );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message The error message.
	 */
	private function log_error( string $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$logger->error( $message, array( 'source' => 'woocommerce-clink-gateway' ) );
		}
	}
}
