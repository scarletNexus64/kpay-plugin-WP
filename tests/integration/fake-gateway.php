<?php
/**
 * Fausse page de paiement hébergée (TESTS UNIQUEMENT).
 *
 * Reproduit le comportement de la page K-Pay en mode GATEWAY : le client
 * choisit une issue, puis est renvoyé vers returnUrl avec une query signée
 * selon la spec (« status|reference|externalId|ts », HMAC-SHA256 hex).
 *
 * Permet de vérifier de bout en bout que le plugin n'accepte que les retours
 * correctement signés — et refuse les autres.
 */

// Le dossier est monté dans la racine WordPress du conteneur de test.
require_once dirname( __DIR__ ) . '/wp-load.php';

$payment_id = isset( $_GET['payment'] ) ? sanitize_text_field( wp_unslash( $_GET['payment'] ) ) : '';
$return_url = isset( $_GET['return'] ) ? esc_url_raw( wp_unslash( $_GET['return'] ) ) : '';

$payments = get_option( 'kpay_mock_payments', array() );
$payment  = isset( $payments[ $payment_id ] ) ? $payments[ $payment_id ] : null;

if ( ! $payment || ! $return_url ) {
	wp_die( 'Paiement simulé introuvable.' );
}

$settings = get_option( 'woocommerce_kpay_settings', array() );
$secret   = isset( $settings['gateway_secret'] ) ? $settings['gateway_secret'] : '';

// Soumission : on applique l'issue choisie et on renvoie le client signé.
if ( isset( $_POST['outcome'] ) ) {
	$outcome = sanitize_text_field( wp_unslash( $_POST['outcome'] ) );
	$status  = in_array( $outcome, array( 'COMPLETED', 'FAILED', 'CANCELLED' ), true ) ? $outcome : 'FAILED';

	// L'API reflète désormais ce statut sur GET /payments/:id : c'est cette
	// confirmation serveur qui fait autorité côté plugin.
	update_option( 'kpay_mock_status', $status, false );

	$ts     = (string) ( time() * 1000 );
	$signed = $status . '|' . $payment['reference'] . '|' . $payment['externalId'] . '|' . $ts;

	// « broken » permet de vérifier qu'un retour mal signé est bien refusé.
	$sig = ! empty( $_POST['broken'] )
		? str_repeat( 'a', 64 )
		: hash_hmac( 'sha256', $signed, $secret );

	wp_redirect( add_query_arg(
		array(
			'status'     => $status,
			'reference'  => $payment['reference'],
			'externalId' => $payment['externalId'],
			'ts'         => $ts,
			'sig'        => $sig,
		),
		$return_url
	) );
	exit;
}
?>
<!doctype html>
<html lang="fr">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title>K-Pay — page de paiement simulée</title>
	<style>
		body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0;
			display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
		.card { background: #1e293b; padding: 2rem; border-radius: 12px; max-width: 420px; width: 100%; }
		h1 { font-size: 1.1rem; margin: 0 0 .5rem; }
		dl { display: grid; grid-template-columns: auto 1fr; gap: .25rem 1rem; font-size: .875rem;
			color: #94a3b8; margin: 1rem 0 1.5rem; }
		dd { margin: 0; color: #e2e8f0; font-family: ui-monospace, monospace; }
		button { width: 100%; padding: .75rem; margin-bottom: .5rem; border: 0; border-radius: 8px;
			font-size: .95rem; cursor: pointer; }
		.ok { background: #16a34a; color: #fff; }
		.ko { background: #dc2626; color: #fff; }
		.mute { background: #475569; color: #fff; }
		.warn { font-size: .8rem; color: #fbbf24; margin-top: 1rem; }
	</style>
</head>
<body>
	<div class="card">
		<h1>Page de paiement simulée</h1>
		<p style="font-size:.85rem;color:#94a3b8;margin:0">
			Cette page remplace la passerelle hébergée K-Pay pour les tests.
		</p>

		<dl>
			<dt>Transaction</dt><dd><?php echo esc_html( $payment['id'] ); ?></dd>
			<dt>Référence</dt><dd><?php echo esc_html( $payment['reference'] ); ?></dd>
			<dt>Montant</dt><dd><?php echo esc_html( $payment['amount'] . ' ' . $payment['currency'] ); ?></dd>
		</dl>

		<form method="post">
			<button class="ok" name="outcome" value="COMPLETED">Payer (COMPLETED)</button>
			<button class="ko" name="outcome" value="FAILED">Échouer (FAILED)</button>
			<button class="mute" name="outcome" value="CANCELLED">Annuler (CANCELLED)</button>
		</form>

		<form method="post">
			<input type="hidden" name="broken" value="1" />
			<input type="hidden" name="outcome" value="COMPLETED" />
			<button class="mute" type="submit">
				Retour avec signature invalide (doit être refusé)
			</button>
		</form>

		<?php if ( '' === $secret ) : ?>
			<p class="warn">Aucun secret passerelle configuré : les retours seront refusés.</p>
		<?php endif; ?>
	</div>
</body>
</html>
