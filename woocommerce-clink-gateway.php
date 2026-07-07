<?php
/**
 * Plugin Name: CLINK Gateway for WooCommerce
 * Plugin URI: https://github.com/WoompaLoompa/woo-clink
 * Description: Accept Bitcoin Lightning payments via the CLINK protocol (clinkme.dev). Customers pay with ShockWallet.app, ZEUS, Amethyst, or any other CLINK-compatible wallet. All transmitted privately and anonymously via relays of the Nostr protocol.
 * Version: 1.0.7
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: WooClink
 * Author URI: https://woo-clink.wasmer.app
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: clink-gateway-for-woocommerce
 * Domain Path: /languages
 *
 * @package CLINK_Gateway_for_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_CLINK_VERSION', '1.0.7' );
define( 'WC_CLINK_PLUGIN_FILE', __FILE__ );
define( 'WC_CLINK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_CLINK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

add_action( 'plugins_loaded', 'wc_clink_init', 0 );

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=clink&from=WCADMIN_PAYMENT_SETTINGS' ) ) . '">' . esc_html__( 'Settings', 'clink-gateway-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}
);

/**
 * Initialize the plugin after WooCommerce is available.
 */
function wc_clink_init() {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="error"><p>' . wp_kses_post( __( '<strong>WooCommerce CLINK Gateway</strong> requires WooCommerce to be installed and activated.', 'clink-gateway-for-woocommerce' ) ) . '</p></div>';
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
	add_action( 'wp_ajax_wc_clink_save_ndebit', 'wc_clink_ajax_save_ndebit' );
	add_action( 'wp_ajax_nopriv_wc_clink_save_ndebit', 'wc_clink_ajax_save_ndebit' );
	add_action( 'woocommerce_thankyou_clink', 'wc_clink_thankyou_page', 10, 1 );

	add_filter(
		'woocommerce_gateway_description',
		function ( $description, $gateway_id ) {
			if ( 'clink' === $gateway_id ) {
				$description = WC_Gateway_CLINK::external_linkify( wp_kses_post( $description ) );
			}
			return $description;
		},
		10,
		2
	);

	add_action( 'wp_enqueue_scripts', 'wc_clink_price_converter_scripts', 100 );
	add_filter( 'woocommerce_get_price_html', 'wc_clink_price_html', PHP_INT_MAX, 2 );
	add_filter( 'woocommerce_variable_price_html', 'wc_clink_variable_price_html', PHP_INT_MAX, 2 );
	add_filter( 'woocommerce_cart_item_price', 'wc_clink_cart_item_price', PHP_INT_MAX, 3 );
	add_filter( 'woocommerce_cart_subtotal', 'wc_clink_cart_subtotal', PHP_INT_MAX, 3 );
	add_filter( 'woocommerce_cart_total', 'wc_clink_cart_total', PHP_INT_MAX );
	add_filter( 'woocommerce_currency_symbol', 'wc_clink_currency_symbol', PHP_INT_MAX, 2 );

	if ( wc_clink_is_subscription_plugin_active() ) {
		require_once WC_CLINK_PLUGIN_DIR . 'includes/class-wc-clink-subscriptions.php';
		WC_CLINK_Subscriptions::init();
	}

	add_action( 'init', 'wc_clink_rewrite_endpoints' );
	add_filter( 'query_vars', 'wc_clink_query_vars' );
	add_action( 'template_redirect', 'wc_clink_handle_auto_renewal_action' );

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
 * Check if any supported subscription plugin is active.
 *
 * Supports WooCommerce Subscriptions, Subscriptions for WooCommerce (hasthemes),
 * YITH WooCommerce Subscription, Flexible Subscriptions, and others
 * that implement the WC_Subscription interface.
 *
 * @return bool
 */
function wc_clink_is_subscription_plugin_active() {
	if ( class_exists( 'WC_Subscriptions_Order' ) ) {
		return true;
	}
	if ( class_exists( 'Hforce_Subscription' ) ) {
		return true;
	}
	if ( class_exists( 'YWSBS_Subscription' ) ) {
		return true;
	}
	if ( class_exists( 'Flexible_Subscription' ) || class_exists( 'WP_Desk\\Flexible_Subscriptions\\Subscription' ) ) {
		return true;
	}
	if ( class_exists( 'WC_Subscription' ) && class_exists( 'WC_Subscriptions_Product' ) ) {
		return true;
	}

	return false;
}

/**
 * Register My Account rewrite endpoint for auto-renewal management.
 */
function wc_clink_rewrite_endpoints() {
	add_rewrite_endpoint( 'clink-auto-renewal', EP_ROOT | EP_PAGES );
}

/**
 * Register query var for My Account endpoint.
 *
 * @param  array $vars Existing query vars.
 * @return array
 */
function wc_clink_query_vars( $vars ) {
	$vars[] = 'clink-auto-renewal';
	return $vars;
}

/**
 * Handle auto-renewal actions from My Account.
 */
function wc_clink_handle_auto_renewal_action() {
	global $wp;

	if ( ! isset( $wp->query_vars['clink-auto-renewal'] ) ) {
		return;
	}

	$action          = $wp->query_vars['clink-auto-renewal'];
	$subscription_id = absint( get_query_var( 'subscription_id', 0 ) );

	if ( ! $subscription_id || ! is_user_logged_in() ) {
		wc_add_notice( __( 'Invalid request.', 'clink-gateway-for-woocommerce' ), 'error' );
		return;
	}

	$nonce_action = 'clink_' . str_replace( '-', '_', $action ) . '_' . $subscription_id;

	if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), $nonce_action ) ) {
		wc_add_notice( __( 'Security check failed.', 'clink-gateway-for-woocommerce' ), 'error' );
		wp_safe_redirect( wc_get_account_endpoint_url( 'subscriptions' ) );
		exit;
	}

	$subscription = wc_get_order( $subscription_id );

	if ( ! $subscription ) {
		wc_add_notice( __( 'Subscription not found.', 'clink-gateway-for-woocommerce' ), 'error' );
		return;
	}

	$user_id = $subscription->get_customer_id();
	if ( get_current_user_id() !== $user_id ) {
		wc_add_notice( __( 'Access denied.', 'clink-gateway-for-woocommerce' ), 'error' );
		return;
	}

	if ( 'disable-auto-renewal' === $action ) {
		$subscription->delete_meta_data( '_clink_ndebit' );
		$subscription->add_order_note( __( 'Auto-renewal disabled by customer from My Account.', 'clink-gateway-for-woocommerce' ) );
		$subscription->save();
		wc_add_notice( __( 'Auto-renewal has been disabled for this subscription.', 'clink-gateway-for-woocommerce' ), 'success' );

		wp_safe_redirect( wc_get_account_endpoint_url( 'subscriptions' ) );
		exit;
	}

	if ( 'enable-auto-renewal' === $action ) {
		wc_add_notice( __( 'To re-enable auto-renewal, make a new subscription payment and authorize auto-renewal at checkout.', 'clink-gateway-for-woocommerce' ), 'notice' );

		wp_safe_redirect( wc_get_account_endpoint_url( 'subscriptions' ) );
		exit;
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

	$order_id          = absint( get_query_var( 'order-received' ) );
	$order_amount_sats = 0;
	$noffer           = '';
	$description      = '';
	$has_subscription = false;
	$is_renewal       = false;
	$ndebit           = '';
	$redirect_url     = '';

	if ( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( $order && 'clink' === $order->get_payment_method() ) {
			$order_amount_sats = (int) $order->get_meta( '_clink_amount_sats' );
			$noffer           = $order->get_meta( '_clink_noffer' );
			$description      = sprintf(
			/* translators: 1: order number, 2: site name */
				__( 'Order %1$s - %2$s', 'clink-gateway-for-woocommerce' ),
				$order->get_order_number(),
				wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
			);

			$subscription_ids = wc_clink_get_subscription_ids_for_order( $order_id );
			if ( ! empty( $subscription_ids ) ) {
				$has_subscription = true;
				$cached_ids       = $order->get_meta( '_clink_subscription_ids' );
				if ( empty( $cached_ids ) ) {
					$order->update_meta_data( '_clink_subscription_ids', $subscription_ids );
					$order->save();
				}
				$first_sub = wc_get_order( $subscription_ids[0] );
				if ( $first_sub ) {
					$ndebit = $first_sub->get_meta( '_clink_ndebit' );
				}
				$is_renewal = wc_clink_order_is_renewal( $order );
				if ( $is_renewal && empty( $ndebit ) ) {
					$ndebit = $order->get_meta( '_clink_ndebit' );
				}
			}

			$redirect_url = $order->get_checkout_order_received_url();
		}
	}

	$currency_format = $gateway->get_option( 'currency_display', 'sats' );

	wp_localize_script(
		'wc-clink-checkout',
		'wcClinkData',
		array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'wc_clink_nonce' ),
			'orderId'         => intval( $order_id ),
			'amountSats'      => intval( $order_amount_sats ),
			'description'     => esc_js( $description ),
			'noffer'          => esc_js( $noffer ),
			'pollInterval'    => $gateway->get_option( 'poll_interval', 5000 ),
			'timeout'         => $gateway->get_option( 'invoice_timeout', 600 ),
			'loader'          => esc_url( admin_url( 'images/spinner.gif' ) ),
			'hasSubscription' => $has_subscription,
			'isRenewal'       => $is_renewal,
			'ndebit'          => esc_js( $ndebit ),
			'redirectUrl'     => esc_url( $redirect_url ),
			'currencyFormat'  => esc_js( $currency_format ),
			'i18n'            => array(
				'generatingInvoice' => __( 'Generating Lightning Invoice...', 'clink-gateway-for-woocommerce' ),
				'scanToPay'        => __( 'Scan with your Lightning Wallet to pay', 'clink-gateway-for-woocommerce' ),
				'copyInvoice'      => __( 'Copy Invoice', 'clink-gateway-for-woocommerce' ),
				'invoiceCopied'    => __( 'Copied!', 'clink-gateway-for-woocommerce' ),
				'waitingPayment'   => __( 'Waiting for payment confirmation...', 'clink-gateway-for-woocommerce' ),
				'paymentConfirmed'  => __( 'Payment confirmed!', 'clink-gateway-for-woocommerce' ),
				'paymentError'     => __( 'Error generating invoice. Please try again.', 'clink-gateway-for-woocommerce' ),
				'expired'          => __( 'Invoice expired. Please try again.', 'clink-gateway-for-woocommerce' ),
				'openInWallet'     => __( 'Open in Wallet', 'clink-gateway-for-woocommerce' ),
				'renewalProcessing' => __( 'Processing renewal subscription payment...', 'clink-gateway-for-woocommerce' ),
				'renewalAutoPay'   => __( 'Auto-renewal in progress. Your wallet should pay this automatically.', 'clink-gateway-for-woocommerce' ),
				'ndebitTitle'      => __( 'Setup Auto-Renewal', 'clink-gateway-for-woocommerce' ),
				/* translators: %s is the My Account URL */
				'ndebitDescription' => sprintf( __( 'Auto-renewal keeps your subscription active without manual payments. <a href="%1$s" target="_blank" rel="noopener noreferrer">Get your nDebit string</a> from your CLINK wallet and paste it below. You can disable this anytime from <a href="%2$s" target="_blank" rel="noopener noreferrer">My Account > Subscriptions</a>.', 'clink-gateway-for-woocommerce' ), 'https://my.shockwallet.app/lapps', esc_url( wc_get_account_endpoint_url( 'subscriptions' ) ) ),
				'ndebitPlaceholder' => __( 'ndebit1...', 'clink-gateway-for-woocommerce' ),
				'ndebitSave'       => __( 'Save & Continue', 'clink-gateway-for-woocommerce' ),
				'ndebitSkip'       => __( 'Not now', 'clink-gateway-for-woocommerce' ),
				'ndebitSaved'      => __( 'Auto-renewal is active! Future payments will be processed automatically.', 'clink-gateway-for-woocommerce' ),
				'ndebitActive'     => __( 'Auto-renewal is active. Your wallet should pay this invoice automatically.', 'clink-gateway-for-woocommerce' ),
				'tryAgain'         => __( 'Try Again', 'clink-gateway-for-woocommerce' ),
			),
		)
	);
}

