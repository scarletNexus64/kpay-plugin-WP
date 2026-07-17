<?php
/**
 * Parcours de paiement : conformité de la requête à l'API, gestion des
 * réponses, et transitions de la commande.
 */

use PHPUnit\Framework\TestCase;

final class PaymentFlowTest extends TestCase {

	private $gateway;

	protected function setUp(): void {
		kpay_test_reset();
		kpay_test_settings();
		$this->gateway = new WC_KPay_Gateway();
	}

	private function order( $id = 42 ) {
		return kpay_test_add_order( new WC_Order( $id ) );
	}

	private function post( $provider = 'MTN_MOMO_CMR', $phone = '653456789' ) {
		$_POST['kpay_provider'] = $provider;
		$_POST['kpay_phone']    = $phone;
	}

	/** La requête d'init doit être conforme à la spec K-Pay. */
	public function test_init_request_matches_api_spec(): void {
		$order = $this->order();
		$this->post();

		kpay_test_queue_response( 201, array(
			'id'        => 'pay_abc123',
			'reference' => 'KPAY-20260717-ABC',
			'status'    => 'PENDING',
		) );

		$this->gateway->process_payment( $order->get_id() );

		$request = $GLOBALS['kpay_test_http_requests'][0];
		$body    = json_decode( $request['args']['body'], true );

		// Endpoint documenté : /api/v1/payments/init (et non /payments).
		$this->assertSame( 'https://admin.kpay.site/api/v1/payments/init', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );

		// En-têtes d'authentification.
		$this->assertSame( 'kpay_test_abc', $request['args']['headers']['X-API-Key'] );
		$this->assertSame( 'sk_test_abc', $request['args']['headers']['X-Secret-Key'] );
		$this->assertSame( 'application/json', $request['args']['headers']['Content-Type'] );

		// Noms de champs exacts de la spec.
		$this->assertArrayHasKey( 'amount', $body );
		$this->assertArrayHasKey( 'provider', $body );
		$this->assertArrayHasKey( 'phoneNumber', $body );
		$this->assertArrayHasKey( 'externalId', $body );

		// Champs de l'ancienne version, absents de la spec.
		$this->assertArrayNotHasKey( 'phone', $body );
		$this->assertArrayNotHasKey( 'reference', $body );

		// La devise est déduite du provider côté K-Pay : ne pas l'envoyer.
		$this->assertArrayNotHasKey( 'currency', $body );

		$this->assertSame( 'MTN_MOMO_CMR', $body['provider'] );
		$this->assertSame( '237653456789', $body['phoneNumber'] );
	}

	/** Le code provider Orange doit être ORANGE_CMR, pas ORANGE_MONEY_CMR. */
	public function test_orange_provider_code_is_correct(): void {
		$order = $this->order();
		$this->post( 'ORANGE_CMR', '237653456789' );

		kpay_test_queue_response( 201, array( 'id' => 'pay_x', 'status' => 'PENDING' ) );
		$this->gateway->process_payment( $order->get_id() );

		$body = json_decode( $GLOBALS['kpay_test_http_requests'][0]['args']['body'], true );
		$this->assertSame( 'ORANGE_CMR', $body['provider'] );

		$providers = WC_KPay_API::get_providers();
		$this->assertArrayHasKey( 'ORANGE_CMR', $providers );
		$this->assertArrayNotHasKey( 'ORANGE_MONEY_CMR', $providers );
	}

	/** Un provider XAF sans décimales reçoit un montant entier. */
	public function test_amount_is_integer_for_xaf_providers(): void {
		$order = $this->order();
		$order->set_total( 5000.75 );
		$this->post();

		kpay_test_queue_response( 201, array( 'id' => 'pay_x', 'status' => 'PENDING' ) );
		$this->gateway->process_payment( $order->get_id() );

		$body = json_decode( $GLOBALS['kpay_test_http_requests'][0]['args']['body'], true );
		$this->assertSame( 5001, $body['amount'] );
		$this->assertIsInt( $body['amount'] );
	}

	/** Un provider gérant les décimales conserve les centimes. */
	public function test_amount_keeps_decimals_for_decimal_providers(): void {
		$GLOBALS['kpay_test_currency'] = 'KES';
		kpay_test_settings( array( 'providers' => array( 'MPESA_KEN' ) ) );
		$gateway = new WC_KPay_Gateway();

		$order = $this->order();
		$order->set_total( 1500.50 );
		$this->post( 'MPESA_KEN', '254703456789' );

		kpay_test_queue_response( 201, array( 'id' => 'pay_x', 'status' => 'PENDING' ) );
		$gateway->process_payment( $order->get_id() );

		$body = json_decode( $GLOBALS['kpay_test_http_requests'][0]['args']['body'], true );
		$this->assertSame( 1500.50, $body['amount'] );
	}

