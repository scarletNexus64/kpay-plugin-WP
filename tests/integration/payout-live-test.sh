#!/usr/bin/env bash
#
# Retraits via de vraies requêtes HTTP contre le WordPress local.
#
# L'API K-Pay est simulée par le mu-plugin (aucun argent réel en jeu), mais
# WordPress, les nonces, les capacités et le plugin sont réels.
#
# La notice de résultat est stockée en transient et consommée au premier
# affichage : on la lit via WP-CLI plutôt que dans le HTML, car charger la
# page pour récupérer le nonce suivant la consommerait.
#
# Prérequis : pile Docker démarrée, simulateur installé, plugin configuré.

set -uo pipefail

BASE="http://localhost:8888"
COOKIES="/tmp/kpay-payout-cookies.txt"
USER="${KPAY_TEST_USER:-admin}"
PASS="${KPAY_TEST_PASS:-admin123}"
DC="docker compose"

pass=0
fail=0

check() {
	local label="$1" expected="$2" actual="$3"
	if echo "$actual" | grep -qi -- "$expected"; then
		printf '  OK    %s\n' "$label"
		pass=$((pass + 1))
	else
		printf '  ECHEC %s\n         attendu : %s\n         obtenu  : %s\n' "$label" "$expected" "$actual"
		fail=$((fail + 1))
	fi
}

login() {
	rm -f "$COOKIES"
	curl -s -c "$COOKIES" -b "$COOKIES" \
		-d "log=${USER}&pwd=${PASS}&wp-submit=Log+In&testcookie=1" \
		"${BASE}/wp-login.php" -o /dev/null
}

get_nonce() {
	curl -s -b "$COOKIES" "${BASE}/wp-admin/admin.php?page=kpay-payouts" \
		| grep -oE 'name="_wpnonce" value="[^"]+"' | head -1 \
		| sed 's/.*value="//; s/"//'
}

# Vide la notice en attente, pour ne pas lire celle du test précédent.
clear_notice() {
	$DC exec -T cli wp transient delete kpay_admin_notice_1 >/dev/null 2>&1
}

# Soumet un retrait et renvoie la notice produite (message brut).
withdraw() {
	local phone="$1" amount="$2" confirm="${3:-1}" provider="${4:-MTN_MOMO_CMR}"
	local nonce
	nonce=$(get_nonce)
	clear_notice

	local data="action=kpay_withdraw&_wpnonce=${nonce}&wallet_currency=XAF"
	data="${data}&provider=${provider}&phone=${phone}&amount=${amount}&description=test"
	[ "$confirm" = "1" ] && data="${data}&confirm=1"

	curl -s -b "$COOKIES" -o /dev/null -d "$data" "${BASE}/wp-admin/admin-post.php"

	$DC exec -T cli wp eval '
		$n = get_transient("kpay_admin_notice_1");
		echo $n ? $n["type"] . "|" . $n["message"] : "AUCUNE_NOTICE";
	' 2>/dev/null
}

login

echo ""
echo "=== Retraits — numéros virtuels de la spec ==="

out=$(withdraw "237653456789" "5000")
check "237653456789 -> retrait initié" "success|Retrait initié" "$out"
check "  net après commission de 5 %" "4,750 XAF" "$out"

out=$(withdraw "237653456129" "1000")
check "237653456129 (SUBMITTED) -> accepté" "success|Retrait initié" "$out"

out=$(withdraw "237653456089" "1000")
check "237653456089 (RECIPIENT_NOT_FOUND) -> accepté par l'API" "success|Retrait initié" "$out"

echo ""
echo "=== Garde-fous ==="

out=$(withdraw "237653456789" "5000" "0")
check "sans confirmation -> bloqué" "error|.*confirmer" "$out"

out=$(withdraw "123" "5000")
check "numéro invalide -> bloqué" "error|Numéro invalide" "$out"

out=$(withdraw "237653456789" "50")
check "montant sous le minimum -> bloqué" "error|.*minimum" "$out"

out=$(withdraw "237653456789" "999999")
check "montant > solde disponible -> bloqué" "error|.*insuffisant" "$out"

out=$(withdraw "237653456789" "5000" "1" "PROVIDER_BIDON")
check "opérateur invalide -> bloqué" "error|Opérateur invalide" "$out"

echo ""
echo "=== Contrôle d'accès ==="

stolen_nonce=$(get_nonce)
clear_notice
rm -f "$COOKIES"

curl -s -o /dev/null \
	-d "action=kpay_withdraw&_wpnonce=${stolen_nonce}&wallet_currency=XAF&provider=MTN_MOMO_CMR&phone=237653456789&amount=5000&confirm=1" \
	"${BASE}/wp-admin/admin-post.php"

after=$($DC exec -T cli wp eval '
	$n = get_transient("kpay_admin_notice_1");
	echo $n ? $n["type"] . "|" . $n["message"] : "AUCUNE_NOTICE";
' 2>/dev/null)

if echo "$after" | grep -qi "Retrait initié"; then
	printf '  ECHEC visiteur non connecté -> retrait accepté (FAILLE)\n'
	fail=$((fail + 1))
else
	printf '  OK    visiteur non connecté -> refusé\n'
	pass=$((pass + 1))
fi

echo ""
echo "--------------------------------------------------"
if [ "$fail" -gt 0 ]; then
	echo "ECHEC : ${fail} test(s) en échec, ${pass} réussi(s)."
	exit 1
fi
echo "SUCCES : ${pass} tests passés, aucun échec."