/**
 * Enqueue frontend price converter script (JS fallback for when PHP filters
 * are overridden by other plugins like Custom Price for WooCommerce Pro).
 */
function wc_clink_price_converter_scripts() {
	if ( is_admin() ) {
		return;
	}

	$gateway = WC_Gateway_CLINK::get_instance();

	if ( ! $gateway || ! $gateway->is_available() ) {
		return;
	}

	$display = $gateway->get_option( 'currency_display', 'sats' );

	if ( 'default' === $display ) {
		return;
	}

	$btc_price = WC_Gateway_CLINK::get_btc_price( get_woocommerce_currency() );

	if ( $btc_price <= 0 ) {
		return;
	}

	$min = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? '' : '.min';

	wp_enqueue_script(
		'wc-clink-price-converter',
		WC_CLINK_PLUGIN_URL . "assets/js/clink-price-converter{$min}.js",
		array(),
		WC_CLINK_VERSION,
		true
	);

	wp_localize_script(
		'wc-clink-price-converter',
		'wcClinkPriceData',
		array(
			'btcRate'        => $btc_price,
			'currencyFormat' => $display,
		)
	);
}

/**
 * Get subscription IDs associated with an order, plugin-agnostic.
 *
 * Supports WooCommerce Subscriptions, Subscriptions for WooCommerce (hasthemes),
 * YITH WooCommerce Subscription, Flexible Subscriptions, and any plugin
 * that stores subscription IDs in standard order meta.
 *
 * @param  int $order_id The order ID.
 * @return int[]
 */
