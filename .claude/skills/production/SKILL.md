---
name: production
description: Prépare une release de production pour le plugin G2RD Connector en deux phases. Phase 1 — bump version, checks locaux (PHPCS + PHPStan + build) et push pour validation CI. Phase 2 — déclenche la release via un commit `version:` une fois la CI verte.
metadata:
  author: G2RD
  version: "1.0.0"
  domain: release
  triggers: /production
  role: release-manager
  scope: automation
---

# Production Release — G2RD Connector (2 phases)

> Plugin distribué via **GitHub Releases** (pas wp.org). Le self-update est assuré
> par `includes/Updater/GitHubUpdater.php` qui interroge
> `api.github.com/repos/SebG2RD/g2rd-connector/releases/latest`.
>
> ⚠️ **Pré-requis pour que les sites voient la MAJ** : la Release doit être
> **accessible publiquement par l'updater** (le repo doit être public, OU
> l'updater doit envoyer un token — voir « Règles impératives » §repo privé).
> Une release publiée sur un repo privé sans token ne déclenchera **aucune**
> notification de MAJ. L'étape 2.4 vérifie ce point.

## Détection de phase au démarrage

Avant toute action, exécuter :

```bash
git log --oneline -5
```

- Si le dernier commit commence par `chore: prépare release` **sans** commit `version:` depuis → aller directement à la **Phase 2**
- Sinon → commencer par la **Phase 1**

---

## Phase 1 — Préparation et validation CI

### Étape 1.1 — Déterminer la nouvelle version

