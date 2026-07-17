<?php
/**
 * Logo et diagnostics affichés en administration.
 */

use PHPUnit\Framework\TestCase;

final class BrandingTest extends TestCase {

	protected function setUp(): void {
		kpay_test_reset();
		kpay_test_settings();
	}

	private function gateway( array $settings = array(), $currency = 'XAF' ) {
		$GLOBALS['kpay_test_currency'] = $currency;
		kpay_test_settings( $settings );
		return new WC_KPay_Gateway();
	}

	// --- Logo ---

	public function test_gateway_declares_svg_icon(): void {
		$gateway = $this->gateway();

		$this->assertStringEndsWith( 'assets/images/kpay-logo-dark.png', $gateway->icon );
	}

	/**
	 * Le logo officiel doit exister dans ses deux variantes et être
	 * transparent : un fond opaque produirait un rectangle noir sur l'admin.
	 */
	public function test_logo_files_are_transparent_png(): void {
		foreach ( array( 'kpay-logo.png', 'kpay-logo-dark.png' ) as $file ) {
			$path = WC_KPAY_PLUGIN_DIR . 'assets/images/' . $file;
			$this->assertFileExists( $path );

			$info = getimagesize( $path );
			$this->assertSame( IMAGETYPE_PNG, $info[2], "{$file} doit être un PNG." );

			// Octet 25 de l'en-tête IHDR : 6 = RGBA, 4 = gris+alpha.
			$ctype = ord( file_get_contents( $path, false, null, 25, 1 ) );
			$this->assertContains( $ctype, array( 4, 6 ), "{$file} doit avoir un canal alpha." );
		}
	}

	/** L'icône doit être bornée en hauteur, sinon un SVG s'étire. */
	public function test_checkout_icon_html_is_size_constrained(): void {
		$html = $this->gateway()->get_icon();

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'kpay-logo-dark.png', $html );
		$this->assertMatchesRegularExpression( '/max-height\s*:\s*\d+px/', $html );
	}

	public function test_checkout_icon_is_escaped(): void {
		$html = $this->gateway()->get_icon();

		$this->assertStringNotContainsString( '"><script', $html );
		$this->assertStringContainsString( 'alt=', $html, 'Une image décorative doit porter un attribut alt.' );
	}

	// --- Diagnostics en administration ---

	/** Une devise non prise en charge doit être expliquée, pas subie. */
	public function test_admin_warns_about_unsupported_currency(): void {
		$gateway = $this->gateway( array(), 'EUR' );

		ob_start();
		$gateway->admin_options();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'EUR', $output );
		$this->assertStringContainsString( 'notice-warning', $output );
		// Le message doit dire qu'aucune conversion n'a lieu.
		$this->assertStringContainsString( 'conversion', $output );
	}

	/** Devise supportée mais aucun opérateur correspondant sélectionné. */
	public function test_admin_warns_when_no_provider_matches_currency(): void {
		$gateway = $this->gateway( array( 'providers' => array( 'MTN_MOMO_CMR' ) ), 'XOF' );

		ob_start();
		$gateway->admin_options();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
		$this->assertStringContainsString( 'XOF', $output );
	}

	public function test_admin_warns_about_missing_keys(): void {
		$gateway = $this->gateway( array(
			'sandbox_api_key'    => '',
			'sandbox_secret_key' => '',
		) );

		ob_start();
		$gateway->admin_options();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
	}

	/** Configuration correcte : aucun avertissement parasite. */
	public function test_admin_shows_no_warning_when_configured(): void {
		$gateway = $this->gateway();

		ob_start();
		$gateway->admin_options();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'notice-warning', $output );
	}

	/** Passerelle désactivée : pas de diagnostic, elle n'est pas censée servir. */
	public function test_admin_shows_no_warning_when_disabled(): void {
		$gateway = $this->gateway( array( 'enabled' => 'no' ), 'EUR' );

		ob_start();
		$gateway->admin_options();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'notice-warning', $output );
	}

	public function test_admin_page_shows_logo(): void {
		$gateway = $this->gateway();

		ob_start();
		$gateway->admin_options();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'kpay-logo-dark.png', $output );
	}
}