function wc_clink_get_subscription_ids_for_order( $order_id ) {
	$subscription_ids = array();

	if ( function_exists( 'wcs_get_subscriptions_for_order' ) ) {
		$subs = wcs_get_subscriptions_for_order( $order_id );
		foreach ( $subs as $sub ) {
			$subscription_ids[] = $sub->get_id();
		}
		if ( ! empty( $subscription_ids ) ) {
			return $subscription_ids;
		}
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return $subscription_ids;
	}

	$cached = $order->get_meta( '_clink_subscription_ids' );
	if ( ! empty( $cached ) && is_array( $cached ) ) {
		return $cached;
	}

	$possible_keys = array(
		'_subscription_id',
		' _ywsbs_subscription_id',
		'hforce_subscription_id',
		'_flexible_subscription_id',
		'subscription_id',
	);

	foreach ( $possible_keys as $key ) {
		$raw = $order->get_meta( $key );
		if ( ! empty( $raw ) ) {
			$ids = is_array( $raw ) ? $raw : array( absint( $raw ) );
			$subscription_ids = array_merge( $subscription_ids, array_filter( $ids ) );
		}
	}

	if ( ! empty( $subscription_ids ) ) {
		$order->update_meta_data( '_clink_subscription_ids', $subscription_ids );
		$order->save();
	}

	return $subscription_ids;
}

