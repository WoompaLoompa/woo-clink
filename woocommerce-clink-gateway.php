<?php
/**
 * Plugin Name: WooCommerce CLINK Gateway
 * Plugin URI: https://github.com/shocknet/woo-clink
 * Description: Accept Lightning Network payments via the CLINK protocol (clinkme.dev). Customers pay with ShockWallet, ZEUS, Amethyst, or any CLINK-compatible wallet.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: ShockNet
 * Author URI: https://github.com/shocknet
 * License: MIT
 * Text Domain: woocommerce-clink-gateway
 * Domain Path: /languages
 *
 * @package WooCommerce_CLINK_Gateway
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_CLINK_VERSION', '1.0.0' );
define( 'WC_CLINK_PLUGIN_FILE', __FILE__ );
define( 'WC_CLINK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_CLINK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'wc_clink_init', 0 );

/**
 * Initialize the plugin after WooCommerce is available.
 */
function wc_clink_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="error"><p>' . wp_kses_post( __( '<strong>WooCommerce CLINK Gateway</strong> requires WooCommerce to be installed and activated.', 'woocommerce-clink-gateway' ) ) . '</p></div>';
			}
		);
		return;
	}

	require_once WC_CLINK_PLUGIN_DIR . 'includes/class-wc-gateway-clink.php';

	add_filter(
		'woocommerce_payment_gateways',
		function ( $gateways ) {
			$gateways[] = 'WC_Gateway_CLINK';
			return $gateways;
		}
	);

	add_action( 'wp_enqueue_scripts', 'wc_clink_checkout_scripts' );
	add_action( 'wp_ajax_wc_clink_check_payment', 'wc_clink_ajax_check_payment' );
	add_action( 'wp_ajax_nopriv_wc_clink_check_payment', 'wc_clink_ajax_check_payment' );
	add_action( 'wp_ajax_wc_clink_confirm_payment', 'wc_clink_ajax_confirm_payment' );
	add_action( 'wp_ajax_nopriv_wc_clink_confirm_payment', 'wc_clink_ajax_confirm_payment' );
	add_action( 'wp_ajax_wc_clink_mark_paid', 'wc_clink_ajax_mark_paid' );
	add_action( 'wp_ajax_nopriv_wc_clink_mark_paid', 'wc_clink_ajax_mark_paid' );
	add_action( 'woocommerce_thankyou_clink', 'wc_clink_thankyou_page', 10, 1 );

	if ( class_exists( 'WC_Subscriptions_Order' ) ) {
		require_once WC_CLINK_PLUGIN_DIR . 'includes/class-wc-clink-subscriptions.php';
		WC_CLINK_Subscriptions::init();
	}

	if ( class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
		require_once WC_CLINK_PLUGIN_DIR . 'includes/class-wc-clink-blocks-support.php';
		add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( $registry ) {
				$registry->register( new WC_CLINK_Blocks_Support() );
			}
		);
	}
}

/**
 * Enqueue checkout scripts and styles.
 */
function wc_clink_checkout_scripts() {
	if ( ! is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
		return;
	}

	$gateway = WC_Gateway_CLINK::get_instance();

	if ( ! $gateway || ! $gateway->is_available() ) {
		return;
	}

	$min       = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';
	$asset_file = WC_CLINK_PLUGIN_DIR . "assets/js/clink-checkout{$min}.asset.php";

	$deps    = array( 'jquery', 'wp-element', 'wp-i18n' );
	$version = WC_CLINK_VERSION;

	if ( file_exists( $asset_file ) ) {
		$asset   = require $asset_file;
		$deps    = array_merge( $deps, $asset['dependencies'] ?? array() );
		$version = $asset['version'] ?? $version;
	}

	wp_enqueue_script(
		'wc-clink-checkout',
		WC_CLINK_PLUGIN_URL . "assets/js/clink-checkout{$min}.js",
		$deps,
		$version,
		true
	);

	wp_enqueue_style(
		'wc-clink-checkout',
		WC_CLINK_PLUGIN_URL . 'assets/css/clink-checkout.css',
		array(),
		WC_CLINK_VERSION
	);

	$order_id         = absint( get_query_var( 'order-received' ) );
	$order_amount_sats = 0;
	$noffer           = '';
	$description      = '';

	if ( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order && 'clink' === $order->get_payment_method() ) {
			$order_amount_sats = (int) $order->get_meta( '_clink_amount_sats' );
			$noffer           = $order->get_meta( '_clink_noffer' );
			$description      = sprintf(
			/* translators: 1: order number, 2: site name */
				__( 'Order %1$s - %2$s', 'woocommerce-clink-gateway' ),
				$order->get_order_number(),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			);
		}
	}

	wp_localize_script(
		'wc-clink-checkout',
		'wcClinkData',
		array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'wc_clink_nonce' ),
			'orderId'     => intval( $order_id ),
			'amountSats'  => intval( $order_amount_sats ),
			'description' => esc_js( $description ),
			'noffer'      => esc_js( $noffer ),
			'pollInterval' => $gateway->get_option( 'poll_interval', 5000 ),
			'timeout'     => $gateway->get_option( 'invoice_timeout', 600 ),
			'loader'      => esc_url( admin_url( 'images/spinner.gif' ) ),
			'i18n'        => array(
				'generatingInvoice' => __( 'Generating Lightning Invoice...', 'woocommerce-clink-gateway' ),
				'scanToPay'        => __( 'Scan with your Lightning Wallet to pay', 'woocommerce-clink-gateway' ),
				'copyInvoice'      => __( 'Copy Invoice', 'woocommerce-clink-gateway' ),
				'invoiceCopied'    => __( 'Copied!', 'woocommerce-clink-gateway' ),
				'waitingPayment'   => __( 'Waiting for payment confirmation...', 'woocommerce-clink-gateway' ),
				'paymentConfirmed'  => __( 'Payment confirmed! Redirecting...', 'woocommerce-clink-gateway' ),
				'paymentError'     => __( 'Error generating invoice. Please try again.', 'woocommerce-clink-gateway' ),
				'expired'          => __( 'Invoice expired. Please try again.', 'woocommerce-clink-gateway' ),
				'openInWallet'     => __( 'Open in Wallet', 'woocommerce-clink-gateway' ),
			),
		)
	);
}