	/** Un init réussi met la commande en attente, jamais payée. */
	public function test_successful_init_puts_order_on_hold_not_paid(): void {
		$order = $this->order();
		$this->post();

		kpay_test_queue_response( 201, array(
			'id'        => 'pay_abc123',
			'reference' => 'KPAY-REF-1',
			'status'    => 'PENDING',
		) );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid(), 'Une commande ne doit jamais être payée à l\'init.' );
		$this->assertSame( 'pay_abc123', $order->get_meta( '_kpay_payment_id' ) );
		$this->assertSame( 'sandbox', $order->get_meta( '_kpay_environment' ) );
		$this->assertSame( 'KPAY-REF-1', $order->get_meta( '_kpay_reference' ) );
	}

	/** Une erreur API n'entraîne aucune transition de statut. */
	public function test_api_error_fails_cleanly(): void {
		$order = $this->order();
		$this->post();

		kpay_test_queue_response( 400, array(
			'statusCode' => 400,
			'message'    => 'Invalid phone number',
			'error'      => 'Bad Request',
		) );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertNotEmpty( $GLOBALS['kpay_test_notices'] );
		$this->assertEmpty( $order->get_meta( '_kpay_payment_id' ) );
	}

	/** Une panne réseau est signalée sans casser la commande. */
	public function test_network_error_fails_cleanly(): void {
		$order = $this->order();
		$this->post();

		kpay_test_queue_error( 'cURL error 28: timeout' );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertSame( 'pending', $order->get_status() );
	}

	/** Une réponse 2xx sans id est un échec, pas un succès silencieux. */
	public function test_missing_id_in_response_is_failure(): void {
		$order = $this->order();
		$this->post();

		kpay_test_queue_response( 201, array( 'status' => 'PENDING' ) );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertNotSame( 'on-hold', $order->get_status() );
	}

	/** Un numéro invalide est rejeté avant tout appel réseau. */
	public function test_invalid_phone_never_calls_api(): void {
		$order = $this->order();
		$this->post( 'MTN_MOMO_CMR', '123' );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'], 'Aucun appel API sur numéro invalide.' );
	}

	/** Un provider non activé est rejeté avant tout appel réseau. */
	public function test_inactive_provider_never_calls_api(): void {
		$order = $this->order();
		$this->post( 'MPESA_KEN', '254703456789' );

		$result = $this->gateway->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	/** Chaque tentative porte un externalId distinct (évite le 409). */
	public function test_external_id_is_unique_per_attempt(): void {
		$order = $this->order();
		$this->post();

		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			kpay_test_queue_response( 201, array( 'id' => 'pay_' . $i, 'status' => 'PENDING' ) );
			$this->gateway->process_payment( $order->get_id() );
			$body  = json_decode( end( $GLOBALS['kpay_test_http_requests'] )['args']['body'], true );
			$ids[] = $body['externalId'];
		}

		$this->assertCount( 3, array_unique( $ids ), 'Les externalId doivent tous différer : ' . implode( ', ', $ids ) );
		$this->assertSame( 'WC-42-1', $ids[0] );
		$this->assertSame( 'WC-42-3', $ids[2] );
	}

	/** Un échec API incrémente aussi le compteur : pas de réutilisation. */
	public function test_external_id_advances_after_failure(): void {
		$order = $this->order();
		$this->post();

		kpay_test_queue_error( 'timeout' );
		$this->gateway->process_payment( $order->get_id() );

		kpay_test_queue_response( 201, array( 'id' => 'pay_ok', 'status' => 'PENDING' ) );
		$this->gateway->process_payment( $order->get_id() );

		$first  = json_decode( $GLOBALS['kpay_test_http_requests'][0]['args']['body'], true );
		$second = json_decode( $GLOBALS['kpay_test_http_requests'][1]['args']['body'], true );

		$this->assertNotSame( $first['externalId'], $second['externalId'] );
	}

	/** Le panier n'est vidé qu'au checkout classique, pas via la Store API. */
	public function test_cart_not_emptied_during_store_api_request(): void {
		$order = $this->order();
		$this->post();
		WC()->is_rest = true;

		kpay_test_queue_response( 201, array( 'id' => 'pay_x', 'status' => 'PENDING' ) );
		$this->gateway->process_payment( $order->get_id() );

		$this->assertFalse( WC()->cart->emptied, 'Le checkout blocs gère le panier lui-même.' );
	}

	public function test_cart_emptied_during_classic_checkout(): void {
		$order = $this->order();
		$this->post();
		WC()->is_rest = false;

		kpay_test_queue_response( 201, array( 'id' => 'pay_x', 'status' => 'PENDING' ) );
		$this->gateway->process_payment( $order->get_id() );

		$this->assertTrue( WC()->cart->emptied );
	}

	/** Les métadonnées transmises permettent de retrouver la commande. */
	public function test_metadata_carries_order_id(): void {
		$order = $this->order( 77 );
		$this->post();

		kpay_test_queue_response( 201, array( 'id' => 'pay_x', 'status' => 'PENDING' ) );
		$this->gateway->process_payment( 77 );

		$body = json_decode( $GLOBALS['kpay_test_http_requests'][0]['args']['body'], true );
		$this->assertSame( '77', $body['metadata']['orderId'] );
	}

	/** Une commande inexistante ne déclenche aucun appel. */
	public function test_unknown_order_fails(): void {
		$this->post();
		$result = $this->gateway->process_payment( 9999 );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}
}
