<?php
/**
 * Sécurité du webhook : seule une notification authentique et destinée à la
 * bonne commande peut marquer un paiement.
 *
 * Ces tests exercent la vraie méthode WC_KPay_Webhook::handle_webhook().
 */

use PHPUnit\Framework\TestCase;

final class WebhookSecurityTest extends TestCase {

	const SECRET = 'whsec_test_xyz';

	protected function setUp(): void {
		kpay_test_reset();
		kpay_test_settings();

		// handle_webhook() résout la passerelle via WC()->payment_gateways().
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

	/** Commande en attente, prête à recevoir une notification. */
	private function pending_order( $id = 42, $payment_id = 'pay_abc123' ) {
		$order = new WC_Order( $id );
		$order->update_meta_data( '_kpay_payment_id', $payment_id );
		$order->update_meta_data( '_kpay_external_id', 'WC-' . $id . '-1' );
		$order->update_meta_data( '_kpay_environment', 'sandbox' );
		$order->update_status( 'on-hold' );
		return kpay_test_add_order( $order );
	}

	private function payload( array $overrides = array() ) {
		return json_encode( array_merge( array(
			'event'       => 'payment.completed',
			'paymentId'   => 'pay_abc123',
			'reference'   => 'KPAY-REF-1',
			'status'      => 'COMPLETED',
			'amount'      => 5000,
			'externalId'  => 'WC-42-1',
			'metadata'    => array( 'orderId' => '42' ),
			// Horodatage courant : le webhook applique une fenêtre anti-rejeu de
			// 10 minutes, une date figée deviendrait obsolète.
			'timestamp'   => gmdate( 'c' ),
		), $overrides ) );
	}

	private function sign( $body, $secret = self::SECRET ) {
		return hash_hmac( 'sha256', $body, $secret );
	}

	private function send( $body, $signature = null ) {
		$headers = array();
		if ( null !== $signature ) {
			$headers['X-KPAY-Signature'] = $signature;
		}
		return WC_KPay_Webhook::handle_webhook( new WP_REST_Request( $body, $headers ) );
	}

	// --- Cas légitime ---

	public function test_valid_signed_webhook_marks_order_paid(): void {
		$order = $this->pending_order();
		$body  = $this->payload();

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $order->is_paid(), 'Un webhook valide doit marquer la commande payée.' );
	}

