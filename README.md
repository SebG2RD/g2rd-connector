# G2RD Connector

> Plugin WordPress qui relie un site à la console centralisée **[G2RD WP Manager](https://wp-manager.g2rd.fr)**.

![WordPress ≥ 6.4](https://img.shields.io/badge/WordPress-%E2%89%A56.4-blue)
![PHP ≥ 8.1](https://img.shields.io/badge/PHP-%E2%89%A58.1-777BB4)
![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green)

## Ce que fait le plugin

| Capacité | Comment |
| --- | --- |
| **Inventaire WP** | Expose `GET /wp-json/g2rd/v1/snapshot` (Bearer auth) — version WP, plugins installés/actifs/à jour, thèmes, serveur. Consommé par le manager pour la page Site détaillé. |
| **Heartbeat** | WP-Cron horaire `POST {manager}/api/sites/{id}/heartbeat` — métriques légères (espace disque, utilisateurs, plugins actifs). |
| **Webhook events** | Push temps réel au manager : `user.login`, `user.login_failed`, `plugin.activated/deactivated`, `core.updated`, `core.auto_update`. |
| **Commandes distantes** | `POST /wp-json/g2rd/v1/command` (Bearer auth) — `clear_cache`, `check_updates`, `update_core` déclenchables depuis le manager. |
| **Intégration thème** | Si le thème [`g2rd-theme`](https://github.com/SebG2RD/g2rd-theme) ≥ v1.19 est actif, le plugin s'enregistre comme **onglet dans Apparence → Options G2RD**. Sinon, menu top-level autonome. |

## Architecture

```text
g2rd-connector/
├── g2rd-connector.php          Plugin header + bootstrap
├── includes/
│   ├── autoload.php            PSR-4 minimaliste
│   ├── Plugin.php              Boot class (singleton)
│   ├── Settings.php            wp_options ‘g2rd_connector_settings’
│   ├── Rest/
│   │   ├── Auth.php            Permission callback Bearer SiteToken
│   │   ├── SnapshotController  /snapshot
│   │   ├── HealthController    /health (public)
│   │   └── CommandController   /command
│   ├── Outbound/
│   │   └── ManagerClient       enrollment, heartbeat, send_event
│   ├── Cron/
│   │   └── HeartbeatJob        hourly wp_schedule_event
│   ├── Events/
│   │   └── Listener            wp_login, activated_plugin, etc.
│   └── Admin/
│       └── Page                tab thème OU menu top-level
└── assets/admin/src/           React app (page admin)
```

## Installation

### Depuis GitHub Release

```bash
wp plugin install https://github.com/SebG2RD/g2rd-connector/releases/latest/download/g2rd-connector.zip --activate
```

### Depuis le source

```bash
git clone https://github.com/SebG2RD/g2rd-connector.git wp-content/plugins/g2rd-connector
cd wp-content/plugins/g2rd-connector
composer install --no-dev
npm ci && npm run build
wp plugin activate g2rd-connector
```

## Enrollment d'un site

1. **Côté manager** (https://wp-manager.g2rd.fr) : Sites → *Inviter un site* → copier le **token d'invitation**.
2. **Côté WordPress** : Réglages → G2RD Connector (ou Apparence → Options G2RD → onglet *Manager G2RD*).
3. Coller le token, cliquer **Enrôler le site**. Le plugin POST `/api/sites/enroll`, reçoit `{ site_id, site_token }` et persiste.
4. À partir de là : sync, heartbeat, events, commandes — tout transite via le SiteToken (révoquable à tout moment côté manager).

## Routes REST exposées

| Méthode | Path | Auth | Description |
| --- | --- | --- | --- |
| GET | `/wp-json/g2rd/v1/health` | none | Ping public |
| GET | `/wp-json/g2rd/v1/snapshot` | Bearer SiteToken (+ signature) | Inventaire complet |
| POST | `/wp-json/g2rd/v1/command` | Bearer SiteToken (+ signature) | Exécute commande |

### Signature des requêtes du manager

En plus du Bearer, le manager signe chaque requête (HMAC-SHA256, clé dérivée du SiteToken,
horodatage ±300 s, nonce anti-rejeu) : en-têtes `X-G2RD-Timestamp`, `X-G2RD-Nonce`,
`X-G2RD-Signature: v1=…`. C'est la **route REST** (`/g2rd/v1/command`) qui est signée, pas le
chemin de l'URL — un site en sous-dossier ou en permaliens simples (`?rest_route=`) reste valide.

Politique, réglable dans la page d'administration (« Exiger des commandes signées ») :

- `report` (défaut) : la signature est vérifiée et son résultat remonté au manager (champ
  `signature_check`, en-tête `X-G2RD-Signature-Check`), mais une requête non signée reste acceptée ;
- `required` : toute requête non signée ou mal signée est refusée (401).

Les commandes `rollback_plugin`, `delete_restore_point` et `set_signature_policy` exigent une
signature valide quelle que soit la politique.

### Rollback des extensions (points de restauration)

Quand le manager envoie `update_plugin` avec `snapshot: true`, le plugin enchaîne, dans la même
requête : référence de santé (loopback accueil + `admin-ajax.php?action=g2rd_health`), zip du
dossier de l'extension dans `wp-content/g2rd-snapshots/`, mise à jour, contrôle de santé, et
**rollback automatique** si le site a régressé (HTTP ≥ 500, écran blanc, erreur fatale). Un site
dont le loopback est impossible donne « non vérifiable », jamais « cassé ».

- Les points portent un nom aléatoire et le dossier est protégé (`index.php`, `.htaccess`,
  `web.config`). Sous **nginx**, `.htaccess` est ignoré : ajoutez à la configuration du site
  `location ~* /wp-content/g2rd-snapshots/ { deny all; return 404; }`.
- Rétention : un point par extension, purgé après le délai de grâce fixé par le manager (72 h par
  défaut) ou immédiatement sur les plans sans délai ; budget disque global (300 Mo par défaut) ;
  un point retenu après un échec est supprimé au plus tard après 7 jours ; jamais plus de 3 points
  par extension. La purge est un cron WordPress local, elle fonctionne hors connexion au manager.
- Après un rollback, la version retirée est bloquée pour les mises à jour automatiques de
  WordPress jusqu'à la version suivante.
- Le plugin ne se rollback jamais lui-même. La capacité `restore_points` n'est annoncée dans
  l'inventaire que si le système de fichiers est en accès direct et qu'une bibliothèque zip existe.
- Limite connue : WordPress peut envoyer son e-mail « erreur critique » avant le rollback
  automatique (au plus une fois par jour).

## Développement

```bash
composer install
composer run lint         # PHPCS WordPress Standards (+ sniffs Security, comme Plugin Check)
composer run analyse      # PHPStan level 6
composer run test         # PHPUnit + Brain Monkey (sans WordPress)
composer run ci           # les trois
npm ci && npm run start   # @wordpress/scripts en watch mode
```

Une version `X.Y.Z-rc.N` (commit `version: X.Y.Z-rc.N`) est publiée en **pré-release** : l'updater
des sites l'ignore, elle s'installe à la main sur les sites pilotes.

## License

GPL-2.0-or-later — voir [LICENSE](LICENSE).
