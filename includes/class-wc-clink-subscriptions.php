<?php
/**
 * WooCommerce CLINK Gateway - Subscriptions Integration
 *
 * Supports WooCommerce Subscriptions, Subscriptions for WooCommerce (hasthemes),
 * YITH WooCommerce Subscription, Flexible Subscriptions, and others.
 *
 * @package CLINK_Gateway_for_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_CLINK_Subscriptions
 *
 * Handles subscription compatibility for CLINK payments.
 */
class WC_CLINK_Subscriptions {

	/**
	 * Initialize subscription hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_payment_meta', array( __CLASS__, 'add_subscription_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_scheduled_subscription_payment_clink', array( __CLASS__, 'process_renewal' ), 10, 2 );
		add_filter( 'woocommerce_subscription_validate_payment_meta', array( __CLASS__, 'validate_subscription_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', array( __CLASS__, 'subscription_cancelled' ) );
		add_action( 'woocommerce_subscription_status_on-hold', array( __CLASS__, 'subscription_on_hold' ) );
		add_action( 'woocommerce_subscription_status_active', array( __CLASS__, 'subscription_activated' ) );
		add_filter( 'woocommerce_my_account_my_subscriptions_actions', array( __CLASS__, 'my_account_actions' ), 10, 2 );
		add_filter( 'woocommerce_my_subscriptions_payment_method', array( __CLASS__, 'my_account_payment_method' ), 10, 2 );
		add_filter( 'woocommerce_subscription_payment_method_to_display', array( __CLASS__, 'filter_payment_method_display' ), 10, 3 );
		add_action( 'woocommerce_subscription_details_after_subscription_table', array( __CLASS__, 'output_activate_script' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_my_account_styles' ) );
	}

	/**
	 * Add subscription payment meta for the CLINK gateway.
	 *
	 * @param  array           $payment_meta Payment meta data.
	 * @param  WC_Subscription $subscription The subscription object.
	 * @return array
	 */
	public static function add_subscription_payment_meta( $payment_meta, $subscription ) {
		$payment_meta['clink'] = array(
			'post_meta' => array(
				'_clink_noffer' => array(
					'value' => $subscription->get_meta( '_clink_noffer' ),
					'label' => __( 'CLINK Offer String', 'clink-gateway-for-woocommerce' ),
				),
				'_clink_ndebit' => array(
					'value' => $subscription->get_meta( '_clink_ndebit' ),
					'label' => __( 'CLINK Ndebit (auto-renewal)', 'clink-gateway-for-woocommerce' ),
				),
			),
		);

		return $payment_meta;
	}

	/**
	 * Validate subscription payment meta.
	 *
	 * @param  array           $payment_meta Payment meta data.
	 * @param  WC_Subscription $subscription The subscription object.
	 * @throws Exception If noffer is missing.
	 */
	public static function validate_subscription_payment_meta( $payment_meta, $subscription ) {
		if ( ! isset( $payment_meta['clink']['post_meta']['_clink_noffer']['value'] ) ) {
			throw new Exception( esc_html__( 'CLINK Offer String is required for subscription payments.', 'clink-gateway-for-woocommerce' ) );
		}
	}

	/**
	 * Clean up ndebit when a subscription is cancelled.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 */
	public static function subscription_cancelled( $subscription ) {
		$ndebit = $subscription->get_meta( '_clink_ndebit' );
		if ( ! empty( $ndebit ) ) {
			$subscription->delete_meta_data( '_clink_ndebit' );
			$subscription->add_order_note( __( 'Auto-renewal ndebit removed due to cancellation.', 'clink-gateway-for-woocommerce' ) );
			$subscription->save();
		}
	}

	/**
	 * Suspend auto-renewal when a subscription is put on hold.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 */
	public static function subscription_on_hold( $subscription ) {
		$ndebit = $subscription->get_meta( '_clink_ndebit' );
		if ( ! empty( $ndebit ) ) {
			$subscription->update_meta_data( '_clink_ndebit_suspended', $ndebit );
			$subscription->delete_meta_data( '_clink_ndebit' );
			$subscription->add_order_note( __( 'Auto-renewal ndebit suspended (subscription on hold).', 'clink-gateway-for-woocommerce' ) );
			$subscription->save();
		}
	}

