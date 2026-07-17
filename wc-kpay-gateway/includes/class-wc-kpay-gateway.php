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
		$this->method_title       = __( 'K-Pay (Mobile Money)', 'wc-kpay-gateway' );
		$this->method_description = __( 'Accepter les paiements Mobile Money (MTN MoMo, Orange Money, Airtel…) via K-Pay.', 'wc-kpay-gateway' );
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

		// La devise de la boutique doit correspondre à au moins un provider actif.
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
				'title'   => __( 'Activer/Désactiver', 'wc-kpay-gateway' ),
				'type'    => 'checkbox',
				'label'   => __( 'Activer la passerelle K-Pay', 'wc-kpay-gateway' ),
				'default' => 'no',
			),
			'title' => array(
				'title'       => __( 'Titre', 'wc-kpay-gateway' ),
				'type'        => 'text',
				'description' => __( 'Titre affiché au client lors du choix du moyen de paiement.', 'wc-kpay-gateway' ),
				'default'     => __( 'Mobile Money', 'wc-kpay-gateway' ),
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => __( 'Description', 'wc-kpay-gateway' ),
				'type'        => 'textarea',
				'description' => __( 'Message affiché sous le titre au checkout.', 'wc-kpay-gateway' ),
				'default'     => __( 'Payez avec MTN MoMo ou Orange Money. Vous recevrez une demande de confirmation sur votre téléphone.', 'wc-kpay-gateway' ),
			),
			'providers' => array(
				'title'       => __( 'Opérateurs proposés', 'wc-kpay-gateway' ),
				'type'        => 'multiselect',
				'class'       => 'wc-enhanced-select',
				'description' => __( 'Opérateurs proposés au client. Seuls ceux correspondant à la devise de la boutique seront affichés. Laisser vide pour tous les accepter.', 'wc-kpay-gateway' ),
				'options'     => $provider_options,
				'default'     => array( 'MTN_MOMO_CMR', 'ORANGE_CMR' ),
				'desc_tip'    => true,
			),
			'environment' => array(
				'title'       => __( 'Environnement', 'wc-kpay-gateway' ),
				'type'        => 'select',
				'description' => __( 'Sandbox pour tester sans argent réel, Live pour encaisser réellement.', 'wc-kpay-gateway' ),
				'default'     => 'sandbox',
				'options'     => array(
					'sandbox' => __( 'Sandbox (test)', 'wc-kpay-gateway' ),
					'live'    => __( 'Live (production)', 'wc-kpay-gateway' ),
				),
				'desc_tip'    => true,
			),

			'sandbox_section' => array(
				'title'       => __( 'Clés Sandbox', 'wc-kpay-gateway' ),
				'type'        => 'title',
				'description' => __( 'Clés de test, préfixées <code>kpay_test_</code> et <code>sk_test_</code>.', 'wc-kpay-gateway' ),
			),
			'sandbox_api_key' => array(
				'title' => __( 'Clé API Sandbox', 'wc-kpay-gateway' ),
				'type'  => 'text',
			),
			'sandbox_secret_key' => array(
				'title' => __( 'Clé secrète Sandbox', 'wc-kpay-gateway' ),
				'type'  => 'password',
			),

			'live_section' => array(
				'title'       => __( 'Clés Live', 'wc-kpay-gateway' ),
				'type'        => 'title',
				'description' => __( 'Clés de production, préfixées <code>kpay_live_</code> et <code>sk_live_</code>. Disponibles après validation KYC.', 'wc-kpay-gateway' ),
			),
			'live_api_key' => array(
				'title' => __( 'Clé API Live', 'wc-kpay-gateway' ),
				'type'  => 'text',
			),
			'live_secret_key' => array(
				'title' => __( 'Clé secrète Live', 'wc-kpay-gateway' ),
				'type'  => 'password',
			),

			'webhook_section' => array(
				'title'       => __( 'Webhook', 'wc-kpay-gateway' ),
				'type'        => 'title',
				'description' => sprintf(
					/* translators: %s: URL du webhook */
					__( 'Configurez cette URL comme callback dans votre tableau de bord K-Pay :<br><code>%s</code>', 'wc-kpay-gateway' ),
					esc_html( WC_KPay_Webhook::get_url() )
				),
			),
			'webhook_secret' => array(
				'title'       => __( 'Secret webhook', 'wc-kpay-gateway' ),
				'type'        => 'password',
				'description' => __( 'Secret fourni par K-Pay, utilisé pour vérifier la signature HMAC des notifications. Sans lui, les webhooks entrants sont rejetés.', 'wc-kpay-gateway' ),
				'desc_tip'    => true,
			),

			'debug' => array(
				'title'       => __( 'Journalisation', 'wc-kpay-gateway' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enregistrer les échanges avec K-Pay', 'wc-kpay-gateway' ),
				'description' => __( 'Journaux disponibles dans WooCommerce > État > Journaux (source : kpay).', 'wc-kpay-gateway' ),
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
					__( 'K-Pay : aucune clé %s n\'est renseignée, la passerelle restera masquée au checkout.', 'wc-kpay-gateway' ),
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
					__( 'K-Pay : les clés %1$s n\'ont pas pu être vérifiées (%2$s).', 'wc-kpay-gateway' ),
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
					__( 'K-Pay : vous avez choisi l\'environnement %1$s mais les clés fournies sont des clés %2$s.', 'wc-kpay-gateway' ),
					$expected,
					$resolved
				)
			);
			return $saved;
		}

		WC_Admin_Settings::add_message(
			sprintf(
				/* translators: 1: nom de l'application, 2: environnement */
				__( 'K-Pay : clés valides — application « %1$s », environnement %2$s.', 'wc-kpay-gateway' ),
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
			esc_attr__( 'K-Pay', 'wc-kpay-gateway' )
		);

		return apply_filters( 'woocommerce_gateway_icon', $html, $this->id );
	}

	/**
	 * Page de réglages : logo, puis diagnostic. Une passerelle activée mais
	 * invisible au checkout est le piège le plus courant, et rien à l'écran ne
	 * l'explique par défaut.
	 */
	public function admin_options() {
		printf(
			'<img src="%s" alt="%s" class="kpay-admin__logo" />',
			esc_url( WC_KPAY_PLUGIN_URL . 'assets/images/kpay-logo-dark.png' ),
			esc_attr__( 'K-Pay', 'wc-kpay-gateway' )
		);

		$this->render_admin_diagnostics();
		parent::admin_options();
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
				? __( 'Environnement : Production', 'wc-kpay-gateway' )
				: __( 'Environnement : Sandbox (test)', 'wc-kpay-gateway' ) ),
			esc_html( $is_live
				? __( '— les paiements et les retraits portent sur de l\'argent réel.', 'wc-kpay-gateway' )
				: __( '— aucun argent réel n\'est échangé. Les clés de test commencent par kpay_test_.', 'wc-kpay-gateway' ) )
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
				__( 'Les clés %s ne sont pas renseignées : la passerelle reste masquée au checkout.', 'wc-kpay-gateway' ),
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
				__( 'La devise de votre boutique (%1$s) n\'est prise en charge par aucun opérateur K-Pay. Aucune conversion n\'est effectuée : réglez la devise sur l\'une des suivantes dans WooCommerce → Réglages → Général : %2$s.', 'wc-kpay-gateway' ),
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
				__( 'Aucun opérateur sélectionné ne correspond à la devise de votre boutique (%1$s) : la passerelle reste masquée au checkout. Opérateurs compatibles : %2$s.', 'wc-kpay-gateway' ),
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
			echo wpautop( wp_kses_post( $this->description ) );
		}

		if ( 'sandbox' === $this->get_environment() ) {
			echo '<p><strong>' . esc_html__( 'Mode test actif — aucun paiement réel ne sera effectué.', 'wc-kpay-gateway' ) . '</strong></p>';
		}

		$providers = $this->get_active_providers();
		?>
		<fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-form" class="wc-payment-form wc-kpay-fields">
			<p class="form-row form-row-wide validate-required">
				<label for="kpay_provider">
					<?php esc_html_e( 'Opérateur', 'wc-kpay-gateway' ); ?>&nbsp;<span class="required">*</span>
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
					<?php esc_html_e( 'Numéro Mobile Money', 'wc-kpay-gateway' ); ?>&nbsp;<span class="required">*</span>
				</label>
				<input type="tel" class="input-text kpay-input" name="kpay_phone" id="kpay_phone"
					autocomplete="tel" placeholder="<?php esc_attr_e( 'Ex : 6 70 00 00 01', 'wc-kpay-gateway' ); ?>" />
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
		$provider = isset( $_POST['kpay_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_provider'] ) ) : '';
		$phone    = isset( $_POST['kpay_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_phone'] ) ) : '';

		if ( ! array_key_exists( $provider, $this->get_active_providers() ) ) {
			wc_add_notice( __( 'Veuillez sélectionner un opérateur valide.', 'wc-kpay-gateway' ), 'error' );
			return false;
		}

		if ( ! WC_KPay_API::normalize_phone( $phone, $provider ) ) {
			wc_add_notice( __( 'Veuillez saisir un numéro Mobile Money valide.', 'wc-kpay-gateway' ), 'error' );
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
			wc_add_notice( __( 'Commande introuvable.', 'wc-kpay-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$provider = isset( $_POST['kpay_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_provider'] ) ) : '';
		$phone    = isset( $_POST['kpay_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['kpay_phone'] ) ) : '';

		if ( ! array_key_exists( $provider, $this->get_active_providers() ) ) {
			wc_add_notice( __( 'Opérateur invalide.', 'wc-kpay-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$phone = WC_KPay_API::normalize_phone( $phone, $provider );
		if ( ! $phone ) {
			wc_add_notice( __( 'Numéro Mobile Money invalide.', 'wc-kpay-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$environment = $this->get_environment();

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
		if ( WC_KPay_API::provider_uses_integer_amount( $provider ) ) {
			$amount = (int) round( $amount );
		}

		// La devise n'est pas transmise : l'API la déduit du provider.
		$payload = array(
			'amount'      => $amount,
			'provider'    => $provider,
			'phoneNumber' => $phone,
			'externalId'  => $external_id,
			'description' => sprintf(
				/* translators: 1: numéro de commande, 2: nom de la boutique */
				__( 'Commande %1$s sur %2$s', 'wc-kpay-gateway' ),
				$order->get_order_number(),
				get_bloginfo( 'name' )
			),
			'customerName'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'customerEmail' => $order->get_billing_email(),
			'metadata'      => array(
				'orderId'  => (string) $order->get_id(),
				'orderKey' => $order->get_order_key(),
			),
		);

		$this->log( sprintf( 'Init paiement commande #%d (%s, %s)', $order->get_id(), $provider, $environment ) );

		$result = $this->get_api( $environment )->init_payment( $payload );

		if ( is_wp_error( $result ) ) {
			$this->log( sprintf( 'Échec init commande #%d : %s', $order->get_id(), $result->get_error_message() ), 'error' );
			wc_add_notice( $this->customer_facing_error( $result ), 'error' );
			return array( 'result' => 'failure' );
		}

		if ( empty( $result['id'] ) ) {
			$this->log( sprintf( 'Réponse sans id pour la commande #%d', $order->get_id() ), 'error' );
			wc_add_notice( __( 'La création du paiement a échoué. Merci de réessayer.', 'wc-kpay-gateway' ), 'error' );
			return array( 'result' => 'failure' );
		}

		$order->update_meta_data( '_kpay_payment_id', sanitize_text_field( $result['id'] ) );
		$order->update_meta_data( '_kpay_external_id', $external_id );
		$order->update_meta_data( '_kpay_environment', $environment );
		$order->update_meta_data( '_kpay_provider', $provider );
		if ( ! empty( $result['reference'] ) ) {
			$order->update_meta_data( '_kpay_reference', sanitize_text_field( $result['reference'] ) );
		}

		$order->update_status(
			'on-hold',
			sprintf(
				/* translators: 1: opérateur, 2: identifiant K-Pay */
				__( 'Paiement K-Pay initié via %1$s. En attente de confirmation du client (transaction %2$s).', 'wc-kpay-gateway' ),
				$provider,
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
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/**
	 * Traduit une erreur API en message client, sans fuiter de détail interne.
	 */
	private function customer_facing_error( WP_Error $error ) {
		$code = $error->get_error_code();

		if ( 'kpay_api_error_400' === $code ) {
			return __( 'Le paiement a été refusé : vérifiez votre numéro et l\'opérateur sélectionné.', 'wc-kpay-gateway' );
		}
		if ( 'kpay_api_error_429' === $code ) {
			return __( 'Trop de tentatives. Merci de patienter quelques instants avant de réessayer.', 'wc-kpay-gateway' );
		}
		if ( 'kpay_http_error' === $code ) {
			return __( 'Le service de paiement est momentanément injoignable. Merci de réessayer.', 'wc-kpay-gateway' );
		}

		return __( 'Le paiement n\'a pas pu être initié. Merci de réessayer ou de contacter le support.', 'wc-kpay-gateway' );
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
				'pending'   => __( 'En attente de votre confirmation sur le téléphone…', 'wc-kpay-gateway' ),
				'completed' => __( 'Paiement reçu. Votre commande est confirmée.', 'wc-kpay-gateway' ),
				'failed'    => __( 'Le paiement a échoué ou a été annulé.', 'wc-kpay-gateway' ),
				'expired'   => __( 'Nous n\'avons pas reçu de confirmation. Rechargez la page pour connaître le statut.', 'wc-kpay-gateway' ),
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
