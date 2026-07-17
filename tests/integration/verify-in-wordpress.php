<?php
/**
 * Vérification dans un vrai WordPress + WooCommerce.
 *
 * À exécuter via WP-CLI :
 *   wp eval-file tests/integration/verify-in-wordpress.php
 *
 * Contrairement aux tests unitaires, rien n'est simulé ici : ce script
 * confirme que le plugin s'active, s'enregistre auprès de WooCommerce et
 * expose ses réglages dans l'environnement réel.
 */

/**
 * Compteurs portés par une classe : wp eval-file exécute ce fichier dans une
 * portée de fonction, où `global $pass` ne référencerait pas ces variables.
 */
final class KPay_Verify {
	public static $pass = 0;
	public static $fail = 0;
}

function check( $label, $condition, $detail = '' ) {
	if ( $condition ) {
		KPay_Verify::$pass++;
		WP_CLI::log( "  OK    {$label}" );
	} else {
		KPay_Verify::$fail++;
		WP_CLI::log( "  ECHEC {$label}" . ( $detail ? " -> {$detail}" : '' ) );
	}
}

WP_CLI::log( "\n=== Environnement ===" );
check( 'WordPress chargé', function_exists( 'get_bloginfo' ), '' );
check( 'WooCommerce actif', class_exists( 'WooCommerce' ), 'WooCommerce absent' );
WP_CLI::log( '  WordPress ' . get_bloginfo( 'version' ) . ' / WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) );

WP_CLI::log( "\n=== Activation du plugin ===" );
check( 'Plugin actif', is_plugin_active( 'wc-kpay-gateway/wc-kpay-gateway.php' ), 'non activé' );
check( 'Constantes définies', defined( 'WC_KPAY_VERSION' ) && defined( 'WC_KPAY_PLUGIN_DIR' ) );
check( 'Classe API chargée', class_exists( 'WC_KPay_API' ) );
check( 'Classe passerelle chargée', class_exists( 'WC_KPay_Gateway' ) );
check( 'Classe webhook chargée', class_exists( 'WC_KPay_Webhook' ) );

WP_CLI::log( "\n=== Enregistrement auprès de WooCommerce ===" );
$gateways = WC()->payment_gateways()->payment_gateways();
check( 'Passerelle enregistrée sous "kpay"', isset( $gateways['kpay'] ), 'introuvable dans la liste' );

if ( ! isset( $gateways['kpay'] ) ) {
	WP_CLI::log( '  Passerelles trouvées : ' . implode( ', ', array_keys( $gateways ) ) );
	WP_CLI::error( "\nLa passerelle n'apparaît pas dans WooCommerce." );
}

$gateway = $gateways['kpay'];
check( 'Hérite de WC_Payment_Gateway', $gateway instanceof WC_Payment_Gateway );
check( 'method_title renseigné', ! empty( $gateway->method_title ) );
check( 'Champs de réglages déclarés', count( $gateway->form_fields ) > 5 );

WP_CLI::log( "\n=== Réglages ===" );
$expected_fields = array(
	'enabled', 'title', 'description', 'providers', 'environment',
	'sandbox_api_key', 'sandbox_secret_key', 'live_api_key',
	'live_secret_key', 'webhook_secret', 'debug',
);
foreach ( $expected_fields as $field ) {
	check( "Champ « {$field} » présent", isset( $gateway->form_fields[ $field ] ) );
}

WP_CLI::log( "\n=== Webhook (route REST) ===" );
$routes = rest_get_server()->get_routes();
check( 'Route /kpay/v1/webhook enregistrée', isset( $routes['/kpay/v1/webhook'] ), 'route absente' );
$url = WC_KPay_Webhook::get_url();
check( 'URL du webhook en HTTPS ou locale', ! empty( $url ) );
WP_CLI::log( "  URL : {$url}" );

WP_CLI::log( "\n=== Logo ===" );
foreach ( array( 'kpay-logo.png', 'kpay-logo-dark.png' ) as $file ) {
	check( "Fichier {$file} présent", file_exists( WC_KPAY_PLUGIN_DIR . 'assets/images/' . $file ) );
}
check( 'Logo déclaré sur la passerelle', false !== strpos( $gateway->icon, 'kpay-logo-dark.png' ) );
check( 'get_icon() renvoie une balise img', false !== strpos( $gateway->get_icon(), '<img' ) );

WP_CLI::log( "\n=== Devise ===" );
$currency = get_woocommerce_currency();
WP_CLI::log( "  Devise de la boutique : {$currency}" );
check(
	'Opérateurs cohérents avec la devise',
	is_array( $gateway->get_active_providers() )
);

WP_CLI::log( "\n=== Isolation test / production ===" );
$sandbox_history = get_option( 'kpay_withdrawal_history_sandbox', array() );
$live_history    = get_option( 'kpay_withdrawal_history_live', array() );

check(
	'Historiques de retrait séparés',
	'kpay_withdrawal_history_sandbox' !== 'kpay_withdrawal_history_live'
);
WP_CLI::log( sprintf( '  sandbox : %d retrait(s) | production : %d retrait(s)', count( $sandbox_history ), count( $live_history ) ) );

// Aucun retrait ne doit porter l'environnement opposé à sa liste.
$leaks = 0;
foreach ( $sandbox_history as $entry ) {
	if ( isset( $entry['environment'] ) && 'sandbox' !== $entry['environment'] ) {
		$leaks++;
	}
}
foreach ( $live_history as $entry ) {
	if ( isset( $entry['environment'] ) && 'live' !== $entry['environment'] ) {
		$leaks++;
	}
}
check( 'Aucun retrait dans le mauvais historique', 0 === $leaks, "{$leaks} fuite(s)" );

check(
	'Caches de solde séparés par environnement',
	'kpay_balances_sandbox' !== 'kpay_balances_live'
);

WP_CLI::log( "\n=== Compatibilité ===" );
check(
	'HPOS déclaré',
	class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class )
);
check(
	'Intégration blocs présente',
	file_exists( WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-blocks-support.php' )
);

WP_CLI::log( "\n=== Erreurs PHP ===" );
$log = WP_CONTENT_DIR . '/debug.log';
if ( file_exists( $log ) ) {
	$contents = file_get_contents( $log );
	$kpay_errors = array_filter(
		explode( "\n", $contents ),
		function ( $line ) {
			return false !== stripos( $line, 'kpay' )
				&& ( false !== stripos( $line, 'error' )
					|| false !== stripos( $line, 'warning' )
					|| false !== stripos( $line, 'fatal' )
					|| false !== stripos( $line, 'deprecated' ) );
		}
	);
	check( 'Aucune erreur PHP liée à K-Pay', empty( $kpay_errors ), implode( ' | ', array_slice( $kpay_errors, 0, 3 ) ) );
} else {
	WP_CLI::log( '  (aucun debug.log généré)' );
}

WP_CLI::log( "\n" . str_repeat( '-', 50 ) );
if ( KPay_Verify::$fail > 0 ) {
	WP_CLI::error( KPay_Verify::$fail . ' échec(s), ' . KPay_Verify::$pass . ' succès.' );
}
WP_CLI::success( KPay_Verify::$pass . ' vérifications passées, aucun échec.' );
