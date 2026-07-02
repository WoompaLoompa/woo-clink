<?php
/**
 * WooCommerce CLINK Gateway - Cart/Checkout Blocks Support
 *
 * @package WooCommerce_CLINK_Gateway
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * WC_CLINK_Blocks_Support
 *
 * Registers the CLINK payment method with the WooCommerce
 * Cart and Checkout Blocks system.
 */
final class WC_CLINK_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Payment method ID.
	 *
	 * @var string
	 */
	protected $name = 'clink';

	/**
	 * Initialize blocks integration.
	 */
	public function initialize() {
		$this->settings = get_option( "woocommerce_{$this->name}_settings", array() );
	}

	/**
	 * Check if the gateway is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		$gateway = WC_Gateway_CLINK::get_instance();
		return $gateway ? $gateway->is_available() : false;
	}

	/**
	 * Register the payment method script handles.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$suffix = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';

		wp_register_script(
			'wc-clink-blocks',
			WC_CLINK_PLUGIN_URL . "assets/js/clink-blocks{$suffix}.js",
			array(
				'wc-blocks-registry',
				'wc-settings',
				'wp-element',
				'wp-i18n',
				'wp-hooks',
			),
			WC_CLINK_VERSION,
			true
		);

		return array( 'wc-clink-blocks' );
	}

	/**
	 * Get payment method data for the store API.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		$gateway = WC_Gateway_CLINK::get_instance();

		return array(
			'id'          => $this->name,
			'title'       => $gateway ? esc_html( $gateway->get_title() ) : esc_html__( 'Lightning (CLINK)', 'woocommerce-clink-gateway' ),
			'description' => $gateway ? WC_Gateway_CLINK::external_linkify( wp_kses_post( $gateway->get_description() ) ) : '',
			'supports'    => $gateway ? $gateway->supports : array( 'products' ),
			'icon'        => $gateway ? esc_url( $gateway->icon ) : '',
		);
	}
}
