#!/usr/bin/env bash
#
# Génère l'archive distribuable du plugin.
#
#   ./build.sh
#
# Produit wc-kpay-gateway-<version>.zip à la racine, où <version> est lue dans
# l'en-tête du plugin. L'archive contient le seul dossier wc-kpay-gateway/ :
# tests/ et documentation de développement en sont exclus.

set -euo pipefail

cd "$(dirname "$0")"

PLUGIN_DIR="wc-kpay-gateway"
MAIN_FILE="$PLUGIN_DIR/wc-kpay-gateway.php"

# --- Version -----------------------------------------------------------------

version=$(grep -m1 "^ \* Version:" "$MAIN_FILE" | tr -d ' ' | cut -d: -f2)

if [ -z "$version" ]; then
	echo "Version introuvable dans $MAIN_FILE" >&2
	exit 1
fi

# La version de l'en-tête, la constante PHP et le Stable tag du readme sont lus
# par trois consommateurs différents (WordPress, le plugin, wordpress.org) : un
# écart entre eux passe inaperçu jusqu'à la mise à jour qui ne se déclenche pas.
constant=$(grep -m1 "WC_KPAY_VERSION" "$MAIN_FILE" | sed -E "s/.*'([0-9.]+)'.*/\1/")
stable=$(grep -m1 "^Stable tag:" "$PLUGIN_DIR/readme.txt" | tr -d ' ' | cut -d: -f2)

if [ "$version" != "$constant" ] || [ "$version" != "$stable" ]; then
	echo "Versions incohérentes :" >&2
	echo "  en-tête      $version" >&2
	echo "  constante    $constant" >&2
	echo "  Stable tag   $stable" >&2
	exit 1
fi

# --- Contrôles ---------------------------------------------------------------

name=$(grep -m1 "^ \* Plugin Name:" "$MAIN_FILE" | sed -E 's/^ \* Plugin Name: *//')

echo "$name $version"
echo

echo "Syntaxe PHP…"
find "$PLUGIN_DIR" -name '*.php' -print0 | xargs -0 -n1 php -l > /dev/null
echo "  ok"

if [ -x tests/vendor/bin/phpunit ]; then
	echo "Tests…"
	( cd tests && vendor/bin/phpunit --no-output ) && echo "  ok"
else
	echo "Tests ignorés (composer install non exécuté dans tests/)"
fi

# --- Archive -----------------------------------------------------------------

archive="wc-kpay-gateway-$version.zip"

rm -f "$archive"
zip -r -q "$archive" "$PLUGIN_DIR" \
	-x '*.DS_Store' \
	-x "$PLUGIN_DIR/README.md"

echo
echo "$archive"
unzip -l "$archive" | tail -1
