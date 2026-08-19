# K-Pay for WooCommerce

Passerelle de paiement Mobile Money (MTN MoMo, Orange Money, Airtel, M-Pesa…)
pour WooCommerce. 12 pays africains.

Le plugin encaisse uniquement. Vos soldes et vos retraits se consultent et se
pilotent depuis votre tableau de bord K-Pay (<https://admin.kpay.site>), pas
depuis WordPress.

---

## Contenu du dépôt

```
wc-kpay-gateway/        ← LE PLUGIN. C'est ce dossier qu'on installe et publie.
tests/                  ← suite de tests, usage interne — à NE PAS installer
kpay-api-reference.md   ← documentation de l'API K-Pay
```

**Un seul dossier compte : `wc-kpay-gateway/`.** Tout le reste sert au
développement et ne doit jamais se retrouver sur un site en production.

---

## Installer sur votre WordPress

Le plugin s'installe tel quel : aucune compilation, aucune dépendance, rien à
exécuter. Copiez simplement le dossier.

### 1. Récupérez les sources

```bash
git clone <url-du-depot>
cd plugin-woo-kpay
```

### 2. Copiez le plugin dans WordPress

```bash
cp -r wc-kpay-gateway /chemin/vers/wordpress/wp-content/plugins/
```

Adaptez le chemin à votre installation. Quelques emplacements courants :

| Environnement | Chemin |
| --- | --- |
| Local par défaut (macOS/Linux) | `/var/www/html/wp-content/plugins/` |
| MAMP | `/Applications/MAMP/htdocs/<site>/wp-content/plugins/` |
| XAMPP | `C:\xampp\htdocs\<site>\wp-content\plugins\` |
| LocalWP | `~/Local Sites/<site>/app/public/wp-content/plugins/` |
| Hébergement mutualisé | via FTP, dans `wp-content/plugins/` |

Le résultat doit être exactement :

```
wp-content/plugins/wc-kpay-gateway/wc-kpay-gateway.php
```

Si vous obtenez `plugins/plugin-woo-kpay/wc-kpay-gateway/…`, c'est un niveau de
trop : WordPress ne verra pas le plugin.

### 3. Activez

**Extensions** → « K-Pay for WooCommerce » → **Activer**.

WooCommerce doit être installé et actif au préalable.

### Alternative : archive à téléverser

Si votre équipe préfère l'installateur WordPress :

```bash
zip -r wc-kpay-gateway.zip wc-kpay-gateway -x '*.DS_Store'
```

Puis **Extensions → Ajouter → Téléverser une extension**.

---

## Configurer

La suite se passe dans WordPress. **Le guide complet est dans
[`wc-kpay-gateway/README.md`](wc-kpay-gateway/README.md)** : configuration pas à
pas, numéros de test, dépannage.

En résumé :

1. **WooCommerce → Réglages → Général** : la devise doit être XAF, XOF, KES,
   CDF, UGX, RWF, ZMW ou SLE. K-Pay ne convertit aucune devise — une boutique en
   EUR ne pourra pas l'utiliser.
2. **WooCommerce → Réglages → Paiements → K-Pay** : cochez « Activer », gardez
   l'environnement **Sandbox**, collez votre clé API `kpay_test_…` et votre
   clé secrète (64 caractères hexadécimaux, sans préfixe).
3. Réglez le **Mode de paiement** sur celui configuré pour votre Application
   dans le tableau de bord K-Pay : **USSD** (le client saisit son numéro sur
   votre site) ou **Passerelle hébergée** (le client est redirigé vers la page
   de paiement K-Pay). Les deux réglages doivent concorder, sinon l'API refuse
   les paiements. En mode passerelle, renseignez aussi le **secret passerelle**.
4. Copiez l'**URL du webhook** affichée dans les réglages, déclarez-la dans
   votre tableau de bord K-Pay, et collez en retour le **secret webhook**.
   Sans ce secret, les notifications de paiement sont rejetées.
5. Testez une commande avec le numéro `237653456789` : il force un paiement
   réussi en sandbox.

Les soldes et les retraits ne se gèrent pas depuis WordPress : rendez-vous sur
<https://admin.kpay.site>.

---

## Développer

### Tests unitaires (rapides, aucune dépendance)

```bash
cd tests
composer install
./vendor/bin/phpunit             # 185 tests
./vendor/bin/phpunit --testdox   # détail lisible
```

Ils exercent le vrai code du plugin ; seules les fonctions WordPress sont
simulées. Aucun WordPress ni Docker n'est nécessaire.

### Tests sur un WordPress réel

`tests/README.md` décrit une pile Docker optionnelle. **Elle n'est pas
nécessaire** : pour développer, copiez le plugin dans votre WordPress habituel,
ou créez un lien symbolique pour que vos modifications soient immédiates :

```bash
ln -s "$(pwd)/wc-kpay-gateway" /chemin/vers/wordpress/wp-content/plugins/wc-kpay-gateway
```

Activez ensuite `WP_DEBUG` dans `wp-config.php` : les scripts et styles seront
rechargés automatiquement à chaque modification, sans vider le cache.

---

## État vérifié

| Contrôle | Résultat |
| --- | --- |
| Tests unitaires | 185 tests, 369 assertions |
| WordPress 6.9 + WooCommerce 10.9 | 34 vérifications |
| Webhook attaqué en HTTP réel | 8 tests |
| Audit de sécurité | aucune faille critique ni élevée |
| Erreurs / avertissements PHP | aucun |

**Ce qui reste à valider** : l'API K-Pay réelle n'a jamais été appelée — les
réponses ont été simulées d'après la spécification. Le parcours complet doit
être testé en sandbox avec de vraies clés `kpay_test_` avant toute mise en
production.
