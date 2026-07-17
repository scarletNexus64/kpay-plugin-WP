# K-Pay pour WooCommerce

Encaissez par Mobile Money (MTN MoMo, Orange Money, Airtel Money, M-Pesa…) et
gérez vos retraits, directement depuis WordPress. Couvre 12 pays africains.

- **Version** : 2.0.0
- **Prérequis** : WordPress 6.0+, PHP 7.4+, WooCommerce 7.0+
- **Compatible** : HPOS, checkout en blocs et checkout classique

---

## Installation

### Depuis WordPress (recommandé)

1. **Extensions → Ajouter → Téléverser une extension**
2. Choisissez le fichier `wc-kpay-gateway.zip`, puis **Installer maintenant**
3. Cliquez sur **Activer**

### Par FTP

Copiez le dossier `wc-kpay-gateway/` **entier** dans `wp-content/plugins/`, puis
activez le plugin depuis **Extensions**. Le résultat doit être :

```
wp-content/plugins/wc-kpay-gateway/wc-kpay-gateway.php
```

WooCommerce doit être installé et actif : sans lui, K-Pay ne s'affiche pas.

---

## Configuration

Rendez-vous dans **WooCommerce → Réglages → Paiements → K-Pay (Mobile Money)**,
ou via le menu **K-Pay → Réglages**.

### 1. Vérifiez la devise de votre boutique

K-Pay encaisse dans la devise du pays de l'opérateur et **ne convertit aucune
devise**. La devise de votre boutique (**WooCommerce → Réglages → Général**)
doit donc être l'une des suivantes :

| Devise | Pays |
| --- | --- |
| XAF | Cameroun, Gabon, Congo |
| XOF | Bénin, Côte d'Ivoire, Sénégal |
| KES | Kenya |
| CDF | RD Congo |
| UGX | Ouganda |
| RWF | Rwanda |
| ZMW | Zambie |
| SLE | Sierra Leone |

Une boutique en EUR ou en USD ne peut pas utiliser K-Pay. Le plugin vous
l'indique clairement dans les réglages plutôt que de disparaître en silence.

### 2. Renseignez vos clés

Récupérez vos clés depuis votre tableau de bord K-Pay :

| Environnement | Clé API | Clé secrète |
| --- | --- | --- |
| **Sandbox** (test) | `kpay_test_…` | `sk_test_…` |
| **Live** (production) | `kpay_live_…` | `sk_live_…` |

Les clés Live sont débloquées après validation KYC. Les deux paires sont
conservées séparément : renseignez vos clés Live une fois, et basculez en
Sandbox pour tester sans les perdre.

Le plugin vérifie vos clés à l'enregistrement et confirme le nom de votre
application. Une clé du mauvais environnement est signalée immédiatement.

### 3. Choisissez vos opérateurs

Seuls les opérateurs correspondant à la devise de votre boutique sont proposés
au client. Laisser vide revient à tous les accepter.

### 4. Configurez le webhook

Copiez l'**URL du webhook** affichée dans les réglages et déclarez-la comme
callback dans votre tableau de bord K-Pay. Collez ensuite le **secret webhook**
fourni par K-Pay dans le champ prévu.

Ce secret est **obligatoire**. Sans lui, les notifications entrantes sont
rejetées : accepter un webhook non signé permettrait à n'importe qui de marquer
une commande comme payée sans avoir jamais payé.

### 5. Activez la passerelle

Cochez **Activer la passerelle K-Pay**, puis enregistrez.

---

## Tester avant de passer en production

Placez-vous en **Sandbox** : aucun argent réel n'est échangé, et le client voit
la mention « Mode test actif » au checkout.

En sandbox, **le numéro saisi détermine le résultat**. Pour le Cameroun :

**Paiements**

| Numéro | Résultat |
| --- | --- |
| `237653456789` | Paiement réussi |
| `237653456129` | Reste en attente (teste le suivi) |
| `237653456029` | Échec — payeur introuvable |
| `237653456039` | Échec — paiement non approuvé |
| `237653456019` | Échec — plafond du payeur atteint |

**Retraits** (les numéros diffèrent de ceux des paiements)

| Numéro | Résultat |
| --- | --- |
| `237653456789` | Retrait réussi |
| `237653456129` | Reste en attente |
| `237653456089` | Échec — bénéficiaire introuvable |
| `237653456119` | Échec — erreur non spécifiée |

Les numéros des onze autres pays figurent dans la documentation K-Pay.

### Passer en production

1. Validez votre KYC pour débloquer les clés `kpay_live_`
2. Renseignez-les dans la section **Clés Live**
3. Basculez l'**Environnement** sur **Live (production)**
4. Vérifiez que l'URL du webhook pointe vers votre domaine public — un webhook
   ne peut pas joindre `localhost`
