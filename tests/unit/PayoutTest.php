<?php
/**
 * Soldes et retraits.
 *
 * Les retraits déplacent de l'argent réel en production : les garde-fous
 * (confirmation, solde, minimum, numéro) sont testés autant que le succès.
 */

use PHPUnit\Framework\TestCase;

final class PayoutTest extends TestCase {

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
		$GLOBALS['kpay_test_redirect']         = null;
	}

	private function api() {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return $gateways['kpay']->get_api();
	}

	private function balances_response() {
		return array(
			array( 'currency' => 'XAF', 'balance' => 150000, 'reservedBalance' => 25000, 'availableBalance' => 125000 ),
			array( 'currency' => 'XOF', 'balance' => 80000, 'reservedBalance' => 0, 'availableBalance' => 80000 ),
		);
	}

	// --- API : soldes ---

	public function test_balance_endpoint_matches_spec(): void {
		kpay_test_queue_response( 200, $this->balances_response() );

		$this->api()->get_balances();

		$request = $GLOBALS['kpay_test_http_requests'][0];
		$this->assertSame( 'https://admin.kpay.site/api/v1/payments/balance', $request['url'] );
		$this->assertSame( 'GET', $request['args']['method'] );
		$this->assertSame( 'kpay_test_abc', $request['args']['headers']['X-API-Key'] );
	}

	public function test_balances_are_returned_per_currency(): void {
		kpay_test_queue_response( 200, $this->balances_response() );

		$balances = $this->api()->get_balances();

		$this->assertCount( 2, $balances );
		$this->assertSame( 'XAF', $balances[0]['currency'] );
		$this->assertSame( 125000, $balances[0]['availableBalance'] );
	}

	// --- API : retraits ---

	public function test_withdrawal_endpoint_matches_spec(): void {
		kpay_test_queue_response( 201, array( 'id' => 'wdr_1', 'status' => 'PENDING' ) );

		$this->api()->init_withdrawal( array(
			'amount'      => 5000,
			'provider'    => 'MTN_MOMO_CMR',
			'phoneNumber' => '237653456789',
			'externalId'  => 'WD-1',
		) );

		$request = $GLOBALS['kpay_test_http_requests'][0];
		$body    = json_decode( $request['args']['body'], true );

		$this->assertSame( 'https://admin.kpay.site/api/v1/payments/withdraw', $request['url'] );
		$this->assertSame( 'POST', $request['args']['method'] );

		// Noms de champs exacts de la spec.
		$this->assertArrayHasKey( 'phoneNumber', $body );
		$this->assertArrayHasKey( 'externalId', $body );
		$this->assertArrayNotHasKey( 'phone', $body );
	}

	public function test_withdrawal_status_endpoint_matches_spec(): void {
		kpay_test_queue_response( 200, array( 'id' => 'wdr_xyz456', 'status' => 'COMPLETED' ) );

		$this->api()->get_withdrawal( 'wdr_xyz456' );

		$this->assertSame(
			'https://admin.kpay.site/api/v1/payments/withdraw/wdr_xyz456',
			$GLOBALS['kpay_test_http_requests'][0]['url']
		);
	}

	// --- Numéros de test de retrait (spec) ---

	/**
	 * Les numéros de retrait diffèrent de ceux des paiements : ils doivent
	 * traverser la normalisation intacts pour que le sandbox les reconnaisse.
	 *
	 * @dataProvider payout_test_numbers
	 */
	public function test_payout_sandbox_numbers_pass_through( $number, $provider, $expected_outcome ): void {
		$this->assertSame(
			$number,
			WC_KPay_API::normalize_phone( $number, $provider ),
			"Le numéro de retrait {$number} ({$expected_outcome}) doit être transmis tel quel."
		);
	}

	public static function payout_test_numbers(): array {
		return array(
			// Cameroun — retraits (payouts)
			'CMR COMPLETED'            => array( '237653456789', 'MTN_MOMO_CMR', 'COMPLETED' ),
			'CMR SUBMITTED'            => array( '237653456129', 'MTN_MOMO_CMR', 'SUBMITTED' ),
			'CMR RECIPIENT_NOT_FOUND'  => array( '237653456089', 'ORANGE_CMR', 'FAILED' ),
			'CMR UNSPECIFIED_FAILURE'  => array( '237653456119', 'ORANGE_CMR', 'FAILED' ),
			// Autres pays
			'BEN RECIPIENT_NOT_FOUND'  => array( '22951345089', 'MTN_MOMO_BEN', 'FAILED' ),
			'CIV COMPLETED'            => array( '2250503456789', 'MTN_MOMO_CIV', 'COMPLETED' ),
			'GAB RECIPIENT_NOT_FOUND'  => array( '24174345088', 'AIRTEL_GAB', 'FAILED' ),
			'KEN WALLET_LIMIT'         => array( '254703456099', 'MPESA_KEN', 'FAILED' ),
			'KEN RECIPIENT_LIMIT'      => array( '254703456109', 'MPESA_KEN', 'FAILED' ),
			'SEN COMPLETED'            => array( '221763456789', 'ORANGE_SEN', 'COMPLETED' ),
			'UGA WALLET_LIMIT'         => array( '256753456099', 'MTN_MOMO_UGA', 'FAILED' ),
			'ZMB RECIPIENT_NOT_FOUND'  => array( '260973456089', 'MTN_MOMO_ZMB', 'FAILED' ),
		);
	}

	// --- Correspondance pays / devise ---

	public function test_provider_country_mapping(): void {
		$this->assertSame( 'CMR', WC_KPay_API::get_provider_country( 'MTN_MOMO_CMR' ) );
		$this->assertSame( 'CMR', WC_KPay_API::get_provider_country( 'ORANGE_CMR' ) );
		$this->assertSame( 'SEN', WC_KPay_API::get_provider_country( 'ORANGE_SEN' ) );
		$this->assertSame( 'KEN', WC_KPay_API::get_provider_country( 'MPESA_KEN' ) );
		$this->assertSame( 'ZMB', WC_KPay_API::get_provider_country( 'ZAMTEL_ZMB' ) );
		$this->assertSame( '', WC_KPay_API::get_provider_country( 'INEXISTANT' ) );
	}

	/** Chaque provider du catalogue doit être rattaché à un pays. */
	public function test_every_provider_has_a_country(): void {
		foreach ( array_keys( WC_KPay_API::get_providers() ) as $code ) {
			$this->assertNotSame(
				'',
				WC_KPay_API::get_provider_country( $code ),
				"Le provider {$code} n'est rattaché à aucun pays."
			);
		}
	}

	/** Une devise de zone couvre plusieurs pays : c'est ce qui rend le
	 *  libellé « solde XAF » ambigu sans précision. */
	public function test_zone_currencies_cover_multiple_countries(): void {
		$xaf = WC_KPay_API::get_countries_for_currency( 'XAF' );

		$this->assertContains( 'CMR', $xaf );
		$this->assertContains( 'GAB', $xaf );
		$this->assertContains( 'COG', $xaf );

		$xof = WC_KPay_API::get_countries_for_currency( 'XOF' );
		$this->assertContains( 'SEN', $xof );
		$this->assertContains( 'CIV', $xof );
		$this->assertContains( 'BEN', $xof );

		// Une devise nationale ne couvre qu'un pays.
		$this->assertSame( array( 'KEN' ), WC_KPay_API::get_countries_for_currency( 'KES' ) );
	}

	public function test_country_labels_are_translated(): void {
		$this->assertSame( 'Cameroun', WC_KPay_API::get_country_label( 'CMR' ) );
		$this->assertSame( 'Sénégal', WC_KPay_API::get_country_label( 'SEN' ) );
		// Un code inconnu est renvoyé tel quel plutôt que masqué.
		$this->assertSame( 'ZZZ', WC_KPay_API::get_country_label( 'ZZZ' ) );
	}

	// --- Formatage ---

	public function test_amount_formatting_respects_currency_decimals(): void {
		// XAF : sans décimales (spec).
		$this->assertSame( '125,000 XAF', WC_KPay_Admin::format_amount( 125000, 'XAF' ) );
		$this->assertSame( '100 XOF', WC_KPay_Admin::format_amount( 100, 'XOF' ) );
		// KES : décimales.
		$this->assertSame( '1,500.50 KES', WC_KPay_Admin::format_amount( 1500.5, 'KES' ) );
	}

	public function test_minimum_withdrawal_matches_spec(): void {
		$this->assertSame( 100, WC_KPay_API::get_minimum_withdrawal( 'XAF' ) );
		$this->assertSame( 100, WC_KPay_API::get_minimum_withdrawal( 'XOF' ) );
	}

	// --- Erreurs de l'API ---

	/** Spec : 422 si le solde ne couvre pas le montant. */
	public function test_insufficient_balance_returns_error(): void {
		kpay_test_queue_response( 422, array(
			'statusCode' => 422,
			'message'    => 'Insufficient wallet balance',
			'error'      => 'Unprocessable Entity',
		) );

		$result = $this->api()->init_withdrawal( array( 'amount' => 999999, 'provider' => 'MTN_MOMO_CMR' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kpay_api_error_422', $result->get_error_code() );
		$this->assertStringContainsString( 'Insufficient', $result->get_error_message() );
	}

	/** Spec : 409 si l'externalId est déjà actif. */
	public function test_duplicate_external_id_returns_conflict(): void {
		kpay_test_queue_response( 409, array(
			'statusCode' => 409,
			'message'    => 'External ID already in use',
			'error'      => 'Conflict',
		) );

		$result = $this->api()->init_withdrawal( array( 'amount' => 5000, 'externalId' => 'WD-1' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'kpay_api_error_409', $result->get_error_code() );
	}

	public function test_withdrawal_response_exposes_fee_and_net(): void {
		kpay_test_queue_response( 201, array(
			'id'        => 'wdr_xyz456',
			'reference' => 'KPAY-WD-1',
			'status'    => 'PENDING',
			'amount'    => 5000,
			'netAmount' => 4750,
			'feeAmount' => 250,
			'currency'  => 'XAF',
		) );

		$result = $this->api()->init_withdrawal( array( 'amount' => 5000 ) );

		$this->assertSame( 4750, $result['netAmount'] );
		$this->assertSame( 250, $result['feeAmount'] );
	}

	/** Payout cross-devise : la réponse porte le taux et le montant converti. */
	public function test_cross_currency_payout_exposes_conversion(): void {
		kpay_test_queue_response( 201, array(
			'id'             => 'wdr_cross',
			'status'         => 'PENDING',
			'amount'         => 50000,
			'currency'       => 'XAF',
			'payoutCurrency' => 'XOF',
			'payoutAmount'   => 48211.75,
			'exchangeRate'   => 1.015,
		) );

		$result = $this->api()->init_withdrawal( array(
			'amount'        => 50000,
			'provider'      => 'MTN_MOMO_CIV',
			'sourceCountry' => 'CMR',
		) );

		$this->assertSame( 'XOF', $result['payoutCurrency'] );
		$this->assertSame( 1.015, $result['exchangeRate'] );
	}
}
