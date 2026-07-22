<?php
/**
 * Intégration de la passerelle au checkout en blocs (WooCommerce Blocks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_KPay_Blocks_Support extends AbstractPaymentMethodType {

	protected $name = 'kpay';

	/** @var WC_KPay_Gateway|null */
	private $gateway;

	public function initialize() {
		$this->settings = get_option( 'woocommerce_kpay_settings', array() );

		$gateways      = WC()->payment_gateways()->payment_gateways();
		$this->gateway = isset( $gateways['kpay'] ) ? $gateways['kpay'] : null;
	}

	public function is_active() {
		return $this->gateway ? $this->gateway->is_available() : false;
	}

	public function get_payment_method_script_handles() {
		wp_register_style(
			'wc-kpay-checkout',
			WC_KPAY_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			wc_kpay_asset_version( 'assets/css/checkout.css' )
		);
		wp_enqueue_style( 'wc-kpay-checkout' );

		wp_register_script(
			'wc-kpay-blocks',
			WC_KPAY_PLUGIN_URL . 'assets/js/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			wc_kpay_asset_version( 'assets/js/blocks.js' ),
			true
		);

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'wc-kpay-blocks', 'k-pay-for-woocommerce' );
		}

		return array( 'wc-kpay-blocks' );
	}

	public function get_payment_method_data() {
		if ( ! $this->gateway ) {
			return array();
		}

		$providers = array();
		foreach ( $this->gateway->get_active_providers() as $code => $provider ) {
			$providers[] = array(
				'value' => $code,
				'label' => $provider['label'],
			);
		}

		return array(
			'title'       => $this->gateway->get_option( 'title' ),
			'description' => $this->gateway->get_option( 'description' ),
			'providers'   => $providers,
			// En mode passerelle, le bloc n'affiche aucun champ : opérateur et
			// numéro sont saisis sur la page hébergée par K-Pay.
			'mode'        => $this->gateway->get_payment_mode(),
			'redirectNotice' => __( 'Vous serez redirigé vers la page de paiement sécurisée K-Pay pour finaliser votre commande.', 'k-pay-for-woocommerce' ),
			'isSandbox'   => 'sandbox' === $this->gateway->get_environment(),
			'iconUrl'     => WC_KPAY_PLUGIN_URL . 'assets/images/kpay-logo-dark.png',
			'supports'    => array_filter( $this->gateway->supports, array( $this->gateway, 'supports' ) ),
		);
	}
}
