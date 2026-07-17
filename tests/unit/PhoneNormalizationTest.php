<?php
/**
 * Normalisation des numéros vers le format international attendu par l'API.
 */

use PHPUnit\Framework\TestCase;

final class PhoneNormalizationTest extends TestCase {

	/**
	 * Numéros de test officiels de la spec : ils doivent traverser la
	 * normalisation sans être altérés, sinon le sandbox ne les reconnaît pas.
	 *
	 * @dataProvider spec_test_numbers
	 */
	public function test_spec_sandbox_numbers_pass_through( $number, $provider ): void {
		$this->assertSame(
			$number,
			WC_KPay_API::normalize_phone( $number, $provider ),
			"Le numéro de test {$number} doit être transmis tel quel."
		);
	}

	public static function spec_test_numbers(): array {
		return array(
			// Cameroun (deposits)
			'CMR COMPLETED'          => array( '237653456789', 'MTN_MOMO_CMR' ),
			'CMR SUBMITTED'          => array( '237653456129', 'MTN_MOMO_CMR' ),
			'CMR PAYER_NOT_FOUND'    => array( '237653456029', 'MTN_MOMO_CMR' ),
			'CMR NOT_APPROVED'       => array( '237653456039', 'ORANGE_CMR' ),
			'CMR LIMIT_REACHED'      => array( '237653456019', 'ORANGE_CMR' ),
			'CMR exemple doc'        => array( '237670000001', 'MTN_MOMO_CMR' ),
			// Autres pays
			'BEN COMPLETED'          => array( '22951345789', 'MTN_MOMO_BEN' ),
			'CIV COMPLETED'          => array( '2250503456789', 'MTN_MOMO_CIV' ),
			'COD COMPLETED'          => array( '243813456789', 'AIRTEL_COD' ),
			'GAB COMPLETED'          => array( '24174345678', 'AIRTEL_GAB' ),
			'KEN COMPLETED'          => array( '254703456789', 'MPESA_KEN' ),
			'COG COMPLETED'          => array( '242053456789', 'MTN_MOMO_COG' ),
			'RWA COMPLETED'          => array( '250733456789', 'MTN_MOMO_RWA' ),
			'SEN COMPLETED'          => array( '221763456789', 'ORANGE_SEN' ),
			'SLE COMPLETED'          => array( '23276123456', 'ORANGE_SLE' ),
			'UGA COMPLETED'          => array( '256753456789', 'MTN_MOMO_UGA' ),
			'ZMB COMPLETED'          => array( '260973456789', 'MTN_MOMO_ZMB' ),
		);
	}

	/**
	 * Formats de saisie usuels d'un client camerounais.
	 *
	 * @dataProvider cameroon_input_formats
	 */
	public function test_cameroon_input_formats( $input, $expected ): void {
		$this->assertSame( $expected, WC_KPay_API::normalize_phone( $input, 'MTN_MOMO_CMR' ) );
	}

	public static function cameroon_input_formats(): array {
		return array(
			'national'            => array( '653456789', '237653456789' ),
			'zéro initial'        => array( '0653456789', '237653456789' ),
			'indicatif +'         => array( '+237653456789', '237653456789' ),
			'espaces'             => array( '237 653 456 789', '237653456789' ),
			'espaces national'    => array( '6 53 45 67 89', '237653456789' ),
			'tirets'              => array( '237-653-456-789', '237653456789' ),
			'points'              => array( '6.53.45.67.89', '237653456789' ),
			'parenthèses'         => array( '(237) 653456789', '237653456789' ),
			'00 international'    => array( '00237653456789', '237653456789' ),
			'espaces en trop'     => array( '  237653456789  ', '237653456789' ),
		);
	}

	/**
	 * @dataProvider invalid_inputs
	 */
	public function test_invalid_inputs_are_rejected( $input ): void {
		$this->assertFalse( WC_KPay_API::normalize_phone( $input, 'MTN_MOMO_CMR' ) );
	}

	public static function invalid_inputs(): array {
		return array(
			'vide'            => array( '' ),
			'espaces'         => array( '   ' ),
			'lettres'         => array( 'abcdefghi' ),
			'trop court'      => array( '123' ),
			'trop court 2'    => array( '65345' ),
			'trop long'       => array( '2376534567890123456' ),
			'zéros'           => array( '000000' ),
			'symboles'        => array( '+++---' ),
		);
	}

	public function test_unknown_provider_is_rejected(): void {
		$this->assertFalse( WC_KPay_API::normalize_phone( '653456789', 'PROVIDER_INEXISTANT' ) );
		$this->assertFalse( WC_KPay_API::normalize_phone( '653456789', 'ORANGE_MONEY_CMR' ) );
		$this->assertFalse( WC_KPay_API::normalize_phone( '653456789', '' ) );
	}

	/** Le résultat ne contient jamais de + ni de séparateur (spec). */
	public function test_output_is_digits_only(): void {
		foreach ( self::cameroon_input_formats() as $case ) {
			$result = WC_KPay_API::normalize_phone( $case[0], 'MTN_MOMO_CMR' );
			$this->assertMatchesRegularExpression( '/^\d+$/', $result );
		}
	}

	/** Le résultat porte toujours l'indicatif du pays du provider. */
	public function test_output_always_carries_country_prefix(): void {
		$cases = array(
			'MTN_MOMO_CMR' => array( '653456789', '237' ),
			'ORANGE_SEN'   => array( '763456789', '221' ),
			'MPESA_KEN'    => array( '703456789', '254' ),
			'MTN_MOMO_CIV' => array( '0503456789', '225' ),
		);

		foreach ( $cases as $provider => list( $input, $prefix ) ) {
			$result = WC_KPay_API::normalize_phone( $input, $provider );
			$this->assertStringStartsWith( $prefix, $result, "Indicatif manquant pour {$provider}." );
		}
	}

	/** Un numéro déjà normalisé ne doit pas être re-préfixé. */
	public function test_normalization_is_idempotent(): void {
		$once  = WC_KPay_API::normalize_phone( '653456789', 'MTN_MOMO_CMR' );
		$twice = WC_KPay_API::normalize_phone( $once, 'MTN_MOMO_CMR' );

		$this->assertSame( $once, $twice );
		$this->assertSame( '237653456789', $twice );
	}
}
