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

#### Registre anti-rejeu

Les nonces des signatures valides (route REST et file du cron) sont gardés dans l'option
`g2rd_connector_signature_state`, sans autoload, **tant qu'ils sont rejouables** : deux fenêtres de
±300 s plus une minute (660 s), comptées depuis leur arrivée. Le registre est borné par le temps,
jamais par le volume : aucun nonce encore vivant n'est évincé.

- Garde mémoire : 5 000 nonces vivants (environ 7,5 requêtes signées par seconde pendant 11 min).
  Au-delà, le nouveau nonce est **refusé** plutôt qu'un ancien évincé : code `nonce_store_full`,
  compté dans le diagnostic de signature. En politique `report`, la requête reste acceptée ; en
  `required` ou pour les commandes ci-dessus, elle est refusée (401
  `g2rd_connector_nonce_store_full`, ou `failed` par la file) avec un message explicite.
- La lecture-modification-écriture de l'option se fait sous verrou consultatif MySQL
  (`GET_LOCK`, nom propre à la base et à la table des options, 3 s au plus). Si l'hébergeur ne le
  permet pas, le connecteur fonctionne comme avant, sans verrou.

#### File des commandes (cron)

Le cron horaire tire aussi des commandes de la file du manager
(`GET /api/agent/sites/{site}/commands`). Les trois commandes ci-dessus y obéissent à la **même
règle** que par la route REST : sans enveloppe signée valide, elles ne sont pas exécutées, quelle que
soit la politique, et le site répond `failed` avec un code dans `result.code`
(`g2rd_connector_signature_missing`, `g2rd_connector_signature_invalid`,
`g2rd_connector_signature_replayed`, `g2rd_connector_clock_skew`, `g2rd_connector_signature_error`,
`g2rd_connector_nonce_store_full`)
et un message explicite dans `error`.
L'échec est compté dans le diagnostic de signature (page d'administration, battement de cœur).

Format d'une entrée signée — la preuve voyage dans l'entrée, puisque c'est le site qui tire :

```json
{ "id": 42,
  "signed": { "body": "{\"command\":\"rollback_plugin\",\"payload\":{…}}",
              "timestamp": "1789000000", "nonce": "<32 hex>", "signature": "v1=<64 hex>" } }
```

- même clé et même chaîne canonique v1 que la route REST, avec la méthode `PULL` (jamais une
  méthode HTTP) et la route `/api/agent/sites/{site}/commands/{commande}` : une preuve ne vaut que
  pour CE site et CETTE commande, et ne se rejoue pas d'un canal à l'autre ;
- `body` est la chaîne JSON brute signée ; c'est la seule source de la commande et de son payload
  (un `payload` en clair est ignoré pour ces commandes, un `kind` en clair qui la contredit fait
  refuser l'entrée) ;
- la signature doit être faite à la **remise** de la file, pas à la mise en file : la fenêtre est de
  ±300 s et le cron est horaire. Toutes les entrées d'un passage sont vérifiées au même instant,
  avant toute exécution ;
- pour qu'un connecteur plus ancien ignore l'entrée au lieu de l'exécuter, le manager omet `kind`
  et `payload` en clair (une entrée sans `kind` ni `signed` reste ignorée, comme avant).

Vecteurs de référence calculés indépendamment : `tests/fixtures/queue-signature-vectors.json`.

Les commandes historiques de la file (`clear_cache`, `check_updates`, `update_plugin`…), que le
manager publie aujourd'hui sans signature, suivent le chemin d'avant, inchangé, y compris en
politique `required` ; une enveloppe éventuelle n'y est vérifiée que pour rapport.

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
