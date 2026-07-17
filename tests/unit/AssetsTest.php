<?php
/**
 * Fichiers d'assets et versionnage.
 */

use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase {

	protected function setUp(): void {
		kpay_test_reset();
		kpay_test_settings();
	}

	/**
	 * Tous les assets référencés par le code doivent exister : une URL vers
	 * un fichier absent produit un 404 silencieux au checkout.
	 *
	 * @dataProvider asset_files
	 */
	public function test_referenced_assets_exist( $relative ): void {
		$this->assertFileExists( WC_KPAY_PLUGIN_DIR . $relative );
	}

	public static function asset_files(): array {
		return array(
			'JS blocs'    => array( 'assets/js/blocks.js' ),
			'JS polling'  => array( 'assets/js/polling.js' ),
			'JS admin'    => array( 'assets/js/admin.js' ),
			'CSS checkout' => array( 'assets/css/checkout.css' ),
			'Logo clair'  => array( 'assets/images/kpay-logo.png' ),
			'Logo sombre' => array( 'assets/images/kpay-logo-dark.png' ),
		);
	}

	/**
	 * Le plugin déclare wc_kpay_asset_version() ; le bootstrap des tests en
	 * fournit une copie. Ce test garantit que les deux restent alignés : si
	 * la signature change dans le plugin, il échoue.
	 */
	public function test_asset_version_helper_is_declared_by_plugin(): void {
		$main = file_get_contents( WC_KPAY_PLUGIN_DIR . 'wc-kpay-gateway.php' );

		$this->assertStringContainsString(
			'function wc_kpay_asset_version( $relative_path = \'\' )',
			$main,
			'La signature de wc_kpay_asset_version() a changé : mettre à jour tests/bootstrap.php.'
		);
	}

	public function test_asset_version_returns_plugin_version_in_production(): void {
		$this->assertSame( WC_KPAY_VERSION, wc_kpay_asset_version( 'assets/js/blocks.js' ) );
		$this->assertSame( WC_KPAY_VERSION, wc_kpay_asset_version( '' ) );
	}

	public function test_asset_version_handles_missing_file(): void {
		$this->assertSame( WC_KPAY_VERSION, wc_kpay_asset_version( 'assets/js/inexistant.js' ) );
	}

	/** Le CSS doit contraindre la largeur du select, écrasée par WooCommerce. */
	public function test_checkout_css_overrides_woocommerce_select_width(): void {
		$css = file_get_contents( WC_KPAY_PLUGIN_DIR . 'assets/css/checkout.css' );

		// woocommerce-layout.css impose width:auto via `#payment .form-row select`.
		$this->assertStringContainsString( '#payment .form-row select.kpay-select', $css );
		$this->assertStringContainsString( 'width: 100%', $css );
	}

	/**
	 * Le JS des blocs ne doit pas emprunter la classe de champ de
	 * WooCommerce Blocks : son label est positionné en absolu et se
	 * superpose au champ si le markup n'est pas exactement le sien.
	 */
	public function test_blocks_js_does_not_borrow_wc_text_input_class(): void {
		$js = file_get_contents( WC_KPAY_PLUGIN_DIR . 'assets/js/blocks.js' );

		$this->assertStringNotContainsString( "'wc-block-components-text-input'", $js );
		$this->assertStringContainsString( 'wc-kpay-field', $js );
	}

	/** Les champs des blocs doivent être stylés par notre CSS. */
	public function test_blocks_field_styles_exist(): void {
		$css = file_get_contents( WC_KPAY_PLUGIN_DIR . 'assets/css/checkout.css' );

		$this->assertStringContainsString( '.wc-kpay-field__label', $css );
		$this->assertStringContainsString( '.wc-kpay-field__control', $css );
	}

	/** Le formulaire classique doit porter les classes ciblées par le CSS. */
	public function test_classic_form_carries_css_classes(): void {
		$gateway = new WC_KPay_Gateway();

		ob_start();
		$gateway->payment_fields();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'wc-kpay-fields', $html );
		$this->assertStringContainsString( 'kpay-select', $html );
		$this->assertStringContainsString( 'kpay-input', $html );
	}

	/** Le formulaire doit lister les opérateurs actifs, et eux seuls. */
	public function test_classic_form_lists_active_providers(): void {
		$gateway = new WC_KPay_Gateway();

		ob_start();
		$gateway->payment_fields();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'MTN_MOMO_CMR', $html );
		$this->assertStringContainsString( 'ORANGE_CMR', $html );
		$this->assertStringNotContainsString( 'MPESA_KEN', $html );
	}
}
