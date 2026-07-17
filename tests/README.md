# Tests — K-Pay Gateway

**Ce dossier ne s'installe pas sur un site.** Il sert au développement du
plugin. Pour installer K-Pay, seul `wc-kpay-gateway/` compte — voir le
[README à la racine](../README.md).

Trois niveaux de vérification, du plus rapide au plus proche du réel. **Seul le
premier est nécessaire au quotidien** ; les deux autres exigent Docker et ne
servent qu'à valider une release.

## 1. Tests unitaires (2 secondes, aucune dépendance)

Exercent le vrai code du plugin ; seules les fonctions WordPress sont simulées.

```bash
cd tests
composer install
./vendor/bin/phpunit            # 125 tests
./vendor/bin/phpunit --testdox  # détail lisible
```

Couverture par fichier :

| Suite | Ce qui est vérifié |
| --- | --- |
| `PaymentFlowTest` | Conformité de la requête à la spec K-Pay (endpoint `/init`, `phoneNumber`, `externalId`, devise non transmise), montants entiers/décimaux, unicité de l'`externalId` par tentative, gestion des erreurs API et réseau |
| `WebhookSecurityTest` | 6 scénarios d'attaque rejetés, idempotence, machine à états, course avant enregistrement |
| `PollingTest` | Authentification (nonce + clé de commande), application du statut, environnement de la commande respecté |
| `AvailabilityTest` | Affichage de la passerelle, filtrage par devise, sélection des opérateurs |
| `PhoneNormalizationTest` | Numéros de test officiels des 12 pays, formats de saisie, rejets |
| `BrandingTest` | Logo, avertissements d'administration |
| `AssetsTest` | Présence des fichiers, styles, markup des blocs |
| `EnvironmentTest` | Bascule Sandbox/Live : sélection des clés, URL identique, validation des préfixes, clés du mauvais environnement, bandeau |
| `PayoutTest` | Endpoints solde/retrait, numéros de test des 12 pays, correspondance pays/devise, payout cross-devise |
| `PayoutFormTest` | Garde-fous du retrait : confirmation, nonce, capacité, numéro, minimum, solde, unicité de l'`externalId` |

## 2. WordPress réel (Docker, optionnel)

> **Attention** — le plugin est monté en volume depuis ce dépôt. N'exécutez
> jamais `wp plugin install --force` ni `wp plugin delete` sur `wc-kpay-gateway`
> dans cette pile : WordPress supprimerait les **sources du dépôt** à travers le
> montage. Pour tester une archive, extrayez-la ailleurs (`/tmp`).

Vérifie ce que les tests unitaires ne peuvent pas : activation réelle,
enregistrement auprès de WooCommerce, route REST, rendu.

```bash
cd tests
docker compose up -d

# Installation (une seule fois)
docker compose exec cli wp core install \
  --url=http://localhost:8888 --title="Boutique Test" \
  --admin_user=admin --admin_password=admin123 \
  --admin_email=test@example.com --skip-email
docker compose exec cli wp plugin install woocommerce --activate
docker compose exec cli wp plugin activate wc-kpay-gateway
docker compose exec cli wp option update woocommerce_currency XAF
docker compose exec cli wp rewrite structure '/%postname%/' && docker compose exec cli wp rewrite flush

# Vérification (30 contrôles)
docker compose exec cli wp eval-file /var/www/html/kpay-tests/verify-in-wordpress.php
```

Admin : <http://localhost:8888/wp-admin> (`admin` / `admin123`).
Le dossier du plugin est monté en direct : toute modification est immédiate.

Pour arrêter : `docker compose down -v`.

### Simulateur d'API (pour tester le menu K-Pay sans clés réelles)

Le menu Soldes/Retraits interroge l'API K-Pay. Pour le tester sans clés — et
sans jamais risquer un transfert réel — un mu-plugin intercepte les appels
vers `admin.kpay.site` et renvoie des réponses conformes à la spec.

```bash
docker compose exec wordpress sh -c \
  'mkdir -p /var/www/html/wp-content/mu-plugins && \
   cp /var/www/html/kpay-tests/mu-kpay-api-mock.php /var/www/html/wp-content/mu-plugins/'
```

Il simule trois wallets (XAF, XOF, KES) et respecte les numéros de test de la
spec pour les retraits. **À ne jamais déployer en production** : pour tester
avec la vraie API, ne l'installez pas et renseignez de vraies clés sandbox.

Pour le retirer : `docker compose exec wordpress rm /var/www/html/wp-content/mu-plugins/mu-kpay-api-mock.php`

## 3. Retraits en HTTP réel

```bash
# Prérequis : simulateur installé, secret webhook configuré
./integration/payout-live-test.sh
```

Vérifie les numéros virtuels de la spec (`237653456789` réussit,
`237653456089` échoue côté opérateur) et chaque garde-fou : confirmation
obligatoire, numéro invalide, montant sous le minimum, montant supérieur au
solde, opérateur invalide, visiteur non connecté.

## 4. Webhook attaqué en HTTP réel

Requêtes réelles contre le serveur, sans simulation.

```bash
# Configurer le secret webhook « whsec_integration_test » dans les réglages,
# puis créer une commande en attente et lancer :
./integration/webhook-live-test.sh <order_id> <payment_id>
```

Vérifie que sont rejetés : absence de signature, signature forgée, mauvais
secret, corps modifié, JSON malformé, commande inconnue, transaction d'une
autre commande — et qu'une notification correctement signée passe.

## Ce qui n'est pas couvert

- **L'API K-Pay réelle** n'est jamais appelée : les réponses sont simulées.
  Le parcours complet doit être validé en sandbox avec de vraies clés et le
  numéro `237653456789` (force un `COMPLETED`).
- **Le webhook en conditions réelles** suppose que K-Pay puisse joindre le
  site : impossible sur `localhost` sans tunnel (ngrok ou équivalent).
- **Thèmes tiers** : le rendu a été vérifié sur le thème par défaut
  (Twenty Twenty-Five), en checkout classique et en checkout blocs.

## Vider le cache après modification d'un asset

En développement (`WP_DEBUG` à `true`), la version des scripts inclut la date
de modification du fichier : le navigateur recharge automatiquement. En
production, incrémenter `WC_KPAY_VERSION` dans `wc-kpay-gateway.php`.
