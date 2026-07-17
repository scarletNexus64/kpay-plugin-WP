=== K-Pay pour WooCommerce ===
Contributors: kpay
Tags: woocommerce, payment gateway, mobile money, mtn momo, orange money
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.9
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encaissez par Mobile Money (MTN MoMo, Orange Money, Airtel, M-Pesa) et gérez vos retraits depuis WordPress. 12 pays africains.

== Description ==

K-Pay ajoute le paiement Mobile Money à votre boutique WooCommerce et vous
permet de suivre vos soldes et d'effectuer des retraits sans quitter WordPress.

= Paiements =

Le client choisit son opérateur et saisit son numéro au checkout. Il reçoit une
demande de confirmation sur son téléphone. La commande n'est marquée payée
qu'après confirmation vérifiée de K-Pay, jamais sur la base d'une redirection.

= Soldes et retraits =

Un menu K-Pay affiche vos soldes par devise, avec les pays couverts, et permet
d'envoyer des fonds vers n'importe quel numéro Mobile Money supporté. Les
retraits interfrontaliers convertissent automatiquement la devise.

= Opérateurs supportés =

* Bénin — MTN MoMo, Moov
* Cameroun — MTN MoMo, Orange Money
* Congo — Airtel, MTN MoMo
* Côte d'Ivoire — MTN MoMo, Orange Money
* Gabon — Airtel Money
* Kenya — M-Pesa
* Ouganda — Airtel, MTN MoMo
* RD Congo — Vodacom M-Pesa, Airtel, Orange
* Rwanda — Airtel, MTN MoMo
* Sénégal — Free Money, Orange Money
* Sierra Leone — Orange Money
* Zambie — Airtel, MTN MoMo, Zamtel

= Devises =

XAF, XOF, KES, CDF, UGX, RWF, ZMW, SLE. Aucune conversion n'est effectuée : la
devise de la boutique doit correspondre à celle du pays de l'opérateur.

= Sécurité =

* Webhooks signés (HMAC-SHA256, comparaison à temps constant, fenêtre anti-rejeu)
* Montant toujours calculé côté serveur
* Clés API jamais exposées au navigateur ni journalisées
* Environnements test et production strictement cloisonnés

== Installation ==

1. Téléversez le dossier `wc-kpay-gateway` dans `/wp-content/plugins/`, ou
   installez le plugin depuis Extensions → Ajouter.
2. Activez le plugin depuis le menu Extensions.
3. Allez dans WooCommerce → Réglages → Paiements → K-Pay (Mobile Money).
4. Renseignez vos clés API, choisissez vos opérateurs et configurez l'URL du
   webhook dans votre tableau de bord K-Pay.
5. Testez en Sandbox avant de basculer en production.

WooCommerce doit être installé et actif.

== Frequently Asked Questions ==

= K-Pay n'apparaît pas au checkout =

Vérifiez que la case « Activer » est cochée, que les clés de l'environnement
courant sont renseignées, et que la devise de votre boutique correspond à un
opérateur activé. Les réglages affichent la raison exacte.

= Ma boutique est en euros, puis-je utiliser K-Pay ? =

Non. K-Pay encaisse dans la devise du pays de l'opérateur et ne convertit
aucune devise. Réglez votre boutique sur XAF, XOF, KES, CDF, UGX, RWF, ZMW ou SLE.

= Comment tester sans argent réel ? =

Choisissez l'environnement Sandbox et utilisez vos clés `kpay_test_`. En
sandbox, le numéro saisi détermine le résultat : `237653456789` réussit,
`237653456029` échoue.

= Les commandes restent en attente =

Le webhook n'atteint pas votre site. En local, c'est normal : K-Pay ne peut pas
joindre `localhost`. En production, vérifiez l'URL du webhook et le secret
configurés dans votre tableau de bord K-Pay.

= Le plugin est-il compatible avec le checkout en blocs ? =

Oui, ainsi qu'avec le checkout classique et le stockage HPOS des commandes.

== Changelog ==

= 2.0.0 =
* Réécriture complète, conforme à l'API K-Pay v1
* Menu K-Pay : soldes par devise et retraits Mobile Money
* Prise en charge du checkout en blocs et de HPOS
* Webhooks signés avec vérification HMAC et fenêtre anti-rejeu
* Bascule Sandbox/Live avec validation du préfixe des clés
* Cloisonnement strict des données test et production
* 12 pays et 23 opérateurs pris en charge

== Upgrade Notice ==

= 2.0.0 =
Version initiale publique. Configurez le secret webhook dans les réglages :
sans lui, les notifications de paiement sont rejetées.
