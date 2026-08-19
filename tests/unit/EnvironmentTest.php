<?php
/**
 * Bascule Sandbox / Live.
 *
 * L'URL de l'API est identique dans les deux environnements : seul le préfixe
 * de la clé les distingue. Une erreur de clé est donc invisible sans contrôle
 * explicite, et coûteuse — clés de test en production, rien n'est encaissé ;
 * clés de production en test, des clients sont débités pour de vrai.
 */

use PHPUnit\Framework\TestCase;

final class EnvironmentTest extends TestCase {

	/**
	 * Clés secrètes au format réel : 64 caractères hexadécimaux, sans
	 * préfixe — c'est exactement ce que K-Pay délivre au marchand.
	 */
	private const SANDBOX_SECRET = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';
	private const LIVE_SECRET    = 'f0e1d2c3b4a5968778695a4b3c2d1e0ff0e1d2c3b4a5968778695a4b3c2d1e0f';

	protected function setUp(): void {
		kpay_test_reset();
		WC_Admin_Settings::$errors = array();
		WC_Admin_Settings::$messages = array();
	}

	private function gateway( array $settings = array() ) {
		kpay_test_settings( $settings );
		return new WC_KPay_Gateway();
	}

	/** Clés des deux environnements, correctement préfixées. */
	private function both_environments( $environment ) {
		return array(
			'environment'        => $environment,
			'sandbox_api_key'    => 'kpay_test_abc',
			'sandbox_secret_key' => self::SANDBOX_SECRET,
			'live_api_key'       => 'kpay_live_xyz',
			'live_secret_key'    => self::LIVE_SECRET,
		);
	}

	// --- Détection depuis le préfixe ---

	public function test_environment_detected_from_key_prefix(): void {
		$this->assertSame( 'sandbox', WC_KPay_API::detect_environment( 'kpay_test_abc123' ) );
		$this->assertSame( 'live', WC_KPay_API::detect_environment( 'kpay_live_abc123' ) );
	}

	public function test_unknown_prefix_is_not_guessed(): void {
		$this->assertSame( '', WC_KPay_API::detect_environment( 'pk_live_stripe' ) );
		$this->assertSame( '', WC_KPay_API::detect_environment( 'abc123' ) );
		$this->assertSame( '', WC_KPay_API::detect_environment( '' ) );
	}

	// --- Bascule ---

	public function test_switching_environment_selects_matching_keys(): void {
		$sandbox = $this->gateway( $this->both_environments( 'sandbox' ) );
		$this->assertSame( 'sandbox', $sandbox->get_environment() );

		kpay_test_queue_response( 200, array( 'status' => 'PENDING' ) );
		$sandbox->get_api()->get_payment( 'pay_1' );
		$headers = $GLOBALS['kpay_test_http_requests'][0]['args']['headers'];
		$this->assertSame( 'kpay_test_abc', $headers['X-API-Key'] );
		$this->assertSame( self::SANDBOX_SECRET, $headers['X-Secret-Key'] );

		$GLOBALS['kpay_test_http_requests'] = array();

		$live = $this->gateway( $this->both_environments( 'live' ) );
		$this->assertSame( 'live', $live->get_environment() );

		kpay_test_queue_response( 200, array( 'status' => 'PENDING' ) );
		$live->get_api()->get_payment( 'pay_1' );
		$headers = $GLOBALS['kpay_test_http_requests'][0]['args']['headers'];
		$this->assertSame( 'kpay_live_xyz', $headers['X-API-Key'] );
		$this->assertSame( self::LIVE_SECRET, $headers['X-Secret-Key'] );
	}

	/** Spec : l'URL ne change pas d'un environnement à l'autre. */
	public function test_api_url_is_identical_in_both_environments(): void {
		foreach ( array( 'sandbox', 'live' ) as $environment ) {
			$GLOBALS['kpay_test_http_requests'] = array();
			$gateway = $this->gateway( $this->both_environments( $environment ) );

			kpay_test_queue_response( 200, array( 'status' => 'PENDING' ) );
			$gateway->get_api()->get_payment( 'pay_1' );

			$this->assertStringStartsWith(
				'https://admin.kpay.site/',
				$GLOBALS['kpay_test_http_requests'][0]['url'],
				"L'URL doit être la même en {$environment}."
			);
		}
	}

	/** Les clés du mauvais environnement ne doivent jamais fuiter. */
	public function test_live_mode_never_sends_test_keys(): void {
		$gateway = $this->gateway( $this->both_environments( 'live' ) );

		kpay_test_queue_response( 200, array( 'status' => 'PENDING' ) );
		$gateway->get_api()->get_payment( 'pay_1' );

		$headers = $GLOBALS['kpay_test_http_requests'][0]['args']['headers'];
		$this->assertStringNotContainsString( 'test', $headers['X-API-Key'] );
		$this->assertStringNotContainsString( 'test', $headers['X-Secret-Key'] );
	}

