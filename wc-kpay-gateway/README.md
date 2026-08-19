# K-Pay for WooCommerce

Encaissez par Mobile Money (MTN MoMo, Orange Money, Airtel Money, M-Pesa…)
directement depuis WordPress. Couvre 12 pays africains.

Le plugin se consacre à l'encaissement. Vos soldes et vos retraits se
consultent et se pilotent depuis votre tableau de bord K-Pay
(<https://admin.kpay.site>).

- **Version** : 2.1.0
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

Rendez-vous dans **WooCommerce → Réglages → Paiements → K-Pay (Mobile Money)**.

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
| **Sandbox** (test) | `kpay_test_…` | 64 caractères hexadécimaux, sans préfixe |
| **Live** (production) | `kpay_live_…` | 64 caractères hexadécimaux, sans préfixe |

Seule la **clé API** porte un préfixe : c'est lui qui sélectionne
l'environnement côté K-Pay. La **clé secrète** n'en porte aucun — elle se
présente comme `3f8a1c…` et n'est affichée qu'une seule fois, au moment où
vous la générez.

Les clés Live sont débloquées après validation KYC. Les deux paires sont
conservées séparément : renseignez vos clés Live une fois, et basculez en
Sandbox pour tester sans les perdre.

Le plugin vérifie vos clés à l'enregistrement et confirme le nom de votre
application. Une clé du mauvais environnement est signalée immédiatement.

### 3. Choisissez le mode de paiement

Deux modes sont disponibles :

| Mode | Parcours du client |
| --- | --- |
| **USSD** (défaut) | Il choisit son opérateur et saisit son numéro sur le checkout de votre boutique. K-Pay envoie une demande de confirmation sur son téléphone. |
| **Passerelle hébergée** | Aucun champ au checkout : il est redirigé vers la page de paiement hébergée par K-Pay, puis renvoyé sur votre boutique. |

**Ce réglage doit correspondre au mode configuré pour votre Application dans le
tableau de bord K-Pay.** C'est la configuration K-Pay qui fait autorité ; le
réglage du plugin ne fait que s'y aligner. Si les deux divergent, l'API refuse
les paiements avec une erreur 400 — le contrat de mode n'est pas respecté.

En cas de doute, vérifiez le mode de votre Application sur
<https://admin.kpay.site> avant de modifier ce réglage.

### 4. Choisissez vos opérateurs

Seuls les opérateurs correspondant à la devise de votre boutique sont proposés
au client. Laisser vide revient à tous les accepter.

En mode **Passerelle hébergée**, ce choix ne s'applique pas au checkout : c'est
la page K-Pay qui présente les opérateurs disponibles.

### 5. Configurez le webhook

Copiez l'**URL du webhook** affichée dans les réglages et déclarez-la comme
callback dans votre tableau de bord K-Pay. Collez ensuite le **secret webhook**
fourni par K-Pay dans le champ prévu.

Ce secret est **obligatoire**. Sans lui, les notifications entrantes sont
rejetées : accepter un webhook non signé permettrait à n'importe qui de marquer
une commande comme payée sans avoir jamais payé.

### 6. En mode passerelle : renseignez le secret passerelle

Le **secret passerelle** sert à vérifier la signature de l'URL par laquelle
K-Pay renvoie le client sur votre boutique. Il est **distinct du secret
webhook** : ne confondez pas les deux champs.

Il n'est utilisé qu'en mode **Passerelle hébergée**, où il est obligatoire :
sans lui, le plugin refuse d'initier un paiement plutôt que d'accepter un retour
dont il ne peut pas vérifier l'origine. En mode USSD, laissez le champ vide.

### 7. Choisissez la langue (facultatif)

Le réglage **Langue** détermine la langue des textes du plugin, au checkout
comme dans l'administration :

| Valeur | Effet |
| --- | --- |
| **Langue du site** (défaut) | Suit le réglage de langue de WordPress |
| **Français** | Force `fr_FR` |
| **English** | Force `en_US` |

Il ne concerne que le plugin : le reste du site conserve sa propre langue.

### 8. Activez la passerelle

Cochez **Activer la passerelle K-Pay**, puis enregistrez.

---

## Tester avant de passer en production

Placez-vous en **Sandbox** : aucun argent réel n'est échangé, et le client voit
la mention « Mode test actif » au checkout.

En sandbox, **le numéro saisi détermine le résultat**. Pour le Cameroun :

| Numéro | Résultat |
| --- | --- |
| `237653456789` | Paiement réussi |
| `237653456129` | Reste en attente (teste le suivi) |
| `237653456029` | Échec — payeur introuvable |
| `237653456039` | Échec — paiement non approuvé |
| `237653456019` | Échec — plafond du payeur atteint |

Les numéros des onze autres pays figurent dans la documentation K-Pay.

En mode **Passerelle hébergée**, le numéro n'est pas saisi sur votre boutique :
l'issue du test se choisit sur la page de paiement K-Pay en sandbox.

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

### Le paiement en mode USSD

1. Au checkout, le client choisit son opérateur et saisit son numéro Mobile Money.
2. Le plugin demande à K-Pay d'initier le paiement ; le client reçoit une
   demande de confirmation sur son téléphone.
3. La commande passe **en attente** — elle n'est jamais payée à ce stade.
4. Le client valide sur son téléphone. K-Pay notifie votre site par **webhook
   signé** (source d'autorité). En secours, la page de confirmation interroge
   le statut toutes les 5 secondes pendant 5 minutes.
5. À réception d'un paiement confirmé, la commande est marquée payée.

### Le paiement en mode passerelle hébergée

1. Au checkout, aucun champ n'est demandé : le client voit seulement un message
   l'informant qu'il va être redirigé.
2. Le plugin demande à K-Pay d'ouvrir une session de paiement et redirige le
   client vers la page hébergée par K-Pay, où il choisit son opérateur et paie.
3. La commande passe **en attente**.
4. K-Pay renvoie le client sur votre boutique par une URL **signée en HMAC** :
   le plugin vérifie cette signature, puis **reconfirme le statut par un appel
   API** avant de conclure. Une URL de retour, même correctement signée, ne
   suffit jamais à marquer une commande payée.
5. Le webhook signé reste la source d'autorité et s'applique de la même manière.

Dans les deux modes, une commande n'est marquée payée que sur confirmation
vérifiée de K-Pay — jamais sur la base d'une redirection ou d'une requête venant
du navigateur du client.

### Soldes et retraits

Ils ne se gèrent pas depuis WordPress. Connectez-vous à votre tableau de bord
K-Pay (<https://admin.kpay.site>) pour consulter vos soldes par devise et
effectuer vos retraits. Le plugin ne fait qu'encaisser.

### Séparation test / production

Les données des deux environnements ne se mélangent jamais : chaque **commande**
mémorise l'environnement dans lequel elle a été payée, et reste suivie dans
celui-ci même après bascule.

---

## Sécurité

- **Webhooks signés** : signature HMAC-SHA256 vérifiée sur le corps brut, en
  comparaison à temps constant. Un webhook non signé, mal signé, ou dont le
  corps a été modifié est rejeté.
- **Anti-rejeu** : l'horodatage est obligatoire, et une notification de plus de
  10 minutes est refusée. Une notification identique rejouée dans les 15 minutes
  est ignorée.
- **Liaison stricte** : la transaction annoncée doit correspondre à celle
  enregistrée sur la commande. Une notification légitime destinée à une autre
  commande ne peut pas la marquer payée.
- **Montant contrôlé** : le montant annoncé par le webhook est comparé au total
  de la commande. S'il est insuffisant, la commande reste en attente, une note
  est ajoutée et l'écart est journalisé.
- **Événements filtrés** : seuls les événements `payment.*` pilotent le statut
  d'une commande. Les événements `refund.*` et `payout.*` sont acquittés puis
  ignorés.
- **Montant côté serveur** : le montant débité provient de la commande, jamais
  du navigateur du client.
- **Retour de passerelle vérifié** : en mode passerelle hébergée, la signature
  de l'URL de retour est contrôlée, puis le statut est reconfirmé par un appel
  API avant toute conclusion.
- **Suivi limité** : le suivi automatique n'interroge l'API qu'une fois toutes
  les 4 secondes par commande, et répond de façon identique (403) qu'une
  commande soit inconnue ou la clé invalide — impossible d'énumérer les
  commandes.
- **Clés protégées** : elles ne transitent que de serveur à serveur, ne sont
  jamais exposées au navigateur ni journalisées.

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
Pour la clé **API**, le préfixe doit correspondre à l'environnement :
`kpay_test_` en Sandbox, `kpay_live_` en Live. Pour la clé **secrète**, il n'y
a pas de préfixe à chercher : seuls sa longueur (64 caractères) et son alphabet
(hexadécimal) sont contrôlés — un message « tronquée à la copie » signale le
cas le plus courant. Le message d'erreur précise toujours ce qui est attendu.

**Les paiements sont refusés avec une erreur 400**
Le **Mode de paiement** du plugin ne correspond probablement pas à celui
configuré pour votre Application dans le tableau de bord K-Pay. Comparez les
deux et alignez le réglage du plugin sur celui de l'Application.

**En mode passerelle, le paiement ne démarre pas**
Le **secret passerelle** n'est pas renseigné. Il est obligatoire dans ce mode et
distinct du secret webhook.

**Le client revient de la page K-Pay mais la commande reste en attente**
Le retour a été refusé (signature invalide ou secret passerelle erroné), ou
K-Pay n'a pas encore confirmé le paiement. Vérifiez le secret passerelle, puis
les journaux.

**Une commande reste en attente alors que le paiement semble passé**
Le montant notifié était peut-être inférieur au total de la commande : le plugin
refuse alors de la valider. Une note est ajoutée à la commande et l'écart est
journalisé.

**Voir ce qui se passe**
Activez **Journalisation** dans les réglages, puis consultez
**WooCommerce → État → Journaux**, source `kpay`. Les clés n'y figurent jamais.

---

## Support

Documentation et tableau de bord : <https://admin.kpay.site>
