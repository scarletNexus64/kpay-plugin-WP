<?php
/**
 * Plugin Name: K-Pay — simulateur d'API (TESTS UNIQUEMENT)
 * Description: Intercepte les appels HTTP vers admin.kpay.site et renvoie des réponses conformes à la spec. Permet de tester les deux modes de paiement sans clés réelles.
 *
 * À NE JAMAIS DÉPLOYER EN PRODUCTION.
 *
 * Options de pilotage (via wp option update) :
 *   kpay_mock_status      Statut renvoyé par GET /payments/:id (PENDING par défaut).
 *   kpay_mock_paid_amount Force le montant renvoyé, pour simuler un sous-paiement.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'pre_http_request', 'kpay_mock_http', 10, 3 );

function kpay_mock_http( $preempt, $args, $url ) {
	if ( false === strpos( $url, 'admin.kpay.site' ) ) {
		return $preempt;
	}

	$path   = wp_parse_url( $url, PHP_URL_PATH );
	$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
	$body   = isset( $args['body'] ) ? json_decode( $args['body'], true ) : array();

	// Trace des appels, pour les assertions.
	$log   = get_option( 'kpay_mock_calls', array() );
	$log[] = array( 'method' => $method, 'path' => $path, 'body' => $body );
	update_option( 'kpay_mock_calls', array_slice( $log, -50 ), false );

	if ( '/api/v1/payments/me' === $path ) {
		return kpay_mock_response( 200, array(
			'application' => array( 'id' => 'app_test_1', 'name' => 'Boutique Test' ),
			'company'     => array( 'id' => 'co_test_1', 'name' => 'Test Inc' ),
			'environment' => 'TEST',
		) );
	}

	if ( '/api/v1/payments/init' === $path && 'POST' === $method ) {
		return kpay_mock_init( $body );
	}

	// Statut d'un paiement : sert au polling et à la confirmation du retour
	// passerelle. Le statut simulé est piloté par l'option kpay_mock_status.
	if ( preg_match( '#^/api/v1/payments/(pay_[A-Za-z0-9]+)$#', (string) $path, $m ) ) {
		$payments = get_option( 'kpay_mock_payments', array() );
		$payment  = isset( $payments[ $m[1] ] ) ? $payments[ $m[1] ] : array(
			'id'       => $m[1],
			'amount'   => 0,
			'currency' => 'XAF',
		);

		$payment['status'] = get_option( 'kpay_mock_status', 'PENDING' );

		// Permet de simuler un sous-paiement sans toucher au reste.
		$forced = get_option( 'kpay_mock_paid_amount', '' );
		if ( '' !== $forced ) {
			$payment['amount'] = (float) $forced;
		}

		return kpay_mock_response( 200, $payment );
	}

	return kpay_mock_response( 404, array(
		'statusCode' => 404,
		'message'    => 'Endpoint non simulé : ' . $path,
		'error'      => 'Not Found',
	) );
}

/**
 * Initialisation d'un paiement.
 *
 * Le mode est déduit de la forme du corps, exactement comme le fait l'API :
 * un phoneNumber signale l'USSD, un returnUrl la passerelle hébergée. Un
 * corps qui mélange les deux contrats est refusé par un 400, afin que le
 * plugin soit testé contre le vrai comportement de l'API.
 */
function kpay_mock_init( $body ) {
	$has_phone  = ! empty( $body['phoneNumber'] );
	$has_return = ! empty( $body['returnUrl'] );

	if ( $has_phone && $has_return ) {
		return kpay_mock_response( 400, array(
			'statusCode' => 400,
			'message'    => 'phoneNumber et returnUrl sont mutuellement exclusifs.',
			'error'      => 'Bad Request',
		) );
	}

	if ( ! $has_phone && ! $has_return ) {
		return kpay_mock_response( 400, array(
			'statusCode' => 400,
			'message'    => 'phoneNumber (USSD) ou returnUrl (GATEWAY) est requis.',
			'error'      => 'Bad Request',
		) );
	}

	$amount = isset( $body['amount'] ) ? $body['amount'] : 0;
	$id     = 'pay_' . substr( md5( wp_json_encode( $body ) . microtime() ), 0, 8 );
	$ref    = 'KPAY-TEST-' . strtoupper( substr( md5( microtime() ), 0, 6 ) );

	$payment = array(
		'id'         => $id,
		'reference'  => $ref,
		'status'     => 'PENDING',
		'amount'     => $amount,
		'currency'   => 'XAF',
		'externalId' => isset( $body['externalId'] ) ? $body['externalId'] : '',
		'isTest'     => true,
	);

	// Mémorisé pour que GET /payments/:id réponde de façon cohérente.
	$payments        = get_option( 'kpay_mock_payments', array() );
	$payments[ $id ] = $payment;
	update_option( 'kpay_mock_payments', $payments, false );

	if ( $has_return ) {
		$payment['mode']       = 'GATEWAY';
		$payment['gatewayUrl'] = 'http://localhost:8888/kpay-tests/fake-gateway.php?payment=' . $id
			. '&return=' . rawurlencode( $body['returnUrl'] );
		$payment['expiresAt']  = gmdate( 'c', time() + 1800 );
	} else {
		$payment['provider']    = isset( $body['provider'] ) ? $body['provider'] : '';
		$payment['phoneNumber'] = $body['phoneNumber'];
		$payment['message']     = 'Paiement initié. Le client doit valider la demande sur son téléphone.';
	}

	return kpay_mock_response( 201, $payment );
}

function kpay_mock_response( $code, array $body ) {
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}
