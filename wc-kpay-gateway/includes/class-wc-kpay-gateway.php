<?php
/**
 * Passerelle de paiement K-Pay.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_KPay_Gateway extends WC_Payment_Gateway {

	public function __construct() {
		$this->id                 = 'kpay';
		// Variante sombre : le checkout de la quasi-totalité des thèmes est
		// sur fond clair. La variante blanche existe pour les thèmes sombres.
		$this->icon               = WC_KPAY_PLUGIN_URL . 'assets/images/kpay-logo-dark.png';
		$this->has_fields         = true;
		$this->method_title       = __( 'K-Pay (Mobile Money)', 'k-pay-for-woocommerce' );
		$this->method_description = __( 'Accepter les paiements Mobile Money (MTN MoMo, Orange Money, Airtel…) via K-Pay.', 'k-pay-for-woocommerce' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_checkout_scripts' ) );

		// Le checkout en blocs transmet les champs dans paymentMethodData et
		// non dans $_POST : on les y réinjecte pour que validate_fields() et
		// process_payment() lisent la même source dans les deux checkouts.
		add_action(
			'woocommerce_rest_checkout_process_payment_with_context',
			array( $this, 'hydrate_request_from_blocks_context' ),
			10,
			2
		);
	}

	/**
	 * Laisser $result sans statut est délibéré : le traitement historique
	 * (process_payment) prend alors le relais, et les deux checkouts partagent
	 * le même chemin de code.
	 *
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentContext $context
	 * @param \Automattic\WooCommerce\StoreApi\Payments\PaymentResult  $result
	 */
	public function hydrate_request_from_blocks_context( $context, &$result ) {
		if ( $this->id !== $context->payment_method ) {
			return;
		}

		$data = $context->payment_data;

		if ( isset( $data['kpay_provider'] ) ) {
			$_POST['kpay_provider'] = wc_clean( $data['kpay_provider'] );
		}
		if ( isset( $data['kpay_phone'] ) ) {
			$_POST['kpay_phone'] = wc_clean( $data['kpay_phone'] );
		}
	}

	/**
	 * Environnement courant : 'sandbox' ou 'live'.
	 */
	public function get_environment() {
		return 'live' === $this->get_option( 'environment' ) ? 'live' : 'sandbox';
	}

	/**
	 * Mode de paiement : 'ussd' (numéro saisi sur la boutique) ou 'gateway'
	 * (page de paiement hébergée par K-Pay).
	 *
	 * Le mode doit refléter la configuration de l'Application côté K-Pay :
	 * c'est elle qui fait autorité, le réglage local ne fait que s'y aligner.
	 */
	public function get_payment_mode() {
		return 'gateway' === $this->get_option( 'payment_mode' ) ? 'gateway' : 'ussd';
	}

	/**
	 * En mode passerelle, K-Pay collecte lui-même l'opérateur et le numéro :
	 * le formulaire local n'a plus lieu d'être.
	 */
	public function has_checkout_fields() {
		return 'ussd' === $this->get_payment_mode();
	}

	/**
	 * Clés API de l'environnement courant.
	 */
	private function get_credentials( $environment = null ) {
		$environment = $environment ? $environment : $this->get_environment();

		if ( 'live' === $environment ) {
			return array( $this->get_option( 'live_api_key' ), $this->get_option( 'live_secret_key' ) );
		}
		return array( $this->get_option( 'sandbox_api_key' ), $this->get_option( 'sandbox_secret_key' ) );
	}

	/**
	 * Client API pour l'environnement courant (ou celui d'une commande).
	 */
	public function get_api( $environment = null ) {
		list( $api_key, $secret_key ) = $this->get_credentials( $environment );
		return new WC_KPay_API( $api_key, $secret_key );
	}

	/**
	 * Providers activés par le marchand, restreints à la devise de la boutique.
	 */
	public function get_active_providers() {
		$currency  = get_woocommerce_currency();
		$available = WC_KPay_API::get_providers_for_currency( $currency );
		// Un multiselect dont rien n'est coché est enregistré comme chaîne vide,
		// et non comme tableau vide : seule l'absence de la clé distingue
		// « jamais configuré » (= tous les opérateurs) de « tout décoché »
		// (= aucun). get_option() ne fait pas cette différence, d'où la lecture
		// des réglages bruts.
		if ( ! array_key_exists( 'providers', (array) $this->settings ) ) {
			return $available;
		}

		$enabled = $this->settings['providers'];
		if ( ! is_array( $enabled ) ) {
			$enabled = '' === $enabled ? array() : (array) $enabled;
		}

		return array_intersect_key( $available, array_flip( $enabled ) );
	}

	/**
	 * Conditions d'affichage de la passerelle au checkout.
	 */
	public function is_available() {
		if ( ! parent::is_available() ) {
			return false;
		}

		list( $api_key, $secret_key ) = $this->get_credentials();
		if ( empty( $api_key ) || empty( $secret_key ) ) {
			return false;
		}

		// La devise doit rester couverte par K-Pay dans les deux modes : aucune
		// conversion n'est faite. En mode passerelle, en revanche, la sélection
		// d'opérateurs est faite côté K-Pay — n'exiger que la devise.
		if ( 'gateway' === $this->get_payment_mode() ) {
			return ! empty( WC_KPay_API::get_providers_for_currency( get_woocommerce_currency() ) );
		}

		if ( empty( $this->get_active_providers() ) ) {
			return false;
		}

		return true;
	}

	public function init_form_fields() {
		$provider_options = array();
		foreach ( WC_KPay_API::get_providers() as $code => $provider ) {
			$provider_options[ $code ] = sprintf( '%s — %s', $provider['label'], $provider['currency'] );
		}

		$this->form_fields = array(
			'enabled' => array(
				'title'       => __( 'Activer/Désactiver', 'k-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Activer la passerelle K-Pay', 'k-pay-for-woocommerce' ),
				'description' => __( 'Une fois activée, K-Pay apparaît au checkout comme moyen de paiement — à condition que les clés de l\'environnement courant soient renseignées et que la devise de la boutique corresponde à un opérateur. Le diagnostic ci-dessus signale ce qui manque.', 'k-pay-for-woocommerce' ),
				'default'     => 'no',
			),
			'title' => array(
				'title'       => __( 'Titre', 'k-pay-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Titre affiché au client lors du choix du moyen de paiement.', 'k-pay-for-woocommerce' ),
				'default'     => __( 'Mobile Money', 'k-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'k-pay-for-woocommerce' ),
				'type'        => 'textarea',
				'description' => __( 'Message affiché sous le titre au checkout.', 'k-pay-for-woocommerce' ),
				'default'     => __( 'Payez avec MTN MoMo ou Orange Money. Vous recevrez une demande de confirmation sur votre téléphone.', 'k-pay-for-woocommerce' ),
			),
			'language' => array(
				'title'       => __( 'Langue', 'k-pay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Langue des textes du plugin, au checkout comme dans l\'administration. « Langue du site » suit le réglage WordPress.', 'k-pay-for-woocommerce' ),
				'default'     => 'site',
				'options'     => array(
					'site'  => __( 'Langue du site', 'k-pay-for-woocommerce' ),
					'fr_FR' => __( 'Français', 'k-pay-for-woocommerce' ),
					'en_US' => __( 'English', 'k-pay-for-woocommerce' ),
				),
				'desc_tip'    => true,
			),

			'payment_mode' => array(
				'title'       => __( 'Mode de paiement', 'k-pay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Doit correspondre au mode configuré pour votre Application dans le tableau de bord K-Pay. Un mode différent de celui de votre application fera refuser les paiements (erreur 400).', 'k-pay-for-woocommerce' ),
				'default'     => 'ussd',
				'options'     => array(
					'ussd'    => __( 'USSD — le client saisit son numéro sur votre site', 'k-pay-for-woocommerce' ),
					'gateway' => __( 'Passerelle hébergée — redirection vers la page K-Pay', 'k-pay-for-woocommerce' ),
				),
			),

			'providers' => array(
				'title'       => __( 'Opérateurs proposés', 'k-pay-for-woocommerce' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Opérateurs proposés au client. Seuls ceux correspondant à la devise de la boutique seront affichés. Laisser vide pour tous les accepter.', 'k-pay-for-woocommerce' ),
				'options'     => $provider_options,
				'default'     => array( 'MTN_MOMO_CMR', 'ORANGE_CMR' ),
				'desc_tip'    => true,
			),
			'environment' => array(
				'title'       => __( 'Environnement', 'k-pay-for-woocommerce' ),
				'type'        => 'select',
				'description' => __( 'Sandbox pour tester sans argent réel, Live pour encaisser réellement.', 'k-pay-for-woocommerce' ),
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox' => __( 'Sandbox (test)', 'k-pay-for-woocommerce' ),
					'live'    => __( 'Live (production)', 'k-pay-for-woocommerce' ),
				),
				'desc_tip'    => true,
			),

			'sandbox_section' => array(
				'title'       => __( 'Clés Sandbox', 'k-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Clés de test, préfixées <code>kpay_test_</code> et <code>sk_test_</code>.', 'k-pay-for-woocommerce' ),
			),
			'sandbox_api_key' => array(
				'title' => __( 'Clé API Sandbox', 'k-pay-for-woocommerce' ),
				'type'  => 'text',
			),
			'sandbox_secret_key' => array(
				'title' => __( 'Clé secrète Sandbox', 'k-pay-for-woocommerce' ),
				'type'  => 'password',
			),

			'live_section' => array(
				'title'       => __( 'Clés Live', 'k-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Clés de production, préfixées <code>kpay_live_</code> et <code>sk_live_</code>. Disponibles après validation KYC.', 'k-pay-for-woocommerce' ),
			),
			'live_api_key' => array(
				'title' => __( 'Clé API Live', 'k-pay-for-woocommerce' ),
				'type'  => 'text',
			),
			'live_secret_key' => array(
				'title' => __( 'Clé secrète Live', 'k-pay-for-woocommerce' ),
				'type'  => 'password',
			),

			'webhook_section' => array(
				'title'       => __( 'Webhook', 'k-pay-for-woocommerce' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: URL du webhook */
					__( 'Configurez cette URL comme callback dans votre tableau de bord K-Pay :<br><code>%s</code>', 'k-pay-for-woocommerce' ),
					esc_html( WC_KPay_Webhook::get_url() )
				),
			),
			'webhook_secret' => array(
				'title'       => __( 'Secret webhook', 'k-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Secret fourni par K-Pay, utilisé pour vérifier la signature HMAC des notifications. Sans lui, les webhooks entrants sont rejetés.', 'k-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'gateway_secret' => array(
				'title'       => __( 'Secret passerelle', 'k-pay-for-woocommerce' ),
				'type'        => 'password',
				'description' => __( 'Utilisé uniquement en mode « Passerelle hébergée », pour vérifier la signature de l\'URL de retour. Distinct du secret webhook. Sans lui, les retours de paiement sont refusés.', 'k-pay-for-woocommerce' ),
				'desc_tip'    => true,
			),

			'debug' => array(
				'title'       => __( 'Journalisation', 'k-pay-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enregistrer les échanges avec K-Pay', 'k-pay-for-woocommerce' ),
				'description' => __( 'Journaux disponibles dans WooCommerce > État > Journaux (source : kpay).', 'k-pay-for-woocommerce' ),
				'default'     => 'no',
			),
		);
	}

	/**
	 * Masque les champs de l'environnement non sélectionné.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}
		// Lecture seule d'un paramètre de navigation de WooCommerce, pour savoir
		// si la page affichée est la nôtre : aucune donnée n'est traitée ici.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';
		if ( $this->id !== $section ) {
			return;
		}

		wp_enqueue_script(
			'wc-kpay-admin',
			WC_KPAY_PLUGIN_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			wc_kpay_asset_version( 'assets/js/admin.js' ),
			true
		);

		wp_enqueue_style(
			'wc-kpay-admin',
			WC_KPAY_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			wc_kpay_asset_version( 'assets/css/admin.css' )
		);
	}

	/**
	 * Contrôle les clés à l'enregistrement.
	 *
	 * Deux niveaux : le préfixe est vérifié localement (immédiat, sans
	 * réseau), puis les clés sont confrontées à l'API. L'URL étant identique
	 * dans les deux environnements, le préfixe est le seul indice local qu'une
	 * clé de test a été collée en production — ou l'inverse.
	 */
	public function process_admin_options() {
		$saved = parent::process_admin_options();

		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return $saved;
		}

		$environment                  = $this->get_environment();
		list( $api_key, $secret_key ) = $this->get_credentials( $environment );

		if ( empty( $api_key ) && empty( $secret_key ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: environnement courant */
					__( 'K-Pay : aucune clé %s n\'est renseignée, la passerelle restera masquée au checkout.', 'k-pay-for-woocommerce' ),
					$environment
				)
			);
			return $saved;
		}

		$valid = WC_KPay_API::validate_keys( $api_key, $secret_key, $environment );

		if ( is_wp_error( $valid ) ) {
			WC_Admin_Settings::add_error( 'K-Pay : ' . $valid->get_error_message() );
			return $saved;
		}

		// Le préfixe est cohérent : reste à savoir si les clés sont valides.
		$info = $this->get_api( $environment )->get_application_info();

		if ( is_wp_error( $info ) ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: environnement, 2: message d'erreur */
					__( 'K-Pay : les clés %1$s n\'ont pas pu être vérifiées (%2$s).', 'k-pay-for-woocommerce' ),
					$environment,
					$info->get_error_message()
				)
			);
			return $saved;
		}

		$resolved = isset( $info['environment'] ) ? strtoupper( $info['environment'] ) : '';
		$expected = 'live' === $environment ? 'PRODUCTION' : 'TEST';

		if ( $resolved && $resolved !== $expected ) {
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: environnement attendu, 2: environnement résolu */
					__( 'K-Pay : vous avez choisi l\'environnement %1$s mais les clés fournies sont des clés %2$s.', 'k-pay-for-woocommerce' ),
					$expected,
					$resolved
				)
			);
			return $saved;
		}

		WC_Admin_Settings::add_message(
			sprintf(
				/* translators: 1: nom de l'application, 2: environnement */
				__( 'K-Pay : clés valides — application « %1$s », environnement %2$s.', 'k-pay-for-woocommerce' ),
				isset( $info['application']['name'] ) ? $info['application']['name'] : '—',
				$resolved ? $resolved : strtoupper( $environment )
			)
		);

		return $saved;
	}

	/**
	 * Logo affiché à côté du titre au checkout. La taille est contrainte ici :
	 * laissée libre, une icône SVG s'étire à la largeur du conteneur.
	 */
	public function get_icon() {
		$html = sprintf(
			'<img src="%s" alt="%s" class="wc-kpay-icon" style="max-height:24px;width:auto;vertical-align:middle;margin-left:6px;" />',
			esc_url( $this->icon ),
			esc_attr__( 'K-Pay', 'k-pay-for-woocommerce' )
		);

		// Filtre de WooCommerce, appliqué par WC_Payment_Gateway::get_icon() que
		// cette méthode remplace : le préfixer couperait les thèmes et
		// extensions qui s'y branchent pour toutes les passerelles.
		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	/**
	 * Page de réglages : en-tête, diagnostic, puis les champs.
	 *
	 * Le conteneur .kpay-settings porte la mise en forme : WooCommerce rend
	 * tous les champs dans une table nue, où une clé de production et un
	 * libellé d'affichage se ressemblent. Une passerelle activée mais
	 * invisible au checkout est par ailleurs le piège le plus courant, et rien
	 * à l'écran ne l'explique par défaut — d'où le diagnostic.
	 */
	public function admin_options() {
		echo '<div class="kpay-settings">';

		$this->render_admin_header();
		$this->render_admin_diagnostics();

		parent::admin_options();

		echo '</div>';
	}

	/**
	 * En-tête : identité de la passerelle et environnement courant.
	 */
	private function render_admin_header() {
		$environment = $this->get_environment();
		$is_live     = 'live' === $environment;
		?>
		<div class="kpay-header">
			<div class="kpay-header__brand">
				<img src="<?php echo esc_url( WC_KPAY_PLUGIN_URL . 'assets/images/kpay-logo-dark.png' ); ?>"
					alt="<?php esc_attr_e( 'K-Pay', 'k-pay-for-woocommerce' ); ?>"
					class="kpay-admin__logo" />
				<div>
					<h3 class="kpay-header__name"><?php esc_html_e( 'Mobile Money par K-Pay', 'k-pay-for-woocommerce' ); ?></h3>
					<p class="kpay-header__text">
						<?php esc_html_e( 'MTN MoMo, Orange Money, Airtel, M-Pesa — 12 pays africains. Soldes et retraits se gèrent depuis votre tableau de bord K-Pay.', 'k-pay-for-woocommerce' ); ?>
					</p>
				</div>
			</div>

			<span class="kpay-badge kpay-badge--<?php echo esc_attr( $environment ); ?>">
				<?php echo $is_live
					? esc_html__( 'Production', 'k-pay-for-woocommerce' )
					: esc_html__( 'Sandbox', 'k-pay-for-woocommerce' ); ?>
			</span>
		</div>
		<?php
	}

	/**
	 * Bandeau d'environnement.
	 *
	 * L'URL de l'API est la même en test et en production : sans repère
	 * visuel, rien ne distingue une boutique qui encaisse réellement d'une
	 * boutique en test.
	 */
	private function render_environment_banner() {
		$environment = $this->get_environment();
		$is_live     = 'live' === $environment;

		printf(
			'<div class="kpay-env-banner kpay-env-banner--%s"><strong>%s</strong> %s</div>',
			esc_attr( $environment ),
			esc_html( $is_live
				? __( 'Environnement : Production', 'k-pay-for-woocommerce' )
				: __( 'Environnement : Sandbox (test)', 'k-pay-for-woocommerce' ) ),
			esc_html( $is_live
				? __( '— les paiements et les retraits portent sur de l\'argent réel.', 'k-pay-for-woocommerce' )
				: __( '— aucun argent réel n\'est échangé. Les clés de test commencent par kpay_test_.', 'k-pay-for-woocommerce' ) )
		);
	}

	/**
	 * Signale les raisons pour lesquelles la passerelle ne s'affichera pas,
	 * notamment une devise de boutique sans opérateur correspondant.
	 */
	private function render_admin_diagnostics() {
		$this->render_environment_banner();

		if ( 'yes' !== $this->get_option( 'enabled' ) ) {
			return;
		}

		$currency = get_woocommerce_currency();
		$messages = array();

		$environment                  = $this->get_environment();
		list( $api_key, $secret_key ) = $this->get_credentials( $environment );

		if ( empty( $api_key ) || empty( $secret_key ) ) {
			$messages[] = sprintf(
				/* translators: %s: environnement courant */
				__( 'Les clés %s ne sont pas renseignées : la passerelle reste masquée au checkout.', 'k-pay-for-woocommerce' ),
				$environment
			);
		} else {
			// Le préfixe est le seul indice local d'une clé du mauvais
			// environnement : l'URL de l'API est identique dans les deux cas.
			$valid = WC_KPay_API::validate_keys( $api_key, $secret_key, $environment );
			if ( is_wp_error( $valid ) ) {
				$messages[] = $valid->get_error_message();
			}
		}

		if ( empty( WC_KPay_API::get_providers_for_currency( $currency ) ) ) {
			// K-Pay encaisse dans la devise du pays de l'opérateur et ne
			// convertit pas : une boutique en EUR/USD ne peut pas l'utiliser.
			$messages[] = sprintf(
				/* translators: 1: devise de la boutique, 2: devises supportées */
				__( 'La devise de votre boutique (%1$s) n\'est prise en charge par aucun opérateur K-Pay. Aucune conversion n\'est effectuée : réglez la devise sur l\'une des suivantes dans WooCommerce → Réglages → Général : %2$s.', 'k-pay-for-woocommerce' ),
				$currency,
				implode( ', ', WC_KPay_API::get_supported_currencies() )
			);
		} elseif ( empty( $this->get_active_providers() ) ) {
			$compatible = array();
			foreach ( WC_KPay_API::get_providers_for_currency( $currency ) as $code => $provider ) {
				$compatible[] = $provider['label'];
			}
			$messages[] = sprintf(
				/* translators: 1: devise de la boutique, 2: liste d'opérateurs */
				__( 'Aucun opérateur sélectionné ne correspond à la devise de votre boutique (%1$s) : la passerelle reste masquée au checkout. Opérateurs compatibles : %2$s.', 'k-pay-for-woocommerce' ),
				$currency,
				implode( ', ', $compatible )
			);
		}

		foreach ( $messages as $message ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( $message ) . '</p></div>';
		}
	}

	/**
	 * Formulaire client (checkout classique / shortcode).
	 */
	public function payment_fields() {
		if ( $this->description ) {
			// wp_kses_post en dernier : c'est lui qui échappe, et l'appliquer
			// après wpautop garde la sortie reconnaissable comme échappée.
			echo wp_kses_post( wpautop( $this->description ) );
		}

		if ( 'sandbox' === $this->get_environment() ) {
			echo '<p><strong>' . esc_html__( 'Mode test actif — aucun paiement réel ne sera effectué.', 'k-pay-for-woocommerce' ) . '</strong></p>';
		}

		// Mode passerelle : opérateur et numéro sont saisis sur la page K-Pay.
		if ( ! $this->has_checkout_fields() ) {
			echo '<p class="wc-kpay-redirect-notice">' . esc_html__(
				'Vous serez redirigé vers la page de paiement sécurisée K-Pay pour finaliser votre commande.',
				'k-pay-for-woocommerce'
			) . '</p>';
			return;
		}

		$providers = $this->get_active_providers();
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form wc-kpay-fields">
			<p class="form-row form-row-wide validate-required">
				<label for="kpay_provider">
					<?php esc_html_e( 'Opérateur', 'k-pay-for-woocommerce' ); ?>&nbsp;<span class="required">*</span>
				</label>
				<select name="kpay_provider" id="kpay_provider" class="input-text kpay-select">
					<?php foreach ( $providers as $code => $provider ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>">
							<?php echo esc_html( $provider['label'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row form-row-wide validate-required">
				<label for="kpay_phone">
					<?php esc_html_e( 'Numéro Mobile Money', 'k-pay-for-woocommerce' ); ?>&nbsp;<span class="required">*</span>
				</label>
				<input type="tel" class="input-text kpay-input" name="kpay_phone" id="kpay_phone"
					autocomplete="tel" placeholder="<?php esc_attr_e( 'Ex : 6 70 00 00 01', 'k-pay-for-woocommerce' ); ?>" />
			</p>
			<div class="clear"></div>
		</fieldset>
		<?php
	}

	/**
	 * Le nonce du checkout WooCommerce (woocommerce-process-checkout-nonce)
	 * protège déjà la soumission ; on valide ici le contenu des champs.
	 */
	public function validate_fields() {
		// Rien à valider en mode passerelle : les champs sont sur la page K-Pay.
		if ( ! $this->has_checkout_fields() ) {
			return true;
		}

		// WooCommerce vérifie le nonce du checkout avant d'appeler cette méthode
		// (WC_Checkout::process_checkout) : le revérifier ici échouerait sur le
		// checkout en blocs, qui soumet via l'API REST avec son propre jeton.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$provider = isset( $_POST['kpay_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_provider'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$phone    = isset( $_POST['kpay_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_phone'] ) ) : '';

		if ( ! array_key_exists( $provider, $this->get_active_providers() ) ) {
			wc_add_notice( __( 'Veuillez sélectionner un opérateur valide.', 'k-pay-for-woocommerce' ), 'error' );
			return false;
		}

		if ( ! WC_KPay_API::normalize_phone( $phone, $provider ) ) {
			wc_add_notice( __( 'Veuillez saisir un numéro Mobile Money valide.', 'k-pay-for-woocommerce' ), 'error' );
			return false;
		}

		return true;
	}

	/**
	 * Crée le paiement chez K-Pay et laisse la commande en attente : seuls
	 * le webhook signé ou le polling de statut peuvent la marquer payée.
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Commande introuvable.', 'k-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$mode        = $this->get_payment_mode();
		$environment = $this->get_environment();
		$provider    = '';
		$phone       = '';

		// En mode passerelle, K-Pay collecte opérateur et numéro sur sa propre
		// page : les transmettre ferait échouer l'init (contrat de mode).
		if ( 'ussd' === $mode ) {
			// Même chemin que validate_fields() : le nonce du checkout est déjà
			// vérifié par WooCommerce, qui appelle cette méthode.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$provider = isset( $_POST['kpay_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_provider'] ) ) : '';
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			$phone    = isset( $_POST['kpay_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_phone'] ) ) : '';

			if ( ! array_key_exists( $provider, $this->get_active_providers() ) ) {
				wc_add_notice( __( 'Opérateur invalide.', 'k-pay-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}

			$phone = WC_KPay_API::normalize_phone( $phone, $provider );
			if ( ! $phone ) {
				wc_add_notice( __( 'Numéro Mobile Money invalide.', 'k-pay-for-woocommerce' ), 'error' );
				return array( 'result' => 'failure' );
			}
		} elseif ( '' === (string) $this->get_option( 'gateway_secret' ) ) {
			// Sans secret, la signature du retour est invérifiable : refuser
			// tout de suite plutôt que d'encaisser sans pouvoir valider.
			$this->log( 'Mode passerelle sans secret configuré : paiement refusé.', 'error' );
			wc_add_notice(
				__( 'Le paiement n\'est pas disponible pour le moment. Merci de contacter la boutique.', 'k-pay-for-woocommerce' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		// externalId : unique par tentative. L'API renvoie 409 si un externalId
		// est déjà actif, donc chaque nouvelle tentative sur la même commande
		// (paiement précédent FAILED/CANCELLED, ou client qui réessaie) doit
		// porter un suffixe distinct. Le compteur est incrémenté avant l'appel
		// pour qu'une tentative avortée ne réutilise jamais le même identifiant.
		$attempt = (int) $order->get_meta( '_kpay_attempt' ) + 1;
		$order->update_meta_data( '_kpay_attempt', $attempt );
		$order->save();

		$external_id = sprintf( 'WC-%d-%d', $order->get_id(), $attempt );

		$amount = (float) $order->get_total();
		if ( 'ussd' === $mode && WC_KPay_API::provider_uses_integer_amount( $provider ) ) {
			$amount = (int) round( $amount );
		}

		// Champs communs aux deux modes. La devise n'est pas transmise :
		// l'API la déduit du provider (USSD) ou de l'application (GATEWAY).
		$payload = array(
			'amount'      => $amount,
			'externalId'  => $external_id,
			'description' => sprintf(
				/* translators: 1: numéro de commande, 2: nom de la boutique */
				__( 'Commande %1$s sur %2$s', 'k-pay-for-woocommerce' ),
				$order->get_order_number(),
				get_bloginfo( 'name' )
			),
			'customerEmail' => $order->get_billing_email(),
			'metadata'      => array(
				'orderId'  => (string) $order->get_id(),
				'orderKey' => $order->get_order_key(),
			),
		);

		if ( 'ussd' === $mode ) {
			$payload['provider']     = $provider;
			$payload['phoneNumber']  = $phone;
			$payload['customerName'] = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		} else {
			// GATEWAY : returnUrl est requis, et c'est lui qui rapporte la
			// signature de retour. Le cancelUrl ramène au paiement de la
			// commande, pour permettre une nouvelle tentative.
			$payload['returnUrl'] = $this->get_gateway_return_url( $order );
			$payload['cancelUrl'] = $order->get_cancel_order_url_raw();
		}

		$this->log( sprintf(
			'Init paiement commande #%d (%s, %s, %s)',
			$order->get_id(),
			$mode,
			'ussd' === $mode ? $provider : 'hébergée',
			$environment
		) );

		$result = $this->get_api( $environment )->init_payment( $payload );

		if ( is_wp_error( $result ) ) {
			$this->log( sprintf( 'Échec init commande #%d : %s', $order->get_id(), $result->get_error_message() ), 'error' );
			wc_add_notice( $this->customer_facing_error( $result ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $result['id'] ) ) {
			$this->log( sprintf( 'Réponse sans id pour la commande #%d', $order->get_id() ), 'error' );
			wc_add_notice( __( 'La création du paiement a échoué. Merci de réessayer.', 'k-pay-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}

		// En mode passerelle, l'absence d'URL de redirection rend le paiement
		// impossible à poursuivre : mieux vaut échouer ici que laisser le
		// client sur une page de confirmation sans avoir payé.
		if ( 'gateway' === $mode && empty( $result['gatewayUrl'] ) ) {
			$this->log(
				sprintf( 'Réponse sans gatewayUrl pour la commande #%d : mode mal aligné ?', $order->get_id() ),
				'error'
			);
			wc_add_notice(
				__( 'La page de paiement n\'a pas pu être ouverte. Merci de réessayer.', 'k-pay-for-woocommerce' ),
				'error'
			);
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_kpay_payment_id', sanitize_text_field( $result['id'] ) );
		$order->update_meta_data( '_kpay_external_id', $external_id );
		$order->update_meta_data( '_kpay_environment', $environment );
		$order->update_meta_data( '_kpay_provider', $provider );
		$order->update_meta_data( '_kpay_mode', $mode );
		if ( ! empty( $result['reference'] ) ) {
			$order->update_meta_data( '_kpay_reference', sanitize_text_field( $result['reference'] ) );
		}

		$order->update_status(
			'on-hold',
			'ussd' === $mode
				? sprintf(
					/* translators: 1: opérateur, 2: identifiant K-Pay */
					__( 'Paiement K-Pay initié via %1$s. En attente de confirmation du client (transaction %2$s).', 'k-pay-for-woocommerce' ),
					$provider,
					$result['id']
				)
				: sprintf(
					/* translators: %s: identifiant K-Pay */
					__( 'Paiement K-Pay initié sur la passerelle hébergée. Client redirigé (transaction %s).', 'k-pay-for-woocommerce' ),
					$result['id']
				)
		);
		$order->save();

		// Le checkout en blocs (Store API) gère lui-même le cycle de vie du
		// panier : le vider en pleine requête casserait la réponse. On ne le
		// fait donc que pour le checkout classique.
		if ( ! WC()->is_rest_api_request() && WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => 'gateway' === $mode
				? $result['gatewayUrl']
				: $this->get_return_url( $order ),
		);
	}

	/**
	 * URL de retour de la passerelle hébergée.
	 *
	 * La clé de commande y est jointe : la signature K-Pay authentifie le
	 * paiement, cette clé authentifie la commande visée.
	 */
	private function get_gateway_return_url( WC_Order $order ) {
		return add_query_arg(
			array(
				'kpay_return' => '1',
				'order_id'    => $order->get_id(),
				'order_key'   => $order->get_order_key(),
			),
			$this->get_return_url( $order )
		);
	}

	/**
	 * Traduit une erreur API en message client, sans fuiter de détail interne.
	 */
	private function customer_facing_error( WP_Error $error ) {
		$code = $error->get_error_code();

		if ( 'kpay_api_error_400' === $code ) {
			return __( 'Le paiement a été refusé : vérifiez votre numéro et l\'opérateur sélectionné.', 'k-pay-for-woocommerce' );
		}
		if ( 'kpay_api_error_429' === $code ) {
			return __( 'Trop de tentatives. Merci de patienter quelques instants avant de réessayer.', 'k-pay-for-woocommerce' );
		}
		if ( 'kpay_http_error' === $code ) {
			return __( 'Le service de paiement est momentanément injoignable. Merci de réessayer.', 'k-pay-for-woocommerce' );
		}

		return __( 'Le paiement n\'a pas pu être initié. Merci de réessayer ou de contacter le support.', 'k-pay-for-woocommerce' );
	}

	/**
	 * Polling sur la page de confirmation, tant que la commande attend
	 * le résultat de l'opérateur.
	 */
	public function enqueue_checkout_scripts() {
		// La feuille de style sert au formulaire de paiement comme à la page
		// de confirmation : elle est chargée sur toute page WooCommerce.
		if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_cart() ) ) {
			wp_enqueue_style(
				'wc-kpay-checkout',
				WC_KPAY_PLUGIN_URL . 'assets/css/checkout.css',
				array(),
				wc_kpay_asset_version( 'assets/css/checkout.css' )
			);
		}

		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		global $wp;
		$order_id = isset( $wp->query_vars['order-received'] ) ? absint( $wp->query_vars['order-received'] ) : 0;
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order || $this->id !== $order->get_payment_method() ) {
			return;
		}
		if ( ! $order->has_status( array( 'on-hold', 'pending' ) ) ) {
			return;
		}
		if ( ! $order->get_meta( '_kpay_payment_id' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-kpay-polling',
			WC_KPAY_PLUGIN_URL . 'assets/js/polling.js',
			array( 'jquery' ),
			wc_kpay_asset_version( 'assets/js/polling.js' ),
			true
		);

		wp_localize_script( 'wc-kpay-polling', 'wcKPayPolling', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'orderId'  => $order_id,
			'orderKey' => $order->get_order_key(),
			'nonce'    => wp_create_nonce( 'kpay_status_' . $order_id ),
			'interval' => 5000,
			'timeout'  => 300000,
			'i18n'     => array(
				'pending'   => __( 'En attente de votre confirmation sur le téléphone…', 'k-pay-for-woocommerce' ),
				'completed' => __( 'Paiement reçu. Votre commande est confirmée.', 'k-pay-for-woocommerce' ),
				'failed'    => __( 'Le paiement a échoué ou a été annulé.', 'k-pay-for-woocommerce' ),
				'expired'   => __( 'Nous n\'avons pas reçu de confirmation. Rechargez la page pour connaître le statut.', 'k-pay-for-woocommerce' ),
			),
		) );
	}

	public function log( $message, $level = 'info' ) {
		if ( 'yes' !== $this->get_option( 'debug' ) && 'error' !== $level ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->log( $level, $message, array( 'source' => 'kpay' ) );
	}
}
