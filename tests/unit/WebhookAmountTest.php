<?php
/**
 * Contrôle du montant encaissé et du périmètre des événements.
 *
 * Une notification authentique ne suffit pas : elle doit porter sur un
 * paiement (et non un retrait ou un remboursement) et couvrir le total de
 * la commande. Sans ces contrôles, une signature valide obtenue sur une
 * transaction de faible montant validerait une commande au prix fort.
 */

use PHPUnit\Framework\TestCase;

final class WebhookAmountTest extends TestCase {

	const SECRET = 'whsec_test_xyz';

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

	/**
	 * Commande en attente. Le provider est enregistré : il détermine si le
	 * montant attendu est arrondi à l'entier.
	 */
	private function pending_order( $total = 5000, $provider = 'MTN_MOMO_CMR' ) {
		$order = new WC_Order( 42 );
		$order->update_meta_data( '_kpay_payment_id', 'pay_abc123' );
		$order->update_meta_data( '_kpay_external_id', 'WC-42-1' );
		$order->update_meta_data( '_kpay_environment', 'sandbox' );
		$order->update_meta_data( '_kpay_provider', $provider );
		$order->set_total( $total );
		$order->update_status( 'on-hold' );
		return kpay_test_add_order( $order );
	}

	private function payload( array $overrides = array() ) {
		return json_encode( array_merge( array(
			'event'      => 'payment.completed',
			'paymentId'  => 'pay_abc123',
			'reference'  => 'KPAY-REF-1',
			'status'     => 'COMPLETED',
			'amount'     => 5000,
			'externalId' => 'WC-42-1',
			'metadata'   => array( 'orderId' => '42' ),
			'timestamp'  => gmdate( 'c' ),
		), $overrides ) );
	}

	/** Envoie une notification correctement signée. */
	private function send( $body ) {
		return WC_KPay_Webhook::handle_webhook( new WP_REST_Request(
			$body,
			array( 'X-KPAY-Signature' => hash_hmac( 'sha256', $body, self::SECRET ) )
		) );
	}

	// ---------------------------------------------------------------
	// Montant
	// ---------------------------------------------------------------

	public function test_exact_amount_pays_the_order(): void {
		$order = $this->pending_order( 5000 );

		$this->assertSame( 200, $this->send( $this->payload() )->get_status() );
		$this->assertTrue( $order->is_paid() );
	}

	/**
	 * Le cœur de la faille : une notification signée mais portant un montant
	 * inférieur au total ne doit jamais payer la commande.
	 */
	public function test_underpayment_does_not_pay_the_order(): void {
		$order = $this->pending_order( 5000 );

		$response = $this->send( $this->payload( array( 'amount' => 1 ) ) );

		$this->assertSame( 200, $response->get_status(), 'La notification est acceptée mais non appliquée.' );
		$this->assertFalse( $order->is_paid(), 'Un montant insuffisant ne doit pas valider la commande.' );
	}

	/** Un montant très légèrement inférieur reste un sous-paiement. */
	public function test_slight_underpayment_is_rejected(): void {
		$order = $this->pending_order( 5000 );

		$this->send( $this->payload( array( 'amount' => 4999 ) ) );

		$this->assertFalse( $order->is_paid() );
	}

	/** Payer plus que dû reste un paiement valide. */
	public function test_overpayment_pays_the_order(): void {
		$order = $this->pending_order( 5000 );

		$this->send( $this->payload( array( 'amount' => 6000 ) ) );

		$this->assertTrue( $order->is_paid() );
	}

	/**
	 * Les devises sans décimales sont arrondies avant l'appel à K-Pay :
	 * le montant confirmé est donc l'arrondi, et non le total brut.
	 */
	public function test_integer_currency_rounding_is_accepted(): void {
		// XAF : 5000.4 est envoyé arrondi à 5000, K-Pay confirme 5000.
		$order = $this->pending_order( 5000.4, 'MTN_MOMO_CMR' );

		$this->send( $this->payload( array( 'amount' => 5000 ) ) );

		$this->assertTrue( $order->is_paid(), 'L\'arrondi appliqué à l\'init ne doit pas être vu comme un sous-paiement.' );
	}