/**
 * Check if an order is a subscription renewal, plugin-agnostic.
 *
 * @param  WC_Order $order The order object.
 * @return bool
 */
function wc_clink_order_is_renewal( $order ) {
	if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
		return true;
	}

	if ( $order->get_meta( '_subscription_renewal' ) ) {
		return true;
	}

	if ( 'subscription_renewal' === $order->get_meta( '_hforce_subscription_renewal' ) ) {
		return true;
	}

	if ( $order->get_meta( 'is_ywsbs_renewal' ) ) {
		return true;
	}

	$subscription_ids = wc_clink_get_subscription_ids_for_order( $order->get_id() );
	if ( ! empty( $subscription_ids ) ) {
		foreach ( $subscription_ids as $sub_id ) {
			$parent = wc_get_order( $sub_id );
			if ( $parent && $parent->get_meta( '_clink_ndebit' ) ) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Check whether frontend prices should be displayed in BTC/sats/₿.
 *
 * @return bool
 */
function wc_clink_should_display_btc_prices() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return false;
	}

	if ( is_admin() && ! wp_doing_ajax() ) {
		return false;
	}

	$gateway = WC_Gateway_CLINK::get_instance();

	if ( ! $gateway || ! $gateway->is_available() ) {
		return false;
	}

	return true;
}