5. Passez une commande réelle de faible montant pour valider le parcours

Un bandeau rouge en haut des réglages rappelle en permanence que vous êtes en
production.

---

## Fonctionnement

### Le paiement, étape par étape

1. Au checkout, le client choisit son opérateur et saisit son numéro Mobile Money.
2. Le plugin demande à K-Pay d'initier le paiement ; le client reçoit une
   demande de confirmation sur son téléphone.
3. La commande passe **en attente** — elle n'est jamais payée à ce stade.
4. Le client valide sur son téléphone. K-Pay notifie votre site par **webhook
   signé** (source d'autorité). En secours, la page de confirmation interroge
   le statut toutes les 5 secondes pendant 5 minutes.
5. À réception d'un paiement confirmé, la commande est marquée payée.

Une commande n'est marquée payée que sur confirmation vérifiée de K-Pay — jamais
sur la base d'une redirection ou d'une requête venant du navigateur du client.

### Le menu K-Pay

**Soldes** — une carte par devise, avec les pays couverts. K-Pay tient un wallet
par *devise*, pas par pays : un solde XAF vaut pour le Cameroun, le Gabon et le
Congo. Chaque carte affiche le montant disponible, le total et le montant
réservé par des retraits en cours.

**Retraits** — envoyez des fonds vers un numéro Mobile Money. Une commission de
5 % est prélevée : sur 5 000 XAF envoyés, le bénéficiaire reçoit 4 750 XAF. Le
minimum est de 100 XAF.

Si l'opérateur du bénéficiaire relève d'une autre zone que le wallet débité
(wallet XAF vers un numéro ivoirien en XOF, par exemple), K-Pay convertit
automatiquement au taux en vigueur.

Un retrait est **irréversible** : une confirmation explicite est demandée avant
tout envoi.

### Séparation test / production

Les données des deux environnements ne se mélangent jamais :

- les **soldes** sont mis en cache séparément ;
- les **retraits** sont enregistrés dans deux historiques distincts ; la page
  n'affiche que ceux de l'environnement courant ;
- chaque **commande** mémorise l'environnement dans lequel elle a été payée, et
  reste suivie dans celui-ci même après bascule.

### Accès

Les soldes et les retraits sont réservés aux utilisateurs disposant de la
capacité `manage_woocommerce` — administrateurs et gestionnaires de boutique.

---

## Sécurité

- **Webhooks signés** : signature HMAC-SHA256 vérifiée sur le corps brut, en
  comparaison à temps constant. Un webhook non signé, mal signé, ou dont le
  corps a été modifié est rejeté.
- **Anti-rejeu** : une notification de plus de 10 minutes est refusée.
- **Liaison stricte** : la transaction annoncée doit correspondre à celle
  enregistrée sur la commande. Une notification légitime destinée à une autre
  commande ne peut pas la marquer payée.
- **Montant côté serveur** : le montant débité provient de la commande, jamais
  du navigateur du client.
- **Clés protégées** : elles ne transitent que de serveur à serveur, ne sont
  jamais exposées au navigateur ni journalisées.
- **Retraits** : capacité et jeton CSRF vérifiés, solde contrôlé avant envoi ;
  en cas de doute, le retrait est annulé plutôt que tenté.

---

## Dépannage

**K-Pay n'apparaît pas dans WooCommerce → Paiements**
Le dossier n'est pas au bon endroit ou le plugin n'est pas activé. Vérifiez
`wp-content/plugins/wc-kpay-gateway/wc-kpay-gateway.php`, puis la page Extensions.

**K-Pay apparaît en admin mais pas au checkout**
Vérifiez dans l'ordre : la case « Activer » est cochée ; les clés de
l'environnement courant sont renseignées ; la devise de la boutique correspond à
un opérateur activé. Les réglages affichent la raison exacte.

**Les commandes restent bloquées « en attente »**
Le webhook n'arrive pas. En local, K-Pay ne peut pas joindre votre site : le
suivi automatique prend le relais tant que le client reste sur la page. En
production, vérifiez l'URL du webhook et le secret dans votre tableau de bord.

**Une clé est refusée**
Le préfixe doit correspondre à l'environnement : `kpay_test_` en Sandbox,
`kpay_live_` en Live. Le message d'erreur précise ce qui est attendu.

**Voir ce qui se passe**
Activez **Journalisation** dans les réglages, puis consultez
**WooCommerce → État → Journaux**, source `kpay`. Les clés n'y figurent jamais.

---

## Support

Documentation et tableau de bord : <https://admin.kpay.site>
