<?php
/**
 * Menu K-Pay : soldes des wallets et retraits Mobile Money.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_KPay_Admin {

	const MENU_SLUG      = 'kpay';
	const PAYOUTS_SLUG   = 'kpay-payouts';
	const BALANCE_CACHE  = 'kpay_balances_';
	const HISTORY_OPTION = 'kpay_withdrawal_history_';
	const CAPABILITY     = 'manage_woocommerce';

	/**
	 * Clé d'historique de l'environnement demandé.
	 *
	 * Les données de test et de production sont stockées séparément : un
	 * retrait sandbox ne doit jamais apparaître dans l'historique réel, ni
	 * l'inverse.
	 */
	private static function history_key( $environment ) {
		return self::HISTORY_OPTION . ( 'live' === $environment ? 'live' : 'sandbox' );
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_kpay_withdraw', array( __CLASS__, 'handle_withdrawal' ) );
		add_action( 'admin_post_kpay_refresh_balance', array( __CLASS__, 'handle_refresh' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'K-Pay', 'wc-kpay-gateway' ),
			__( 'K-Pay', 'wc-kpay-gateway' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_balance_page' ),
			self::menu_icon(),
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Soldes', 'wc-kpay-gateway' ),
			__( 'Soldes', 'wc-kpay-gateway' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_balance_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Retraits', 'wc-kpay-gateway' ),
			__( 'Retraits', 'wc-kpay-gateway' ),
			self::CAPABILITY,
			self::PAYOUTS_SLUG,
			array( __CLASS__, 'render_payouts_page' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Réglages', 'wc-kpay-gateway' ),
			__( 'Réglages', 'wc-kpay-gateway' ),
			self::CAPABILITY,
			'admin.php?page=wc-settings&tab=checkout&section=kpay'
		);
	}

	/**
	 * Icône du menu en data URI : le SVG doit être inline, WordPress ne
	 * chargeant pas de fichier externe pour les icônes de menu.
	 */
	private static function menu_icon() {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><g fill="black">'
			. '<rect x="10" y="7" width="17" height="82" rx="3.5"/>'
			. '<path d="M87.9 7H63.1a3.5 3.5 0 0 0-2.5 1.1L34.2 35.9a1.2 1.2 0 0 0 0 1.7l10.2 10.6a3.5 3.5 0 0 0 5 0L88.7 8.9A1.1 1.1 0 0 0 87.9 7z"/>'
			. '<path d="M87.9 89H63.1a3.5 3.5 0 0 1-2.5-1.1L34.2 60.1a1.2 1.2 0 0 1 0-1.7l10.2-10.6a3.5 3.5 0 0 1 5 0L88.7 87.1a1.1 1.1 0 0 1-.8 1.9z"/>'
			. '</g></svg>';

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	public static function enqueue_styles( $hook ) {
		if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'wc-kpay-admin',
			WC_KPAY_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			wc_kpay_asset_version( 'assets/css/admin.css' )
		);
	}

	/**
	 * Passerelle configurée, ou null si WooCommerce n'est pas prêt.
	 *
	 * @return WC_KPay_Gateway|null
	 */
	private static function get_gateway() {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return null;
		}
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['kpay'] ) ? $gateways['kpay'] : null;
	}

	/**
	 * Soldes, avec cache court : l'API est limitée à 100 requêtes/minute et
	 * un rechargement de page ne doit pas la solliciter à chaque fois.
	 *
	 * @return array|WP_Error
	 */
	public static function get_balances( $force_refresh = false ) {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return new WP_Error( 'kpay_no_gateway', __( 'Passerelle K-Pay indisponible.', 'wc-kpay-gateway' ) );
		}

		$environment = $gateway->get_environment();
		$cache_key   = self::BALANCE_CACHE . $environment;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$balances = $gateway->get_api()->get_balances();

		if ( is_wp_error( $balances ) ) {
			return $balances;
		}

		set_transient( $cache_key, $balances, 60 );

		return $balances;
	}

	private static function clear_balance_cache() {
		delete_transient( self::BALANCE_CACHE . 'sandbox' );
		delete_transient( self::BALANCE_CACHE . 'live' );
	}

	/**
	 * Formate un montant selon les décimales de la devise.
	 */
	public static function format_amount( $amount, $currency ) {
		$no_decimals = array( 'XAF', 'XOF', 'RWF', 'UGX' );
		$decimals    = in_array( $currency, $no_decimals, true ) ? 0 : 2;

		return number_format_i18n( (float) $amount, $decimals ) . ' ' . $currency;
	}

	// ---------------------------------------------------------------
	// Page : Soldes
	// ---------------------------------------------------------------

	public static function render_balance_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'wc-kpay-gateway' ) );
		}

		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>'
				. esc_html__( 'WooCommerce doit être actif.', 'wc-kpay-gateway' )
				. '</p></div></div>';
			return;
		}

		$environment = $gateway->get_environment();
		$balances    = self::get_balances();
		?>
		<div class="wrap kpay-admin">
			<h1 class="kpay-admin__title">
				<?php esc_html_e( 'K-Pay — Soldes', 'wc-kpay-gateway' ); ?>
				<span class="kpay-badge kpay-badge--<?php echo esc_attr( $environment ); ?>">
					<?php echo 'live' === $environment
						? esc_html__( 'Production', 'wc-kpay-gateway' )
						: esc_html__( 'Sandbox', 'wc-kpay-gateway' ); ?>
				</span>
			</h1>

			<?php self::render_admin_notices(); ?>

			<?php if ( is_wp_error( $balances ) ) : ?>
				<div class="notice notice-error">
					<p><?php echo esc_html( $balances->get_error_message() ); ?></p>
					<p><?php esc_html_e( 'Vérifiez vos clés API dans les réglages de la passerelle.', 'wc-kpay-gateway' ); ?></p>
				</div>
			<?php elseif ( empty( $balances ) ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'Aucun wallet n\'est encore approvisionné.', 'wc-kpay-gateway' ); ?></p>
				</div>
			<?php else : ?>
				<div class="kpay-cards">
					<?php foreach ( $balances as $wallet ) : ?>
						<?php self::render_balance_card( $wallet ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kpay-refresh">
				<input type="hidden" name="action" value="kpay_refresh_balance" />
				<?php wp_nonce_field( 'kpay_refresh_balance' ); ?>
				<button type="submit" class="button">
					<?php esc_html_e( 'Actualiser les soldes', 'wc-kpay-gateway' ); ?>
				</button>
				<span class="description">
					<?php esc_html_e( 'Les soldes sont mis en cache une minute.', 'wc-kpay-gateway' ); ?>
				</span>
			</form>
		</div>
		<?php
	}

	/**
	 * Carte de solde. Une devise de zone couvre plusieurs pays : ils sont
	 * listés pour lever l'ambiguïté « solde XAF = Cameroun ? Gabon ? ».
	 */
	private static function render_balance_card( array $wallet ) {
		$currency  = isset( $wallet['currency'] ) ? $wallet['currency'] : '';
		$available = isset( $wallet['availableBalance'] ) ? $wallet['availableBalance'] : 0;
		$total     = isset( $wallet['balance'] ) ? $wallet['balance'] : 0;
		$reserved  = isset( $wallet['reservedBalance'] ) ? $wallet['reservedBalance'] : 0;

		$countries = array_map(
			array( 'WC_KPay_API', 'get_country_label' ),
			WC_KPay_API::get_countries_for_currency( $currency )
		);
		?>
		<div class="kpay-card">
			<div class="kpay-card__currency"><?php echo esc_html( $currency ); ?></div>

			<div class="kpay-card__amount">
				<?php echo esc_html( self::format_amount( $available, $currency ) ); ?>
			</div>
			<div class="kpay-card__label"><?php esc_html_e( 'Disponible pour retrait', 'wc-kpay-gateway' ); ?></div>

			<?php if ( $countries ) : ?>
				<div class="kpay-card__countries">
					<?php echo esc_html( implode( ' · ', $countries ) ); ?>
				</div>
			<?php endif; ?>

			<dl class="kpay-card__detail">
				<dt><?php esc_html_e( 'Solde total', 'wc-kpay-gateway' ); ?></dt>
				<dd><?php echo esc_html( self::format_amount( $total, $currency ) ); ?></dd>
				<dt><?php esc_html_e( 'Réservé', 'wc-kpay-gateway' ); ?></dt>
				<dd><?php echo esc_html( self::format_amount( $reserved, $currency ) ); ?></dd>
			</dl>

			<?php if ( (float) $available > 0 ) : ?>
				<a class="button button-secondary"
					href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAYOUTS_SLUG . '&currency=' . rawurlencode( $currency ) ) ); ?>">
					<?php esc_html_e( 'Effectuer un retrait', 'wc-kpay-gateway' ); ?>
				</a>
			<?php endif; ?>
		</div>
		<?php
	}

	// ---------------------------------------------------------------
	// Page : Retraits
	// ---------------------------------------------------------------

	public static function render_payouts_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'wc-kpay-gateway' ) );
		}

		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			echo '<div class="wrap"><div class="notice notice-error"><p>'
				. esc_html__( 'WooCommerce doit être actif.', 'wc-kpay-gateway' )
				. '</p></div></div>';
			return;
		}

		$environment = $gateway->get_environment();
		$balances    = self::get_balances();
		$selected    = isset( $_GET['currency'] ) ? sanitize_text_field( wp_unslash( $_GET['currency'] ) ) : '';
		?>
		<div class="wrap kpay-admin">
			<h1 class="kpay-admin__title">
				<?php esc_html_e( 'K-Pay — Retraits', 'wc-kpay-gateway' ); ?>
				<span class="kpay-badge kpay-badge--<?php echo esc_attr( $environment ); ?>">
					<?php echo 'live' === $environment
						? esc_html__( 'Production', 'wc-kpay-gateway' )
						: esc_html__( 'Sandbox', 'wc-kpay-gateway' ); ?>
				</span>
			</h1>

			<?php self::render_admin_notices(); ?>

			<?php if ( 'live' === $environment ) : ?>
				<div class="notice notice-warning">
					<p><strong><?php esc_html_e( 'Environnement de production : les retraits transfèrent des fonds réels et sont irréversibles.', 'wc-kpay-gateway' ); ?></strong></p>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'Mode sandbox : aucun argent réel n\'est transféré. Le numéro saisi détermine le résultat (237653456789 réussit, 237653456089 échoue).', 'wc-kpay-gateway' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( is_wp_error( $balances ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $balances->get_error_message() ); ?></p></div>
				<?php return; ?>
			<?php endif; ?>

			<?php self::render_withdrawal_form( $balances, $selected ); ?>
			<?php self::render_history( $environment ); ?>
		</div>
		<?php
	}

	private static function render_withdrawal_form( $balances, $selected_currency ) {
		$currencies = wp_list_pluck( (array) $balances, 'currency' );

		if ( empty( $currencies ) ) {
			echo '<div class="notice notice-info inline"><p>'
				. esc_html__( 'Aucun wallet disponible : approvisionnez un wallet avant d\'effectuer un retrait.', 'wc-kpay-gateway' )
				. '</p></div>';
			return;
		}

		$selected_currency = in_array( $selected_currency, $currencies, true ) ? $selected_currency : $currencies[0];
		?>
		<div class="kpay-panel">
			<h2><?php esc_html_e( 'Nouveau retrait', 'wc-kpay-gateway' ); ?></h2>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="kpay-form">
				<input type="hidden" name="action" value="kpay_withdraw" />
				<?php wp_nonce_field( 'kpay_withdraw' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="kpay_wallet"><?php esc_html_e( 'Wallet à débiter', 'wc-kpay-gateway' ); ?></label>
						</th>
						<td>
							<select name="wallet_currency" id="kpay_wallet" required>
								<?php foreach ( (array) $balances as $wallet ) : ?>
									<?php
									$countries = array_map(
										array( 'WC_KPay_API', 'get_country_label' ),
										WC_KPay_API::get_countries_for_currency( $wallet['currency'] )
									);
									?>
									<option value="<?php echo esc_attr( $wallet['currency'] ); ?>"
										<?php selected( $selected_currency, $wallet['currency'] ); ?>>
										<?php
										printf(
											'%s — %s (%s)',
											esc_html( $wallet['currency'] ),
											esc_html( self::format_amount( $wallet['availableBalance'], $wallet['currency'] ) ),
											esc_html( implode( ', ', $countries ) )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Une devise de zone (XAF, XOF) est partagée entre plusieurs pays.', 'wc-kpay-gateway' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="kpay_provider"><?php esc_html_e( 'Opérateur du bénéficiaire', 'wc-kpay-gateway' ); ?></label>
						</th>
						<td>
							<select name="provider" id="kpay_provider" required>
								<?php foreach ( WC_KPay_API::get_providers() as $code => $provider ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>"
										data-currency="<?php echo esc_attr( $provider['currency'] ); ?>">
										<?php
										printf(
											'%s — %s',
											esc_html( $provider['label'] ),
											esc_html( WC_KPay_API::get_country_label( WC_KPay_API::get_provider_country( $code ) ) )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Si l\'opérateur relève d\'un autre pays que le wallet, la conversion est automatique au taux en vigueur.', 'wc-kpay-gateway' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="kpay_phone"><?php esc_html_e( 'Numéro du bénéficiaire', 'wc-kpay-gateway' ); ?></label>
						</th>
						<td>
							<input type="tel" name="phone" id="kpay_phone" class="regular-text"
								placeholder="<?php esc_attr_e( 'Ex : 237653456789', 'wc-kpay-gateway' ); ?>" required />
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="kpay_amount"><?php esc_html_e( 'Montant', 'wc-kpay-gateway' ); ?></label>
						</th>
						<td>
							<input type="number" name="amount" id="kpay_amount" class="regular-text"
								min="100" step="any" required />
							<p class="description">
								<?php esc_html_e( 'Montant en devise du wallet débité. Une commission de 5 % est prélevée ; minimum 100.', 'wc-kpay-gateway' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="kpay_description"><?php esc_html_e( 'Description', 'wc-kpay-gateway' ); ?></label>
						</th>
						<td>
							<input type="text" name="description" id="kpay_description" class="regular-text"
								maxlength="140" />
							<p class="description"><?php esc_html_e( 'Facultatif, pour votre réconciliation.', 'wc-kpay-gateway' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Confirmation', 'wc-kpay-gateway' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="confirm" value="1" required />
								<?php esc_html_e( 'Je confirme le montant et le numéro : un retrait ne peut pas être annulé.', 'wc-kpay-gateway' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Envoyer le retrait', 'wc-kpay-gateway' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Historique local des retraits, limité à l'environnement courant :
	 * afficher des retraits de test à côté de retraits réels rendrait la
	 * liste trompeuse.
	 */
	private static function render_history( $environment ) {
		$history = get_option( self::history_key( $environment ), array() );

		if ( empty( $history ) ) {
			return;
		}
		?>
		<div class="kpay-panel">
			<h2>
				<?php
				echo esc_html( 'live' === $environment
					? __( 'Retraits récents (production)', 'wc-kpay-gateway' )
					: __( 'Retraits récents (sandbox)', 'wc-kpay-gateway' ) );
				?>
			</h2>
			<p class="description">
				<?php esc_html_e( 'Seuls les retraits de l\'environnement courant sont listés.', 'wc-kpay-gateway' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Date', 'wc-kpay-gateway' ); ?></th>
						<th><?php esc_html_e( 'Bénéficiaire', 'wc-kpay-gateway' ); ?></th>
						<th><?php esc_html_e( 'Montant', 'wc-kpay-gateway' ); ?></th>
						<th><?php esc_html_e( 'Statut', 'wc-kpay-gateway' ); ?></th>
						<th><?php esc_html_e( 'Référence', 'wc-kpay-gateway' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_slice( $history, 0, 20 ) as $item ) : ?>
						<?php
						// Les lignes enregistrées par une version antérieure
						// peuvent ne pas porter toutes les clés : on complète
						// plutôt que d'émettre un avertissement PHP.
						$item = array_merge(
							array(
								'date'      => '—',
								'phone'     => '—',
								'provider'  => '—',
								'amount'    => 0,
								'currency'  => '',
								'status'    => 'PENDING',
								'reference' => '—',
								'isTest'    => false,
							),
							(array) $item
						);
						?>
						<tr>
							<td><?php echo esc_html( $item['date'] ); ?></td>
							<td>
								<?php echo esc_html( $item['phone'] ); ?><br />
								<small><?php echo esc_html( $item['provider'] ); ?></small>
							</td>
							<td><?php echo esc_html( self::format_amount( $item['amount'], $item['currency'] ) ); ?></td>
							<td>
								<span class="kpay-status kpay-status--<?php echo esc_attr( strtolower( $item['status'] ) ); ?>">
									<?php echo esc_html( $item['status'] ); ?>
								</span>
								<?php if ( ! empty( $item['isTest'] ) ) : ?>
									<small>(<?php esc_html_e( 'test', 'wc-kpay-gateway' ); ?>)</small>
								<?php endif; ?>
							</td>
							<td><code><?php echo esc_html( $item['reference'] ); ?></code></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	// ---------------------------------------------------------------
	// Actions
	// ---------------------------------------------------------------

	public static function handle_refresh() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'wc-kpay-gateway' ) );
		}
		check_admin_referer( 'kpay_refresh_balance' );

		self::clear_balance_cache();
		self::get_balances( true );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Traite une demande de retrait.
	 *
	 * Le montant est validé côté serveur contre le solde disponible : l'API
	 * renverrait un 422, mais autant l'éviter et donner un message clair.
	 */
	public static function handle_withdrawal() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'wc-kpay-gateway' ) );
		}
		check_admin_referer( 'kpay_withdraw' );

		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			self::redirect_with_notice( 'error', __( 'Passerelle indisponible.', 'wc-kpay-gateway' ) );
		}

		if ( empty( $_POST['confirm'] ) ) {
			self::redirect_with_notice( 'error', __( 'Vous devez confirmer le retrait avant de l\'envoyer.', 'wc-kpay-gateway' ) );
		}

		// Fixé une fois pour toute la requête : c'est lui qui détermine les
		// clés utilisées et l'historique dans lequel le retrait est consigné.
		$environment = $gateway->get_environment();

		$provider = isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '';
		$phone    = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$amount   = isset( $_POST['amount'] ) ? (float) wp_unslash( $_POST['amount'] ) : 0;
		$wallet   = isset( $_POST['wallet_currency'] ) ? sanitize_text_field( wp_unslash( $_POST['wallet_currency'] ) ) : '';
		$desc     = isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

		$providers = WC_KPay_API::get_providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			self::redirect_with_notice( 'error', __( 'Opérateur invalide.', 'wc-kpay-gateway' ) );
		}

		$normalized = WC_KPay_API::normalize_phone( $phone, $provider );
		if ( ! $normalized ) {
			self::redirect_with_notice(
				'error',
				__( 'Numéro invalide pour cet opérateur : vérifiez l\'indicatif et le format.', 'wc-kpay-gateway' )
			);
		}

		$minimum = WC_KPay_API::get_minimum_withdrawal( $wallet );
		if ( $amount < $minimum ) {
			self::redirect_with_notice(
				'error',
				sprintf(
					/* translators: 1: montant minimum, 2: devise */
					__( 'Le montant minimum d\'un retrait est de %1$s %2$s.', 'wc-kpay-gateway' ),
					$minimum,
					$wallet
				)
			);
		}

		// Contrôle du solde avant l'appel. Ce contrôle échoue fermé : si le
		// solde est indisponible ou si le wallet demandé n'existe pas, on
		// refuse plutôt que d'envoyer un montant non vérifié.
		$balances = self::get_balances( true );

		if ( is_wp_error( $balances ) ) {
			self::redirect_with_notice(
				'error',
				sprintf(
					/* translators: %s: message d'erreur */
					__( 'Solde indisponible, retrait annulé par précaution : %s', 'wc-kpay-gateway' ),
					$balances->get_error_message()
				)
			);
		}

		$source_wallet = null;
		foreach ( (array) $balances as $entry ) {
			if ( isset( $entry['currency'] ) && $entry['currency'] === $wallet ) {
				$source_wallet = $entry;
				break;
			}
		}

		if ( null === $source_wallet ) {
			self::redirect_with_notice(
				'error',
				sprintf(
					/* translators: %s: devise demandée */
					__( 'Aucun wallet %s n\'existe sur votre compte K-Pay.', 'wc-kpay-gateway' ),
					$wallet
				)
			);
		}

		$available = isset( $source_wallet['availableBalance'] ) ? (float) $source_wallet['availableBalance'] : 0;

		if ( $amount > $available ) {
			self::redirect_with_notice(
				'error',
				sprintf(
					/* translators: %s: solde disponible */
					__( 'Solde insuffisant : %s disponible (commission de 5 %% incluse dans le débit).', 'wc-kpay-gateway' ),
					self::format_amount( $available, $wallet )
				)
			);
		}

		if ( WC_KPay_API::provider_uses_integer_amount( $provider ) ) {
			$amount = (int) round( $amount );
		}

		$payload = array(
			'amount'      => $amount,
			'provider'    => $provider,
			'phoneNumber' => $normalized,
			'externalId'  => 'WD-' . wp_generate_uuid4(),
		);

		if ( $desc ) {
			$payload['description'] = $desc;
		}

		// sourceCountry rend le retrait cross-country explicite lorsque le
		// pays de l'opérateur ne partage pas la devise du wallet débité.
		$provider_currency = $providers[ $provider ]['currency'];
		if ( $provider_currency !== $wallet ) {
			$source_countries = WC_KPay_API::get_countries_for_currency( $wallet );
			if ( ! empty( $source_countries ) ) {
				$payload['sourceCountry'] = $source_countries[0];
			}
		}

		$result = $gateway->get_api()->init_withdrawal( $payload );

		if ( is_wp_error( $result ) ) {
			$gateway->log( 'Échec du retrait : ' . $result->get_error_message(), 'error' );
			self::redirect_with_notice(
				'error',
				sprintf(
					/* translators: %s: message d'erreur de l'API */
					__( 'Le retrait a échoué : %s', 'wc-kpay-gateway' ),
					$result->get_error_message()
				)
			);
		}

		self::record_withdrawal( $result, $provider, $normalized, $environment );
		self::clear_balance_cache();

		$gateway->log( sprintf(
			'Retrait initié : %s %s vers %s (%s)',
			$amount,
			$wallet,
			$normalized,
			isset( $result['reference'] ) ? $result['reference'] : '?'
		) );

		self::redirect_with_notice(
			'success',
			sprintf(
				/* translators: 1: montant net, 2: numéro, 3: référence */
				__( 'Retrait initié : %1$s vers %2$s (référence %3$s).', 'wc-kpay-gateway' ),
				self::format_amount(
					isset( $result['netAmount'] ) ? $result['netAmount'] : $amount,
					isset( $result['currency'] ) ? $result['currency'] : $wallet
				),
				$normalized,
				isset( $result['reference'] ) ? $result['reference'] : '—'
			)
		);
	}

	/**
	 * Journalise le retrait localement, dans l'historique de son
	 * environnement : l'API reste la source de vérité, mais l'historique
	 * évite d'ouvrir le tableau de bord K-Pay pour un suivi rapide.
	 */
	private static function record_withdrawal( array $result, $provider, $phone, $environment ) {
		$key     = self::history_key( $environment );
		$history = get_option( $key, array() );

		array_unshift( $history, array(
			'date'        => current_time( 'mysql' ),
			'id'          => isset( $result['id'] ) ? $result['id'] : '',
			'reference'   => isset( $result['reference'] ) ? $result['reference'] : '',
			'phone'       => $phone,
			'provider'    => $provider,
			'amount'      => isset( $result['amount'] ) ? $result['amount'] : 0,
			'currency'    => isset( $result['currency'] ) ? $result['currency'] : '',
			'status'      => isset( $result['status'] ) ? $result['status'] : 'PENDING',
			'environment' => $environment,
			'isTest'      => ! empty( $result['isTest'] ),
		) );

		update_option( $key, array_slice( $history, 0, 50 ), false );
	}

	private static function redirect_with_notice( $type, $message ) {
		set_transient( 'kpay_admin_notice_' . get_current_user_id(), array(
			'type'    => $type,
			'message' => $message,
		), 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAYOUTS_SLUG ) );
		exit;
	}

	private static function render_admin_notices() {
		$key    = 'kpay_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ),
			esc_html( $notice['message'] )
		);
	}
}
