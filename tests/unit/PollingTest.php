<?php
/**
 * Polling de statut : authentification de la requête et application du statut.
 */

use PHPUnit\Framework\TestCase;

final class PollingTest extends TestCase {

	protected function setUp(): void {
		kpay_test_reset();
		kpay_test_settings();

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

	private function pending_order( $id = 42, array $meta = array() ) {
		$order = new WC_Order( $id );
		$order->update_meta_data( '_kpay_payment_id', 'pay_abc123' );
		$order->update_meta_data( '_kpay_environment', 'sandbox' );
		foreach ( $meta as $k => $v ) {
			$order->update_meta_data( $k, $v );
		}
		$order->update_status( 'on-hold' );
		return kpay_test_add_order( $order );
	}

	/** Exécute le polling et retourne la réponse JSON interceptée. */
	private function poll( array $post ) {
		$_POST = $post;
		try {
			WC_KPay_Webhook::handle_status_check();
		} catch ( KPay_Test_JsonResponse $r ) {
			return $r;
		}
		$this->fail( 'Aucune réponse JSON émise.' );
	}

	private function valid_post( WC_Order $order ) {
		return array(
			'order_id'  => (string) $order->get_id(),
			'order_key' => $order->get_order_key(),
			'nonce'     => wp_create_nonce( 'kpay_status_' . $order->get_id() ),
		);
	}

	// --- Authentification ---

	public function test_rejects_invalid_nonce(): void {
		$order = $this->pending_order();
		$post  = $this->valid_post( $order );
		$post['nonce'] = 'nonce_bidon';

		$r = $this->poll( $post );

		$this->assertFalse( $r->success );
		$this->assertSame( 403, $r->status );
	}

	/** Le nonce ne prouve pas la propriété : la clé de commande est requise. */
	public function test_rejects_wrong_order_key(): void {
		$order = $this->pending_order();
		$post  = $this->valid_post( $order );
		$post['order_key'] = 'wc_order_dun_autre';

		$r = $this->poll( $post );

		$this->assertFalse( $r->success );
		$this->assertSame( 403, $r->status );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'], 'Aucun appel API sur requête non autorisée.' );
	}

	public function test_rejects_missing_order_key(): void {
		$order = $this->pending_order();
		$post  = $this->valid_post( $order );
		unset( $post['order_key'] );

		$r = $this->poll( $post );

		$this->assertFalse( $r->success );
		$this->assertSame( 403, $r->status );
	}

	/**
	 * Une commande inexistante et une clé invalide donnent la même réponse :
	 * distinguer les deux transformerait l'endpoint en oracle permettant
	 * d'énumérer les commandes existantes.
	 */
	public function test_rejects_unknown_order(): void {
		$r = $this->poll( array(
			'order_id'  => '9999',
			'order_key' => 'x',
			'nonce'     => wp_create_nonce( 'kpay_status_9999' ),
		) );

		$this->assertFalse( $r->success );
		$this->assertSame( 403, $r->status );
	}

	/** Même réponse pour une commande existante dont la clé est fausse. */
	public function test_unknown_order_and_bad_key_are_indistinguishable(): void {
		$order = new WC_Order( 77 );
		$order->update_meta_data( '_kpay_payment_id', 'pay_x' );
		$order->update_status( 'on-hold' );
		kpay_test_add_order( $order );

		$existing = $this->poll( array(
			'order_id'  => '77',
			'order_key' => 'wrong_key',
			'nonce'     => wp_create_nonce( 'kpay_status_77' ),
		) );

		$missing = $this->poll( array(
			'order_id'  => '9999',
			'order_key' => 'wrong_key',
			'nonce'     => wp_create_nonce( 'kpay_status_9999' ),
		) );

		$this->assertSame(
			$missing->status,
			$existing->status,
			'Le code de retour ne doit pas révéler l\'existence de la commande.'
		);
	}

	// --- Application du statut ---

	public function test_completed_marks_order_paid(): void {
		$order = $this->pending_order();
		kpay_test_queue_response( 200, array( 'id' => 'pay_abc123', 'status' => 'COMPLETED' ) );

		$r = $this->poll( $this->valid_post( $order ) );

		$this->assertTrue( $r->success );
		$this->assertSame( 'COMPLETED', $r->data['status'] );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_failed_marks_order_failed(): void {
		$order = $this->pending_order();
		kpay_test_queue_response( 200, array( 'id' => 'pay_abc123', 'status' => 'FAILED' ) );

		$r = $this->poll( $this->valid_post( $order ) );

		$this->assertSame( 'FAILED', $r->data['status'] );
		$this->assertSame( 'failed', $order->get_status() );
	}

	public function test_pending_leaves_order_untouched(): void {
		$order = $this->pending_order();
		kpay_test_queue_response( 200, array( 'id' => 'pay_abc123', 'status' => 'PENDING' ) );

		$r = $this->poll( $this->valid_post( $order ) );

		$this->assertSame( 'PENDING', $r->data['status'] );
		$this->assertSame( 'on-hold', $order->get_status() );
	}

	/** L'URL interrogée doit être celle de la spec. */
	public function test_status_endpoint_matches_spec(): void {
		$order = $this->pending_order();
		kpay_test_queue_response( 200, array( 'status' => 'PENDING' ) );

		$this->poll( $this->valid_post( $order ) );

		$request = $GLOBALS['kpay_test_http_requests'][0];
		$this->assertSame( 'https://admin.kpay.site/api/v1/payments/pay_abc123', $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertSame( 'kpay_test_abc', $request['args']['headers']['X-API-Key'] );
	}

	/** Une erreur API ne doit pas faire échouer la commande. */
	public function test_api_error_reports_pending_not_failure(): void {
		$order = $this->pending_order();
		kpay_test_queue_error( 'timeout' );

		$r = $this->poll( $this->valid_post( $order ) );

		$this->assertTrue( $r->success );
		$this->assertSame( 'PENDING', $r->data['status'] );
		$this->assertSame( 'on-hold', $order->get_status(), 'Une panne réseau ne doit pas annuler une commande.' );
	}

	/** Court-circuit : une commande déjà payée n'appelle pas l'API. */
	public function test_already_paid_order_skips_api_call(): void {
		$order = $this->pending_order();
		$order->payment_complete();

		$r = $this->poll( $this->valid_post( $order ) );

		$this->assertSame( 'COMPLETED', $r->data['status'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	/**
	 * Régression : sans environnement enregistré, on refuse de deviner
	 * plutôt que d'interroger avec les clés du réglage courant.
	 */
	public function test_missing_environment_is_reported_not_guessed(): void {
		$order = new WC_Order( 42 );
		$order->update_meta_data( '_kpay_payment_id', 'pay_abc123' );
		// pas de _kpay_environment
		$order->update_status( 'on-hold' );
		kpay_test_add_order( $order );

		$r = $this->poll( $this->valid_post( $order ) );

		$this->assertFalse( $r->success );
		$this->assertSame( 409, $r->status );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	/** Une commande sandbox reste interrogée en sandbox après bascule live. */
	public function test_order_environment_wins_over_current_setting(): void {
		kpay_test_settings( array(
			'environment'     => 'live',
			'live_api_key'    => 'kpay_live_zzz',
			'live_secret_key' => 'sk_live_zzz',
		) );

		$order = $this->pending_order(); // _kpay_environment = sandbox
		kpay_test_queue_response( 200, array( 'status' => 'PENDING' ) );

		$this->poll( $this->valid_post( $order ) );

		$headers = $GLOBALS['kpay_test_http_requests'][0]['args']['headers'];
		$this->assertSame( 'kpay_test_abc', $headers['X-API-Key'], 'Une commande sandbox doit rester interrogée en sandbox.' );
	}
}
