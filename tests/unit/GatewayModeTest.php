<?php
/**
 * Mode « passerelle hébergée » : forme de la requête et sécurité du retour.
 *
 * En GATEWAY, K-Pay héberge la page de paiement. Le contrat de la spec est
 * strict : pas de phoneNumber à l'init, returnUrl requis, et surtout un
 * retour dont la signature doit être vérifiée AVANT toute confirmation —
 * elle-même reconfirmée par l'API.
 */

use PHPUnit\Framework\TestCase;

final class GatewayModeTest extends TestCase {

	const GATEWAY_SECRET = 'gwsec_test_123';

	protected function setUp(): void {
		kpay_test_reset();
		kpay_test_settings( array(
			'payment_mode'   => 'gateway',
			'gateway_secret' => self::GATEWAY_SECRET,
		) );

		$GLOBALS['kpay_test_wc'] = new class extends KPay_Test_WC {
			public function payment_gateways() {
				return new class {
					public function payment_gateways() {
						return array( 'kpay' => new WC_KPay_Gateway() );
					}
				};
			}
		};
	}

	private function gateway() {
		return new WC_KPay_Gateway();
	}

	// ---------------------------------------------------------------
	// Forme de la requête d'initialisation
	// ---------------------------------------------------------------

	/** Réponse d'init conforme au mode GATEWAY. */
	private function queue_gateway_init() {
		kpay_test_queue_response( 201, array(
			'id'         => 'pay_xyz789',
			'reference'  => 'KPAY-20260514-XYZ789',
			'externalId' => 'WC-42-1',
			'status'     => 'PENDING',
			'mode'       => 'GATEWAY',
			'amount'     => 5000,
			'currency'   => 'XAF',
			'gatewayUrl' => 'https://admin.kpay.site/gateway/gw_8sJ2',
		) );
	}

	private function order( $id = 42, $total = 5000 ) {
		$order = new WC_Order( $id );
		$order->set_total( $total );
		return kpay_test_add_order( $order );
	}

	private function last_request_body() {
		$requests = $GLOBALS['kpay_test_http_requests'];
		$last     = end( $requests );
		return json_decode( $last['args']['body'], true );
	}

	/**
	 * La spec interdit phoneNumber/customerName en GATEWAY : les transmettre
	 * fait échouer l'init avec un 400 (contrat de mode non respecté).
	 */
	public function test_gateway_init_omits_phone_and_provider(): void {
		$this->order();
		$this->queue_gateway_init();

		$this->gateway()->process_payment( 42 );

		$body = $this->last_request_body();

		$this->assertArrayNotHasKey( 'phoneNumber', $body );
		$this->assertArrayNotHasKey( 'provider', $body );
		$this->assertArrayNotHasKey( 'customerName', $body );
	}

	/** returnUrl est requis par la spec. */
	public function test_gateway_init_sends_return_url(): void {
		$this->order();
		$this->queue_gateway_init();

		$this->gateway()->process_payment( 42 );

		$body = $this->last_request_body();

		$this->assertArrayHasKey( 'returnUrl', $body );
		$this->assertStringContainsString( 'kpay_return=1', $body['returnUrl'] );
		$this->assertStringContainsString( 'order_key=', $body['returnUrl'] );
	}

	/** Le client doit être envoyé vers la page hébergée. */
	public function test_gateway_redirects_to_hosted_page(): void {
		$this->order();
		$this->queue_gateway_init();

		$result = $this->gateway()->process_payment( 42 );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://admin.kpay.site/gateway/gw_8sJ2', $result['redirect'] );
	}

	/**
	 * Une réponse sans gatewayUrl signale un mode mal aligné avec
	 * l'application K-Pay : mieux vaut échouer que laisser le client sans
	 * moyen de payer.
	 */
	public function test_missing_gateway_url_fails(): void {
		$this->order();
		kpay_test_queue_response( 201, array(
			'id'     => 'pay_xyz789',
			'status' => 'PENDING',
			'amount' => 5000,
		) );

		$result = $this->gateway()->process_payment( 42 );

		$this->assertSame( 'failure', $result['result'] );
	}

