# Détection des MAJ annoncées par des updaters tiers absents du contexte REST

- **Date** : 2026-07-29
- **Repo** : `g2rd-connector` (aucun changement manager)
- **Version cible** : 1.9.4 → **1.10.0**
- **Statut** : design validé, cause racine vérifiée en production

## Problème

Sur g2rd.fr, wp-admin annonce une mise à jour de **SEOPress PRO** (10.0.2 → 10.1).
Le manager affiche le plugin en 10.0.2 sans MAJ disponible, et cela survit à
autant de synchronisations qu'on veut.

Ce n'est **pas** un problème « premium vs wp.org » : Elementor Pro, Astra Pro et
Discount Rules PRO remontent correctement leurs MAJ dans le manager.

## Cause racine (vérifiée en production, 2026-07-29)

`SnapshotController::handle()` faisait, à chaque snapshot :

```php
delete_site_transient( 'update_plugins' );
wp_update_plugins();
```

puis lisait `get_site_transient( 'update_plugins' )->response`.

Le problème : **certains updaters tiers ne s'enregistrent pas dans le contexte
REST**. Le discriminant n'est pas la nature du plugin mais le contexte dans
lequel son updater accepte de se brancher.

### Mesures

Toutes faites sur g2rd.fr en SSH.

| # | Commande | Résultat |
| --- | --- | --- |
| 1 | Liste des callbacks sur `pre_set_site_transient_update_plugins` (WP-CLI) | `SEOPRESS_Updater::check_update` présent, aux côtés de `G2RD\Connector\Updater\GitHubUpdater`, `FluentCart*`, `FluentCommunity*`, `FluentCampaign*`, `FluentToolkit` |
| 2 | Liste des callbacks sur `pre_site_transient_update_plugins` | **aucun** |
| 3 | `delete_site_transient` + `wp_update_plugins()` + lecture, **en WP-CLI** | `wp-seopress-pro/seopress-pro.php` **présent** |
| 4 | `GET /wp-json/g2rd/v1/snapshot` avec le vrai Bearer | `has_update` **faux**, `version_latest` = 10.0.2 |
| 5 | `wp option get _site_transient_update_plugins` après le snapshot | `response` **vide** |

### Lecture des mesures

- (1) et (2) : SEOPress s'enregistre sur le filtre d'**écriture**, pas de lecture.
  L'entrée est donc bien censée être persistée dans le transient.
- (1) et (3) : ce filtre est actif en **WP-CLI** — et il l'est aussi en
  administration, puisque wp-admin affiche la mise à jour.
- (4) et (5) : la **même séquence** exécutée en REST produit un transient dont
  `response` est vide, et un snapshot à `has_update: false`.

**Conclusion** : le filtre de SEOPress ne tourne pas dans le contexte REST. Le
connecteur détruisait donc une entrée qu'il était incapable de reconstruire.

Comme la synchronisation de fond (`app:sites:sync-all`) tourne toutes les heures,
la fenêtre pendant laquelle l'entrée existe — entre une visite de wp-admin et la
sync suivante — est très étroite. D'où le symptôme : la MAJ reste invisible en
permanence côté manager, et wp-admin la réaffiche à chaque fois qu'on y retourne.

### Second défaut, même cause

`CommandExecutor::update_plugin()` exécutait le même
`delete_site_transient` + `wp_update_plugins()` juste avant
`Plugin_Upgrader::upgrade()`. Or `Plugin_Upgrader` lit lui aussi le transient
pour y trouver l'URL de `package`. Sans entrée : pas de package, l'upgrade
« réussit » sans rien changer, et le connecteur remonte `version_unchanged`.
Conséquence : **même en forçant la MAJ depuis le manager, elle ne s'appliquerait
pas.** Idem `update_theme()` pour les thèmes.

Le commentaire du code attribuait ce `version_unchanged` à « un plugin premium
sans licence » — cause possible mais pas la seule ; il est corrigé.

### Rien à faire côté manager

`SyncService::applyRemoteSnapshot()` ne fait que recopier le `has_update` reçu.
Le correctif est intégralement dans le connecteur.

## Objectif

