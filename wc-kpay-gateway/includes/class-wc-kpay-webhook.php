<?php
/**
 * Webhook K-Pay et vérification de statut par polling.
 *
 * Le webhook est la source d'autorité ; le polling est un filet de secours
 * quand la notification n'arrive pas (URL locale, pare-feu, etc.).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_KPay_Webhook {

	const REST_NAMESPACE = 'kpay/v1';
	const REST_ROUTE     = '/webhook';

	/**
	 * Âge maximal d'une notification, en secondes (spec : 10 minutes).
	 * La tolérance vaut dans les deux sens : l'horloge du serveur peut
	 * légèrement devancer celle de K-Pay.
	 */
	const MAX_WEBHOOK_AGE = 600;

	/**
	 * Durée de mémorisation d'une notification déjà traitée, en secondes.
	 * Couvre largement la fenêtre anti-rejeu : au-delà, l'horodatage suffit.
	 */
	const REPLAY_CACHE_TTL = 900;

	/**
	 * Tolérance sur la comparaison des montants.
	 *
	 * Les devises sans décimales sont arrondies à l'initialisation ; comparer
	 * au centime près produirait des faux positifs sur ces arrondis.
	 */
	const AMOUNT_TOLERANCE = 0.01;

	/**
	 * Intervalle minimal entre deux interrogations de l'API pour une même
	 * commande, en secondes. Le client interroge toutes les 5 s ; on autorise
	 * un appel réel toutes les 4 s pour absorber la dérive sans le bloquer.
	 */
	const POLL_THROTTLE = 4;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_ajax_kpay_check_status', array( __CLASS__, 'handle_status_check' ) );
		add_action( 'wp_ajax_nopriv_kpay_check_status', array( __CLASS__, 'handle_status_check' ) );
		// Retour de la passerelle hébergée, sur la page de confirmation.
		add_action( 'template_redirect', array( __CLASS__, 'handle_gateway_return' ) );
	}

	/**
	 * Retour du client depuis la page de paiement hébergée.
	 *
	 * La règle d'or de la spec : ne marquer payé qu'après une signature VALIDE
	 * *et* un statut COMPLETED reconfirmé par l'API. Le `status` présent dans
	 * l'URL n'est jamais cru sur parole — il est signé, mais la confirmation
	 * serveur reste l'autorité.
	 */
	public static function handle_gateway_return() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- authentifié par signature HMAC.
		if ( empty( $_GET['kpay_return'] ) || empty( $_GET['order_id'] ) ) {
			return;
		}

		$order_id = absint( $_GET['order_id'] );
		$order    = wc_get_order( $order_id );
		$key      = isset( $_GET['order_key'] ) ? sanitize_text_field( wp_unslash( $_GET['order_key'] ) ) : '';

		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return;
		}

		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return;
		}

		if ( $order->is_paid() ) {
			return;
		}

		$params = array();
		foreach ( array( 'status', 'reference', 'externalId', 'ts', 'sig' ) as $field ) {
			$params[ $field ] = isset( $_GET[ $field ] )
				? sanitize_text_field( wp_unslash( $_GET[ $field ] ) )
				: '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$verified = WC_KPay_API::verify_gateway_return( $params, $gateway->get_option( 'gateway_secret' ) );

		if ( is_wp_error( $verified ) ) {
			$gateway->log(
				sprintf( 'Retour passerelle commande #%d refusé : %s', $order_id, $verified->get_error_message() ),
				'error'
			);
			return;
		}

		// L'externalId signé doit désigner cette commande : sans ce contrôle,
		// un retour authentique obtenu sur une commande à 100 XOF validerait
		// n'importe quelle autre commande.
		$stored_external = (string) $order->get_meta( '_kpay_external_id' );
		if ( '' === $stored_external || ! hash_equals( $stored_external, $params['externalId'] ) ) {
			$gateway->log(
				sprintf( 'Retour passerelle : externalId « %s » ne correspond pas à la commande #%d.', $params['externalId'], $order_id ),
				'error'
			);
			return;
		}

		$payment_id = $order->get_meta( '_kpay_payment_id' );
		if ( ! $payment_id ) {
			return;
		}

		$environment = $order->get_meta( '_kpay_environment' );
		if ( ! $environment ) {
			return;
		}

		// Confirmation serveur : c'est elle, et non l'URL, qui fait autorité.
		$payment = $gateway->get_api( $environment )->get_payment( $payment_id );

		if ( is_wp_error( $payment ) ) {
			$gateway->log(
				sprintf( 'Retour passerelle commande #%d : statut non confirmé (%s).', $order_id, $payment->get_error_message() ),
				'error'
			);
			return;
		}

		$status = isset( $payment['status'] ) ? strtoupper( sanitize_text_field( $payment['status'] ) ) : '';
		$amount = isset( $payment['amount'] ) ? $payment['amount'] : null;

		self::apply_status( $order, $status, 'retour passerelle', $gateway, $amount );
	}

	/**
	 * URL à configurer côté K-Pay.
	 */
	public static function get_url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_webhook' ),
				'permission_callback' => '__return_true', // Authentifié par signature HMAC.
			)
		);
	}

	private static function get_gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways['kpay'] ) ? $gateways['kpay'] : null;
	}

	/**
	 * Traite une notification K-Pay.
	 *
	 * La signature HMAC-SHA256 est calculée sur le corps BRUT reçu, avec le
	 * secret webhook, et comparée en temps constant. Sans secret configuré,
	 * on rejette : accepter un webhook non signé permettrait à n'importe qui
	 * de marquer une commande payée.
	 */
	public static function handle_webhook( WP_REST_Request $request ) {
		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			return new WP_REST_Response( array( 'message' => 'Gateway unavailable.' ), 503 );
		}

		$secret = $gateway->get_option( 'webhook_secret' );
		if ( empty( $secret ) ) {
			$gateway->log( 'Webhook reçu mais aucun secret configuré : rejeté.', 'error' );
			return new WP_REST_Response( array( 'message' => 'Webhook secret not configured.' ), 500 );
		}

		$raw       = $request->get_body();
		$signature = $request->get_header( 'x-kpay-signature' );

		if ( empty( $signature ) ) {
			$gateway->log( 'Webhook sans signature : rejeté.', 'error' );
			return new WP_REST_Response( array( 'message' => 'Missing signature.' ), 401 );
		}

		$expected = hash_hmac( 'sha256', $raw, $secret );
		if ( ! hash_equals( $expected, strtolower( trim( $signature ) ) ) ) {
			$gateway->log( 'Webhook avec signature invalide : rejeté.', 'error' );
			return new WP_REST_Response( array( 'message' => 'Invalid signature.' ), 401 );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response( array( 'message' => 'Invalid payload.' ), 400 );
		}

		// Seuls les événements de dépôt pilotent le statut d'une commande.
		// Les familles payout.* (retraits) et refund.* (remboursements)
		// partagent le même secret et le même champ `status` : sans ce filtre,
		// un refund.completed rejoué marquerait la commande payée.
		$event = isset( $payload['event'] ) ? sanitize_text_field( (string) $payload['event'] ) : '';
		if ( '' !== $event && 0 !== strpos( $event, 'payment.' ) ) {
			$gateway->log( sprintf( 'Webhook ignoré : événement « %s » hors périmètre paiement.', $event ) );
			// 200 : la notification est valide, simplement hors périmètre.
			// Une 4xx pousserait K-Pay à la considérer en échec.
			return new WP_REST_Response( array( 'received' => true, 'ignored' => true ), 200 );
		}

		// Anti-rejeu (spec) : une notification signée mais ancienne est
		// rejetée. Sans cette fenêtre, un webhook FAILED capturé pourrait être
		// rejoué plus tard contre une nouvelle tentative sur la même commande.
		// L'horodatage est exigé : le traiter en optionnel ferait disparaître
		// la protection sans bruit le jour où le champ manque.
		if ( empty( $payload['timestamp'] ) ) {
			$gateway->log( 'Webhook sans horodatage : rejeté (anti-rejeu).', 'error' );
			return new WP_REST_Response( array( 'message' => 'Missing timestamp.' ), 400 );
		}

		$sent = strtotime( (string) $payload['timestamp'] );

		if ( false === $sent ) {
			$gateway->log( 'Webhook : horodatage illisible, rejeté.', 'error' );
			return new WP_REST_Response( array( 'message' => 'Invalid timestamp.' ), 400 );
		}

		if ( abs( time() - $sent ) > self::MAX_WEBHOOK_AGE ) {
			$gateway->log(
				sprintf( 'Webhook rejeté : horodatage hors fenêtre (%s).', $payload['timestamp'] ),
				'error'
			);
			return new WP_REST_Response( array( 'message' => 'Timestamp outside accepted window.' ), 401 );
		}

		// Déduplication : à l'intérieur de la fenêtre anti-rejeu, une même
		// notification signée peut être rejouée. L'idempotence de apply_status
		// couvre le cas COMPLETED, mais pas un FAILED rejoué contre une
		// nouvelle tentative encore en attente sur la même commande.
		//
		// La marque n'est posée qu'une fois la notification réellement
		// appliquée (plus bas) : les chemins qui demandent un réessai à K-Pay
		// (commande pas encore enregistrée, passerelle indisponible) doivent
		// pouvoir être rejoués, sans quoi une notification légitime serait
		// définitivement perdue.
		$replay_key = 'kpay_wh_' . md5( $raw );
		if ( false !== get_transient( $replay_key ) ) {
			$gateway->log( 'Webhook déjà traité : ignoré (rejeu).' );
			return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
		}

		$order = self::locate_order( $payload );
		if ( ! $order ) {
			$gateway->log( 'Webhook : commande introuvable pour ' . wp_json_encode( $payload ), 'error' );
			// 404 : K-Pay ne réessaiera pas sur une 4xx, ce qui est le
			// comportement voulu pour une commande qui n'existe pas.
			return new WP_REST_Response( array( 'message' => 'Order not found.' ), 404 );
		}

		// L'identifiant de la notification doit correspondre à celui stocké.
		$payment_id = isset( $payload['paymentId'] ) ? sanitize_text_field( $payload['paymentId'] ) : '';
		$stored_id  = (string) $order->get_meta( '_kpay_payment_id' );

		if ( '' === $stored_id ) {
			// K-Pay peut notifier avant que process_payment() ait fini d'écrire
			// l'identifiant. Une 5xx déclenche un réessai côté K-Pay, là où une
			// 4xx abandonnerait définitivement une notification légitime.
			$gateway->log(
				sprintf( 'Webhook reçu pour la commande #%d avant enregistrement du paiement : réessai demandé.', $order->get_id() ),
				'error'
			);
			return new WP_REST_Response( array( 'message' => 'Payment not yet recorded, retry.' ), 503 );
		}

		if ( '' === $payment_id || ! hash_equals( $stored_id, $payment_id ) ) {
			$gateway->log(
				sprintf( 'Webhook : paymentId "%s" ne correspond pas à la commande #%d.', $payment_id, $order->get_id() ),
				'error'
			);
			return new WP_REST_Response( array( 'message' => 'Payment mismatch.' ), 404 );
		}

		// Tous les contrôles sont passés : la notification va être appliquée,
		// on peut la marquer comme traitée pour neutraliser un rejeu.
		set_transient( $replay_key, 1, self::REPLAY_CACHE_TTL );

		$status = isset( $payload['status'] ) ? strtoupper( sanitize_text_field( $payload['status'] ) ) : '';
		$amount = isset( $payload['amount'] ) ? $payload['amount'] : null;
		self::apply_status( $order, $status, 'webhook', $gateway, $amount );

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Retrouve la commande visée par une notification.
	 *
	 * On privilégie metadata.orderId (transmis à l'init, donc toujours présent
	 * pour nos paiements) ; l'externalId sert de repli. La correspondance est
	 * ensuite revérifiée par l'appelant via le paymentId stocké.
	 */
	private static function locate_order( array $payload ) {
		if ( ! empty( $payload['metadata']['orderId'] ) ) {
			$order = wc_get_order( absint( $payload['metadata']['orderId'] ) );
			if ( $order ) {
				return $order;
			}
		}

		if ( empty( $payload['externalId'] ) ) {
			return false;
		}

		$external_id = sanitize_text_field( $payload['externalId'] );

		// Nos externalId ont la forme WC-<orderId>-<tentative> : on en extrait
		// l'identifiant de commande plutôt que d'interroger les métadonnées,
		// dont la syntaxe de requête diffère entre HPOS et l'ancien stockage.
		if ( preg_match( '/^WC-(\d+)-\d+$/', $external_id, $matches ) ) {
			$order = wc_get_order( absint( $matches[1] ) );
			if ( $order && hash_equals( (string) $order->get_meta( '_kpay_external_id' ), $external_id ) ) {
				return $order;
			}
		}

		// Repli : requête sur métadonnée, compatible HPOS et post meta.
		// Repli rare : seules les références au format hérité arrivent ici, et
		// la requête est bornée à une ligne sur une métadonnée indexée.
		$orders = wc_get_orders( array(
			'limit'      => 1,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query' => array(
				array(
					'key'     => '_kpay_external_id',
					'value'   => $external_id,
					'compare' => '=',
				),
			),
		) );

		return ! empty( $orders ) ? $orders[0] : false;
	}

	/**
	 * Applique un statut K-Pay à la commande, de façon idempotente.
	 *
	 * @param float|string|null $paid_amount Montant réellement encaissé, tel
	 *                                       que rapporté par K-Pay. Null si la
	 *                                       source ne le fournit pas.
	 */
	private static function apply_status( WC_Order $order, $status, $source, $gateway, $paid_amount = null ) {
		if ( 'COMPLETED' === $status ) {
			if ( $order->is_paid() ) {
				return;
			}

			// Le montant encaissé doit couvrir le total de la commande. Sans
			// ce contrôle, une confirmation portant sur un montant inférieur
			// (panier modifié après l'initiation, paiement d'une tentative
			// antérieure moins chère) validerait la commande au prix fort.
			if ( null !== $paid_amount && ! self::amount_covers_order( $order, $paid_amount ) ) {
				$expected = (float) $order->get_total();
				$order->add_order_note( sprintf(
					/* translators: 1: montant reçu, 2: montant attendu, 3: source */
					__( 'Paiement K-Pay incohérent : %1$s reçu pour %2$s attendu (%3$s). Commande laissée en attente pour vérification manuelle.', 'k-pay-for-woocommerce' ),
					(float) $paid_amount,
					$expected,
					$source
				) );
				$gateway->log(
					sprintf(
						'Commande #%d : montant insuffisant (%s reçu / %s attendu, %s). Paiement NON validé.',
						$order->get_id(),
						(float) $paid_amount,
						$expected,
						$source
					),
					'error'
				);
				return;
			}

			$payment_id = $order->get_meta( '_kpay_payment_id' );
			$order->add_order_note( sprintf(
				/* translators: 1: source de la confirmation, 2: identifiant K-Pay */
				__( 'Paiement K-Pay confirmé (%1$s), transaction %2$s.', 'k-pay-for-woocommerce' ),
				$source,
				$payment_id
			) );
			$order->payment_complete( $payment_id );
			$gateway->log( sprintf( 'Commande #%d payée (%s).', $order->get_id(), $source ) );
			return;
		}

		if ( in_array( $status, array( 'FAILED', 'CANCELLED' ), true ) ) {
			if ( $order->has_status( 'failed' ) || $order->is_paid() ) {
				return;
			}
			$order->update_status( 'failed', sprintf(
				/* translators: 1: statut, 2: source */
				__( 'Paiement K-Pay %1$s (%2$s).', 'k-pay-for-woocommerce' ),
				$status,
				$source
			) );
			$gateway->log( sprintf( 'Commande #%d en échec : %s (%s).', $order->get_id(), $status, $source ) );
		}
	}

	/**
	 * Le montant encaissé couvre-t-il le total de la commande ?
	 *
	 * La comparaison se fait contre le montant réellement demandé à K-Pay :
	 * pour les devises sans décimales, process_payment() arrondit le total
	 * avant l'appel, et K-Pay confirme donc ce montant arrondi — comparer au
	 * total brut rejetterait à tort un paiement légitime.
	 */
	private static function amount_covers_order( WC_Order $order, $paid_amount ) {
		$expected = (float) $order->get_total();
		$provider = (string) $order->get_meta( '_kpay_provider' );

		if ( $provider && WC_KPay_API::provider_uses_integer_amount( $provider ) ) {
			$expected = (float) (int) round( $expected );
		}

		return (float) $paid_amount + self::AMOUNT_TOLERANCE >= $expected;
	}

	/**
	 * Polling depuis la page de confirmation : interroge l'API et applique
	 * le statut. Sert de secours si le webhook n'arrive pas.
	 */
	public static function handle_status_check() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'kpay_status_' . $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Requête invalide.', 'k-pay-for-woocommerce' ) ), 403 );
		}

		// Le nonce seul ne prouve pas la propriété de la commande : on vérifie
		// la clé de commande, comme WooCommerce le fait sur la page de reçu.
		// Une commande absente et une clé invalide renvoient la même réponse :
		// distinguer les deux permettrait d'énumérer les commandes existantes.
		$order = wc_get_order( $order_id );
		$key   = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';

		if ( ! $order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Requête invalide.', 'k-pay-for-woocommerce' ) ), 403 );
		}

		if ( $order->is_paid() ) {
			wp_send_json_success( array( 'status' => 'COMPLETED' ) );
		}
		if ( $order->has_status( 'failed' ) ) {
			wp_send_json_success( array( 'status' => 'FAILED' ) );
		}

		$payment_id = $order->get_meta( '_kpay_payment_id' );
		if ( ! $payment_id ) {
			wp_send_json_error( array( 'message' => __( 'Aucune transaction associée.', 'k-pay-for-woocommerce' ) ), 404 );
		}

		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'Passerelle indisponible.', 'k-pay-for-woocommerce' ) ), 503 );
		}

		// Sans environnement enregistré, on ne devine pas : interroger avec les
		// clés du réglage courant ferait échouer le polling de toute commande
		// en cours dès que le marchand bascule sandbox -> live.
		$environment = $order->get_meta( '_kpay_environment' );
		if ( ! $environment ) {
			$gateway->log(
				sprintf( 'Polling commande #%d : environnement inconnu, statut indéterminable.', $order_id ),
				'error'
			);
			wp_send_json_error( array( 'message' => __( 'Statut indisponible pour cette commande.', 'k-pay-for-woocommerce' ) ), 409 );
		}

		// Throttling : chaque appel valide déclenche une requête sortante vers
		// K-Pay, dont le quota est de 100/minute pour toute la boutique. Sans
		// cette limite, une boucle sur une seule commande épuiserait le quota
		// et casserait le polling de toutes les autres.
		$throttle_key = 'kpay_poll_' . $order_id;
		if ( false !== get_transient( $throttle_key ) ) {
			wp_send_json_success( array( 'status' => 'PENDING' ) );
		}
		set_transient( $throttle_key, 1, self::POLL_THROTTLE );

		$payment = $gateway->get_api( $environment )->get_payment( $payment_id );

		if ( is_wp_error( $payment ) ) {
			$gateway->log( sprintf( 'Polling commande #%d : %s', $order_id, $payment->get_error_message() ), 'error' );
			wp_send_json_success( array( 'status' => 'PENDING' ) );
		}

		$status = isset( $payment['status'] ) ? strtoupper( sanitize_text_field( $payment['status'] ) ) : 'PENDING';
		$amount = isset( $payment['amount'] ) ? $payment['amount'] : null;
		self::apply_status( $order, $status, 'polling', $gateway, $amount );

		wp_send_json_success( array( 'status' => $status ) );
	}
}