/**
 * Convert a store-currency price to satoshis.
 *
 * @param  float  $price    The price in store currency.
 * @param  string $currency Optional currency code.
 * @return int
 */
function wc_clink_convert_to_sats( $price, $currency = '' ) {
	$gateway = WC_Gateway_CLINK::get_instance();

	if ( ! $gateway || (float) $price <= 0 ) {
		return 0;
	}

	if ( empty( $currency ) ) {
		$currency = get_woocommerce_currency();
	}

	return $gateway->convert_to_sats( (float) $price, $currency );
}

/**
 * Format a satoshi amount according to the currency display setting.
 *
 * @param  int  $amount_sats The amount in satoshis.
 * @param  bool $plain       Return plain text (no HTML).
 * @return string
 */
function wc_clink_format_sats_amount( $amount_sats, $plain = false ) {
	$gateway = WC_Gateway_CLINK::get_instance();
	$format  = $gateway ? $gateway->get_option( 'currency_display', 'sats' ) : 'sats';
	$btc     = (float) $amount_sats / 100000000;

	switch ( $format ) {
		case 'btc':
			if ( $plain ) {
				return sprintf( '%.8f BTC', $btc );
			}
			return wc_price( $btc, array( 'currency' => 'BTC', 'decimals' => 8 ) );
		case 'bip0177':
			if ( $plain ) {
				return sprintf( "\xE2\x82\xBF %.8f", $btc );
			}
			return wc_price( $btc, array( 'currency' => 'BTC', 'decimals' => 8 ) );
		case 'sats':
		default:
			if ( $plain ) {
				return number_format_i18n( $amount_sats ) . ' sats';
			}
			return '<span class="woocommerce-Price-amount amount">' . esc_html( number_format_i18n( $amount_sats ) ) . '&nbsp;<span class="woocommerce-Price-currencySymbol">sats</span></span>';
	}
}

/**
 * Filter the currency symbol for BTC when in bip-0177 mode.
 *
 * @param  string $symbol   The currency symbol.
 * @param  string $currency The currency code.
 * @return string
 */
function wc_clink_currency_symbol( $symbol, $currency ) {
	if ( 'BTC' !== $currency ) {
		return $symbol;
	}

	$gateway = WC_Gateway_CLINK::get_instance();

	if ( ! $gateway ) {
		return $symbol;
	}

	if ( 'bip0177' === $gateway->get_option( 'currency_display', 'sats' ) ) {
		return "\xE2\x82\xBF";
	}

	return $symbol;
}

/**
 * Override product price HTML to show BTC/sats/₿.
 *
 * @param  string     $price_html The price HTML.
 * @param  WC_Product $product    The product object.
 * @return string
 */
