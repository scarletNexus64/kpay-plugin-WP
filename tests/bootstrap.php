<?php
/**
 * Amorçage des tests unitaires.
 *
 * Le plugin est chargé tel quel : seules les dépendances WordPress et
 * WooCommerce sont simulées. Les tests portent donc sur le vrai code du
 * plugin, pas sur une réimplémentation.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';

define( 'ABSPATH', '/tmp/wordpress/' );
define( 'WC_KPAY_PLUGIN_DIR', dirname( __DIR__ ) . '/wc-kpay-gateway/' );
define( 'WC_KPAY_PLUGIN_URL', 'https://example.test/wp-content/plugins/wc-kpay-gateway/' );
define( 'WC_KPAY_VERSION', '2.0.0' );

/**
 * Classe de base WooCommerce, réduite au comportement dont dépend la
 * passerelle : stockage des réglages et lecture avec valeur par défaut.
 */
abstract class WC_Payment_Gateway {

	public $id = '';
	public $title = '';
	public $description = '';
	public $enabled = 'no';
	public $icon = '';
	public $has_fields = false;
	public $method_title = '';
	public $method_description = '';
	public $supports = array( 'products' );
	public $form_fields = array();
	public $settings = array();

	public function init_settings() {
		$saved = get_option( 'woocommerce_' . $this->id . '_settings', null );

		$defaults = array();
		foreach ( $this->form_fields as $key => $field ) {
			if ( isset( $field['default'] ) ) {
				$defaults[ $key ] = $field['default'];
			}
		}

		$this->settings = is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	public function get_option( $key, $empty_value = null ) {
		if ( ! array_key_exists( $key, $this->settings ) ) {
			return $empty_value;
		}
		if ( '' === $this->settings[ $key ] && null !== $empty_value ) {
			return $empty_value;
		}
		return $this->settings[ $key ];
	}

	public function is_available() {
		return 'yes' === $this->enabled;
	}

	public function supports( $feature ) {
		return in_array( $feature, $this->supports, true );
	}

	public function get_return_url( $order = null ) {
		return 'https://example.test/checkout/order-received/' . ( $order ? $order->get_id() : 0 );
	}

	public function init_form_fields() {}
	public function process_admin_options() {
		return true;
	}

	public function admin_options() {
		echo '<h2>' . esc_html( $this->method_title ) . '</h2>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	public function generate_settings_html( $form_fields = array(), $echo = true ) {
		$html = '';
		foreach ( $this->form_fields as $key => $field ) {
			$html .= '<tr><th>' . esc_html( isset( $field['title'] ) ? $field['title'] : $key ) . '</th></tr>';
		}
		if ( $echo ) {
			echo $html;
		}
		return $html;
	}

	public function get_icon() {
		return $this->icon ? '<img src="' . esc_url( $this->icon ) . '" alt="" />' : '';
	}
}

/**
 * Commande WooCommerce simulée : métadonnées, statuts et notes, avec un
 * journal des transitions pour les assertions.
 */
class WC_Order {

	private $id;
	private $status = 'pending';
	private $meta = array();
	private $notes = array();
	private $paid = false;
	private $total = 5000.0;
	private $currency = 'XAF';
	private $key;
	private $payment_method = 'kpay';
	public $status_history = array();

	public function __construct( $id = 1 ) {
		$this->id  = $id;
		$this->key = 'wc_order_test' . $id;
	}

	public function get_id() {
		return $this->id;
	}
	public function get_order_number() {
		return (string) $this->id;
	}
	public function get_order_key() {
		return $this->key;
	}
	public function get_cancel_order_url_raw() {
		return 'https://boutique.test/panier/?cancel_order=true&order_id=' . $this->id;
	}
	public function get_total() {
		return $this->total;
	}
	public function set_total( $t ) {
		$this->total = $t;
	}
	public function get_currency() {
		return $this->currency;
	}
	public function get_payment_method() {
		return $this->payment_method;
	}
	public function get_billing_first_name() {
		return 'Jean';
	}
	public function get_billing_last_name() {
		return 'Dupont';
	}
	public function get_billing_email() {
		return 'jean@example.test';
	}

	public function get_meta( $key, $single = true ) {
		return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
	}
	public function update_meta_data( $key, $value ) {
		$this->meta[ $key ] = $value;
	}
	public function save() {
		return $this->id;
	}

	public function get_status() {
		return $this->status;
	}
	public function has_status( $status ) {
		return is_array( $status ) ? in_array( $this->status, $status, true ) : $this->status === $status;
	}
	public function update_status( $status, $note = '' ) {
		$this->status           = $status;
		$this->status_history[] = $status;
		if ( $note ) {
			$this->notes[] = $note;
		}
		return true;
	}
	public function add_order_note( $note ) {
		$this->notes[] = $note;
	}
	public function get_notes() {
		return $this->notes;
	}