	/**
	 * Restore auto-renewal when a subscription is reactivated.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 */
	public static function subscription_activated( $subscription ) {
		$suspended = $subscription->get_meta( '_clink_ndebit_suspended' );
		if ( ! empty( $suspended ) ) {
			$subscription->update_meta_data( '_clink_ndebit', $suspended );
			$subscription->delete_meta_data( '_clink_ndebit_suspended' );
			$subscription->add_order_note( __( 'Auto-renewal ndebit restored (subscription reactivated).', 'clink-gateway-for-woocommerce' ) );
			$subscription->save();
		}
	}

	/**
	 * Override the payment method label in My Account subscriptions table.
	 *
	 * @param  string          $payment_method The payment method label.
	 * @param  WC_Subscription $subscription   The subscription object.
	 * @return string
	 */
	public static function my_account_payment_method( $payment_method, $subscription ) {
		if ( 'clink' !== $subscription->get_payment_method() ) {
			return $payment_method;
		}

		$ndebit = $subscription->get_meta( '_clink_ndebit' );

		if ( ! empty( $ndebit ) ) {
			return __( 'Auto-Renewal', 'clink-gateway-for-woocommerce' );
		}

		return __( 'Activate Auto-Renewal', 'clink-gateway-for-woocommerce' );
	}

	/**
	 * Filter the payment method label on the View Subscription page.
	 *
	 * @param  string          $payment_method_to_display The payment method label.
	 * @param  WC_Subscription $subscription              The subscription object.
	 * @param  string          $context                   The display context.
	 * @return string
	 */
	public static function filter_payment_method_display( $payment_method_to_display, $subscription, $context ) {
		if ( 'clink' !== $subscription->get_payment_method() ) {
			return $payment_method_to_display;
		}

		$ndebit = $subscription->get_meta( '_clink_ndebit' );

		if ( ! empty( $ndebit ) ) {
			return __( 'Auto-Renewal', 'clink-gateway-for-woocommerce' );
		}

		return __( 'Activate Auto-Renewal', 'clink-gateway-for-woocommerce' );
	}

	/**
	 * Output inline JS that converts "Activate Auto-Renewal" text into a link
	 * pointing to the parent order's order-received page.
	 *
	 * @param WC_Subscription $subscription The subscription object.
	 */
	public static function output_activate_script( $subscription ) {
		if ( 'clink' !== $subscription->get_payment_method() ) {
			return;
		}

		$ndebit = $subscription->get_meta( '_clink_ndebit' );
		if ( ! empty( $ndebit ) ) {
			return;
		}

		$parent       = $subscription->get_parent();
		$activate_url = $parent ? $parent->get_checkout_order_received_url() . '#wc-clink-payment-root' : '';

		if ( empty( $activate_url ) ) {
			return;
		}
		?>
		<script>
		( function() {
			var pmSpan = document.querySelector( '.subscription-payment-method' );
			if ( pmSpan && pmSpan.textContent.trim() === 'Activate Auto-Renewal' ) {
				pmSpan.innerHTML = '<a href="<?php echo esc_url( $activate_url ); ?>" class="clink-activate-link">' + pmSpan.textContent.trim() + '</a>';
			}
		} )();
		</script>
		<?php
	}