function wc_clink_price_html( $price_html, $product ) {
	if ( ! wc_clink_should_display_btc_prices() ) {
		return $price_html;
	}

	$currency = get_woocommerce_currency();

	if ( $product->is_on_sale() && (float) $product->get_regular_price() > 0 ) {
		$regular_sats = wc_clink_convert_to_sats( (float) $product->get_regular_price(), $currency );
		$sale_sats    = wc_clink_convert_to_sats( (float) $product->get_price(), $currency );

		if ( $regular_sats > 0 && $sale_sats > 0 ) {
			return '<del aria-hidden="true">' . wc_clink_format_sats_amount( $regular_sats ) . '</del> <ins>' . wc_clink_format_sats_amount( $sale_sats ) . '</ins>';
		}
	}

	$price       = (float) $product->get_price();
	$amount_sats = wc_clink_convert_to_sats( $price, $currency );

	if ( $amount_sats <= 0 ) {
		return $price_html;
	}

	return wc_clink_format_sats_amount( $amount_sats );
}

/**
 * Override variable product price range to show BTC/sats/₿.
 *
 * @param  string     $price_html The price HTML.
 * @param  WC_Product $product    The product object.
 * @return string
 */
function wc_clink_variable_price_html( $price_html, $product ) {
	if ( ! wc_clink_should_display_btc_prices() ) {
		return $price_html;
	}

	$currency = get_woocommerce_currency();

	$min_price = (float) $product->get_variation_price( 'min' );
	$max_price = (float) $product->get_variation_price( 'max' );

	$min_sats = wc_clink_convert_to_sats( $min_price, $currency );
	$max_sats = wc_clink_convert_to_sats( $max_price, $currency );

	if ( $min_sats <= 0 || $max_sats <= 0 ) {
		return $price_html;
	}

	if ( $min_sats === $max_sats ) {
		return wc_clink_format_sats_amount( $min_sats );
	}

	/* translators: 1: minimum price, 2: maximum price */
	return sprintf( __( '%1$s &ndash; %2$s', 'clink-gateway-for-woocommerce' ), wc_clink_format_sats_amount( $min_sats ), wc_clink_format_sats_amount( $max_sats ) );
}

/**
 * Override cart item price to show BTC/sats/₿.
 *
 * @param  string $price_html    The item price HTML.
 * @param  array  $cart_item     The cart item.
 * @param  string $cart_item_key The cart item key.
 * @return string
 */
function wc_clink_cart_item_price( $price_html, $cart_item, $cart_item_key ) {
	if ( ! wc_clink_should_display_btc_prices() ) {
		return $price_html;
	}

	$product     = $cart_item['data'];
	$price       = (float) $product->get_price();
	$currency    = get_woocommerce_currency();
	$amount_sats = wc_clink_convert_to_sats( $price, $currency );

	if ( $amount_sats <= 0 ) {
		return $price_html;
	}

	return wc_clink_format_sats_amount( $amount_sats );
}

/**
 * Override cart subtotal to show BTC/sats/₿.
 *
 * @param  string  $subtotal_html The subtotal HTML.
 * @param  bool    $compound      Whether compound.
 * @param  WC_Cart $cart          The cart object.
 * @return string
 */
function wc_clink_cart_subtotal( $subtotal_html, $compound, $cart ) {
	if ( ! wc_clink_should_display_btc_prices() ) {
		return $subtotal_html;
	}

	$currency    = get_woocommerce_currency();
	$subtotal    = (float) $cart->get_subtotal();
	$amount_sats = wc_clink_convert_to_sats( $subtotal, $currency );

	if ( $amount_sats <= 0 ) {
		return $subtotal_html;
	}

	return wc_clink_format_sats_amount( $amount_sats );
}

/**
 * Override cart total to show BTC/sats/₿.
 *
 * @param  string $total_html The total HTML.
 * @return string
 */
