=== K-Pay for WooCommerce ===
Contributors: steveboussa
Tags: woocommerce, payment gateway, mobile money, mtn momo, orange money
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 10.9
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Encaissez par Mobile Money (MTN MoMo, Orange Money, Airtel, M-Pesa) dans WooCommerce. 12 pays africains.

== Description ==

K-Pay ajoute le paiement Mobile Money à votre boutique WooCommerce. Le plugin
se consacre à l'encaissement : vos soldes et vos retraits se consultent et se
pilotent depuis votre tableau de bord K-Pay (https://admin.kpay.site).

= Deux modes de paiement =

En mode **USSD**, le client choisit son opérateur et saisit son numéro au
checkout, puis reçoit une demande de confirmation sur son téléphone.

En mode **Passerelle hébergée**, le client est redirigé vers la page de paiement
de K-Pay, puis renvoyé sur votre boutique par une URL signée dont le plugin
vérifie la signature avant de reconfirmer le statut auprès de l'API.

Le mode choisi dans le plugin doit correspondre à celui configuré pour votre
Application dans le tableau de bord K-Pay, sinon l'API refuse les paiements.

Dans les deux cas, la commande n'est marquée payée qu'après confirmation
vérifiée de K-Pay, jamais sur la base d'une redirection.

= Langue =

Les textes du plugin suivent par défaut la langue de WordPress. Vous pouvez les
forcer en français ou en anglais sans affecter le reste du site.

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
* Horodatage obligatoire et déduplication des notifications rejouées
* Montant du webhook comparé au total de la commande
* Seuls les événements payment.* modifient le statut d'une commande
* Retour de la passerelle hébergée signé, puis reconfirmé par appel API
* Montant toujours calculé côté serveur
* Clés API jamais exposées au navigateur ni journalisées
* Environnements test et production strictement cloisonnés

= Service externe =

Ce plugin s'appuie sur K-Pay, un service tiers d'encaissement Mobile Money, pour
traiter les paiements. Sans ce service, le plugin ne peut pas fonctionner : c'est
K-Pay qui contacte l'opérateur mobile du client et confirme le paiement.

Les échanges ont lieu avec https://admin.kpay.site aux moments suivants :

* À la commande, le plugin transmet le montant, la devise, la référence de la
  commande, l'opérateur choisi et le numéro de téléphone Mobile Money du client,
  afin d'initier la demande de paiement.
* Pendant l'attente, le plugin interroge le service avec l'identifiant de la
  transaction pour en connaître le statut.
* En mode passerelle hébergée, le client est redirigé vers la page de paiement
  de K-Pay, puis renvoyé sur la boutique.
* Le service notifie la boutique par webhook signé lorsque le paiement aboutit
  ou échoue.

Aucune donnée n'est transmise avant que le client ait choisi K-Pay comme moyen
de paiement et validé sa commande.

Service fourni par K-Pay :

* Site : https://kpay.site
* Tableau de bord : https://admin.kpay.site
* Conditions d'utilisation : https://kpay.site/legal/conditions
* Politique de confidentialité : https://kpay.site/legal/confidentialite
* Mentions légales : https://kpay.site/legal/mentions

== Installation ==

1. Téléversez le dossier `wc-kpay-gateway` dans `/wp-content/plugins/`, ou
   installez le plugin depuis Extensions → Ajouter.
2. Activez le plugin depuis le menu Extensions.
3. Allez dans WooCommerce → Réglages → Paiements → K-Pay (Mobile Money).
4. Renseignez vos clés API, choisissez vos opérateurs et configurez l'URL du
   webhook dans votre tableau de bord K-Pay.
5. Réglez le mode de paiement sur celui configuré pour votre Application dans le
   tableau de bord K-Pay. En mode passerelle hébergée, renseignez également le
   secret passerelle.
6. Testez en Sandbox avant de basculer en production.

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

= Mes paiements sont refusés avec une erreur 400 =

Le mode de paiement du plugin ne correspond pas à celui configuré pour votre
Application dans le tableau de bord K-Pay. C'est la configuration K-Pay qui fait
autorité : alignez le réglage du plugin sur celui de votre Application.

= Quelle différence entre le secret webhook et le secret passerelle ? =

Le secret webhook vérifie la signature des notifications que K-Pay envoie à
votre site. Le secret passerelle, utilisé uniquement en mode passerelle
hébergée, vérifie la signature de l'URL par laquelle le client est renvoyé sur
votre boutique. Les deux sont distincts et ne sont pas interchangeables. Sans
secret passerelle, le mode passerelle refuse d'initier un paiement.

= Où consulter mes soldes et effectuer mes retraits ? =

Depuis votre tableau de bord K-Pay, sur https://admin.kpay.site. Le plugin ne
gère que l'encaissement.

= Le plugin est-il compatible avec le checkout en blocs ? =

Oui, ainsi qu'avec le checkout classique et le stockage HPOS des commandes.

== Changelog ==

= 2.1.0 =
* Nouveau mode « Passerelle hébergée » : le client paie sur la page K-Pay, le
  retour est authentifié par signature puis reconfirmé auprès de l'API
* Nouveau réglage « Langue » : français, anglais, ou langue du site
* Nouveau réglage « Secret passerelle », distinct du secret webhook
* Sécurité : le montant confirmé est comparé au total de la commande ; une
  confirmation portant sur un montant insuffisant ne valide plus la commande
* Sécurité : seuls les événements `payment.*` pilotent le statut d'une commande
  (les remboursements et retraits ne peuvent plus marquer une commande payée)
* Sécurité : horodatage désormais obligatoire sur les notifications, et
  déduplication des notifications rejouées
* Sécurité : vérification du statut limitée en fréquence, et réponses
  uniformisées pour ne pas révéler l'existence d'une commande
* Les soldes et les retraits se gèrent désormais depuis le tableau de bord
  K-Pay ; le menu correspondant a été retiré de WordPress

= 2.0.0 =
* Réécriture complète, conforme à l'API K-Pay v1
* Menu K-Pay : soldes par devise et retraits Mobile Money
* Prise en charge du checkout en blocs et de HPOS
* Webhooks signés avec vérification HMAC et fenêtre anti-rejeu
* Bascule Sandbox/Live avec validation du préfixe des clés
* Cloisonnement strict des données test et production
* 12 pays et 23 opérateurs pris en charge

== Upgrade Notice ==

= 2.1.0 =
Le menu K-Pay (soldes et retraits) est retiré : ces opérations se font
désormais depuis votre tableau de bord K-Pay. Vérifiez que le nouveau réglage
« Mode de paiement » correspond au mode configuré pour votre Application dans
ce tableau de bord, faute de quoi les paiements seront refusés. Si vous
utilisez le mode « Passerelle hébergée », renseignez le secret passerelle dans
les réglages.

= 2.0.0 =
Version initiale publique. Configurez le secret webhook dans les réglages :
sans lui, les notifications de paiement sont rejetées.
