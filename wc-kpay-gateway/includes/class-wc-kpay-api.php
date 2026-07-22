<?php
/**
 * Client HTTP pour l'API K-Pay.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_KPay_API {

	const BASE_URL = 'https://admin.kpay.site';

	/** @var string */
	private $api_key;

	/** @var string */
	private $secret_key;

	public function __construct( $api_key, $secret_key ) {
		$this->api_key    = $api_key;
		$this->secret_key = $secret_key;
	}

	/**
	 * Préfixes de clés par environnement (spec : l'URL est identique, seul le
	 * préfixe de la clé sélectionne l'environnement côté K-Pay).
	 */
	const KEY_PREFIXES = array(
		'sandbox' => array( 'api' => 'kpay_test_', 'secret' => 'sk_test_' ),
		'live'    => array( 'api' => 'kpay_live_', 'secret' => 'sk_live_' ),
	);

	/**
	 * Environnement déduit du préfixe d'une clé API.
	 *
	 * @return string 'sandbox', 'live', ou '' si le préfixe est inconnu.
	 */
	public static function detect_environment( $api_key ) {
		$api_key = (string) $api_key;

		foreach ( self::KEY_PREFIXES as $environment => $prefixes ) {
			if ( 0 === strpos( $api_key, $prefixes['api'] ) ) {
				return $environment;
			}
		}

		return '';
	}

	/**
	 * Vérifie qu'une paire de clés correspond à l'environnement attendu.
	 *
	 * C'est la seule protection locale contre l'erreur la plus coûteuse :
	 * des clés de test en production (les paiements ne sont jamais encaissés)
	 * ou des clés de production en test (des paiements réels sont débités).
	 *
	 * @return true|WP_Error
	 */
	public static function validate_keys( $api_key, $secret_key, $expected_environment ) {
		if ( '' === (string) $api_key || '' === (string) $secret_key ) {
			return new WP_Error(
				'kpay_keys_missing',
				__( 'Les deux clés sont requises.', 'k-pay-for-woocommerce' )
			);
		}

		if ( ! isset( self::KEY_PREFIXES[ $expected_environment ] ) ) {
			return new WP_Error( 'kpay_unknown_environment', __( 'Environnement inconnu.', 'k-pay-for-woocommerce' ) );
		}

		$expected = self::KEY_PREFIXES[ $expected_environment ];
		$detected = self::detect_environment( $api_key );

		if ( '' === $detected ) {
			return new WP_Error(
				'kpay_key_prefix_unknown',
				sprintf(
					/* translators: %s: préfixe attendu */
					__( 'La clé API ne commence pas par %s : vérifiez que vous avez copié la bonne clé depuis votre tableau de bord K-Pay.', 'k-pay-for-woocommerce' ),
					$expected['api']
				)
			);
		}

		if ( $detected !== $expected_environment ) {
			return new WP_Error(
				'kpay_key_environment_mismatch',
				sprintf(
					/* translators: 1: environnement choisi, 2: environnement de la clé, 3: préfixe attendu */
					__( 'Environnement « %1$s » sélectionné, mais la clé API est une clé « %2$s ». Une clé %3$s est attendue.', 'k-pay-for-woocommerce' ),
					$expected_environment,
					$detected,
					$expected['api']
				)
			);
		}

		if ( 0 !== strpos( (string) $secret_key, $expected['secret'] ) ) {
			return new WP_Error(
				'kpay_secret_prefix_mismatch',
				sprintf(
					/* translators: %s: préfixe attendu */
					__( 'La clé secrète ne commence pas par %s : elle n\'appartient pas au même environnement que la clé API.', 'k-pay-for-woocommerce' ),
					$expected['secret']
				)
			);
		}

		return true;
	}

	/**
	 * Catalogue des providers supportés, indexé par code exact attendu
	 * par l'API. Le pays et la devise sont déduits du provider côté K-Pay ;
	 * la devise listée ici sert uniquement à filtrer l'affichage de la
	 * passerelle selon la devise de la boutique.
	 */
	public static function get_providers() {
		return array(
			'MTN_MOMO_BEN'      => array( 'label' => 'MTN MoMo (Bénin)', 'currency' => 'XOF', 'prefix' => '229' ),
			'MOOV_BEN'          => array( 'label' => 'Moov (Bénin)', 'currency' => 'XOF', 'prefix' => '229' ),
			'MTN_MOMO_CMR'      => array( 'label' => 'MTN Mobile Money', 'currency' => 'XAF', 'prefix' => '237' ),
			'ORANGE_CMR'        => array( 'label' => 'Orange Money', 'currency' => 'XAF', 'prefix' => '237' ),
			'MTN_MOMO_CIV'      => array( 'label' => 'MTN MoMo (Côte d\'Ivoire)', 'currency' => 'XOF', 'prefix' => '225' ),
			'ORANGE_CIV'        => array( 'label' => 'Orange Money (Côte d\'Ivoire)', 'currency' => 'XOF', 'prefix' => '225' ),
			'AIRTEL_GAB'        => array( 'label' => 'Airtel Money (Gabon)', 'currency' => 'XAF', 'prefix' => '241' ),
			'AIRTEL_COG'        => array( 'label' => 'Airtel Money (Congo)', 'currency' => 'XAF', 'prefix' => '242' ),
			'MTN_MOMO_COG'      => array( 'label' => 'MTN MoMo (Congo)', 'currency' => 'XAF', 'prefix' => '242' ),
			'FREE_SEN'          => array( 'label' => 'Free Money (Sénégal)', 'currency' => 'XOF', 'prefix' => '221' ),
			'ORANGE_SEN'        => array( 'label' => 'Orange Money (Sénégal)', 'currency' => 'XOF', 'prefix' => '221' ),
			'MPESA_KEN'         => array( 'label' => 'M-Pesa (Kenya)', 'currency' => 'KES', 'prefix' => '254' ),
			'AIRTEL_RWA'        => array( 'label' => 'Airtel Money (Rwanda)', 'currency' => 'RWF', 'prefix' => '250' ),
			'MTN_MOMO_RWA'      => array( 'label' => 'MTN MoMo (Rwanda)', 'currency' => 'RWF', 'prefix' => '250' ),
			'AIRTEL_OAPI_UGA'   => array( 'label' => 'Airtel Money (Ouganda)', 'currency' => 'UGX', 'prefix' => '256' ),
			'MTN_MOMO_UGA'      => array( 'label' => 'MTN MoMo (Ouganda)', 'currency' => 'UGX', 'prefix' => '256' ),
			'ORANGE_SLE'        => array( 'label' => 'Orange Money (Sierra Leone)', 'currency' => 'SLE', 'prefix' => '232' ),
			'AIRTEL_OAPI_ZMB'   => array( 'label' => 'Airtel Money (Zambie)', 'currency' => 'ZMW', 'prefix' => '260' ),
			'MTN_MOMO_ZMB'      => array( 'label' => 'MTN MoMo (Zambie)', 'currency' => 'ZMW', 'prefix' => '260' ),
			'ZAMTEL_ZMB'        => array( 'label' => 'Zamtel (Zambie)', 'currency' => 'ZMW', 'prefix' => '260' ),
			'VODACOM_MPESA_COD' => array( 'label' => 'Vodacom M-Pesa (RDC)', 'currency' => 'CDF', 'prefix' => '243' ),
			'AIRTEL_COD'        => array( 'label' => 'Airtel Money (RDC)', 'currency' => 'CDF', 'prefix' => '243' ),
			'ORANGE_COD'        => array( 'label' => 'Orange Money (RDC)', 'currency' => 'CDF', 'prefix' => '243' ),
		);
	}

	/**
	 * Providers disponibles pour une devise donnée.
	 */
	public static function get_providers_for_currency( $currency ) {
		return array_filter(
			self::get_providers(),
			function ( $provider ) use ( $currency ) {
				return $provider['currency'] === $currency;
			}
		);
	}

	/**
	 * Devises acceptées par la passerelle.
	 */
	public static function get_supported_currencies() {
		return array_values( array_unique( wp_list_pluck( self::get_providers(), 'currency' ) ) );
	}

	/**
	 * Providers dont la devise ne gère pas les décimales : le montant doit
	 * être un entier.
	 */
	public static function provider_uses_integer_amount( $provider ) {
		$integer_only = array(
			'MTN_MOMO_BEN', 'MOOV_BEN', 'MTN_MOMO_CMR', 'ORANGE_CMR',
			'MTN_MOMO_CIV', 'ORANGE_CIV', 'AIRTEL_COG', 'MTN_MOMO_COG',
			'AIRTEL_RWA', 'MTN_MOMO_RWA', 'FREE_SEN', 'ORANGE_SEN',
			'AIRTEL_OAPI_UGA',
		);
		return in_array( $provider, $integer_only, true );
	}

	/**
	 * Initie un paiement.
	 *
	 * Le même endpoint sert les deux modes ; c'est la forme du corps qui les
	 * distingue (USSD : phoneNumber + provider ; GATEWAY : returnUrl, sans
	 * numéro). Le mode doit correspondre à la configuration de l'Application
	 * côté K-Pay, sinon l'API répond 400.
	 *
	 * @return array|WP_Error Ressource paiement, ou WP_Error décrivant l'échec.
	 */
	public function init_payment( array $payload ) {
		return $this->request( 'POST', '/api/v1/payments/init', $payload );
	}

	/**
	 * Vérifie la signature du retour de la passerelle hébergée.
	 *
	 * La chaîne signée est « status|reference|externalId|ts » (spec), en
	 * HMAC-SHA256 hex avec le secret passerelle. La comparaison est faite en
	 * temps constant, et l'horodatage borné pour interdire le rejeu d'un
	 * retour « COMPLETED » capturé.
	 *
	 * @param array  $params Paramètres de l'URL de retour.
	 * @param string $secret Secret passerelle.
	 * @return true|WP_Error
	 */
	public static function verify_gateway_return( array $params, $secret ) {
		if ( '' === (string) $secret ) {
			return new WP_Error( 'kpay_gateway_no_secret', __( 'Secret passerelle non configuré.', 'k-pay-for-woocommerce' ) );
		}

		foreach ( array( 'status', 'reference', 'externalId', 'ts', 'sig' ) as $field ) {
			if ( ! isset( $params[ $field ] ) || '' === (string) $params[ $field ] ) {
				return new WP_Error(
					'kpay_gateway_incomplete',
					__( 'Retour de paiement incomplet.', 'k-pay-for-woocommerce' )
				);
			}
		}

		$signed   = $params['status'] . '|' . $params['reference'] . '|' . $params['externalId'] . '|' . $params['ts'];
		$expected = hash_hmac( 'sha256', $signed, $secret );

		if ( ! hash_equals( $expected, strtolower( trim( (string) $params['sig'] ) ) ) ) {
			return new WP_Error( 'kpay_gateway_bad_signature', __( 'Signature de retour invalide.', 'k-pay-for-woocommerce' ) );
		}

		// `ts` est en millisecondes (spec). Au-delà de 10 minutes, on refuse :
		// un retour signé capturé ne doit pas être rejouable indéfiniment.
		$age = abs( time() - (int) round( (int) $params['ts'] / 1000 ) );
		if ( $age > 600 ) {
			return new WP_Error( 'kpay_gateway_expired', __( 'Retour de paiement expiré.', 'k-pay-for-woocommerce' ) );
		}

		return true;
	}

	/**
	 * Récupère le statut d'un paiement.
	 *
	 * @return array|WP_Error
	 */
	public function get_payment( $payment_id ) {
		return $this->request( 'GET', '/api/v1/payments/' . rawurlencode( $payment_id ) );
	}

	/**
	 * Vérifie la validité des clés et retourne l'environnement résolu.
	 *
	 * @return array|WP_Error
	 */
	public function get_application_info() {
		return $this->request( 'GET', '/api/v1/payments/me' );
	}

	/**
	 * Disponibilité opérationnelle des opérateurs, par pays.
	 *
	 * @return array|WP_Error
	 */
	public function get_availability() {
		return $this->request( 'GET', '/api/v1/payments/availability' );
	}

	/**
	 * Pays (ISO3) associé à un code provider.
	 */
	public static function get_provider_country( $provider ) {
		$countries = array(
			'BEN' => array( 'MTN_MOMO_BEN', 'MOOV_BEN' ),
			'CMR' => array( 'MTN_MOMO_CMR', 'ORANGE_CMR' ),
			'CIV' => array( 'MTN_MOMO_CIV', 'ORANGE_CIV' ),
			'COD' => array( 'VODACOM_MPESA_COD', 'AIRTEL_COD', 'ORANGE_COD' ),
			'GAB' => array( 'AIRTEL_GAB' ),
			'KEN' => array( 'MPESA_KEN' ),
			'COG' => array( 'AIRTEL_COG', 'MTN_MOMO_COG' ),
			'RWA' => array( 'AIRTEL_RWA', 'MTN_MOMO_RWA' ),
			'SEN' => array( 'FREE_SEN', 'ORANGE_SEN' ),
			'SLE' => array( 'ORANGE_SLE' ),
			'UGA' => array( 'AIRTEL_OAPI_UGA', 'MTN_MOMO_UGA' ),
			'ZMB' => array( 'AIRTEL_OAPI_ZMB', 'MTN_MOMO_ZMB', 'ZAMTEL_ZMB' ),
		);

		foreach ( $countries as $country => $providers ) {
			if ( in_array( $provider, $providers, true ) ) {
				return $country;
			}
		}

		return '';
	}

	/**
	 * Nom lisible d'un pays ISO3.
	 */
	public static function get_country_label( $country ) {
		$labels = array(
			'BEN' => __( 'Bénin', 'k-pay-for-woocommerce' ),
			'CMR' => __( 'Cameroun', 'k-pay-for-woocommerce' ),
			'CIV' => __( 'Côte d\'Ivoire', 'k-pay-for-woocommerce' ),
			'COD' => __( 'RD Congo', 'k-pay-for-woocommerce' ),
			'GAB' => __( 'Gabon', 'k-pay-for-woocommerce' ),
			'KEN' => __( 'Kenya', 'k-pay-for-woocommerce' ),
			'COG' => __( 'Congo', 'k-pay-for-woocommerce' ),
			'RWA' => __( 'Rwanda', 'k-pay-for-woocommerce' ),
			'SEN' => __( 'Sénégal', 'k-pay-for-woocommerce' ),
			'SLE' => __( 'Sierra Leone', 'k-pay-for-woocommerce' ),
			'UGA' => __( 'Ouganda', 'k-pay-for-woocommerce' ),
			'ZMB' => __( 'Zambie', 'k-pay-for-woocommerce' ),
		);

		return isset( $labels[ $country ] ) ? $labels[ $country ] : $country;
	}

	/**
	 * Pays partageant une devise donnée. Une devise de zone (XAF, XOF) couvre
	 * plusieurs pays : le solde est porté par la devise, pas par le pays.
	 */
	public static function get_countries_for_currency( $currency ) {
		$countries = array();

		foreach ( self::get_providers() as $code => $provider ) {
			if ( $provider['currency'] !== $currency ) {
				continue;
			}
			$country = self::get_provider_country( $code );
			if ( $country && ! in_array( $country, $countries, true ) ) {
				$countries[] = $country;
			}
		}

		return $countries;
	}

	/**
	 * Exécute une requête authentifiée et normalise la réponse.
	 *
	 * @return array|WP_Error
	 */
	private function request( $method, $path, $body = null ) {
		if ( empty( $this->api_key ) || empty( $this->secret_key ) ) {
			return new WP_Error( 'kpay_missing_keys', __( 'Clés API K-Pay non configurées.', 'k-pay-for-woocommerce' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'X-API-Key'    => $this->api_key,
				'X-Secret-Key' => $this->secret_key,
				'Accept'       => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'kpay_http_error',
				sprintf(
					/* translators: %s: message d'erreur réseau */
					__( 'Impossible de contacter K-Pay : %s', 'k-pay-for-woocommerce' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code >= 200 && $code < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		// L'API renvoie { statusCode, message, error } sur les erreurs.
		$message = is_array( $data ) && ! empty( $data['message'] )
			? ( is_array( $data['message'] ) ? implode( ' ', $data['message'] ) : $data['message'] )
			: sprintf( 'HTTP %d', $code );

		return new WP_Error( 'kpay_api_error_' . $code, $message, array( 'status' => $code, 'body' => $raw ) );
	}

	/**
	 * Normalise un numéro vers le format international attendu par l'API
	 * (indicatif pays en tête, sans + ni 0 initial).
	 *
	 * @return string|false Numéro normalisé, ou false si invalide.
	 */
	public static function normalize_phone( $phone, $provider ) {
		$providers = self::get_providers();
		if ( ! isset( $providers[ $provider ] ) ) {
			return false;
		}

		$prefix = $providers[ $provider ]['prefix'];
		$digits = preg_replace( '/\D/', '', (string) $phone );

		if ( '' === $digits ) {
			return false;
		}

		// Préfixe de composition internationale (00237… saisi pour +237…) :
		// à retirer avant de chercher l'indicatif, sinon celui-ci n'est pas
		// reconnu et le numéro se retrouve préfixé deux fois.
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}

		// Déjà préfixé par l'indicatif pays.
		if ( 0 === strpos( $digits, $prefix ) ) {
			$national = substr( $digits, strlen( $prefix ) );
		} else {
			// Format national, éventuellement avec un 0 de tête.
			$national = ltrim( $digits, '0' );
		}

		// Longueur nationale plausible pour les pays couverts.
		if ( strlen( $national ) < 8 || strlen( $national ) > 12 ) {
			return false;
		}

		return $prefix . $national;
	}
}