function wc_clink_cart_total( $total_html ) {
	if ( ! wc_clink_should_display_btc_prices() ) {
		return $total_html;
	}

	$currency    = get_woocommerce_currency();
	$total       = (float) WC()->cart->get_total( 'edit' );
	$amount_sats = wc_clink_convert_to_sats( $total, $currency );

	if ( $amount_sats <= 0 ) {
		return $total_html;
	}

	return wc_clink_format_sats_amount( $amount_sats );
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
	$invoice  = sanitize_text_field( wp_unslash( $_POST['invoice'] ?? '' ) );

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
			__( 'CLINK invoice generated: %s...', 'clink-gateway-for-woocommerce' ),
			substr( $invoice, 0, 30 )
		)
	);

	$gateway = WC_Gateway_CLINK::get_instance();

	if ( $gateway ) {
		$order->update_status( 'on-hold', __( 'Invoice generated, awaiting payment.', 'clink-gateway-for-woocommerce' ) );
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
		echo '<div class="wc-clink-paid">' . esc_html__( 'Payment confirmed. Thank you for your order!', 'clink-gateway-for-woocommerce' ) . '</div>';
		return;
	}

	$noffer     = $order->get_meta( '_clink_noffer' );
	$amount_sats = (int) $order->get_meta( '_clink_amount_sats' );

	if ( ! $noffer || ! $amount_sats ) {
		echo '<div class="wc-clink-error">' . esc_html__( 'Missing payment configuration. Please contact the store owner.', 'clink-gateway-for-woocommerce' ) . '</div>';
		return;
	}

	echo '<div id="wc-clink-payment-root"></div>';
}

/**
 * AJAX: Save an ndebit authorization string on the subscription.
 */
function wc_clink_ajax_save_ndebit() {
	check_ajax_referer( 'wc_clink_nonce', 'nonce' );

	$ndebit          = sanitize_text_field( wp_unslash( $_POST['ndebit'] ?? '' ) );
	$subscription_id = absint( $_POST['subscription_id'] ?? 0 );
	$order_id        = absint( $_POST['order_id'] ?? 0 );

	if ( ! $ndebit ) {
		wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'clink-gateway-for-woocommerce' ) ) );
	}

	if ( 0 !== strpos( $ndebit, 'ndebit1' ) || strlen( $ndebit ) < 50 ) {
		wp_send_json_error( array( 'message' => __( 'Invalid ndebit format.', 'clink-gateway-for-woocommerce' ) ) );
	}

	$subscription_ids = array();

	if ( $subscription_id > 0 ) {
		$subscription_ids = array( $subscription_id );
	} elseif ( $order_id > 0 ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'clink-gateway-for-woocommerce' ) ) );
		}

		$subscription_ids = wc_clink_get_subscription_ids_for_order( $order_id );
	}

	if ( empty( $subscription_ids ) ) {
		wp_send_json_error( array( 'message' => __( 'No subscription found.', 'clink-gateway-for-woocommerce' ) ) );
	}

	$saved = 0;
	foreach ( $subscription_ids as $sub_id ) {
		$subscription = wc_get_order( $sub_id );
		if ( ! $subscription ) {
			continue;
		}

		if ( is_user_logged_in() && $subscription->get_customer_id() !== get_current_user_id() ) {
			continue;
		}

		$subscription->update_meta_data( '_clink_ndebit', $ndebit );
		$subscription->add_order_note( __( 'Auto-renewal authorized via CLINK ndebit.', 'clink-gateway-for-woocommerce' ) );
		$subscription->save();
		$saved++;
	}

	if ( $saved > 0 ) {
		wp_send_json_success( array( 'message' => __( 'Auto-renewal enabled.', 'clink-gateway-for-woocommerce' ) ) );
	}

	wp_send_json_error( array( 'message' => __( 'Could not save auto-renewal settings.', 'clink-gateway-for-woocommerce' ) ) );
}
