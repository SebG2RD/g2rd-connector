# Découverte des mises à jour sans visite d'administration — design

**Date** : 2026-07-31
**Statut** : validé, à implémenter
**Version cible** : connecteur 1.11.0
**Antécédent** : [2026-07-29-premium-plugin-update-detection-design.md](2026-07-29-premium-plugin-update-detection-design.md)
(livré en 1.10.0 — insuffisant, voir § 2)

## 1. L'exigence

Le manager doit connaître les mises à jour disponibles d'un site **sans que personne n'ouvre son
wp-admin**. C'est la raison d'être du produit : administrer un parc depuis une console, sans se
rendre sur les sites. Une détection qui dépend d'une visite humaine ne remplit pas le contrat.

## 2. Pourquoi 1.10.0 ne suffit pas

Le pont livré en 1.10.0 avait deux sources : la **capture** depuis un écran d'administration, et la
**mémorisation** du transient juste avant sa destruction par le snapshot. Mesuré le 2026-07-31 sur
le parc : **12 sites sur 15 ne voient pas SEOPress PRO 10.1**, dont seuls ceux que je visite
(g2rd.fr, reconversion.pompiersparis.fr, lesjardinsbelavista.fr) sont à jour.

### 2.1 La mémorisation ne mémorise rien

`SnapshotController::handle()` appelle, dans cet ordre :

```php
wp_clean_themes_cache();      // signature WP : ( $clear_update_cache = true )
wp_clean_plugins_cache();     //  → delete_site_transient( 'update_plugins' )
…
PremiumUpdatesBridge::refresh_update_transients();   // « mémorisation AVANT destruction »
```

Les deux fonctions du cœur de WordPress **suppriment les transients de mise à jour** quand on ne
leur passe pas `false`. Quand le pont lit le transient pour le préserver, il a déjà été effacé deux
lignes plus haut : `$preserved` est **toujours vide** en contexte REST. La mémorisation est
neutralisée depuis le premier jour ; seule la capture admin fonctionnait — d'où le motif observé.

### 2.2 Séquence mesurée (holtz-apiculture.fr, 2026-07-31)

| Étape | Résultat |
| --- | --- |
| Re-vérification forcée en contexte privilégié (WP-CLI) | base : SEOPress PRO **10.1**, avec `package` |
| Appel du snapshot REST juste après | **`version_latest: 10.0.2`, `has_update: false`** |
| État de la base après le snapshot | entrée **détruite** |

Chaque synchronisation horaire du manager efface donc ce que le cron de WordPress avait trouvé.

### 2.3 Le verrou côté SEOPress

```php
$doing_cron = defined( 'DOING_CRON' ) && DOING_CRON;
$doing_cli  = defined( 'WP_CLI' ) && WP_CLI;
if ( ! current_user_can( 'manage_options' ) && ! $doing_cron && ! $doing_cli ) {
    return;   // l'updater ne s'enregistre pas
}
```

Trois contextes ouvrent la porte : administrateur connecté, **WP-Cron**, WP-CLI. La requête REST du
connecteur n'en est aucun. Le contexte **cron** est celui que WordPress utilise lui-même pour les
mises à jour automatiques : tout updater qui prétend les supporter doit y fonctionner. C'est donc
celui qu'on emprunte — sans élévation de privilège.

## 3. Conception

### 3.1 Arrêter de détruire

`wp_clean_themes_cache( false )` et `wp_clean_plugins_cache( false )` dans le snapshot : on ne veut
que le nettoyage du cache d'objets. La destruction puis la reconstruction des transients restent le
travail du pont, juste après. Même revue sur les deux appels de `CommandExecutor`.

Effet immédiat : la mémorisation avant destruction redevient réelle, donc une entrée trouvée par le
cron survit à la synchronisation du manager.

### 3.2 Découvrir en contexte cron

Nouveau job `UpdatesDiscoveryJob` (hook `g2rd_connector_refresh_updates`) :

- **récurrence** `twicedaily`, planifié à l'activation **et auto-réparé au boot** si l'événement
  manque — indispensable : les 12 sites concernés ne rejoueront pas le hook d'activation lors d'une
  simple mise à jour du plugin ;
- **enregistré inconditionnellement** après le gate d'enrôlement, contrairement au heartbeat qui
  dépend de `heartbeat_enabled` : la découverte des MAJ ne doit pas s'éteindre avec un réglage
  d'émission vers le manager ;
- **callback** : destruction des transients puis `wp_update_plugins()` / `wp_update_themes()`, ce
  qui redéclenche les filtres d'écriture des updaters tiers — actifs ici puisque `DOING_CRON` est
  vrai — puis **capture** du résultat dans le cache 7 jours du pont.

Le rejeu REST existant sert ensuite le manager sans changement.

### 3.3 Réveiller le cron sur un site sans trafic

