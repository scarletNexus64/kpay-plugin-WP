# Publier une version

Trois canaux de distribution, dans cet ordre de priorité :
GitHub (source), wordpress.org (grand public), serveur privé (archive directe).

---

## 1. Préparer la version

La version apparaît à trois endroits qui doivent rester identiques —
`build.sh` refuse de produire une archive si l'un d'eux diverge :

| Fichier | Ligne |
|---|---|
| `wc-kpay-gateway/wc-kpay-gateway.php` | `* Version:` |
| `wc-kpay-gateway/wc-kpay-gateway.php` | `define( 'WC_KPAY_VERSION', … )` |
| `wc-kpay-gateway/readme.txt` | `Stable tag:` |

Ajoutez l'entrée correspondante sous `== Changelog ==` dans `readme.txt`.

Puis :

```bash
./build.sh
```

Le script vérifie la syntaxe PHP, exécute les tests et écrit
`wc-kpay-gateway-<version>.zip` à la racine.

---

## 2. GitHub

```bash
git add -A
git commit -m "Release 2.1.0"
git tag v2.1.0
git push origin main --tags
```

Créez ensuite la release et attachez l'archive :

```bash
gh release create v2.1.0 wc-kpay-gateway-2.1.0.zip \
  --title "2.1.0" \
  --notes "Voir le changelog dans readme.txt"
```

L'archive attachée à la release est le fichier que les utilisateurs
téléversent dans WordPress. Le ZIP produit par le bouton « Download source
code » de GitHub ne convient pas : il contient `tests/` et un dossier racine
au mauvais nom.

---

## 3. wordpress.org

### Première soumission

À faire une seule fois, sur <https://wordpress.org/plugins/developers/add/>.

Avant d'envoyer :

- Installez le [Plugin Check](https://wordpress.org/plugins/plugin-check/) sur
  un WordPress local et lancez-le sur le plugin. Le formulaire demande de
  confirmer que c'est fait ; l'équipe de revue exécute le même outil.
- Vérifiez que `Contributors:` dans `readme.txt` correspond bien à votre
  identifiant wordpress.org (pas votre email).

Le nom retenu par le répertoire est celui de l'en-tête `Plugin Name`, et il
détermine l'URL définitive. Il ne pourra plus être changé après approbation.

Délai annoncé : 1 à 10 jours ouvrés.

### Mises à jour suivantes

Une fois le plugin approuvé, wordpress.org fournit une URL SVN. Git n'est pas
utilisé de ce côté.

```bash
svn co https://plugins.svn.wordpress.org/VOTRE-SLUG kpay-svn
cd kpay-svn

rsync -a --delete ../wc-kpay-gateway/ trunk/

svn cp trunk tags/2.1.0
svn add --force .
svn ci -m "Release 2.1.0"
```

C'est le `Stable tag:` du `readme.txt` de `trunk/` qui décide quelle version
est servie aux utilisateurs — pas le dernier tag créé. Une version publiée
dans `tags/` sans mise à jour du `Stable tag` reste invisible.

Les captures d'écran et l'icône vont dans un dossier `assets/` à la racine du
dépôt SVN, à côté de `trunk/` — jamais dans le plugin lui-même.

---

## 4. Serveur privé

Pour distribuer hors répertoire officiel, publiez l'archive à une URL stable :

```bash
scp wc-kpay-gateway-2.1.0.zip user@serveur:/var/www/downloads/
```

Les clients installent via **Extensions → Ajouter → Téléverser une extension**.

Les mises à jour automatiques ne fonctionnent pas par ce canal : WordPress ne
sait pas où chercher. Pour les activer, il faut embarquer un vérificateur de
mise à jour comme [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker),
pointé sur une URL servant les métadonnées de version.

À noter : un plugin publié sur wordpress.org ne doit pas embarquer de
mécanisme de mise à jour concurrent. Réservez cette approche à une
distribution exclusivement privée.
