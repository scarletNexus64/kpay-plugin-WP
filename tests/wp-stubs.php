<?php
/**
 * Fonctions WordPress simulées, suffisantes pour exécuter le plugin hors
 * d'une installation réelle.
 */

class WP_Error {

	private $errors = array();
	private $data = array();

	public function __construct( $code = '', $message = '', $data = '' ) {
		if ( $code ) {
			$this->errors[ $code ][] = $message;
			if ( $data ) {
				$this->data[ $code ] = $data;
			}
		}
	}

	public function get_error_code() {
		$codes = array_keys( $this->errors );
		return empty( $codes ) ? '' : $codes[0];
	}

	public function get_error_message( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
	}

	public function get_error_data( $code = '' ) {
		$code = $code ? $code : $this->get_error_code();
		return isset( $this->data[ $code ] ) ? $this->data[ $code ] : null;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/**
 * Réponse HTTP simulée : dépile la file programmée par les tests et
 * enregistre la requête pour inspection.
 */
function wp_remote_request( $url, $args = array() ) {
	$GLOBALS['kpay_test_http_requests'][] = array( 'url' => $url, 'args' => $args );

	if ( empty( $GLOBALS['kpay_test_http_queue'] ) ) {
		return new WP_Error( 'http_request_failed', 'Aucune réponse programmée pour ' . $url );
	}

	return array_shift( $GLOBALS['kpay_test_http_queue'] );
}

function wp_remote_post( $url, $args = array() ) {
	$args['method'] = 'POST';
	return wp_remote_request( $url, $args );
}

function wp_remote_get( $url, $args = array() ) {
	$args['method'] = 'GET';
	return wp_remote_request( $url, $args );
}

function wp_remote_retrieve_response_code( $response ) {
	return is_array( $response ) && isset( $response['code'] ) ? $response['code'] : 0;
}

function wp_remote_retrieve_body( $response ) {
	return is_array( $response ) && isset( $response['body'] ) ? $response['body'] : '';
}

function wp_json_encode( $data, $options = 0 ) {
	return json_encode( $data, $options );
}

$GLOBALS['kpay_test_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['kpay_test_options'] )
		? $GLOBALS['kpay_test_options'][ $name ]
		: $default;
}

function update_option( $name, $value ) {
	$GLOBALS['kpay_test_options'][ $name ] = $value;
	return true;
}

function __( $text, $domain = '' ) {
	return $text;
}
function esc_html__( $text, $domain = '' ) {
	return $text;
}
function esc_attr__( $text, $domain = '' ) {
	return $text;
}
function esc_html_e( $text, $domain = '' ) {
	echo $text;
}
function esc_attr_e( $text, $domain = '' ) {
	echo $text;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL );
}
/**
 * Ajoute des paramètres à une URL, en préservant ceux déjà présents.
 */
function add_query_arg( $args, $url = '' ) {
	$parts    = explode( '#', (string) $url, 2 );
	$fragment = isset( $parts[1] ) ? '#' . $parts[1] : '';
	$base     = $parts[0];

	$existing = array();
	if ( false !== strpos( $base, '?' ) ) {
		list( $base, $query ) = explode( '?', $base, 2 );
		parse_str( $query, $existing );
	}

	$merged = array_merge( $existing, (array) $args );

	return $base . ( $merged ? '?' . http_build_query( $merged ) : '' ) . $fragment;
}
function wp_kses_post( $data ) {
	return $data;
}
function wpautop( $text ) {
	return '<p>' . $text . '</p>';
}
function selected( $a, $b, $echo = true ) {
	$r = (string) $a === (string) $b ? ' selected="selected"' : '';
	if ( $echo ) {
		echo $r;
	}
	return $r;
}

function sanitize_text_field( $str ) {
	if ( is_array( $str ) ) {
		return '';
	}
	$str = (string) $str;
	$str = strip_tags( $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}

function absint( $value ) {
	return abs( (int) $value );
}

function get_bloginfo( $show = '' ) {
	return 'Boutique Test';
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}

function plugin_dir_path( $file ) {
	return dirname( $file ) . '/';
}
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
}
function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

// Hooks : enregistrés sans effet, les tests appellent les méthodes directement.
function add_action( ...$args ) {
	return true;
}
function add_filter( ...$args ) {
	return true;
}
function do_action( ...$args ) {
	return true;
}
function apply_filters( $tag, $value, ...$args ) {
	return $value;
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce_' . md5( (string) $action );
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return $nonce === 'nonce_' . md5( (string) $action ) ? 1 : false;
}

function wp_enqueue_script( ...$args ) {
	return true;
}
function wp_register_script( ...$args ) {
	return true;
}
function wp_localize_script( ...$args ) {
	return true;
}
function wp_set_script_translations( ...$args ) {
	return true;
}
function wp_add_inline_script( ...$args ) {
	return true;
}

function is_wc_endpoint_url( $endpoint = false ) {
	return false;
}

function is_checkout() {
	return false;
}
function is_cart() {
	return false;
}
function wp_enqueue_style( ...$args ) {
	return true;
}
function wp_register_style( ...$args ) {
	return true;
}

function wp_list_pluck( $list, $field ) {
	$out = array();
	foreach ( $list as $key => $item ) {
		$out[ $key ] = is_array( $item ) ? $item[ $field ] : $item->$field;
	}
	return $out;
}

/**
 * Réponses JSON AJAX : lèvent une exception pour interrompre le flux comme
 * le fait wp_die() en production, et exposer le résultat au test.
 */
class KPay_Test_JsonResponse extends Exception {
	public $success;
	public $data;
	public $status;
	public function __construct( $success, $data, $status ) {
		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
		parent::__construct( 'json_response' );
	}
}

function wp_send_json_success( $data = null, $status = 200 ) {
	throw new KPay_Test_JsonResponse( true, $data, $status );
}

function wp_send_json_error( $data = null, $status = 200 ) {
	throw new KPay_Test_JsonResponse( false, $data, $status );
}

/**
 * REST simulé.
 */
class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function get_status() {
		return $this->status;
	}
	public function get_data() {
		return $this->data;
	}
}