	/**
	 * Add auto-renewal management actions on My Account subscriptions page.
	 *
	 * @param  array           $actions      Existing actions.
	 * @param  WC_Subscription $subscription The subscription object.
	 * @return array
	 */
	public static function my_account_actions( $actions, $subscription ) {
		if ( 'clink' !== $subscription->get_payment_method() ) {
			return $actions;
		}

		$ndebit = $subscription->get_meta( '_clink_ndebit' );

		if ( ! empty( $ndebit ) ) {
			$disable_url = add_query_arg(
				array(
					'clink-auto-renewal' => 'disable-auto-renewal',
					'subscription_id'    => $subscription->get_id(),
				),
				wc_get_account_endpoint_url( 'subscriptions' )
			);
			$actions['clink-disable-auto-renewal'] = array(
				'url'  => wp_nonce_url( $disable_url, 'clink_disable_auto_renewal_' . $subscription->get_id() ),
				'name' => __( 'Disable Auto-Renewal', 'clink-gateway-for-woocommerce' ),
			);
		} else {
			$actions['clink-activate-auto-renewal'] = array(
				'url'  => esc_url( 'https://my.shockwallet.app/lapps' ),
				'name' => __( 'Activate Auto-Renewal', 'clink-gateway-for-woocommerce' ),
			);
		}

		return $actions;
	}

	/**
	 * Process a subscription renewal payment.
	 *
	 * @param float    $amount_to_charge The renewal amount.
	 * @param WC_Order $renewal_order    The renewal order.
	 */
	public static function process_renewal( $amount_to_charge, $renewal_order ) {
		$subscription_ids = wc_clink_get_subscription_ids_for_order( $renewal_order->get_id() );
		$subscription     = ! empty( $subscription_ids ) ? wc_get_order( $subscription_ids[0] ) : null;

		if ( ! $subscription ) {
			$renewal_order->update_status( 'failed', __( 'Subscription not found for renewal.', 'clink-gateway-for-woocommerce' ) );
			return;
		}

		$noffer = $subscription->get_meta( '_clink_noffer' );

		if ( empty( $noffer ) ) {
			$renewal_order->update_status( 'failed', __( 'CLINK offer not configured for subscription.', 'clink-gateway-for-woocommerce' ) );
			return;
		}

		$currency = $renewal_order->get_currency();
		$gateway  = WC_Gateway_CLINK::get_instance();

		if ( ! $gateway ) {
			$renewal_order->update_status( 'failed', __( 'Payment gateway not available.', 'clink-gateway-for-woocommerce' ) );
			return;
		}

		$amount_sats = $gateway->convert_to_sats( $amount_to_charge, $currency );

		if ( $amount_sats <= 0 ) {
			$renewal_order->update_status( 'failed', __( 'Could not calculate sats amount.', 'clink-gateway-for-woocommerce' ) );
			return;
		}

		$ndebit = $subscription->get_meta( '_clink_ndebit' );

		$renewal_order->update_meta_data( '_clink_noffer', $noffer );
		$renewal_order->update_meta_data( '_clink_amount_sats', $amount_sats );
		$renewal_order->update_meta_data( '_clink_amount_fiat', $amount_to_charge );
		$renewal_order->update_meta_data( '_clink_currency', $currency );
		$renewal_order->update_meta_data( '_clink_created', time() );

		if ( ! empty( $ndebit ) ) {
			$renewal_order->update_meta_data( '_clink_ndebit', $ndebit );
		}

		$renewal_order->save();

		$note = empty( $ndebit ) ?
			__( 'CLINK renewal invoice ready. Awaiting customer payment.', 'clink-gateway-for-woocommerce' ) :
			/* translators: %s: truncated ndebit */
			sprintf( __( 'CLINK renewal invoice ready. Ndebit auto-renewal configured (%s).', 'clink-gateway-for-woocommerce' ), substr( $ndebit, 0, 20 ) . '...' );

		$renewal_order->update_status( 'pending', $note );
	}

	/**
	 * Enqueue styles on My Account subscription pages.
	 */
	public static function enqueue_my_account_styles() {
		if ( ! is_account_page() ) {
			return;
		}

		if ( ! is_wc_endpoint_url( 'view-subscription' ) && ! is_wc_endpoint_url( 'subscriptions' ) ) {
			return;
		}

		wp_enqueue_style(
			'wc-clink-checkout',
			WC_CLINK_PLUGIN_URL . 'assets/css/clink-checkout.css',
			array(),
			WC_CLINK_VERSION
		);
	}
}
