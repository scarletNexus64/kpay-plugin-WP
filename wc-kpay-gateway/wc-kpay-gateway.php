<?php
/**
 * Plugin Name: K-Pay for WooCommerce
 * Plugin URI: https://kpay.site
 * Description: Passerelle de paiement Mobile Money (MTN MoMo, Orange Money) via K-Pay. Supporte le Cameroun et 11 autres pays africains.
 * Version: 2.1.0
 * Author: Steve Boussa
 * Author URI: https://profiles.wordpress.org/steveboussa/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: k-pay-for-woocommerce
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_KPAY_VERSION', '2.1.0' );
define( 'WC_KPAY_PLUGIN_FILE', __FILE__ );
define( 'WC_KPAY_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WC_KPAY_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Version utilisée pour les scripts et styles.
 *
 * En production, la version du plugin suffit. En développement (WP_DEBUG),
 * elle est complétée par la date de modification du fichier : sans cela, un
 * asset modifié garde la même URL et les navigateurs servent leur cache.
 */
function wc_kpay_asset_version( $relative_path = '' ) {
	if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || '' === $relative_path ) {
		return WC_KPAY_VERSION;
	}

	$file = WC_KPAY_PLUGIN_DIR . ltrim( $relative_path, '/' );

	return file_exists( $file )
		? WC_KPAY_VERSION . '.' . filemtime( $file )
		: WC_KPAY_VERSION;
}

/**
 * Déclare la compatibilité HPOS (High-Performance Order Storage) et
 * le checkout en blocs. Sans ça, WooCommerce affiche un avertissement
 * d'incompatibilité et peut masquer la passerelle.
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			WC_KPAY_PLUGIN_FILE,
			true
		);
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'cart_checkout_blocks',
			WC_KPAY_PLUGIN_FILE,
			true
		);
	}
} );

/**
 * Langue choisie dans les réglages de la passerelle, ou '' pour suivre le site.
 *
 * Les réglages sont lus directement en base : la passerelle n'est instanciée
 * qu'une fois WooCommerce chargé, bien après le filtre de locale.
 */
function wc_kpay_configured_locale() {
	$settings = get_option( 'woocommerce_kpay_settings', array() );

	if ( ! is_array( $settings ) || empty( $settings['language'] ) || 'site' === $settings['language'] ) {
		return '';
	}

	// Liste blanche : la valeur sert à composer un nom de fichier .mo.
	$allowed = array( 'fr_FR', 'en_US' );

	return in_array( $settings['language'], $allowed, true ) ? $settings['language'] : '';
}

/**
 * Force la locale du plugin quand le marchand en a choisi une.
 *
 * Le filtre est restreint à notre domaine : le reste du site, WooCommerce
 * inclus, garde la langue de WordPress.
 */
add_filter( 'plugin_locale', function ( $locale, $domain ) {
	if ( 'k-pay-for-woocommerce' !== $domain ) {
		return $locale;
	}

	$configured = wc_kpay_configured_locale();

	return $configured ? $configured : $locale;
}, 10, 2 );

/**
 * Chargement des traductions.
 *
 * L'interface est en français ; ce chargement permet de la traduire sans
 * modifier le code, en déposant un .mo dans languages/.
 */
add_action( 'init', function () {
	load_plugin_textdomain(
		'k-pay-for-woocommerce',
		false,
		dirname( plugin_basename( WC_KPAY_PLUGIN_FILE ) ) . '/languages'
	);
} );

/**
 * Avertissement si WooCommerce est absent.
 */
add_action( 'admin_notices', function () {
	if ( class_exists( 'WooCommerce' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>';
	esc_html_e( 'K-Pay Gateway nécessite WooCommerce actif pour fonctionner.', 'k-pay-for-woocommerce' );
	echo '</p></div>';
} );

/**
 * Chargement de la passerelle une fois WooCommerce disponible.
 */
add_action( 'plugins_loaded', function () {
	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return;
	}

	require_once WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-api.php';
	require_once WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-gateway.php';
	require_once WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-webhook.php';

	WC_KPay_Webhook::init();

	add_filter( 'woocommerce_payment_gateways', function ( $gateways ) {
		$gateways[] = 'WC_KPay_Gateway';
		return $gateways;
	} );
}, 11 );

/**
 * Intégration au checkout en blocs (WooCommerce Blocks).
 * Sans ce bloc, la passerelle est invisible sur les thèmes modernes
 * qui utilisent le bloc Checkout plutôt que le shortcode.
 */
add_action( 'woocommerce_blocks_loaded', function () {
	if ( ! class_exists( \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType::class ) ) {
		return;
	}

	require_once WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-blocks-support.php';

	add_action(
		'woocommerce_blocks_payment_method_type_registration',
		function ( \Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry ) {
			$registry->register( new WC_KPay_Blocks_Support() );
		}
	);
} );

/**
 * Lien "Réglages" depuis la liste des extensions.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=kpay' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Réglages', 'k-pay-for-woocommerce' ) . '</a>' );
	return $links;
} );
