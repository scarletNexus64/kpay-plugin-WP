# Traductions

L'interface du plugin est en français. Pour la traduire :

1. Générez un catalogue depuis les sources (WP-CLI) :
   `wp i18n make-pot . languages/k-pay-for-woocommerce.pot`
2. Traduisez le `.pot` (Poedit, GlotPress…) vers `k-pay-for-woocommerce-<locale>.po`.
3. Compilez en `.mo` et déposez les deux fichiers ici.

Exemple pour l'anglais : `k-pay-for-woocommerce-en_US.po` / `.mo`.

Le domaine de texte est `k-pay-for-woocommerce` : il reprend le slug du plugin
sur wordpress.org, et les fichiers `.mo` ne sont chargés que si leur nom le
répète exactement.

Le plugin n'appelle pas `load_plugin_textdomain()` : WordPress charge seul les
traductions depuis la 4.6, celles publiées via translate.wordpress.org comme
celles déposées ici. Le réglage « Langue » reste opérant — il passe par le
filtre `plugin_locale`, appliqué à ce chargement automatique.
