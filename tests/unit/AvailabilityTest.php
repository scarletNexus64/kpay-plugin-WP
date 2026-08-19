<?php
/**
 * Affichage de la passerelle et sélection des opérateurs selon la devise.
 *
 * C'est le symptôme d'origine : « je ne vois pas K-Pay dans WooCommerce ».
 */

use PHPUnit\Framework\TestCase;

final class AvailabilityTest extends TestCase {

	protected function setUp(): void {
		kpay_test_reset();
	}

	private function gateway( array $settings = array(), $currency = 'XAF' ) {
		$GLOBALS['kpay_test_currency'] = $currency;
		kpay_test_settings( $settings );
		return new WC_KPay_Gateway();
	}

	// --- Conditions d'affichage ---

	public function test_available_when_fully_configured(): void {
		$this->assertTrue( $this->gateway()->is_available() );
	}

	public function test_unavailable_when_disabled(): void {
		$this->assertFalse( $this->gateway( array( 'enabled' => 'no' ) )->is_available() );
	}

	public function test_unavailable_without_keys(): void {
		$gateway = $this->gateway( array(
			'sandbox_api_key'    => '',
			'sandbox_secret_key' => '',
		) );
		$this->assertFalse( $gateway->is_available(), 'Sans clés, la passerelle ne doit pas s\'afficher.' );
	}

	public function test_unavailable_with_partial_keys(): void {
		$gateway = $this->gateway( array( 'sandbox_secret_key' => '' ) );
		$this->assertFalse( $gateway->is_available() );
	}

	/** Les clés lues doivent être celles de l'environnement sélectionné. */
	public function test_live_mode_requires_live_keys(): void {
		$gateway = $this->gateway( array(
			'environment'    => 'live',
			'live_api_key'   => '',
			'live_secret_key' => '',
		) );
		$this->assertFalse( $gateway->is_available(), 'Les clés sandbox ne doivent pas servir en live.' );

		$gateway = $this->gateway( array(
			'environment'     => 'live',
			'live_api_key'    => 'kpay_live_abc',
			'live_secret_key' => 'f0e1d2c3b4a5968778695a4b3c2d1e0ff0e1d2c3b4a5968778695a4b3c2d1e0f',
		) );
		$this->assertTrue( $gateway->is_available() );
		$this->assertSame( 'live', $gateway->get_environment() );
	}

	// --- Devise ---

	/** Une devise sans opérateur correspondant masque la passerelle. */
	public function test_unavailable_for_unsupported_currency(): void {
		$gateway = $this->gateway( array(), 'EUR' );
		$this->assertFalse( $gateway->is_available(), 'K-Pay ne gère pas l\'EUR : la passerelle doit être masquée.' );
		$this->assertEmpty( $gateway->get_active_providers() );
	}

	public function test_unavailable_for_usd(): void {
		$this->assertFalse( $this->gateway( array(), 'USD' )->is_available() );
	}

	/** La boutique en XOF ne doit proposer que des opérateurs XOF. */
	public function test_xof_store_only_offers_xof_providers(): void {
		$gateway = $this->gateway(
			array( 'providers' => array( 'MTN_MOMO_CMR', 'ORANGE_CMR', 'ORANGE_SEN', 'MTN_MOMO_CIV' ) ),
			'XOF'
		);

		$active = array_keys( $gateway->get_active_providers() );

		$this->assertContains( 'ORANGE_SEN', $active );
		$this->assertContains( 'MTN_MOMO_CIV', $active );
		$this->assertNotContains( 'MTN_MOMO_CMR', $active, 'Un opérateur XAF ne doit pas apparaître sur une boutique XOF.' );
		$this->assertNotContains( 'ORANGE_CMR', $active );
	}

	public function test_xaf_store_only_offers_xaf_providers(): void {
		$gateway = $this->gateway(
			array( 'providers' => array( 'MTN_MOMO_CMR', 'ORANGE_SEN', 'MPESA_KEN' ) ),
			'XAF'
		);

		$active = array_keys( $gateway->get_active_providers() );

		$this->assertSame( array( 'MTN_MOMO_CMR' ), $active );
	}

	/** Chaque devise de la spec doit avoir au moins un opérateur. */
	public function test_every_supported_currency_has_providers(): void {
		foreach ( WC_KPay_API::get_supported_currencies() as $currency ) {
			$this->assertNotEmpty(
				WC_KPay_API::get_providers_for_currency( $currency ),
				"La devise {$currency} devrait avoir au moins un opérateur."
			);
		}
	}