WP-Cron ne part que sur une requête HTTP. Les sites clients à faible trafic peuvent rester muets.
Le snapshot, appelé chaque heure par le manager, devient donc lui-même le déclencheur : si la
capture est périmée (> 6 h) ou absente, il planifie l'événement en immédiat et appelle
`spawn_cron()`. Non bloquant, hors du chemin de la réponse : la synchronisation suivante verra des
données fraîches.

### 3.4 Rendre l'invisible visible

Le snapshot expose un bloc `updates_discovery` :

| Champ | Sens |
| --- | --- |
| `captured_at` | date ISO de la dernière capture, `null` si aucune |
| `stale` | `true` si la capture a plus de 6 h ou n'existe pas |
| `cron_disabled` | `true` si `DISABLE_WP_CRON` est actif sur le site |
| `next_run` | prochaine exécution planifiée de l'événement, `null` si absent |

Sans ce bloc, un site dont le cron est coupé continuerait d'annoncer « tout est à jour » — le
mensonge silencieux qu'on vient de corriger côté audit SEO. Le manager pourra le signaler ;
l'affichage est un chantier distinct.

### 3.5 Ce qui ne change pas

Les trois sources du pont cohabitent, par ordre de fraîcheur : mémorisation avant destruction
(réparée), capture cron (nouvelle), capture admin (inchangée, utile quand on passe sur un site).
Le rejeu reste un filtre de **lecture** : aucune réécriture du transient, donc aucun appel réseau
supplémentaire déclenché chez les updaters déjà présents.

## 4. Fichiers touchés

- `includes/Rest/SnapshotController.php` — `false` aux deux cleaners, bloc `updates_discovery`,
  réveil du cron si périmé
- `includes/Commands/CommandExecutor.php` — `false` aux deux cleaners
- `includes/Updates/PremiumUpdatesBridge.php` — capture statique, `capture_is_stale()`,
  `refresh_in_privileged_context()`, `next_scheduled_run()`
- `includes/Cron/UpdatesDiscoveryJob.php` — **nouveau**
- `includes/Plugin.php` — enregistrement + auto-réparation de la planification, `unschedule()` à la
  désactivation
- `g2rd-connector.php`, `readme.txt`, `CHANGELOG.md` — version 1.11.0

## 5. Vérification

Sur **holtz-apiculture.fr**, après déploiement du plugin :

1. `wp cron event run g2rd_connector_refresh_updates` ;
2. snapshot REST → attendu `version_latest: 10.1`, `has_update: true` ;
3. deux snapshots supplémentaires **avec le transient absent de la base** → toujours
   `has_update: true`, servi par le cache de capture ;
4. campagne de mise à jour depuis le manager → SEOPress PRO passe en 10.1 (le `package` EDD est
   présent dans le cache, vérifié le 2026-07-31).

Le critère 3 a été reformulé après mesure. J'avais d'abord écrit « l'entrée survit en base au
snapshot » : c'est faux et ce n'était pas le bon critère. Le pont **détruit délibérément** le
transient pour forcer une vraie re-vérification (sans quoi `wp_update_plugins()` sort dans sa
fenêtre de 12 h), et il ne le réécrit jamais — le réécrire redéclencherait les updaters déjà
présents et leurs appels réseau à chaque synchronisation. La source durable est le cache 7 jours,
pas la base WordPress. C'est donc la persistance de la détection **à travers plusieurs
synchronisations** qu'il faut vérifier, et elle l'est.

### Résultats mesurés (holtz-apiculture.fr, 2026-07-31)

| Vérification | Résultat |
| --- | --- |
| Planification auto de l'événement au boot | ✅ `2026-08-01T01:06:18+00:00` |
| Job exécuté en contexte cron | ✅ 1,18 s, capture écrite |
| Contenu de la capture | ✅ `wp-seopress-pro → 10.1`, `package` présent |
| Snapshot REST | ✅ `version_installed: 10.0.2`, `version_latest: 10.1`, `has_update: true` |
| Snapshots suivants, base vide | ✅ `has_update: true` (cache), cache intact |
| Bloc `updates_discovery` | ✅ `captured_at`, `stale: false`, `cron_disabled: false`, `next_run` |

## 6. Risques

| Risque | Traitement |
| --- | --- |
| `DISABLE_WP_CRON` sans cron système → aucune découverte | remonté par `cron_disabled`, plus jamais silencieux |
| Appels réseau vers les API vendeurs 2×/jour par site | c'est le rythme de WordPress lui-même (`wp_version_check`) |
| Un updater tiers refusant aussi le contexte cron | non observé ; détectable via `captured_at` ; repli possible = élévation de la requête REST, écartée ici (donner une identité d'administrateur à chaque appel du manager) |
| `spawn_cron()` appelé trop souvent | borné par la fenêtre de péremption de 6 h |