Que toute MAJ visible dans wp-admin d'un site enrôlé soit visible dans le
manager, et applicable depuis le manager, quel que soit le contexte dans lequel
l'updater d'origine accepte de s'enregistrer.

**Non-objectifs** : interroger les serveurs de licence des éditeurs ; simuler un
contexte administrateur dans la requête REST ; modifier le payload du snapshot,
le manager ou le schéma de base.

## Approches envisagées

### A — Capturer en administration, rejouer hors administration (retenue)

Depuis un écran d'administration, où les updaters tiers sont actifs, copier les
entrées de MAJ dans un cache persistant à nous ; hors administration, les
réinjecter. C'est le pattern de MainWP, qui documente le même problème.

### B — Mémoriser avant destruction (retenue, en complément)

Lire `->response` juste avant `delete_site_transient()` et réutiliser ce qui y
était. Utile mais **insuffisant seul** : ne sauve que si une visite admin a eu
lieu depuis la dernière sync, or le cron horaire referme cette fenêtre en
permanence. Gratuit à ajouter, alimente le même mécanisme de rejeu que A.

### C — Simuler un contexte admin en REST (rejetée)

`wp_set_current_user()` + `do_action( 'admin_init' )`. `admin_init` déclenche
chez les plugins tiers des redirections (`wp_redirect(); exit;` tuerait la
réponse REST), des migrations de base, des appels licence bloquants — impossible
à borner sur un parc client. Et élever la requête au rang d'administrateur
élargit la surface d'attaque d'un endpoint Bearer, contre la politique
zéro-vulnérabilité du projet.

### D — Sonde HTTP loopback vers wp-admin (rejetée)

