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
	// Le contrôle de solde doit échouer fermé
	// ---------------------------------------------------------------

	private function post_withdrawal( array $overrides = array() ) {
		$_POST = array_merge( array(
			'_wpnonce'        => wp_create_nonce( 'kpay_withdraw' ),
			'wallet_currency' => 'XAF',
			'provider'        => 'MTN_MOMO_CMR',
			'phone'           => '237653456789',
			'amount'          => '5000',
			'confirm'         => '1',
		), $overrides );
	}

	private function submit() {
		try {
			WC_KPay_Admin::handle_withdrawal();
		} catch ( KPay_Test_Redirect $r ) {
			return get_transient( 'kpay_admin_notice_1' );
		}
		$this->fail( 'Aucune redirection.' );
	}

	/**
	 * Panne de l'API de solde : le retrait doit être annulé, pas envoyé
	 * sans vérification.
	 */
	public function test_balance_api_failure_blocks_withdrawal(): void {
		$this->post_withdrawal();
		kpay_test_queue_error( 'timeout' );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'annulé', $notice['message'] );
		// Un seul appel : le solde. Le retrait n'est jamais parti.
		$this->assertCount( 1, $GLOBALS['kpay_test_http_requests'] );
	}

	public function test_balance_api_error_response_blocks_withdrawal(): void {
		$this->post_withdrawal();
		kpay_test_queue_response( 500, array( 'statusCode' => 500, 'message' => 'Server error' ) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertCount( 1, $GLOBALS['kpay_test_http_requests'], 'Aucun retrait sans solde vérifié.' );
	}

	/**
	 * Wallet inexistant : sans correspondance, aucun contrôle ne s'appliquait
	 * et le retrait passait. Il doit désormais être refusé.
	 */
	public function test_nonexistent_wallet_blocks_withdrawal(): void {
		$this->post_withdrawal( array( 'wallet_currency' => 'KES' ) );

		// Le compte ne détient qu'un wallet XAF.
		kpay_test_queue_response( 200, array(
			array( 'currency' => 'XAF', 'balance' => 150000, 'reservedBalance' => 0, 'availableBalance' => 125000 ),
		) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'KES', $notice['message'] );
		$this->assertCount( 1, $GLOBALS['kpay_test_http_requests'] );
	}

	/** Le cas nominal doit continuer de fonctionner. */
	public function test_valid_withdrawal_still_passes(): void {
		$this->post_withdrawal();

		kpay_test_queue_response( 200, array(
			array( 'currency' => 'XAF', 'balance' => 150000, 'reservedBalance' => 0, 'availableBalance' => 125000 ),
		) );
		kpay_test_queue_response( 201, array(
			'id' => 'wdr_1', 'reference' => 'KPAY-WD-1', 'status' => 'PENDING',
			'amount' => 5000, 'netAmount' => 4750, 'currency' => 'XAF',
		) );

		$notice = $this->submit();

		$this->assertSame( 'success', $notice['type'] );
		$this->assertCount( 2, $GLOBALS['kpay_test_http_requests'] );
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

	/** Sans horodatage, la signature reste la protection principale. */
	public function test_missing_timestamp_still_verifies_signature(): void {
		$order = $this->pending_order();

		$body = json_encode( array(
			'event'     => 'payment.completed',
			'paymentId' => 'pay_abc123',
			'status'    => 'COMPLETED',
			'metadata'  => array( 'orderId' => '42' ),
		) );

		$this->assertSame( 200, $this->send( $body )->get_status() );

		// Mais une signature invalide reste refusée.
		$order2 = kpay_test_add_order( new WC_Order( 43 ) );
		$response = WC_KPay_Webhook::handle_webhook(
			new WP_REST_Request( $body, array( 'X-KPAY-Signature' => str_repeat( 'a', 64 ) ) )
		);
		$this->assertSame( 401, $response->get_status() );
	}

	// ---------------------------------------------------------------
	// Historique tolérant aux lignes anciennes
	// ---------------------------------------------------------------

	/**
	 * Une ligne écrite par une version antérieure ne doit pas produire
	 * d'avertissement PHP à l'affichage.
	 */
	public function test_history_row_missing_keys_does_not_warn(): void {
		$GLOBALS['kpay_test_options']['kpay_withdrawal_history_sandbox'] = array(
			array( 'id' => 'wdr_ancien', 'reference' => 'REF-ANCIENNE' ), // ligne incomplète
		);

		// La page interroge les soldes avant d'afficher l'historique.
		kpay_test_queue_response( 200, array(
			array( 'currency' => 'XAF', 'balance' => 1000, 'reservedBalance' => 0, 'availableBalance' => 1000 ),
		) );

		$errors = array();
		set_error_handler( function ( $no, $str ) use ( &$errors ) {
			$errors[] = $str;
			return true;
		} );

		ob_start();
		WC_KPay_Admin::render_payouts_page();
		$html = ob_get_clean();

		restore_error_handler();

		$this->assertEmpty( $errors, 'Aucun avertissement PHP attendu : ' . implode( ' | ', $errors ) );
		$this->assertStringContainsString( 'REF-ANCIENNE', $html, 'La ligne incomplète doit tout de même s\'afficher.' );
		// Les champs manquants sont remplacés par un tiret, pas par du vide.
		$this->assertStringContainsString( '—', $html );
	}
}