1. Lire la version actuelle dans `g2rd-connector.php` (ligne d'en-tête `* Version: X.Y.Z`)
2. Si une version est passée en argument (ex. `/production 0.2.0`), l'utiliser directement
3. Sinon, proposer un **patch** automatique (+0.0.1) et attendre confirmation
4. Rappeler les conventions sémantiques :
   - `patch` (+0.0.1) : bug fix, petite amélioration
   - `minor` (+0.1.0) : nouvelle fonctionnalité rétro-compatible
   - `major` (+1.0.0) : changement majeur ou rupture de compatibilité

**Règle absolue du projet : jamais de version sans confirmation explicite.**

### Étape 1.2 — Collecter les changements

1. Exécuter `git log --oneline` depuis le dernier commit `version:` pour lister les commits
2. Proposer un résumé du changelog basé sur ces commits
3. Demander validation du changelog avant de continuer

### Étape 1.3 — Mettre à jour les fichiers de version

Mettre à jour la version dans **les 4 sources de vérité** :

- `g2rd-connector.php` — en-tête `* Version:`
- `g2rd-connector.php` — constante `define( 'G2RD_CONNECTOR_VERSION', 'X.Y.Z' )`
- `readme.txt` — ligne `Stable tag:`
- `package.json` — champ `"version"`

Vérifier l'alignement (script CI identique, lancé localement) :

```bash
bash tools/verify-release-version.sh "X.Y.Z"
```

Toutes les lignes doivent être `OK`. Ne pas continuer tant qu'une source n'est pas alignée.

### Étape 1.4 — Mettre à jour le changelog (readme.txt)

Insérer une nouvelle entrée **juste après** la ligne `== Changelog ==`, au format WordPress
existant (anglais, puces `*`, catégorie en gras). Ne PAS inclure de date :

```text
= X.Y.Z =

* **Feature** : concise description.
* **Fix** : concise description of the fix.
```

### Étape 1.5 — PHPCS + PHPStan locaux (obligatoire)

```bash
composer run lint        # PHPCS WordPress
composer run analyse     # PHPStan --memory-limit=1G
```

(ou `composer run ci` qui enchaîne les deux)

**Corriger TOUTES les erreurs avant de continuer. Ne jamais skiper cette étape.**

### Étape 1.6 — Build du bloc admin

```bash
npm run build
```

Vérifie que `wp-scripts build` passe sans erreur (sortie dans `assets/admin/build`).

> Pas de ZIP local : le ZIP wp.org (dossier racine `g2rd-connector/`), sa validation
> (`tools/verify-plugin-zip.sh`) et le **test d'activation réelle dans WordPress (WP-CLI)**
> sont construits et exécutés par `release.yml` en Phase 2. Inutile de les refaire ici.

### Étape 1.7 — Commit de préparation

Stager uniquement les fichiers modifiés (jamais `node_modules/`, `vendor/`, `.claude/`) :

```bash
git add g2rd-connector.php readme.txt package.json assets/admin/build/
```

Message — préfixe `chore:` **intentionnel** pour ne **PAS** déclencher `auto-tag.yml`
(qui ne réagit qu'à un sujet commençant par `version:`) :

```text
chore: prépare release X.Y.Z — <résumé en 5-10 mots>
```

Puis pousser :

```bash
git push origin main
```

### ⏸ PAUSE — Attendre la validation CI

Afficher ce message à l'utilisateur :

> **Phase 1 terminée.** Le code est poussé sur `main`.
>
> Vérifier que les workflows CI sont verts sur GitHub Actions :
>
> - `ci.yml` (**CI**) — PHPCS + PHPStan
> - `phpcs-security.yml` (**PHPCS WordPress + Security Audit**)
> - `sbom.yml` — génération SBOM
>
> Une fois la CI verte, revenir ici et invoquer `/production` à nouveau (ou confirmer).
> Le commit `version:` qui déclenche la release ne sera créé qu'après validation.

**Ne pas continuer avant confirmation explicite de l'utilisateur.**

---

## Phase 2 — Déclenchement de la release (après CI ✅)

> Atteinte automatiquement si un commit `chore: prépare release` existe sans `version:` depuis.

### Étape 2.1 — Vérification finale

Confirmer que la CI est verte. Si l'utilisateur n'a pas vérifié, lui rappeler :

> Confirmes-tu que les workflows GitHub Actions sont verts ?

### Étape 2.2 — Commit `version:` (vide, déclenche auto-tag.yml)

Le bump et le code sont déjà poussés et validés (Phase 1). On crée un commit **vide**
dont le **sujet** déclenche le tag :

```bash
git commit --allow-empty -m "version: X.Y.Z — <même résumé que la Phase 1>"
git push origin main
```

⚠️ **CRITIQUE** :
- `auto-tag.yml` teste `startsWith(head_commit.message, 'version:')` → ce commit doit être le **dernier** poussé.
- Il ré-exécute `tools/verify-release-version.sh` : les 4 fichiers doivent déjà porter `X.Y.Z` (fait en Phase 1).

### Étape 2.3 — Suivi de la chaîne

`auto-tag.yml` extrait `X.Y.Z` du sujet, crée le tag `vX.Y.Z` (poussé via le PAT `GH_PAT`,
pas le `GITHUB_TOKEN`, sinon `release.yml` ne se déclencherait pas), ce qui enchaîne :

```text
release.yml → verify-release-version → composer/npm audit → build → ZIP g2rd-connector/
            → verify-plugin-zip → test activation WP-CLI → GitHub Release → webhook g2rd.fr
```

Informer l'utilisateur :

> **Release X.Y.Z déclenchée.** Tag `vX.Y.Z` en cours de création sur GitHub.
> Suivre `release.yml` dans Actions, puis vérifier la Release publiée.

### Étape 2.4 — Vérifier que l'updater verra la release

Une fois `release.yml` terminé, confirmer que la Release est **accessible par l'updater**
(c'est ce que `GitHubUpdater::fetch_latest_release()` fait, sans token) :

```bash
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Accept: application/vnd.github.v3+json" \
  -H "User-Agent: WordPress/G2RD-Connector-Updater" \
  "https://api.github.com/repos/SebG2RD/g2rd-connector/releases/latest"
```

- `200` → la release est visible, les sites recevront la notification de MAJ. ✅
- `404` → **repo privé ou release inaccessible** : l'updater recevra ce même 404 et
  **aucune notification n'apparaîtra**. Voir « Règles impératives » §repo privé.

---

## Règles impératives

- **Jamais de version sans confirmation** — règle absolue du projet.
- **Jamais de commit `version:` sans CI verte** — règle centrale de ce skill.
- PHPCS + PHPStan locaux obligatoires en Phase 1, avant tout push.
- Le commit `version:` est toujours **vide** — le bump et le code sont dans le commit `chore:` précédent.
- Le ZIP, sa validation et le test d'activation WP-CLI sont faits en CI (`release.yml`), pas en local.
- Signaler clairement si PHPCS, PHPStan ou le build échouent.
- **§repo privé** — Le self-update ne fonctionne que si l'updater peut lire
  `releases/latest`. Si le repo `g2rd-connector` est **privé**, l'updater
  (sans token) reçoit 404 et ne propose jamais de MAJ. Deux options :
  1. **Rendre le repo public** (comme `g2rd-theme`) — l'updater marche tel quel.
  2. **Garder le repo privé** — il faut alors distribuer les releases autrement
     (token côté serveur de licence g2rd.fr + URL de download signée), car
     embarquer un PAT dans le plugin livré à chaque site est à proscrire.
  Tant que ce point n'est pas réglé, publier une release ne rendra PAS la MAJ
  visible sur les sites (l'étape 2.4 le détectera).