	/** Une commande garde son environnement même après bascule. */
	public function test_order_environment_survives_switch(): void {
		$gateway = $this->gateway( $this->both_environments( 'sandbox' ) );
		$order   = kpay_test_add_order( new WC_Order( 42 ) );

		$_POST['kpay_provider'] = 'MTN_MOMO_CMR';
		$_POST['kpay_phone']    = '237653456789';

		kpay_test_queue_response( 201, array( 'id' => 'pay_1', 'status' => 'PENDING' ) );
		$gateway->process_payment( 42 );

		$this->assertSame( 'sandbox', $order->get_meta( '_kpay_environment' ) );

		// Le marchand bascule en live : la commande reste une commande sandbox.
		$live = $this->gateway( $this->both_environments( 'live' ) );

		$GLOBALS['kpay_test_http_requests'] = array();
		kpay_test_queue_response( 200, array( 'status' => 'COMPLETED' ) );
		$live->get_api( $order->get_meta( '_kpay_environment' ) )->get_payment( 'pay_1' );

		$headers = $GLOBALS['kpay_test_http_requests'][0]['args']['headers'];
		$this->assertSame( 'kpay_test_abc', $headers['X-API-Key'], 'Une commande sandbox doit rester interrogée en sandbox.' );
	}

	// --- Validation des clés ---

	public function test_matching_keys_are_valid(): void {
		$this->assertTrue( WC_KPay_API::validate_keys( 'kpay_test_a', self::SANDBOX_SECRET, 'sandbox' ) );
		$this->assertTrue( WC_KPay_API::validate_keys( 'kpay_live_a', self::LIVE_SECRET, 'live' ) );
	}

	/**
	 * K-Pay délivre la clé secrète en hexadécimal brut : aucun préfixe
	 * « sk_live_ » n'est émis. En exiger un rejetait toutes les clés
	 * authentiques — le plugin était inutilisable avec de vraies clés.
	 */
	public function test_unprefixed_secret_is_accepted(): void {
		$this->assertTrue(
			WC_KPay_API::validate_keys( 'kpay_live_a', self::LIVE_SECRET, 'live' ),
			'Une clé secrète K-Pay authentique ne porte aucun préfixe.'
		);
	}

	/** Un préfixe recopié depuis l'ancienne documentation reste toléré. */
	public function test_legacy_prefixed_secret_is_accepted(): void {
		$this->assertTrue( WC_KPay_API::validate_keys( 'kpay_live_a', 'sk_live_' . self::LIVE_SECRET, 'live' ) );
		$this->assertTrue( WC_KPay_API::validate_keys( 'kpay_test_a', 'sk_test_' . self::SANDBOX_SECRET, 'sandbox' ) );
	}

	/** …et il est retiré avant l'envoi, sinon K-Pay refuse l'authentification. */
	public function test_legacy_prefix_is_stripped_from_header(): void {
		$api = new WC_KPay_API( 'kpay_live_a', 'sk_live_' . self::LIVE_SECRET );
		$api->get_application_info();

		$request = $GLOBALS['kpay_test_http_requests'][0];
		$this->assertSame( self::LIVE_SECRET, $request['args']['headers']['X-Secret-Key'] );
	}

	/** Les espaces d'un copier-coller ne doivent pas invalider la clé. */
	public function test_surrounding_whitespace_is_tolerated(): void {
		$this->assertTrue( WC_KPay_API::validate_keys( 'kpay_live_a', "  " . self::LIVE_SECRET . "\n", 'live' ) );
	}