Nécessiterait une session administrateur (le Bearer n'est pas un utilisateur WP),
donc retombe sur les risques de C, avec en plus une requête HTTP interne souvent
bloquée ou lente en mutualisé.

**Retenu : A + B**, alimentant un mécanisme de rejeu commun.

## Conception

### Composant : `PremiumUpdatesBridge`

`includes/Updates/PremiumUpdatesBridge.php`, namespace `G2RD\Connector\Updates`.

Responsabilité unique : **garantir que les lectures de `update_plugins` /
`update_themes` voient les entrées des updaters tiers, y compris dans les
contextes où ces updaters ne s'enregistrent pas.**

Surface publique réduite à trois points d'entrée (le reste est privé) :

```php
public function register(): void;                          // capture (admin)
public static function refresh_update_transients(): void;  // rafraîchit + installe le rejeu
public static function last_capture(): ?array;             // ligne de diagnostic
```

Plus deux utilitaires de lecture de version sur disque
(`installed_plugin_version()`, `installed_theme_version()`), dont
`SnapshotController` devient consommateur pour éviter une seconde copie.

### Capture

Branchement sur `current_screen`, restreint aux écrans `plugins`, `update-core`
et `dashboard`, puis lecture sur `admin_footer` priorité 99 : la page est alors
rendue, tous les updaters ont eu l'occasion d'injecter leur entrée.

Ces trois écrans sont ceux où WordPress rafraîchit le transient :
`load-plugins.php` et `load-update-core.php` forcent un `wp_update_*()`, et
`_maybe_update_plugins()` (tableau de bord) ré-écrit le transient même sur son
chemin « rien à refaire », ce qui redéclenche les filtres.

Stockage : `set_site_transient( 'g2rd_updates_snapshot', $payload, 7 * DAY_IN_SECONDS )`,
avec une **liste blanche de clés** pour borner la taille et ne rien sérialiser
d'imprévu :

- plugins (objets) : `id`, `slug`, `plugin`, `new_version`, `package`, `url`,
  `tested`, `requires`, `requires_php` ;
- thèmes (tableaux) : `theme`, `new_version`, `package`, `url`, `requires`,
  `requires_php` ;
- plus `captured_at` (ISO 8601 UTC).

On capture toutes les entrées, sans heuristique wp.org : le rejeu ne réinjecte
de toute façon que ce que la lecture courante ignore.

Le cache est écrit **même quand tout est à jour**, pour évacuer les entrées déjà
appliquées et pour que la ligne de diagnostic prouve que la capture tourne.

Enregistrement dans `Plugin::boot()` **après** le gate `is_enrolled()`, sous
`if ( is_admin() )` : aucune écriture sur un site non enrôlé (guideline 7).

### Rafraîchissement et rejeu

`refresh_update_transients()` :

1. Mémorise `->response` des deux transients (volet B).
2. `delete_site_transient()` + `wp_update_plugins()` / `wp_update_themes()`.
   La destruction est **conservée** : c'est elle qui contourne la fenêtre de 12 h
   et force les GitHub Updaters à se ré-évaluer.
3. Construit la liste des entrées à rejouer : mémorisé (prioritaire), puis cache
   de capture. Chaque candidate passe la liste blanche **et** le garde
   `'' !== $installed && version_compare( $new_version, $installed, '>' )`, la
   version installée étant lue sur disque. Ce garde est appliqué **ici**, une
   seule fois, et non dans le filtre — il implique une lecture disque par entrée.
4. Installe deux filtres de **lecture**, `site_transient_update_plugins` et
   `site_transient_update_themes`, qui complètent la valeur lue avec les entrées
   manquantes. La lecture courante fait toujours autorité : on ne comble que ses
   manques.

**Pourquoi un filtre de lecture plutôt qu'une réécriture du transient** — trois
raisons, toutes visibles dans la liste de callbacks mesurée en production :

- une réécriture re-déclencherait `pre_set_site_transient_update_plugins`, donc
  un second passage de `FluentToolkit\Classes\AddonUpdatePusher::maybeRefreshVersions`
  et des trois `Fluent*\PluginUpdater::checkPluginUpdate`, avec leurs appels
  réseau, **à chaque synchronisation** ;
- nos entrées reconstituées ne sont jamais persistées en base, donc aucun risque
  de polluer wp-admin avec une entrée périmée ;
- `Plugin_Upgrader::upgrade()` et `Theme_Upgrader::upgrade()` lisent eux aussi
  via `get_site_transient()` : le `package` leur parvient sans code
  supplémentaire, ce qui règle le second défaut sans traitement dédié.

Les filtres restent posés jusqu'à la fin de la requête. Les requêtes REST du
connecteur sont courtes et mono-usage ; un filtre de lecture n'affecte aucune
écriture.

`update_core` n'est pas concerné : le cœur vient toujours de wp.org.

### Points d'appel modifiés

| Emplacement | Changement |
| --- | --- |
| `Plugin.php` `boot()` | `( new PremiumUpdatesBridge() )->register();` après le gate `is_enrolled()`, sous `if ( is_admin() )` |
| `Rest/SnapshotController.php` `handle()` | `delete_site_transient( 'update_core' )` + `wp_version_check( [], true )` conservés ; les deux autres `delete` et les `wp_update_*` → `refresh_update_transients()` |
| `Commands/CommandExecutor.php` `update_plugin()` | → `refresh_update_transients()` : c'est ce qui rend la MAJ applicable |
| `Commands/CommandExecutor.php` `update_theme()` | idem |
| `Commands/CommandExecutor.php` `check_updates()` | `wp_version_check()` conservé ; les deux `wp_update_*` → `refresh_update_transients()` |
| `Commands/CommandExecutor.php` `clear_cache()` | `wp_cache_flush()` et `delete_site_transient( 'update_core' )` conservés ; les deux autres `delete` → `refresh_update_transients()`, qui purge **et** repeuple. Le cache `g2rd_updates_snapshot` n'est **pas** purgé : ce n'est pas un cache de contenu, et le vider ferait perdre la seule source des entrées tierces |
| `Commands/CommandExecutor.php` (commentaire `version_unchanged`) | ne plus attribuer la cause à la seule licence premium |
| `Rest/SnapshotController.php` `fresh_plugin_version()` / `fresh_theme_version()` | délèguent aux utilitaires du bridge (source unique) |

`CommandExecutor::update_translations()` conserve ses `wp_update_*` bruts :
sans `delete` préalable, le chemin « fenêtre 12 h » de WordPress préserve
`response` tel quel, aucune entrée n'est perdue.

### Ligne de diagnostic (UI admin)

Afficher la date de dernière capture et le nombre d'entrées mémorisées, pour
vérifier d'un coup d'œil que la capture tourne. Les **deux** interfaces sont
couvertes, le panneau React étant l'écran nominal sur les sites au thème G2RD
(dont g2rd.fr) :

- **Formulaire PHP** — une ligne dans le bloc `notice notice-success` « Site
  enrôlé » de `Admin/Page.php::render_standalone_page()`.
- **Panneau React** — un champ ajouté à `Admin/Page.php::initial_data()` (donc à
  `window.G2RDConnectorData`), affiché dans `assets/admin/src/ConnectorPanel.tsx`.

Libellé : « Dernière capture des MAJ tierces : \<date\> (\<n\> entrée·s) », ou
« jamais » si le cache est vide. Date formatée avec le fuseau du site
(`wp_date()`). Impose un rebuild des assets admin (`npm run build`).

### Erreurs, sécurité, portée

- Bridge **best-effort intégral** : `try/catch \Throwable` autour de la capture
  et du rafraîchissement, repli silencieux sur le comportement historique. Un
  snapshot ne doit jamais échouer à cause de lui.
- **Aucun appel réseau ajouté** — au contraire, le rejeu par filtre en évite.
- **Aucune élévation de privilège** : ni `wp_set_current_user`, ni
  `do_action( 'admin_init' )`, ni capability supplémentaire.
- **Payload du snapshot inchangé** → aucune release manager, aucune migration.
- Correctif **générique** : couvre tout updater tiers absent du contexte REST,
  sans liste par plugin.

## Vérification

Le connecteur n'a pas de suite PHPUnit — l'outillage est PHPCS + PHPStan
(`composer run ci`). Validation statique puis manuelle :

1. `composer run ci` vert.
2. `npm run lint:js` et `npm run build` verts, bundle `assets/admin/build/` commité.
3. Release et déploiement sur g2rd.fr via la skill `production`.
4. Charger une fois wp-admin → Extensions, pour amorcer le cache de capture.
5. Ligne de diagnostic : date récente, nombre d'entrées non nul.
6. Rejouer la mesure (4) du tableau ci-dessus — l'appel direct au snapshot avec
   le Bearer doit désormais renvoyer `has_update: 1` et
   `version_latest: 10.1` pour `wp-seopress-pro/seopress-pro.php`.
7. Synchroniser depuis le manager → SEOPress PRO en `10.0.2 → 10.1`.
8. Lancer la MAJ depuis le manager → la version passe réellement à 10.1.
9. Resynchroniser → l'entrée disparaît (garde `version_compare`), aucune MAJ
   fantôme.
10. Non-régression : MAJ wp.org et cœur toujours détectées ; Elementor Pro et
    Astra Pro toujours détectés sur les autres sites ; thème custom G2RD
    (GitHub Updater) toujours détecté.

## Reste connu, hors périmètre

`RemoteSnapshotPlugin::fromPayload()` (manager) classe en `WPORG` tout plugin
dont le `file` contient un dossier, puisque le connecteur renvoie
`dirname( $file )` comme slug de repli. Aucun plugin premium ne peut donc être
typé `PREMIUM` : d'où le badge « WPORG » affiché sur SEOPress PRO. Défaut
d'affichage seulement, sans effet sur la détection. Explicitement écarté de ce
lot.

## Journal des hypothèses écartées

Consigné pour éviter de les reformuler plus tard — chacune a été réfutée par une
mesure, dans cet ordre :

1. « Les updaters premium ne s'enregistrent que sur `admin_init` » — réfutée :
   Elementor Pro et Astra Pro remontent bien via REST.
2. « SEOPress s'enregistre en admin uniquement » — réfutée : son filtre est
   actif en WP-CLI (mesure 3).
3. « L'entrée est injectée à la lecture, jamais persistée » — réfutée : le hook
   est `pre_set_site_transient_update_plugins`, un filtre d'écriture (mesure 1),
   et `pre_site_transient_update_plugins` n'a aucun callback (mesure 2).
