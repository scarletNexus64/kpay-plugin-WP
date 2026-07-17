<?php
/**
 * Garde-fous du formulaire de retrait.
 *
 * Un retrait est irréversible : chaque protection est vérifiée par le
 * scénario qu'elle est censée empêcher.
 */

use PHPUnit\Framework\TestCase;

final class PayoutFormTest extends TestCase {

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
		$GLOBALS['kpay_test_transients']       = array();
	}

	/** Requête de retrait valide, surchargeable par test. */
	private function post( array $overrides = array() ) {
		$_POST = array_merge( array(
			'_wpnonce'        => wp_create_nonce( 'kpay_withdraw' ),
			'wallet_currency' => 'XAF',
			'provider'        => 'MTN_MOMO_CMR',
			'phone'           => '237653456789',
			'amount'          => '5000',
			'description'     => 'Test',
			'confirm'         => '1',
		), $overrides );
	}

	private function queue_balances() {
		kpay_test_queue_response( 200, array(
			array( 'currency' => 'XAF', 'balance' => 150000, 'reservedBalance' => 25000, 'availableBalance' => 125000 ),
		) );
	}

	/**
	 * Exécute la demande de retrait et renvoie la notice produite.
	 * handle_withdrawal() se termine par une redirection.
	 */
	private function submit() {
		try {
			WC_KPay_Admin::handle_withdrawal();
		} catch ( KPay_Test_Redirect $r ) {
			return get_transient( 'kpay_admin_notice_1' );
		}
		$this->fail( 'Aucune redirection : le traitement ne s\'est pas terminé.' );
	}

	// --- Garde-fous ---

	/** Sans la case de confirmation, aucun appel ne doit partir. */
	public function test_unconfirmed_withdrawal_is_blocked(): void {
		$this->post( array( 'confirm' => '' ) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'confirmer', $notice['message'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'], 'Aucun appel API sans confirmation.' );
	}

	public function test_invalid_nonce_is_rejected(): void {
		$this->post( array( '_wpnonce' => 'faux' ) );

		$this->expectException( Exception::class );
		WC_KPay_Admin::handle_withdrawal();
	}

	/** Un utilisateur sans droits ne doit pas pouvoir envoyer de fonds. */
	public function test_unauthorized_user_is_blocked(): void {
		$GLOBALS['kpay_test_current_user_can'] = false;
		$this->post();

		$this->expectException( Exception::class );
		WC_KPay_Admin::handle_withdrawal();
	}

	public function test_invalid_provider_is_rejected(): void {
		$this->post( array( 'provider' => 'PROVIDER_BIDON' ) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	/** Un numéro mal formé ne doit jamais atteindre l'API. */
	public function test_invalid_phone_is_rejected(): void {
		$this->post( array( 'phone' => '123' ) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'Numéro invalide', $notice['message'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	/** Numéro d'un autre pays que l'opérateur choisi. */
	public function test_phone_from_wrong_country_is_rejected(): void {
		$this->post( array( 'provider' => 'MTN_MOMO_CMR', 'phone' => 'abcdef' ) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	/** Spec : minimum 100 XAF. */
	public function test_amount_below_minimum_is_rejected(): void {
		$this->post( array( 'amount' => '50' ) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( '100', $notice['message'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	public function test_zero_amount_is_rejected(): void {
		$this->post( array( 'amount' => '0' ) );

		$notice = $this->submit();
		$this->assertSame( 'error', $notice['type'] );
	}

	public function test_negative_amount_is_rejected(): void {
		$this->post( array( 'amount' => '-5000' ) );

		$notice = $this->submit();
		$this->assertSame( 'error', $notice['type'] );
		$this->assertCount( 0, $GLOBALS['kpay_test_http_requests'] );
	}

	/**
	 * Montant supérieur au solde : bloqué avant l'appel, avec un message
	 * chiffré plutôt qu'un 422 opaque.
	 */
	public function test_amount_above_available_balance_is_blocked(): void {
		$this->post( array( 'amount' => '999999' ) );
		$this->queue_balances();

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'insuffisant', $notice['message'] );
		// Seul l'appel de solde a eu lieu, pas le retrait.
		$this->assertCount( 1, $GLOBALS['kpay_test_http_requests'] );
	}

	// --- Cas nominal ---

	public function test_valid_withdrawal_calls_api_with_spec_fields(): void {
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array(
			'id'        => 'wdr_1',
			'reference' => 'KPAY-WD-1',
			'status'    => 'PENDING',
			'amount'    => 5000,
			'netAmount' => 4750,
			'feeAmount' => 250,
			'currency'  => 'XAF',
			'isTest'    => true,
		) );

		$notice = $this->submit();

		$this->assertSame( 'success', $notice['type'] );

		$withdrawal = $GLOBALS['kpay_test_http_requests'][1];
		$body       = json_decode( $withdrawal['args']['body'], true );

		$this->assertSame( 'https://admin.kpay.site/api/v1/payments/withdraw', $withdrawal['url'] );
		$this->assertSame( 5000, $body['amount'] );
		$this->assertSame( 'MTN_MOMO_CMR', $body['provider'] );
		$this->assertSame( '237653456789', $body['phoneNumber'] );
		$this->assertStringStartsWith( 'WD-', $body['externalId'] );
		$this->assertSame( 'Test', $body['description'] );
	}

	/** Le montant net (après commission) est annoncé au marchand. */
	public function test_success_notice_reports_net_amount(): void {
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array(
			'id' => 'wdr_1', 'reference' => 'KPAY-WD-1', 'status' => 'PENDING',
			'amount' => 5000, 'netAmount' => 4750, 'currency' => 'XAF',
		) );

		$notice = $this->submit();

		$this->assertStringContainsString( '4,750 XAF', $notice['message'] );
		$this->assertStringContainsString( 'KPAY-WD-1', $notice['message'] );
	}

	/** Chaque retrait porte un externalId unique : pas de 409 au second envoi. */
	public function test_external_id_is_unique_per_withdrawal(): void {
		$ids = array();

		for ( $i = 0; $i < 3; $i++ ) {
			$GLOBALS['kpay_test_http_requests'] = array();
			$this->post();
			$this->queue_balances();
			kpay_test_queue_response( 201, array( 'id' => 'wdr_' . $i, 'status' => 'PENDING', 'currency' => 'XAF' ) );

			$this->submit();

			$body  = json_decode( $GLOBALS['kpay_test_http_requests'][1]['args']['body'], true );
			$ids[] = $body['externalId'];
		}

		$this->assertCount( 3, array_unique( $ids ) );
	}

	/** Un montant XAF est transmis en entier (spec : sans décimales). */
	public function test_xaf_amount_is_sent_as_integer(): void {
		$this->post( array( 'amount' => '5000.75' ) );
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_1', 'status' => 'PENDING', 'currency' => 'XAF' ) );

		$this->submit();

		$body = json_decode( $GLOBALS['kpay_test_http_requests'][1]['args']['body'], true );
		$this->assertSame( 5001, $body['amount'] );
	}

	/** Retrait cross-country : sourceCountry est transmis. */
	public function test_cross_country_withdrawal_sends_source_country(): void {
		// Wallet XAF débité, bénéficiaire en Côte d'Ivoire (XOF).
		$this->post( array( 'wallet_currency' => 'XAF', 'provider' => 'MTN_MOMO_CIV', 'phone' => '2250503456789' ) );
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_x', 'status' => 'PENDING', 'currency' => 'XAF' ) );

		$this->submit();

		$body = json_decode( $GLOBALS['kpay_test_http_requests'][1]['args']['body'], true );
		$this->assertArrayHasKey( 'sourceCountry', $body );
		$this->assertContains( $body['sourceCountry'], array( 'CMR', 'GAB', 'COG' ) );
	}

	/** Même devise : sourceCountry est inutile et ne doit pas être envoyé. */
	public function test_same_currency_withdrawal_omits_source_country(): void {
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_1', 'status' => 'PENDING', 'currency' => 'XAF' ) );

		$this->submit();

		$body = json_decode( $GLOBALS['kpay_test_http_requests'][1]['args']['body'], true );
		$this->assertArrayNotHasKey( 'sourceCountry', $body );
	}

	// --- Erreurs API ---

	public function test_api_failure_is_reported_to_merchant(): void {
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 422, array(
			'statusCode' => 422,
			'message'    => 'Insufficient wallet balance',
			'error'      => 'Unprocessable Entity',
		) );

		$notice = $this->submit();

		$this->assertSame( 'error', $notice['type'] );
		$this->assertStringContainsString( 'Insufficient wallet balance', $notice['message'] );
	}

	/** Un retrait échoué ne doit pas figurer dans l'historique. */
	public function test_failed_withdrawal_is_not_recorded(): void {
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 500, array( 'statusCode' => 500, 'message' => 'Provider error' ) );

		$this->submit();

		$this->assertEmpty( get_option( 'kpay_withdrawal_history', array() ) );
	}

	// --- Historique ---

	public function test_successful_withdrawal_is_recorded(): void {
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array(
			'id' => 'wdr_1', 'reference' => 'KPAY-WD-1', 'status' => 'PENDING',
			'amount' => 5000, 'currency' => 'XAF', 'isTest' => true,
		) );

		$this->submit();

		$history = get_option( 'kpay_withdrawal_history_sandbox', array() );

		$this->assertCount( 1, $history );
		$this->assertSame( 'wdr_1', $history[0]['id'] );
		$this->assertSame( '237653456789', $history[0]['phone'] );
		$this->assertSame( 'MTN_MOMO_CMR', $history[0]['provider'] );
		$this->assertSame( 'sandbox', $history[0]['environment'] );
		$this->assertTrue( $history[0]['isTest'] );
	}

	/**
	 * Isolation test/live : un retrait sandbox ne doit jamais atterrir dans
	 * l'historique de production.
	 */
	public function test_sandbox_withdrawal_never_lands_in_live_history(): void {
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_test', 'status' => 'PENDING', 'currency' => 'XAF' ) );

		$this->submit();

		$this->assertCount( 1, get_option( 'kpay_withdrawal_history_sandbox', array() ) );
		$this->assertEmpty(
			get_option( 'kpay_withdrawal_history_live', array() ),
			'Un retrait de test ne doit pas apparaître dans l\'historique de production.'
		);
	}

	public function test_live_withdrawal_never_lands_in_sandbox_history(): void {
		kpay_test_settings( array(
			'environment'     => 'live',
			'live_api_key'    => 'kpay_live_x',
			'live_secret_key' => 'sk_live_x',
		) );

		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_reel', 'status' => 'PENDING', 'currency' => 'XAF' ) );

		$this->submit();

		$live = get_option( 'kpay_withdrawal_history_live', array() );

		$this->assertCount( 1, $live );
		$this->assertSame( 'live', $live[0]['environment'] );
		$this->assertEmpty(
			get_option( 'kpay_withdrawal_history_sandbox', array() ),
			'Un retrait réel ne doit pas apparaître dans l\'historique de test.'
		);
	}

	/** Les deux historiques coexistent sans se mélanger. */
	public function test_both_histories_coexist_independently(): void {
		// Un retrait en sandbox.
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_sandbox', 'status' => 'PENDING', 'currency' => 'XAF' ) );
		$this->submit();

		// Puis un retrait en live.
		kpay_test_settings( array(
			'environment'     => 'live',
			'live_api_key'    => 'kpay_live_x',
			'live_secret_key' => 'sk_live_x',
		) );
		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_live', 'status' => 'PENDING', 'currency' => 'XAF' ) );
		$this->submit();

		$sandbox = get_option( 'kpay_withdrawal_history_sandbox', array() );
		$live    = get_option( 'kpay_withdrawal_history_live', array() );

		$this->assertCount( 1, $sandbox );
		$this->assertCount( 1, $live );
		$this->assertSame( 'wdr_sandbox', $sandbox[0]['id'] );
		$this->assertSame( 'wdr_live', $live[0]['id'] );
	}

	/** L'historique est plafonné : il ne doit pas gonfler indéfiniment. */
	public function test_history_is_capped(): void {
		$GLOBALS['kpay_test_options']['kpay_withdrawal_history_sandbox'] = array_fill( 0, 50, array(
			'date' => '2026-01-01 00:00:00', 'id' => 'old', 'reference' => 'r',
			'phone' => '237653456789', 'provider' => 'MTN_MOMO_CMR',
			'amount' => 100, 'currency' => 'XAF', 'status' => 'COMPLETED',
			'environment' => 'sandbox', 'isTest' => true,
		) );

		$this->post();
		$this->queue_balances();
		kpay_test_queue_response( 201, array( 'id' => 'wdr_new', 'status' => 'PENDING', 'currency' => 'XAF' ) );

		$this->submit();

		$history = get_option( 'kpay_withdrawal_history_sandbox', array() );

		$this->assertCount( 50, $history );
		$this->assertSame( 'wdr_new', $history[0]['id'], 'Le plus récent doit être en tête.' );
	}

	/** Les soldes sont mis en cache séparément par environnement. */
	public function test_balance_cache_is_isolated_per_environment(): void {
		kpay_test_queue_response( 200, array(
			array( 'currency' => 'XAF', 'balance' => 100, 'reservedBalance' => 0, 'availableBalance' => 100 ),
		) );
		WC_KPay_Admin::get_balances();

		$this->assertNotFalse( get_transient( 'kpay_balances_sandbox' ) );
		$this->assertFalse(
			get_transient( 'kpay_balances_live' ),
			'Le solde sandbox ne doit pas alimenter le cache de production.'
		);
	}
}