/**
 * AJAX: Check if payment has been completed.
 */
function wc_clink_ajax_check_payment() {
	check_ajax_referer( 'wc_clink_nonce', 'nonce' );

	$order_id = absint( $_POST['order_id'] ?? 0 );

	if ( ! $order_id ) {
		wp_send_json_error( array( 'message' => 'Invalid order ID' ) );
	}

	$order = wc_get_order( $order_id );

	if ( ! $order || 'clink' !== $order->get_payment_method() ) {
		wp_send_json_error( array( 'message' => 'Invalid order' ) );
	}

	$status = $order->get_status();
	$paid   = in_array( $status, array( 'processing', 'completed' ), true );

	wp_send_json_success(
		array(
			'paid'   => $paid,
			'status' => $status,
		)
	);
}

/**
 * AJAX: Confirm that an invoice was generated.
 */
function wc_clink_ajax_confirm_payment() {
	check_ajax_referer( 'wc_clink_nonce', 'nonce' );

	$order_id = absint( $_POST['order_id'] ?? 0 );
	$invoice  = sanitize_text_field( $_POST['invoice'] ?? '' );

	if ( ! $order_id || ! $invoice ) {
		wp_send_json_error( array( 'message' => 'Missing parameters' ) );
	}

	$order = wc_get_order( $order_id );

	if ( ! $order || 'clink' !== $order->get_payment_method() ) {
		wp_send_json_error( array( 'message' => 'Invalid order' ) );
	}

	$order->update_meta_data( '_clink_invoice', $invoice );
	$order->add_order_note(
		sprintf(
		/* translators: %s: truncated invoice string */
			__( 'CLINK invoice generated: %s...', 'woocommerce-clink-gateway' ),
			substr( $invoice, 0, 30 )
		)
	);

	$gateway = WC_Gateway_CLINK::get_instance();

	if ( $gateway ) {
		$order->update_status( 'on-hold', __( 'Invoice generated, awaiting payment.', 'woocommerce-clink-gateway' ) );
	}

	wp_send_json_success();
}

/**
 * AJAX: Mark order as paid (called after CLINK receipt).
 */
function wc_clink_ajax_mark_paid() {
	check_ajax_referer( 'wc_clink_nonce', 'nonce' );

	$order_id = absint( $_POST['order_id'] ?? 0 );

	if ( ! $order_id ) {
		wp_send_json_error( array( 'message' => 'Invalid order ID' ) );
	}

	$order = wc_get_order( $order_id );

	if ( ! $order || 'clink' !== $order->get_payment_method() ) {
		wp_send_json_error( array( 'message' => 'Invalid order' ) );
	}

	$gateway = WC_Gateway_CLINK::get_instance();

	if ( $gateway ) {
		$gateway->payment_complete( $order, 'clink_receipt' );
	}

	wp_send_json_success( array( 'redirect' => $order->get_checkout_order_received_url() ) );
}

/**
 * Render the thank-you / payment page.
 *
 * @param int $order_id The order ID.
 */
function wc_clink_thankyou_page( $order_id ) {
	$order = wc_get_order( $order_id );

	if ( ! $order ) {
		return;
	}

	$paid = in_array( $order->get_status(), array( 'processing', 'completed' ), true );

	if ( $paid ) {
		echo '<div class="wc-clink-paid">' . esc_html__( 'Payment confirmed. Thank you for your order!', 'woocommerce-clink-gateway' ) . '</div>';
		return;
	}

	$noffer     = $order->get_meta( '_clink_noffer' );
	$amount_sats = (int) $order->get_meta( '_clink_amount_sats' );

	if ( ! $noffer || ! $amount_sats ) {
		echo '<div class="wc-clink-error">' . esc_html__( 'Missing payment configuration. Please contact the store owner.', 'woocommerce-clink-gateway' ) . '</div>';
		return;
	}

	echo '<div id="wc-clink-payment-root"></div>';
}