	/** Une clé tronquée à la copie est signalée pour ce qu'elle est. */
	public function test_truncated_secret_is_rejected(): void {
		$result = WC_KPay_API::validate_keys( 'kpay_live_a', substr( self::LIVE_SECRET, 0, 40 ), 'live' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kpay_secret_length_invalid', $result->get_error_code() );
	}

	/** La clé API recopiée par erreur dans le champ secret. */
	public function test_non_hexadecimal_secret_is_rejected(): void {
		$result = WC_KPay_API::validate_keys( 'kpay_live_a', 'kpay_live_deadbeef', 'live' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kpay_secret_format_invalid', $result->get_error_code() );
	}

	/** L'erreur la plus coûteuse : des clés de test en production. */
	public function test_test_keys_in_live_mode_are_rejected(): void {
		$result = WC_KPay_API::validate_keys( 'kpay_test_a', self::SANDBOX_SECRET, 'live' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kpay_key_environment_mismatch', $result->get_error_code() );
		$this->assertStringContainsString( 'kpay_live_', $result->get_error_message() );
	}

	/** L'inverse : des clés de production sur une boutique en test. */
	public function test_live_keys_in_sandbox_mode_are_rejected(): void {
		$result = WC_KPay_API::validate_keys( 'kpay_live_a', self::LIVE_SECRET, 'sandbox' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kpay_key_environment_mismatch', $result->get_error_code() );
	}

	public function test_foreign_key_is_rejected(): void {
		$result = WC_KPay_API::validate_keys( 'pk_live_stripe', 'sk_live_stripe', 'live' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kpay_key_prefix_unknown', $result->get_error_code() );
	}

	public function test_empty_keys_are_rejected(): void {
		$this->assertInstanceOf( WP_Error::class, WC_KPay_API::validate_keys( '', '', 'sandbox' ) );
		$this->assertInstanceOf( WP_Error::class, WC_KPay_API::validate_keys( 'kpay_test_a', '', 'sandbox' ) );
	}

	// --- Contrôle à l'enregistrement ---

	public function test_saving_test_keys_in_live_mode_shows_error(): void {
		$gateway = $this->gateway( array(
			'environment'     => 'live',
			'live_api_key'    => 'kpay_test_oups',
			'live_secret_key' => self::SANDBOX_SECRET,
		) );

		$gateway->process_admin_options();

		$this->assertNotEmpty( WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'kpay_live_', WC_Admin_Settings::$errors[0] );
		// Le préfixe est vérifié localement : aucun appel réseau inutile.
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	public function test_saving_valid_keys_confirms_application(): void {
		$gateway = $this->gateway( $this->both_environments( 'sandbox' ) );

		kpay_test_queue_response( 200, array(
			'application' => array( 'id' => 'app_1', 'name' => 'Ma Boutique' ),
			'company'     => array( 'id' => 'co_1', 'name' => 'Test Inc' ),
			'environment' => 'TEST',
		) );

		$gateway->process_admin_options();

		$this->assertEmpty( WC_Admin_Settings::$errors );
		$this->assertNotEmpty( WC_Admin_Settings::$messages );
		$this->assertStringContainsString( 'Ma Boutique', WC_Admin_Settings::$messages[0] );
	}

	/** L'API fait autorité : elle peut contredire le préfixe. */
	public function test_api_environment_mismatch_is_reported(): void {
		$gateway = $this->gateway( $this->both_environments( 'live' ) );

		// Préfixe live, mais l'API répond TEST.
		kpay_test_queue_response( 200, array(
			'application' => array( 'name' => 'Boutique' ),
			'environment' => 'TEST',
		) );

		$gateway->process_admin_options();

		$this->assertNotEmpty( WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'TEST', WC_Admin_Settings::$errors[0] );
	}

	public function test_invalid_keys_are_reported(): void {
		$gateway = $this->gateway( $this->both_environments( 'sandbox' ) );

		kpay_test_queue_response( 401, array(
			'statusCode' => 401,
			'message'    => 'Invalid API credentials',
			'error'      => 'Unauthorized',
		) );

		$gateway->process_admin_options();

		$this->assertNotEmpty( WC_Admin_Settings::$errors );
		$this->assertStringContainsString( 'Invalid API credentials', WC_Admin_Settings::$errors[0] );
	}

	/** Passerelle désactivée : aucun contrôle, aucun appel. */
	public function test_disabled_gateway_skips_verification(): void {
		$gateway = $this->gateway( array( 'enabled' => 'no', 'environment' => 'live', 'live_api_key' => 'kpay_test_oups' ) );

		$gateway->process_admin_options();

		$this->assertEmpty( WC_Admin_Settings::$errors );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	// --- Repères visuels ---

	public function test_admin_shows_environment_banner(): void {
		$sandbox = $this->gateway( $this->both_environments( 'sandbox' ) );

		ob_start();
		$sandbox->admin_options();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'kpay-env-banner--sandbox', $html );
		$this->assertStringContainsString( 'Sandbox', $html );

		$live = $this->gateway( $this->both_environments( 'live' ) );

		ob_start();
		$live->admin_options();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'kpay-env-banner--live', $html );
		$this->assertStringContainsString( 'argent réel', $html );
	}

	/** Une clé du mauvais environnement est signalée sans enregistrer. */
	public function test_admin_warns_about_wrong_key_prefix(): void {
		$gateway = $this->gateway( array(
			'environment'     => 'live',
			'live_api_key'    => 'kpay_test_oups',
			'live_secret_key' => self::SANDBOX_SECRET,
		) );

		ob_start();
		$gateway->admin_options();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'kpay_live_', $html );
	}

	/** Le client averti le client en sandbox, pas en production. */
	public function test_checkout_shows_test_notice_only_in_sandbox(): void {
		$sandbox = $this->gateway( $this->both_environments( 'sandbox' ) );

		ob_start();
		$sandbox->payment_fields();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'Mode test actif', $html );

		$live = $this->gateway( $this->both_environments( 'live' ) );

		ob_start();
		$live->payment_fields();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'Mode test actif', $html );
	}
}