	// --- Sélection des opérateurs (régression) ---

	/**
	 * Réglage jamais enregistré : la valeur par défaut du champ s'applique
	 * (MTN + Orange Cameroun), et la passerelle reste utilisable.
	 */
	public function test_unsaved_providers_setting_falls_back_to_field_default(): void {
		update_option( 'woocommerce_kpay_settings', array(
			'enabled'            => 'yes',
			'environment'        => 'sandbox',
			'sandbox_api_key'    => 'kpay_test_abc',
			'sandbox_secret_key' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
			// pas de clé 'providers'
		) );
		$GLOBALS['kpay_test_currency'] = 'XAF';

		$gateway = new WC_KPay_Gateway();

		$this->assertSame(
			array( 'MTN_MOMO_CMR', 'ORANGE_CMR' ),
			array_keys( $gateway->get_active_providers() )
		);
		$this->assertTrue( $gateway->is_available() );
	}

	/**
	 * Réglages totalement vides (aucun défaut appliqué) : on retombe sur
	 * tous les opérateurs de la devise plutôt que sur aucun.
	 */
	public function test_absent_providers_key_means_all(): void {
		$GLOBALS['kpay_test_currency'] = 'XAF';

		$gateway = new WC_KPay_Gateway();
		$gateway->settings = array(
			'enabled'            => 'yes',
			'environment'        => 'sandbox',
			'sandbox_api_key'    => 'kpay_test_abc',
			'sandbox_secret_key' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90',
		);

		$this->assertCount( 5, $gateway->get_active_providers(), 'Tous les opérateurs XAF attendus.' );
	}

	/**
	 * Régression : décocher tous les opérateurs ne doit pas les réactiver.
	 * WooCommerce enregistre une chaîne vide, pas un tableau vide.
	 */
	public function test_deselecting_all_providers_disables_gateway(): void {
		$gateway = $this->gateway( array( 'providers' => '' ) );

		$this->assertEmpty( $gateway->get_active_providers(), 'Tout décocher ne doit pas tout réactiver.' );
		$this->assertFalse( $gateway->is_available() );
	}

	public function test_empty_array_providers_disables_gateway(): void {
		$gateway = $this->gateway( array( 'providers' => array() ) );

		$this->assertEmpty( $gateway->get_active_providers() );
		$this->assertFalse( $gateway->is_available() );
	}

	public function test_single_provider_selection(): void {
		$gateway = $this->gateway( array( 'providers' => array( 'ORANGE_CMR' ) ) );

		$this->assertSame( array( 'ORANGE_CMR' ), array_keys( $gateway->get_active_providers() ) );
		$this->assertTrue( $gateway->is_available() );
	}

	// --- Catalogue conforme à la spec ---

	public function test_provider_catalogue_matches_spec(): void {
		$providers = WC_KPay_API::get_providers();

		// Codes exacts de la spec.
		$this->assertArrayHasKey( 'MTN_MOMO_CMR', $providers );
		$this->assertArrayHasKey( 'ORANGE_CMR', $providers );
		$this->assertArrayHasKey( 'VODACOM_MPESA_COD', $providers );
		$this->assertArrayHasKey( 'AIRTEL_OAPI_UGA', $providers );

		// Devises correctes.
		$this->assertSame( 'XAF', $providers['MTN_MOMO_CMR']['currency'] );
		$this->assertSame( 'XOF', $providers['ORANGE_SEN']['currency'] );
		$this->assertSame( 'KES', $providers['MPESA_KEN']['currency'] );

		// Indicatifs corrects.
		$this->assertSame( '237', $providers['MTN_MOMO_CMR']['prefix'] );
		$this->assertSame( '225', $providers['MTN_MOMO_CIV']['prefix'] );
		$this->assertSame( '254', $providers['MPESA_KEN']['prefix'] );
	}

	public function test_decimal_handling_matches_spec(): void {
		// Spec : Cameroun « sans décimales ».
		$this->assertTrue( WC_KPay_API::provider_uses_integer_amount( 'MTN_MOMO_CMR' ) );
		$this->assertTrue( WC_KPay_API::provider_uses_integer_amount( 'ORANGE_CMR' ) );

		// Spec : Gabon/Airtel « 2 décimales ».
		$this->assertFalse( WC_KPay_API::provider_uses_integer_amount( 'AIRTEL_GAB' ) );
		// Spec : Kenya « selon l'opération ».
		$this->assertFalse( WC_KPay_API::provider_uses_integer_amount( 'MPESA_KEN' ) );
	}
}
