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

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
		add_action( 'wp_ajax_kpay_check_status', array( __CLASS__, 'handle_status_check' ) );
		add_action( 'wp_ajax_nopriv_kpay_check_status', array( __CLASS__, 'handle_status_check' ) );
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

		// Anti-rejeu (spec) : une notification signée mais ancienne est
		// rejetée. Sans cette fenêtre, un webhook FAILED capturé pourrait être
		// rejoué plus tard contre une nouvelle tentative sur la même commande.
		if ( ! empty( $payload['timestamp'] ) ) {
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

		$status = isset( $payload['status'] ) ? strtoupper( sanitize_text_field( $payload['status'] ) ) : '';
		self::apply_status( $order, $status, 'webhook', $gateway );

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
		$orders = wc_get_orders( array(
			'limit'      => 1,
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
	 */
	private static function apply_status( WC_Order $order, $status, $source, $gateway ) {
		if ( 'COMPLETED' === $status ) {
			if ( $order->is_paid() ) {
				return;
			}
			$payment_id = $order->get_meta( '_kpay_payment_id' );
			$order->add_order_note( sprintf(
				/* translators: 1: source de la confirmation, 2: identifiant K-Pay */
				__( 'Paiement K-Pay confirmé (%1$s), transaction %2$s.', 'wc-kpay-gateway' ),
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
				__( 'Paiement K-Pay %1$s (%2$s).', 'wc-kpay-gateway' ),
				$status,
				$source
			) );
			$gateway->log( sprintf( 'Commande #%d en échec : %s (%s).', $order->get_id(), $status, $source ) );
		}
	}

	/**
	 * Polling depuis la page de confirmation : interroge l'API et applique
	 * le statut. Sert de secours si le webhook n'arrive pas.
	 */
	public static function handle_status_check() {
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$nonce    = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

		if ( ! $order_id || ! wp_verify_nonce( $nonce, 'kpay_status_' . $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Requête invalide.', 'wc-kpay-gateway' ) ), 403 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Commande introuvable.', 'wc-kpay-gateway' ) ), 404 );
		}

		// Le nonce seul ne prouve pas la propriété de la commande : on vérifie
		// la clé de commande, comme WooCommerce le fait sur la page de reçu.
		$key = isset( $_POST['order_key'] ) ? sanitize_text_field( wp_unslash( $_POST['order_key'] ) ) : '';
		if ( ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Requête invalide.', 'wc-kpay-gateway' ) ), 403 );
		}

		if ( $order->is_paid() ) {
			wp_send_json_success( array( 'status' => 'COMPLETED' ) );
		}
		if ( $order->has_status( 'failed' ) ) {
			wp_send_json_success( array( 'status' => 'FAILED' ) );
		}

		$payment_id = $order->get_meta( '_kpay_payment_id' );
		if ( ! $payment_id ) {
			wp_send_json_error( array( 'message' => __( 'Aucune transaction associée.', 'wc-kpay-gateway' ) ), 404 );
		}

		$gateway = self::get_gateway();
		if ( ! $gateway ) {
			wp_send_json_error( array( 'message' => __( 'Passerelle indisponible.', 'wc-kpay-gateway' ) ), 503 );
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
			wp_send_json_error( array( 'message' => __( 'Statut indisponible pour cette commande.', 'wc-kpay-gateway' ) ), 409 );
		}

		$payment = $gateway->get_api( $environment )->get_payment( $payment_id );

		if ( is_wp_error( $payment ) ) {
			$gateway->log( sprintf( 'Polling commande #%d : %s', $order_id, $payment->get_error_message() ), 'error' );
			wp_send_json_success( array( 'status' => 'PENDING' ) );
		}

		$status = isset( $payment['status'] ) ? strtoupper( sanitize_text_field( $payment['status'] ) ) : 'PENDING';
		self::apply_status( $order, $status, 'polling', $gateway );

		wp_send_json_success( array( 'status' => $status ) );
	}
}