	/** Sans secret passerelle, le retour serait invérifiable : on refuse. */
	public function test_gateway_without_secret_refuses_payment(): void {
		kpay_test_settings( array(
			'payment_mode'   => 'gateway',
			'gateway_secret' => '',
		) );
		$this->order();

		$result = $this->gateway()->process_payment( 42 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertEmpty( $GLOBALS['kpay_test_http_requests'], 'Aucun appel ne doit partir.' );
	}

	/** Le mode USSD conserve son contrat : numéro et opérateur transmis. */
	public function test_ussd_mode_still_sends_phone(): void {
		kpay_test_settings( array( 'payment_mode' => 'ussd' ) );
		$this->order();
		$_POST['kpay_provider'] = 'MTN_MOMO_CMR';
		$_POST['kpay_phone']    = '670000001';

		kpay_test_queue_response( 201, array(
			'id' => 'pay_abc', 'status' => 'PENDING', 'amount' => 5000,
		) );

		$this->gateway()->process_payment( 42 );
		$body = $this->last_request_body();

		$this->assertSame( '237670000001', $body['phoneNumber'] );
		$this->assertSame( 'MTN_MOMO_CMR', $body['provider'] );
		$this->assertArrayNotHasKey( 'returnUrl', $body );
	}

	// ---------------------------------------------------------------
	// Sécurité de la signature de retour
	// ---------------------------------------------------------------

	private function signed_params( array $overrides = array() ) {
		$params = array_merge( array(
			'status'     => 'COMPLETED',
			'reference'  => 'KPAY-20260514-XYZ789',
			'externalId' => 'WC-42-1',
			'ts'         => (string) ( time() * 1000 ),
		), $overrides );

		if ( ! isset( $overrides['sig'] ) ) {
			$signed         = $params['status'] . '|' . $params['reference'] . '|' . $params['externalId'] . '|' . $params['ts'];
			$params['sig']  = hash_hmac( 'sha256', $signed, self::GATEWAY_SECRET );
		} else {
			$params['sig'] = $overrides['sig'];
		}

		return $params;
	}

	public function test_valid_return_signature_is_accepted(): void {
		$this->assertTrue(
			WC_KPay_API::verify_gateway_return( $this->signed_params(), self::GATEWAY_SECRET )
		);
	}

	public function test_forged_signature_is_rejected(): void {
		$params = $this->signed_params( array( 'sig' => str_repeat( 'a', 64 ) ) );

		$this->assertInstanceOf(
			WP_Error::class,
			WC_KPay_API::verify_gateway_return( $params, self::GATEWAY_SECRET )
		);
	}

	/** Passer COMPLETED sans resigner doit casser la vérification. */
	public function test_tampered_status_is_rejected(): void {
		$params           = $this->signed_params( array( 'status' => 'FAILED' ) );
		$params['status'] = 'COMPLETED';

		$this->assertInstanceOf(
			WP_Error::class,
			WC_KPay_API::verify_gateway_return( $params, self::GATEWAY_SECRET )
		);
	}

	/** Changer la commande visée invalide la signature. */
	public function test_tampered_external_id_is_rejected(): void {
		$params               = $this->signed_params();
		$params['externalId'] = 'WC-99-1';

		$this->assertInstanceOf(
			WP_Error::class,
			WC_KPay_API::verify_gateway_return( $params, self::GATEWAY_SECRET )
		);
	}

	public function test_wrong_secret_is_rejected(): void {
		$this->assertInstanceOf(
			WP_Error::class,
			WC_KPay_API::verify_gateway_return( $this->signed_params(), 'mauvais_secret' )
		);
	}

	public function test_missing_secret_is_rejected(): void {
		$this->assertInstanceOf(
			WP_Error::class,
			WC_KPay_API::verify_gateway_return( $this->signed_params(), '' )
		);
	}

	/** Un retour signé capturé ne doit pas rester rejouable indéfiniment. */
	public function test_expired_return_is_rejected(): void {
		$params = $this->signed_params( array(
			'ts' => (string) ( ( time() - 3600 ) * 1000 ),
		) );

		$error = WC_KPay_API::verify_gateway_return( $params, self::GATEWAY_SECRET );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( 'kpay_gateway_expired', $error->get_error_code() );
	}

	public function test_incomplete_return_is_rejected(): void {
		$params = $this->signed_params();
		unset( $params['reference'] );

		$this->assertInstanceOf(
			WP_Error::class,
			WC_KPay_API::verify_gateway_return( $params, self::GATEWAY_SECRET )
		);
	}

	// ---------------------------------------------------------------
	// Champs du checkout
	// ---------------------------------------------------------------

	public function test_gateway_mode_hides_checkout_fields(): void {
		$this->assertFalse( $this->gateway()->has_checkout_fields() );

		ob_start();
		$this->gateway()->payment_fields();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'kpay_phone', $html );
		$this->assertStringContainsString( 'redirig', $html );
	}

	public function test_ussd_mode_shows_checkout_fields(): void {
		kpay_test_settings( array( 'payment_mode' => 'ussd' ) );

		$gateway = $this->gateway();
		$this->assertTrue( $gateway->has_checkout_fields() );

		ob_start();
		$gateway->payment_fields();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'kpay_phone', $html );
	}

	/** Rien à valider localement quand les champs sont chez K-Pay. */
	public function test_gateway_mode_skips_field_validation(): void {
		$this->assertTrue( $this->gateway()->validate_fields() );
	}
}
