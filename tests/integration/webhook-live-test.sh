#!/usr/bin/env bash
#
# Attaque le webhook via de vraies requêtes HTTP contre le WordPress local.
# Aucune simulation : c'est le serveur réel qui répond.
#
# Prérequis : la pile Docker tourne (docker compose up -d) et le plugin est
# configuré avec le secret webhook « whsec_integration_test ».

set -uo pipefail

BASE="http://localhost:8888"
WEBHOOK="${BASE}/index.php?rest_route=/kpay/v1/webhook"
SECRET="whsec_integration_test"

pass=0
fail=0

# Vérifie que le code HTTP obtenu correspond à l'attendu.
check_status() {
	local label="$1" expected="$2" actual="$3"
	if [ "$actual" = "$expected" ]; then
		printf '  OK    %-46s (HTTP %s)\n' "$label" "$actual"
		pass=$((pass + 1))
	else
		printf '  ECHEC %-46s (HTTP %s, attendu %s)\n' "$label" "$actual" "$expected"
		fail=$((fail + 1))
	fi
}

sign() {
	printf '%s' "$1" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //'
}

post() {
	local body="$1" sig="${2-}"
	if [ -n "$sig" ]; then
		curl -s -o /dev/null -w '%{http_code}' -X POST "$WEBHOOK" \
			-H 'Content-Type: application/json' \
			-H "X-KPAY-Signature: ${sig}" \
			-H 'User-Agent: KPAY-Webhook/1.0' \
			--data-binary "$body"
	else
		curl -s -o /dev/null -w '%{http_code}' -X POST "$WEBHOOK" \
			-H 'Content-Type: application/json' \
			--data-binary "$body"
	fi
}

ORDER_ID="${1:?Usage: webhook-live-test.sh <order_id> [payment_id]}"
PAYMENT_ID="${2:-pay_integration_test}"
# Le montant doit couvrir le total de la commande, sans quoi la notification
# est acceptée mais non appliquée. Ajustez-le si votre commande diffère.
AMOUNT="${3:-5000}"

# L'horodatage est obligatoire et borné à 10 minutes : il est généré à chaque
# exécution, une valeur figée serait systématiquement hors fenêtre.
NOW=$(date -u +%Y-%m-%dT%H:%M:%SZ)

BODY=$(printf '{"event":"payment.completed","paymentId":"%s","reference":"KPAY-IT-1","status":"COMPLETED","amount":%s,"externalId":"WC-%s-1","metadata":{"orderId":"%s"},"timestamp":"%s"}' \
	"$PAYMENT_ID" "$AMOUNT" "$ORDER_ID" "$ORDER_ID" "$NOW")
VALID_SIG=$(sign "$BODY")

echo ""
echo "=== Attaques sur le webhook (requêtes HTTP réelles) ==="

check_status "Sans signature"            401 "$(post "$BODY")"
check_status "Signature forgée"          401 "$(post "$BODY" "$(printf 'a%.0s' {1..64})")"
check_status "Mauvais secret"            401 "$(post "$BODY" "$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac 'mauvais' | sed 's/^.* //')")"
check_status "Corps modifié après signature" 401 \
	"$(post "$(printf '%s' "$BODY" | sed 's/"amount":5000/"amount":1/')" "$VALID_SIG")"
check_status "JSON malformé"             400 "$(post '{casse' "$(sign '{casse')")"

UNKNOWN=$(printf '{"event":"payment.completed","paymentId":"pay_x","status":"COMPLETED","externalId":"WC-999999-1","metadata":{"orderId":"999999"},"timestamp":"%s"}' "$NOW")
check_status "Commande inconnue"         404 "$(post "$UNKNOWN" "$(sign "$UNKNOWN")")"

MISMATCH=$(printf '{"event":"payment.completed","paymentId":"pay_autre_client","status":"COMPLETED","externalId":"WC-%s-1","metadata":{"orderId":"%s"},"timestamp":"%s"}' "$ORDER_ID" "$ORDER_ID" "$NOW")
check_status "Transaction d'une autre commande" 404 "$(post "$MISMATCH" "$(sign "$MISMATCH")")"

NO_TS=$(printf '{"event":"payment.completed","paymentId":"%s","status":"COMPLETED","amount":%s,"externalId":"WC-%s-1","metadata":{"orderId":"%s"}}' "$PAYMENT_ID" "$AMOUNT" "$ORDER_ID" "$ORDER_ID")
check_status "Sans horodatage"           400 "$(post "$NO_TS" "$(sign "$NO_TS")")"

OLD=$(printf '{"event":"payment.completed","paymentId":"%s","status":"COMPLETED","amount":%s,"externalId":"WC-%s-1","metadata":{"orderId":"%s"},"timestamp":"2020-01-01T00:00:00Z"}' "$PAYMENT_ID" "$AMOUNT" "$ORDER_ID" "$ORDER_ID")
check_status "Horodatage hors fenêtre"   401 "$(post "$OLD" "$(sign "$OLD")")"

echo ""
echo "=== Notification légitime ==="
check_status "Webhook correctement signé" 200 "$(post "$BODY" "$VALID_SIG")"

echo ""
echo "--------------------------------------------------"
if [ "$fail" -gt 0 ]; then
	echo "ECHEC : ${fail} test(s) en échec, ${pass} réussi(s)."
	exit 1
fi
echo "SUCCES : ${pass} tests passés, aucun échec."