	/** Sans montant dans la notification, la signature reste l'autorité. */
	public function test_missing_amount_still_pays(): void {
		$order   = $this->pending_order( 5000 );
		$payload = $this->payload();
		$decoded = json_decode( $payload, true );
		unset( $decoded['amount'] );

		$this->send( json_encode( $decoded ) );

		$this->assertTrue( $order->is_paid() );
	}

	/** Un sous-paiement laisse une trace exploitable par le marchand. */
	public function test_underpayment_is_logged_as_error(): void {
		$this->pending_order( 5000 );

		$this->send( $this->payload( array( 'amount' => 1 ) ) );

		$this->assertNotEmpty(
			array_filter(
				$GLOBALS['kpay_test_logger']->entries,
				function ( $entry ) {
					return 'error' === $entry['level']
						&& false !== strpos( $entry['message'], 'montant insuffisant' );
				}
			),
			'Un sous-paiement doit être journalisé en erreur.'
		);
	}

	// ---------------------------------------------------------------
	// Périmètre des événements
	// ---------------------------------------------------------------

	/**
	 * Un remboursement réussi porte status=COMPLETED et peut viser le même
	 * paymentId : sans filtre sur `event`, il repasserait la commande en payée.
	 */
	public function test_refund_event_does_not_pay_the_order(): void {
		$order = $this->pending_order( 5000 );

		$response = $this->send( $this->payload( array( 'event' => 'refund.completed' ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $order->is_paid(), 'Un remboursement ne doit jamais marquer la commande payée.' );
	}

	/** Un retrait sortant ne concerne aucune commande. */
	public function test_payout_event_does_not_pay_the_order(): void {
		$order = $this->pending_order( 5000 );

		$this->send( $this->payload( array( 'event' => 'payout.completed' ) ) );

		$this->assertFalse( $order->is_paid() );
	}

	/** Un échec de remboursement ne doit pas faire échouer la commande. */
	public function test_refund_failed_does_not_fail_the_order(): void {
		$order = $this->pending_order( 5000 );

		$this->send( $this->payload( array(
			'event'  => 'refund.failed',
			'status' => 'FAILED',
		) ) );

		$this->assertFalse( $order->has_status( 'failed' ) );
	}

	/** Les trois événements de paiement restent traités. */
	public function test_payment_events_are_processed(): void {
		$order = $this->pending_order( 5000 );

		$this->send( $this->payload( array(
			'event'  => 'payment.failed',
			'status' => 'FAILED',
		) ) );

		$this->assertTrue( $order->has_status( 'failed' ) );
	}

	// ---------------------------------------------------------------
	// Déduplication
	// ---------------------------------------------------------------

	/**
	 * Un FAILED rejoué à l'identique ne doit pas pouvoir faire échouer une
	 * nouvelle tentative en cours sur la même commande.
	 */
	public function test_identical_webhook_replay_is_ignored(): void {
		$order = $this->pending_order( 5000 );
		$body  = $this->payload( array(
			'event'  => 'payment.failed',
			'status' => 'FAILED',
		) );

		$this->send( $body );
		$this->assertTrue( $order->has_status( 'failed' ) );

		// Nouvelle tentative : la commande repasse en attente.
		$order->update_status( 'on-hold' );

		$response = $this->send( $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse(
			$order->has_status( 'failed' ),
			'Le rejeu d\'un échec ne doit pas casser une nouvelle tentative.'
		);
	}

	/**
	 * Une notification non appliquée (commande pas encore enregistrée) doit
	 * rester rejouable : K-Pay réessaie sur 5xx, et cette tentative est
	 * légitime.
	 */
	public function test_retry_after_503_is_still_processed(): void {
		$order = new WC_Order( 42 );
		$order->update_meta_data( '_kpay_external_id', 'WC-42-1' );
		$order->update_meta_data( '_kpay_provider', 'MTN_MOMO_CMR' );
		$order->set_total( 5000 );
		$order->update_status( 'on-hold' );
		kpay_test_add_order( $order );

		$body = $this->payload();

		// _kpay_payment_id absent : réessai demandé.
		$this->assertSame( 503, $this->send( $body )->get_status() );

		// L'identifiant arrive, K-Pay réessaie la même notification.
		$order->update_meta_data( '_kpay_payment_id', 'pay_abc123' );

		$this->assertSame( 200, $this->send( $body )->get_status() );
		$this->assertTrue( $order->is_paid(), 'Le réessai légitime doit être traité.' );
	}
}