class WP_REST_Request {
	private $body = '';
	private $headers = array();
	public function __construct( $body = '', $headers = array() ) {
		$this->body = $body;
		foreach ( $headers as $k => $v ) {
			$this->headers[ strtolower( $k ) ] = $v;
		}
	}
	public function get_body() {
		return $this->body;
	}
	public function get_header( $key ) {
		$key = strtolower( $key );
		return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
	}
}

function register_rest_route( ...$args ) {
	return true;
}

class WC_Admin_Settings {
	public static $errors = array();
	public static $messages = array();
	public static function add_error( $text ) {
		self::$errors[] = $text;
	}
	public static function add_message( $text ) {
		self::$messages[] = $text;
	}
}

// --- Administration ---

$GLOBALS['kpay_test_transients'] = array();

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['kpay_test_transients'] )
		? $GLOBALS['kpay_test_transients'][ $key ]
		: false;
}

function set_transient( $key, $value, $expiration = 0 ) {
	$GLOBALS['kpay_test_transients'][ $key ] = $value;
	return true;
}

function delete_transient( $key ) {
	unset( $GLOBALS['kpay_test_transients'][ $key ] );
	return true;
}

$GLOBALS['kpay_test_current_user_can'] = true;

function current_user_can( $cap ) {
	return ! empty( $GLOBALS['kpay_test_current_user_can'] );
}

function get_current_user_id() {
	return 1;
}

function is_admin() {
	return true;
}

function add_menu_page( ...$args ) {
	return 'toplevel_page_kpay';
}
function add_submenu_page( ...$args ) {
	return 'kpay_page_sub';
}

function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals, '.', ',' );
}

function current_time( $type = 'mysql' ) {
	// Valeur fixe : les tests ne doivent pas dépendre de l'horloge.
	return '2026-07-17 12:00:00';
}

function wp_generate_uuid4() {
	return sprintf(
		'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
		wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
		wp_rand( 0, 0xffff ),
		wp_rand( 0, 0x0fff ) | 0x4000,
		wp_rand( 0, 0x3fff ) | 0x8000,
		wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
	);
}

function wp_rand( $min = 0, $max = 0 ) {
	return random_int( $min, $max ?: PHP_INT_MAX );
}

/**
 * Les redirections lèvent une exception : en production wp_safe_redirect()
 * est suivi d'exit, et les tests doivent pouvoir observer la destination.
 */
class KPay_Test_Redirect extends Exception {
	public $url;
	public function __construct( $url ) {
		$this->url = $url;
		parent::__construct( 'redirect' );
	}
}

function wp_safe_redirect( $url, $status = 302 ) {
	throw new KPay_Test_Redirect( $url );
}

function check_admin_referer( $action = -1, $query_arg = '_wpnonce' ) {
	$nonce = isset( $_POST[ $query_arg ] ) ? $_POST[ $query_arg ] : '';
	if ( ! wp_verify_nonce( $nonce, $action ) ) {
		throw new Exception( 'nonce_invalide' );
	}
	return true;
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $display = true ) {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( wp_create_nonce( $action ) ) . '" />';
	if ( $display ) {
		echo $field;
	}
	return $field;
}

function wp_die( $message = '', $title = '', $args = array() ) {
	throw new Exception( 'wp_die: ' . ( is_string( $message ) ? $message : '' ) );
}
