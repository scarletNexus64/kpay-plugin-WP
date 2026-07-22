<?php
/**
 * Corrections issues de l'audit de sécurité.
 *
 * Chaque test reproduit le scénario d'exploitation que la correction ferme.
 * Ils existent pour que ces failles ne puissent pas réapparaître.
 */

use PHPUnit\Framework\TestCase;

final class SecurityHardeningTest extends TestCase {

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

		$GLOBALS['kpay_test_current_user_can'] = true;
	}

	// ---------------------------------------------------------------
	// Anti-rejeu du webhook
	// ---------------------------------------------------------------

	private function pending_order( $id = 42 ) {
		$order = new WC_Order( $id );
		$order->update_meta_data( '_kpay_payment_id', 'pay_abc123' );
		$order->update_meta_data( '_kpay_external_id', 'WC-' . $id . '-1' );
		$order->update_meta_data( '_kpay_environment', 'sandbox' );
		$order->update_status( 'on-hold' );
		return kpay_test_add_order( $order );
	}

	private function payload( array $overrides = array() ) {
		return json_encode( array_merge( array(
			'event'      => 'payment.completed',
			'paymentId'  => 'pay_abc123',
			'status'     => 'COMPLETED',
			'amount'     => 5000,
			'externalId' => 'WC-42-1',
			'metadata'   => array( 'orderId' => '42' ),
			'timestamp'  => gmdate( 'c' ),
		), $overrides ) );
	}

	private function send( $body ) {
		$signature = hash_hmac( 'sha256', $body, self::SECRET );
		return WC_KPay_Webhook::handle_webhook(
			new WP_REST_Request( $body, array( 'X-KPAY-Signature' => $signature ) )
		);
	}

	public function test_fresh_webhook_is_accepted(): void {
		$order = $this->pending_order();

		$response = $this->send( $this->payload() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $order->is_paid() );
	}

	/**
	 * Rejeu : une notification signée mais ancienne est refusée. Le scénario
	 * réel est un FAILED capturé, rejoué contre une nouvelle tentative.
	 */
	public function test_replayed_old_webhook_is_rejected(): void {
		$order = $this->pending_order();

		$old = $this->payload( array( 'timestamp' => gmdate( 'c', time() - 3600 ) ) );

		$response = $this->send( $old );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $order->is_paid(), 'FAILLE : webhook rejoué accepté.' );
	}

	public function test_webhook_just_inside_window_is_accepted(): void {
		$order = $this->pending_order();

		// 9 minutes : la fenêtre est de 10.
		$response = $this->send( $this->payload( array( 'timestamp' => gmdate( 'c', time() - 540 ) ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $order->is_paid() );
	}

	public function test_webhook_just_outside_window_is_rejected(): void {
		$order = $this->pending_order();

		// 11 minutes.
		$response = $this->send( $this->payload( array( 'timestamp' => gmdate( 'c', time() - 660 ) ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/** Horloge en avance : la tolérance vaut dans les deux sens. */
	public function test_slightly_future_timestamp_is_accepted(): void {
		$order = $this->pending_order();

		$response = $this->send( $this->payload( array( 'timestamp' => gmdate( 'c', time() + 60 ) ) ) );

		$this->assertSame( 200, $response->get_status() );
	}

	public function test_far_future_timestamp_is_rejected(): void {
		$order = $this->pending_order();

		$response = $this->send( $this->payload( array( 'timestamp' => gmdate( 'c', time() + 7200 ) ) ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	public function test_unreadable_timestamp_is_rejected(): void {
		$order = $this->pending_order();

		$response = $this->send( $this->payload( array( 'timestamp' => 'pas-une-date' ) ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertFalse( $order->is_paid() );
	}

	/**
	 * L'horodatage est obligatoire : traité en optionnel, l'anti-rejeu
	 * disparaîtrait sans bruit le jour où le champ viendrait à manquer.
	 */
	public function test_missing_timestamp_is_rejected(): void {
		$order = $this->pending_order();

		$body = json_encode( array(
			'event'     => 'payment.completed',
			'paymentId' => 'pay_abc123',
			'status'    => 'COMPLETED',
			'metadata'  => array( 'orderId' => '42' ),
		) );

		$this->assertSame( 400, $this->send( $body )->get_status() );
		$this->assertFalse( $order->is_paid(), 'Un webhook sans horodatage ne doit pas payer la commande.' );

		// Mais une signature invalide reste refusée.
		$order2 = kpay_test_add_order( new WC_Order( 43 ) );
		$response = WC_KPay_Webhook::handle_webhook(
			new WP_REST_Request( $body, array( 'X-KPAY-Signature' => str_repeat( 'a', 64 ) ) )
		);
		$this->assertSame( 401, $response->get_status() );
	}

}