	public function test_signature_is_case_insensitive(): void {
		$order = $this->pending_order();
		$body  = $this->payload();

		$response = $this->send( $body, strtoupper( $this->sign( $body ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $order->is_paid() );
	}

	// --- Attaques : chacune doit échouer sans marquer la commande payée ---

	/** L'attaque de base : poster COMPLETED sans signature. */
	public function test_attack_unsigned_webhook_is_rejected(): void {
		$order = $this->pending_order();

		$response = $this->send( $this->payload(), null );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $order->is_paid(), 'FAILLE : commande payée sans signature.' );
	}

	public function test_attack_forged_signature_is_rejected(): void {
		$order = $this->pending_order();

		$response = $this->send( $this->payload(), str_repeat( 'a', 64 ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $order->is_paid(), 'FAILLE : signature forgée acceptée.' );
	}

	public function test_attack_wrong_secret_is_rejected(): void {
		$order = $this->pending_order();
		$body  = $this->payload();

		$response = $this->send( $body, $this->sign( $body, 'mauvais_secret' ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/** Corps modifié après signature : la signature ne colle plus. */
	public function test_attack_tampered_body_is_rejected(): void {
		$order = $this->pending_order();
		$body  = $this->payload();
		$sig   = $this->sign( $body );

		$tampered = str_replace( '"amount":5000', '"amount":1', $body );

		$response = $this->send( $tampered, $sig );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/** Sans secret configuré, on rejette plutôt que d'accepter aveuglément. */
	public function test_webhook_rejected_when_no_secret_configured(): void {
		kpay_test_settings( array( 'webhook_secret' => '' ) );
		$order = $this->pending_order();
		$body  = $this->payload();

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 500, $response->get_status() );
		$this->assertFalse( $order->is_paid(), 'FAILLE : webhook accepté sans secret configuré.' );
	}

    /** Rejeu d'une notification signée d'une commande vers une autre. */
	public function test_attack_payment_id_mismatch_is_rejected(): void {
		$order = $this->pending_order( 42, 'pay_legitime' );

		// Notification authentiquement signée, mais pour une autre transaction.
		$body = $this->payload( array( 'paymentId' => 'pay_autre_client' ) );

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $order->is_paid(), 'FAILLE : paiement d\'autrui appliqué à cette commande.' );
	}

	public function test_unknown_order_is_rejected(): void {
		$body = $this->payload( array( 'metadata' => array( 'orderId' => '9999' ), 'externalId' => 'WC-9999-1' ) );

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_malformed_json_is_rejected(): void {
		$this->pending_order();
		$body = '{ceci nest pas du json';

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 400, $response->get_status() );
	}

	// --- Machine à états ---

	public function test_failed_status_marks_order_failed(): void {
		$order = $this->pending_order();
		$body  = $this->payload( array( 'event' => 'payment.failed', 'status' => 'FAILED' ) );

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'failed', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_cancelled_status_marks_order_failed(): void {
		$order = $this->pending_order();
		$body  = $this->payload( array( 'status' => 'CANCELLED' ) );

		$this->send( $body, $this->sign( $body ) );

		$this->assertSame( 'failed', $order->get_status() );
	}

	/** Idempotence : K-Pay peut renvoyer plusieurs fois le même événement. */
	public function test_duplicate_completed_webhook_is_idempotent(): void {
		$order = $this->pending_order();
		$body  = $this->payload();
		$sig   = $this->sign( $body );

		$this->send( $body, $sig );
		$this->send( $body, $sig );
		$this->send( $body, $sig );

		$paid_transitions = array_filter( $order->status_history, fn( $s ) => 'processing' === $s );
		$this->assertCount( 1, $paid_transitions, 'payment_complete() ne doit s\'exécuter qu\'une fois.' );
	}

	/** Un FAILED tardif ne doit pas annuler un paiement déjà encaissé. */
	public function test_failed_after_completed_does_not_unpay_order(): void {
		$order = $this->pending_order();

		$ok = $this->payload();
		$this->send( $ok, $this->sign( $ok ) );
		$this->assertTrue( $order->is_paid() );

		$ko = $this->payload( array( 'status' => 'FAILED' ) );
		$this->send( $ko, $this->sign( $ko ) );

		$this->assertTrue( $order->is_paid(), 'Une commande payée ne doit pas repasser en échec.' );
		$this->assertNotSame( 'failed', $order->get_status() );
	}

	/** PENDING/PROCESSING ne changent rien : on attend le statut terminal. */
	public function test_pending_status_leaves_order_on_hold(): void {
		$order = $this->pending_order();
		$body  = $this->payload( array( 'status' => 'PENDING' ) );

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'on-hold', $order->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/** Course : notification arrivée avant l'écriture du paymentId. */
	public function test_webhook_before_payment_recorded_asks_retry(): void {
		$order = new WC_Order( 42 );
		$order->update_meta_data( '_kpay_external_id', 'WC-42-1' );
		$order->update_status( 'pending' );
		kpay_test_add_order( $order );

		$body     = $this->payload();
		$response = $this->send( $body, $this->sign( $body ) );

		// 5xx : K-Pay réessaie. Une 4xx abandonnerait la notification.
		$this->assertSame( 503, $response->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/** Repli : commande retrouvée via externalId si metadata est absent. */
	public function test_order_located_by_external_id_fallback(): void {
		$order = $this->pending_order();
		$body  = $this->payload( array( 'metadata' => array() ) );

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $order->is_paid() );
	}

	/** L'externalId doit correspondre à celui stocké sur la commande. */
	public function test_external_id_mismatch_is_rejected(): void {
		$order = $this->pending_order();
		$body  = $this->payload( array(
			'metadata'   => array(),
			'externalId' => 'WC-42-99', // tentative inexistante
		) );

		$response = $this->send( $body, $this->sign( $body ) );

		$this->assertSame( 404, $response->get_status() );
		$this->assertFalse( $order->is_paid() );
	}
}
