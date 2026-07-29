# Détection des MAJ tierces — Plan d'implémentation (2026-07-29)

> Plan d'exécution du spec
> [docs/superpowers/specs/2026-07-29-premium-plugin-update-detection-design.md](../specs/2026-07-29-premium-plugin-update-detection-design.md).
> Chaque phase se termine par `composer run ci` (PHPCS WordPress-Core + PHPStan
> level 6) puis un commit Conventional Commit français. La phase C ajoute
> `npm run lint:js` + `npm run build`.

## Conventions à respecter (rappel)

- PHP 8.1+ (`declare(strict_types=1)`), PSR-4 sous `G2RD\Connector\`, fichiers en
  PascalCase.
- PHPCS **WordPress-Core** (exclusions de `phpcs.xml.dist`), PHPStan **level 6** +
  `phpstan-wordpress`.
- Échappement obligatoire de toute sortie (`esc_html`, `esc_attr`).
- Chaînes d'interface via `__()` / `esc_html__()`, domaine `g2rd-connector`.
- Commits français : `ajout/corrige/refacto/doc(scope): sujet`. **Jamais** de
  trailer `Co-Authored-By`. **Jamais** `version:` en préfixe de sujet hors
  release (déclenche `auto-tag`).
- L'IDE signale des `Undefined function 'G2RD\Connector\...\add_action'` sur tous
  les fichiers du plugin : c'est le serveur de langage sans les stubs WordPress,
  pas une erreur. Les gates sont PHPCS et PHPStan.

---

## Phase A — `PremiumUpdatesBridge` ✅ faite

`includes/Updates/PremiumUpdatesBridge.php`, namespace `G2RD\Connector\Updates`.

| Membre | Rôle |
| --- | --- |
| `register()` | Branche la capture sur `current_screen` → `admin_footer` (écrans `plugins`, `update-core`, `dashboard`) |
| `capture()` | Copie les entrées de MAJ dans `g2rd_updates_snapshot` (TTL 7 j, liste blanche de clés) |
| `last_capture()` | Métadonnées pour la ligne de diagnostic (phase C) |
| `refresh_update_transients()` | Mémorise → détruit → reconstruit → installe le rejeu |
| `replay_plugins()` / `replay_themes()` | Filtres de **lecture** qui complètent la valeur lue |
| `installed_plugin_version()` / `installed_theme_version()` | Lecture de version sur disque, source unique |

Points de conception à ne pas perdre en relecture :

- Le garde `version_compare` est appliqué **à la construction** de la liste de
  rejeu, pas dans le filtre : le filtre peut être appelé de nombreuses fois par
  requête et le garde implique une lecture disque par entrée.
- Le rejeu se fait par **filtre de lecture**, jamais par réécriture du transient.
  Cf. spec §Conception pour les trois raisons.
- TTL via une méthode privée, pas une constante de classe : `DAY_IN_SECONDS` est
  défini à l'exécution par WordPress.
- Tout est best-effort (`try/catch \Throwable`), repli sur le comportement
  historique.

**Refacto incluse** : `SnapshotController::fresh_plugin_version()` /
`fresh_theme_version()` délèguent aux utilitaires du bridge — plus de troisième
copie de la lecture disque.

Commit : `ajout(updates): passerelle de detection des MAJ tierces`.

---

## Phase B — Branchement des appelants ✅ faite

| Fichier | Changement |
| --- | --- |
| `includes/Plugin.php` `boot()` | `( new PremiumUpdatesBridge() )->register();` après le gate `is_enrolled()`, sous `if ( is_admin() )` |
| `includes/Rest/SnapshotController.php` `handle()` | `delete_site_transient( 'update_core' )` + `wp_version_check( [], true )` conservés ; les deux autres `delete` et les `wp_update_*` → `refresh_update_transients()`. Pavé de commentaire complété |
| `includes/Commands/CommandExecutor.php` `update_plugin()` | → `refresh_update_transients()` |
| `includes/Commands/CommandExecutor.php` `update_theme()` | idem |
| `includes/Commands/CommandExecutor.php` `check_updates()` | `wp_version_check()` conservé, les deux `wp_update_*` → bridge |
| `includes/Commands/CommandExecutor.php` `clear_cache()` | purge + repeuplement via le bridge ; `g2rd_updates_snapshot` **non** purgé |
| `includes/Commands/CommandExecutor.php` (commentaire `version_unchanged`) | ne plus attribuer la cause à la seule licence premium |

Contrôle de fin exécuté :
`grep -rn "delete_site_transient\|wp_update_plugins\|wp_update_themes" includes/`
ne laisse que les `update_core` (intentionnels) et les deux `wp_update_*` de
`update_translations()` — sans `delete` préalable, donc sans perte d'entrées.

Commit : `corrige(updates): detecte les MAJ des updaters tiers absents du contexte REST`.

---

## Phase C — Ligne de diagnostic (deux interfaces)

**But** : savoir d'un coup d'œil si la capture tourne. Le panneau React est
l'écran nominal sur les sites au thème G2RD (dont g2rd.fr) : les deux UI doivent
l'afficher, sinon la ligne serait invisible là où on en a besoin.

| Fichier | Changement |
| --- | --- |
| `includes/Admin/Page.php` `initial_data()` | Clé `lastUpdatesCapture` alimentée par `PremiumUpdatesBridge::last_capture()` (`null` si jamais capturé) |
| `includes/Admin/Page.php` `render_standalone_page()` | Une ligne dans le bloc `notice notice-success` « Site enrôlé ». Échappement `esc_html` |
| `assets/admin/src/ConnectorPanel.tsx` | Même information dans la zone d'état, depuis `window.G2RDConnectorData.lastUpdatesCapture`. Typer le champ dans l'interface existante |

Libellé : « Dernière capture des MAJ tierces : \<date locale\> (\<n\> entrée·s) »,
ou « jamais » si `null`. Date formatée avec `wp_date()` (fuseau du site).

### Vérification

- `composer run ci` vert.
- `npm run lint:js` puis `npm run build` verts, `assets/admin/build/` commité
  (c'est le bundle servi en production).
- Commit : `ajout(admin): ligne de diagnostic des captures de MAJ tierces`.

---

## Phase D — Release 1.10.0 et validation en production

### Bump de version (4 emplacements, à garder synchrones)

| Fichier | Ligne |
| --- | --- |
| `g2rd-connector.php` | en-tête `Version:` |
| `g2rd-connector.php` | `define( 'G2RD_CONNECTOR_VERSION', … )` |
| `package.json` | `"version"` |
| `readme.txt` | `Stable tag` + entrée `= 1.10.0 =` dans `== Changelog ==` (en anglais, comme les entrées existantes) |

Changelog à rédiger autour de deux points : détection des MAJ annoncées par des
updaters tiers qui ne s'enregistrent pas dans le contexte REST, et application
effective de ces MAJ depuis le manager.

### Release

Via la skill `production` (bump + checks locaux + push pour CI, puis commit
`version:` une fois la CI verte).

### Validation sur g2rd.fr

1. Déployer, puis charger une fois **wp-admin → Extensions** (amorce la capture).
2. Vérifier la ligne de diagnostic : date récente, nombre d'entrées non nul.
3. Rejouer la mesure qui avait révélé le bug :

   ```sh
   cd ~/domains/g2rd.fr/public_html
   TOKEN=$(wp eval 'echo G2RD\Connector\Settings::site_token();')
   curl -s -H "Authorization: Bearer $TOKEN" https://g2rd.fr/wp-json/g2rd/v1/snapshot \
     | php -r '$d=json_decode(file_get_contents("php://stdin"),true); foreach(($d["plugins"]??[]) as $p){ if(str_contains($p["file"],"seopress")) print_r($p);} '
   ```

   Attendu : `has_update => 1` et `version_latest => 10.1` pour
   `wp-seopress-pro/seopress-pro.php`.
4. Synchroniser depuis le manager → SEOPress PRO en `10.0.2 → 10.1`.
5. Lancer la MAJ depuis le manager → la version passe réellement à 10.1.
6. Resynchroniser → l'entrée disparaît, aucune MAJ fantôme.
7. Non-régression : MAJ wp.org et cœur toujours détectées ; Elementor Pro et
   Astra Pro toujours détectés ; thème custom G2RD toujours détecté.

### Suites

- Mettre à jour `MEMORY.md` : ce chantier recouvre partiellement
  `theme-update-detection-bug` et `inventory-refresh-phantom-updates`.
- Reste connu hors périmètre, consigné dans le spec : le badge « WPORG » affiché
  pour les plugins premium (`RemoteSnapshotPlugin::fromPayload()`, manager).
