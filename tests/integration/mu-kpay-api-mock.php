<?php
/**
 * Plugin Name: K-Pay — simulateur d'API (TESTS UNIQUEMENT)
 * Description: Intercepte les appels HTTP vers admin.kpay.site et renvoie des réponses conformes à la spec. Permet de tester le menu K-Pay sans clés réelles et sans jamais transférer d'argent.
 *
 * À NE JAMAIS DÉPLOYER EN PRODUCTION.
 *
 * Le comportement des retraits suit les numéros de test de la spec :
 *   237653456789 -> COMPLETED
 *   237653456129 -> SUBMITTED (reste en attente)
 *   237653456089 -> FAILED / RECIPIENT_NOT_FOUND
 *   237653456119 -> FAILED / UNSPECIFIED_FAILURE
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

	if ( '/api/v1/payments/balance' === $path ) {
		return kpay_mock_response( 200, get_option( 'kpay_mock_balances', array(
			array( 'currency' => 'XAF', 'balance' => 150000, 'reservedBalance' => 25000, 'availableBalance' => 125000 ),
			array( 'currency' => 'XOF', 'balance' => 80000, 'reservedBalance' => 0, 'availableBalance' => 80000 ),
			array( 'currency' => 'KES', 'balance' => 12500.75, 'reservedBalance' => 500.25, 'availableBalance' => 12000.50 ),
		) ) );
	}

	if ( '/api/v1/payments/withdraw' === $path && 'POST' === $method ) {
		return kpay_mock_withdraw( $body );
	}

	if ( '/api/v1/payments/init' === $path && 'POST' === $method ) {
		return kpay_mock_response( 201, array(
			'id'        => 'pay_' . substr( md5( wp_json_encode( $body ) ), 0, 8 ),
			'reference' => 'KPAY-TEST-' . strtoupper( substr( md5( microtime() ), 0, 6 ) ),
			'status'    => 'PENDING',
			'amount'    => isset( $body['amount'] ) ? $body['amount'] : 0,
			'currency'  => 'XAF',
			'isTest'    => true,
		) );
	}

	return kpay_mock_response( 404, array(
		'statusCode' => 404,
		'message'    => 'Endpoint non simulé : ' . $path,
		'error'      => 'Not Found',
	) );
}

/**
 * Retrait : l'issue dépend du numéro, comme en sandbox réel.
 */
function kpay_mock_withdraw( $body ) {
	$phone  = isset( $body['phoneNumber'] ) ? $body['phoneNumber'] : '';
	$amount = isset( $body['amount'] ) ? (float) $body['amount'] : 0;

	$balances = get_option( 'kpay_mock_balances', array(
		array( 'currency' => 'XAF', 'balance' => 150000, 'reservedBalance' => 25000, 'availableBalance' => 125000 ),
	) );

	// Solde insuffisant -> 422 (spec).
	$available = 0;
	foreach ( $balances as $b ) {
		if ( 'XAF' === $b['currency'] ) {
			$available = $b['availableBalance'];
		}
	}
	if ( $amount > $available ) {
		return kpay_mock_response( 422, array(
			'statusCode' => 422,
			'message'    => 'Insufficient wallet balance',
			'error'      => 'Unprocessable Entity',
		) );
	}

	// Numéros d'échec de la spec (retraits).
	$failures = array(
		'237653456089' => 'RECIPIENT_NOT_FOUND',
		'237653456119' => 'UNSPECIFIED_FAILURE',
		'22951345089'  => 'RECIPIENT_NOT_FOUND',
		'24174345088'  => 'RECIPIENT_NOT_FOUND',
		'254703456099' => 'WALLET_LIMIT_REACHED',
		'254703456109' => 'RECIPIENT_LIMIT_REACHED',
	);

	$fee = round( $amount * 0.05 );
	$id  = 'wdr_' . substr( md5( $phone . microtime() ), 0, 8 );
	$ref = 'KPAY-WD-' . strtoupper( substr( md5( microtime() ), 0, 6 ) );

	$status         = 'PENDING';
	$failure_reason = null;

	if ( isset( $failures[ $phone ] ) ) {
		$status         = 'FAILED';
		$failure_reason = $failures[ $phone ];
	} elseif ( '237653456129' === $phone ) {
		$status = 'PENDING'; // SUBMITTED : reste en attente
	}

	$response = array(
		'id'            => $id,
		'reference'     => $ref,
		'status'        => $status,
		'amount'        => $amount,
		'netAmount'     => $amount - $fee,
		'feeAmount'     => $fee,
		'currency'      => 'XAF',
		'externalId'    => isset( $body['externalId'] ) ? $body['externalId'] : '',
		'provider'      => isset( $body['provider'] ) ? $body['provider'] : '',
		'phoneNumber'   => $phone,
		'isTest'        => true,
		'failureReason' => $failure_reason,
	);

	// Payout cross-devise : la spec ajoute ces champs.
	if ( isset( $body['sourceCountry'] ) && isset( $body['provider'] )
		&& false !== strpos( $body['provider'], '_CIV' ) ) {
		$response['payoutCurrency'] = 'XOF';
		$response['payoutAmount']   = round( ( $amount - $fee ) * 1.015, 2 );
		$response['exchangeRate']   = 1.015;
	}

	return kpay_mock_response( 201, $response );
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