	public function is_paid() {
		return $this->paid;
	}
	public function payment_complete( $txn = '' ) {
		$this->paid             = true;
		$this->status           = 'processing';
		$this->status_history[] = 'processing';
		return true;
	}
}

/** Panier simulé, pour vérifier qu'il n'est vidé qu'au bon moment. */
class KPay_Test_Cart {
	public $emptied = false;
	public function empty_cart() {
		$this->emptied = true;
	}
}

/** Conteneur WooCommerce simulé. */
class KPay_Test_WC {
	public $cart;
	public $is_rest = false;
	public function __construct() {
		$this->cart = new KPay_Test_Cart();
	}
	public function is_rest_api_request() {
		return $this->is_rest;
	}
	public function payment_gateways() {
		return null;
	}
}

$GLOBALS['kpay_test_wc'] = new KPay_Test_WC();

function WC() {
	return $GLOBALS['kpay_test_wc'];
}

/** Journal WooCommerce simulé. */
class KPay_Test_Logger {
	public $entries = array();
	public function log( $level, $message, $context = array() ) {
		$this->entries[] = array( 'level' => $level, 'message' => $message );
	}
	public function error( $message, $context = array() ) {
		$this->log( 'error', $message, $context );
	}
}

$GLOBALS['kpay_test_logger'] = new KPay_Test_Logger();

function wc_get_logger() {
	return $GLOBALS['kpay_test_logger'];
}

/**
 * Registre des commandes simulées.
 */
$GLOBALS['kpay_test_orders'] = array();

function wc_get_order( $id ) {
	$id = (int) $id;
	return isset( $GLOBALS['kpay_test_orders'][ $id ] ) ? $GLOBALS['kpay_test_orders'][ $id ] : false;
}

function wc_get_orders( $args ) {
	return isset( $GLOBALS['kpay_test_orders_query_result'] )
		? $GLOBALS['kpay_test_orders_query_result']
		: array();
}

$GLOBALS['kpay_test_notices'] = array();

function wc_add_notice( $message, $type = 'success' ) {
	$GLOBALS['kpay_test_notices'][] = array( 'message' => $message, 'type' => $type );
}

function wc_clean( $value ) {
	return is_array( $value ) ? array_map( 'wc_clean', $value ) : ( is_scalar( $value ) ? trim( (string) $value ) : '' );
}

$GLOBALS['kpay_test_currency'] = 'XAF';

function get_woocommerce_currency() {
	return $GLOBALS['kpay_test_currency'];
}

/**
 * Réponses HTTP programmées pour wp_remote_request.
 *
 * Chaque entrée : array( 'code' => int, 'body' => string ) ou un WP_Error.
 */
$GLOBALS['kpay_test_http_queue']    = array();
$GLOBALS['kpay_test_http_requests'] = array();

function kpay_test_queue_response( $code, $body ) {
	$GLOBALS['kpay_test_http_queue'][] = array(
		'code' => $code,
		'body' => is_array( $body ) ? json_encode( $body ) : $body,
	);
}

function kpay_test_queue_error( $message ) {
	$GLOBALS['kpay_test_http_queue'][] = new WP_Error( 'http_request_failed', $message );
}

function kpay_test_reset() {
	$GLOBALS['kpay_test_http_queue']    = array();
	$GLOBALS['kpay_test_http_requests'] = array();
	$GLOBALS['kpay_test_notices']       = array();
	$GLOBALS['kpay_test_orders']        = array();
	$GLOBALS['kpay_test_options']       = array();
	$GLOBALS['kpay_test_currency']      = 'XAF';
	$GLOBALS['kpay_test_logger']        = new KPay_Test_Logger();
	$GLOBALS['kpay_test_wc']            = new KPay_Test_WC();
	// Le webhook mémorise les notifications traitées et limite la fréquence du
	// polling via des transients : sans remise à zéro, ces marques fuiraient
	// d'un test à l'autre et feraient passer des notifications légitimes pour
	// des rejeux.
	$GLOBALS['kpay_test_transients']    = array();
	unset( $GLOBALS['kpay_test_orders_query_result'] );
	$_POST = array();
}

/**
 * Enregistre une commande simulée dans le registre.
 */
function kpay_test_add_order( WC_Order $order ) {
	$GLOBALS['kpay_test_orders'][ $order->get_id() ] = $order;
	return $order;
}

/**
 * Réglages de passerelle par défaut, surchargeables par test.
 */
function kpay_test_settings( array $overrides = array() ) {
	$defaults = array(
		'enabled'            => 'yes',
		'title'              => 'Mobile Money',
		'description'        => 'Payez avec MTN MoMo ou Orange Money.',
		'providers'          => array( 'MTN_MOMO_CMR', 'ORANGE_CMR' ),
		'environment'        => 'sandbox',
		'sandbox_api_key'    => 'kpay_test_abc',
		'sandbox_secret_key' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
		'live_api_key'       => '',
		'live_secret_key'    => '',
		'webhook_secret'     => 'whsec_test_xyz',
		'debug'              => 'no',
	);

	update_option( 'woocommerce_kpay_settings', array_merge( $defaults, $overrides ) );
}

/**
 * Version des assets : reprise du fichier principal du plugin, que le
 * bootstrap ne charge pas (il déclare des hooks et des constantes déjà
 * définies ici). Sans elle, les appels du plugin lèveraient une erreur
 * fatale que les tests ne verraient pas.
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

// Le vrai code du plugin est chargé ici : les tests portent sur ces classes.
require_once WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-api.php';
require_once WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-gateway.php';
require_once WC_KPAY_PLUGIN_DIR . 'includes/class-wc-kpay-webhook.php';
