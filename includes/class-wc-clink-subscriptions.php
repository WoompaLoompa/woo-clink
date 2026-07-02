<?php
/**
 * WooCommerce CLINK Gateway - Subscriptions Integration
 *
 * @package CLINK_Gateway_for_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * WC_CLINK_Subscriptions
 *
 * Handles WooCommerce Subscriptions compatibility for CLINK payments.
 */
class WC_CLINK_Subscriptions {

	/**
	 * Initialize subscription hooks.
	 */
	public static function init() {
		add_filter( 'woocommerce_subscription_payment_meta', array( __CLASS__, 'add_subscription_payment_meta' ), 10, 2 );
		add_action( 'woocommerce_scheduled_subscription_payment_clink', array( __CLASS__, 'process_renewal' ), 10, 2 );
		add_filter( 'woocommerce_subscription_validate_payment_meta', array( __CLASS__, 'validate_subscription_payment_meta' ), 10, 2 );
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
	 * Process a subscription renewal payment.
	 *
	 * @param float    $amount_to_charge The renewal amount.
	 * @param WC_Order $renewal_order    The renewal order.
	 */
	public static function process_renewal( $amount_to_charge, $renewal_order ) {
		$subscriptions = wcs_get_subscriptions_for_renewal_order( $renewal_order );
		$subscription  = reset( $subscriptions );

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

		$renewal_order->update_meta_data( '_clink_noffer', $noffer );
		$renewal_order->update_meta_data( '_clink_amount_sats', $amount_sats );
		$renewal_order->update_meta_data( '_clink_amount_fiat', $amount_to_charge );
		$renewal_order->update_meta_data( '_clink_currency', $currency );
		$renewal_order->update_meta_data( '_clink_created', time() );
		$renewal_order->save();

		$renewal_order->update_status( 'pending', __( 'CLINK renewal invoice ready. Awaiting customer payment.', 'clink-gateway-for-woocommerce' ) );
	}
}
