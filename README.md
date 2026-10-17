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
| **Webhook events** | Push temps réel au manager : `user.login`, `user.login_failed`, `plugin.activated/deactivated`, `core.updated`, `core.auto_update`. `user.login_failed` part avec une attente plafonnée à 2 s (au lieu de 15), réponse non lue, 30 fois par minute au plus (`Events\LoginFailedThrottle`) ; au-delà, ni envoi ni écriture, pas d'événement récapitulatif. |
| **Commandes distantes** | `POST /wp-json/g2rd/v1/command` (Bearer auth) — `clear_cache`, `check_updates`, `update_core` déclenchables depuis le manager. |
| **Connexion directe** | `admin-ajax.php?action=g2rd_login&ticket=…` — ouvre l'administration sans mot de passe depuis le manager (ticket signé, 60 s, usage unique). Case « Autoriser la connexion directe depuis G2RD », cochée par défaut. |
| **Intégration thème** | Si le thème parent est [`g2rd-theme`](https://github.com/SebG2RD/g2rd-theme) ≥ v1.19 (thème enfant compris), le plugin s'enregistre comme **onglet « Manager G2RD » de la console du thème**, avec une page de secours hors du menu. Sinon, menu top-level autonome. Voir [Intégration au thème G2RD](#intégration-au-thème-g2rd). |

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
│   ├── DirectLogin/            connexion directe : point d'entrée, contrôles, snapshot
│   ├── Security/
│   │   ├── RequestSignature    signature des requêtes du manager
│   │   └── DirectLoginTicket   ticket de connexion directe (classe pure)
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
2. **Côté WordPress** : menu *G2RD Connector* (ou, avec le thème G2RD, onglet *Manager G2RD* de sa console : Apparence → Options G2RD jusqu'au thème 1.40, menu *G2RD* à partir de la 1.41).
3. Coller le token, cliquer **Enrôler le site**. Le plugin POST `/api/sites/enroll`, reçoit `{ site_id, site_token }` et persiste.
4. À partir de là : sync, heartbeat, events, commandes — tout transite via le SiteToken (révoquable à tout moment côté manager).

### Stockage du SiteToken (chiffrement au repos)

Le SiteToken est stocké chiffré dans la clé `site_token` de l'option `g2rd_connector_settings`.
Les clés sont dérivées des sels de `wp-config.php` (`AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`,
`NONCE_KEY`) : un dump SQL seul ne suffit pas à le lire.

**Livraison en deux temps.** Cette version **lit** le format `v2` mais continue d'**écrire** le `v1`
de la 1.12.0-rc.4, tant que l'écriture `v2` n'est pas activée. Raison : le connecteur ne peut pas
être rétrogradé par le manager (il refuse de se restaurer lui-même, et la mise à jour GitHub ne
propose jamais une version plus basse). Si des sites étaient déjà passés en `v2`, une version de
retour arrière construite sur un code qui ne lit pas le `v2` les couperait tous du manager (Bearer
faux, 401/403), sans réparation possible à distance.

| Format | Écrit par | Algorithme |
| --- | --- | --- |
| `enc:v2:sb:` | versions après 1.12.0-rc.4, **écriture v2 activée** | libsodium secretbox (XSalsa20-Poly1305), authentifié |
| `enc:v2:gcm:` | idem, sans sodium | AES-256-GCM, authentifié |
| `enc:v1:` | 1.6.7 à 1.12.0-rc.4, **défaut actuel**, et repli sans sodium ni GCM | AES-256-CBC, **non authentifié** |
| sans préfixe | avant 1.6.7, ou hébergeur sans openssl | jeton en clair |

- **Écriture `v2`, dormante par défaut** : `define( 'G2RD_CONNECTOR_TOKEN_V2', true );` dans
  `wp-config.php`, ou le filtre `g2rd_connector_token_v2`. À n'activer sur le parc qu'une fois
  exclu tout retour à une version qui ne lit pas le `v2` (≤ 1.12.0-rc.4).
- **Vrai interrupteur** : retirer l'option ramène un jeton `v2` (ou un `v1` qui enveloppe un `v2`)
  au format `v1` à la première écriture des réglages (battement de cœur suivant), aller-retour
  vérifié ; une valeur illisible n'est jamais réécrite. Sans openssl, un `v2` n'est jamais remis
  en clair (il reste `v2`).
- **Règle de release : aucune version publiée ne doit retirer le lecteur `v2`** (`decode`,
  `decrypt_v2`, et le cas du `v1` qui enveloppe un `v2`). Le test
  `test_le_lecteur_v2_reste_actif_sans_l_option` le vérifie.
- Tous les formats restent lisibles. Une valeur `v2` altérée ne donne aucun jeton (jamais un jeton
  modifié) ; un préfixe `enc:` inconnu n'est jamais pris pour du clair.
- **Limite : seule une valeur `v2` est protégée contre l'altération.** Le `v1` et le clair restent
  acceptés par défaut pour la compatibilité. Un accès en écriture à la base permet donc encore de
  substituer une valeur de l'ancien format : la copie de secours ci-dessous (dont l'IV peut être
  altéré pour obtenir un jeton modifié), ou un jeton en clair choisi. Écriture `v2` activée, une
  telle valeur est signalée (`tokenState: legacy`), pas refusée.
- **Mode strict, dormant par défaut** : `define( 'G2RD_CONNECTOR_REQUIRE_AUTHENTICATED_TOKEN', true );`
  dans `wp-config.php` (hors base : la même personne ne peut pas le retirer), ou le filtre
  `g2rd_connector_require_authenticated_token`. Il refuse un `v1` seul ou un clair dès que le site
  écrit lui-même au format authentifié (écriture `v2` activée ; sinon il reste inactif) ; un `v1`
  qui enveloppe un `v2` reste accepté. Jeton refusé : `tokenState: refused`, avis d'erreur aux
  administrateurs, aucune migration (une valeur refusée n'est jamais « blanchie » en `v2`). À
  n'activer qu'une fois `tokenState: ok`, c'est-à-dire après la migration. Un jeton refusé ou
  illisible n'ouvre jamais la file des commandes : aucune signature n'est acceptée sans jeton.
- Écriture `v2` activée, migration vers `v2` à la **première écriture des réglages** (en pratique
  le battement de cœur suivant), jamais au démarrage : le retour arrière automatique de WordPress
  relit toujours la valeur. Au démarrage, un jeton historique en clair est seulement chiffré au
  format qu'écrivait la 1.12.0-rc.4 (`v1` ; laissé en clair sans openssl, comme alors).
  Aller-retour vérifié avant écriture ; la valeur `v1` est copiée dans l'option
  `g2rd_connector_site_token_v1` (sans autoload, jamais lue, retirée à la désinstallation ; aucune
  copie possible sans openssl). Un `v1` illisible n'est jamais écrasé.
- La copie de secours correspond **toujours au jeton courant** : régénérée à chaque nouvel
  enrôlement, vidée (jamais supprimée) par « Déconnecter du manager ». Un site jamais migré n'en a
  pas.
- États du jeton (`tokenState` dans les données de la page d'administration, `tokenStrict` pour le
  mode strict) : `none`, `ok` (dont le `v1` tant que l'écriture `v2` n'est pas activée), `legacy`
  (écriture `v2` activée, ancien format accepté : normal juste après la mise à jour, suspect s'il
  dure), `refused`, `unreadable` (valeur altérée, sels changés). Pour `refused` et `unreadable`,
  avis d'erreur aux administrateurs (cliquer sur « Déconnecter du manager », puis réenrôler avec
  une nouvelle invitation) ; la page autonome du connecteur affiche cet état à la place du bandeau
  vert « Site enrôlé ».
- Filtre `g2rd_connector_token_cipher` (`sb`, `gcm`, `v1`) : choisit l'algorithme d'écriture parmi
  ceux que permettent l'hébergeur et l'option (sans écriture `v2`, seul `v1`) ; `v1` sert de repli
  d'urgence et ramène aussi un `v2` au format `v1`. La lecture n'en dépend jamais.
- Rétrogradation **manuelle** vers 1.12.0-rc.4 ou avant après une migration `v2` : l'ancien code ne
  lit pas `v2` (site déconnecté). Mieux vaut d'abord retirer l'option `v2` et attendre un battement
  de cœur (le jeton repasse en `v1`). À défaut, remettre la copie : `wp option patch update
  g2rd_connector_settings site_token "$(wp option get g2rd_connector_site_token_v1)"` (la copie est
  celle du jeton courant ; vide après une déconnexion, absente sans openssl), ou remettre le
  connecteur à jour (la valeur réécrite par l'ancienne version est réparée à la lecture).
- Rien ne change sur le réseau : même Bearer, même clé de signature, aucun réenrôlement.

## Intégration au thème G2RD

Avec le thème G2RD, le connecteur se range dans la console d'options du thème, à côté des
autres extensions G2RD (groupe des extensions, onglet « Manager G2RD », icône `cloud`) ; sans
lui, il garde son propre menu « G2RD Connector ». Code : `includes/Admin/Page.php` (PHP),
`assets/admin/src/index.tsx` (montage), `assets/admin/src/ui.ts` (mode de dessin).

### Détection

Le choix se fait d'après le **thème parent** (`get_template()`), celui qui fournit la console, et
SA version (en-tête `Version` lu par `wp_get_theme( get_template() )`) : la version d'un thème
enfant n'a aucun rapport avec celle de la console (g2rd.fr : `g2rd-child` 1.0.0 sur
`g2rd-theme` 1.39.0).

| Thème parent | Résultat |
| --- | --- |
| `g2rd-theme` (ou son ancien dossier `g2rd`) ≥ 1.19.0, thème enfant ou non | Onglet de la console + page de secours hors du menu |
| `g2rd-theme` < 1.19.0, thème parent introuvable, ou autre thème | Menu de premier niveau « G2RD Connector » (formulaire PHP) |

Le filtre `g2rd_options_external_tabs` existe dans le thème depuis la 1.19.

### Onglet de la console

- **Déclaration (PHP)** : `Page::register_as_theme_tab()` ajoute au filtre
  `g2rd_options_external_tabs` l'onglet `connector` — libellé « Manager G2RD », description
  « Connexion au tableau de bord centralisé G2RD WP Manager. », icône `cloud` (un nom du jeu
  d'icônes de la console, pas une classe dashicons), conteneur `g2rd-connector-tab-root`, et
  `data` = les données de la page (`BootData::build()`, aucun secret : le jeton du site n'y
  figure pas). La console lit libellé, description et icône dans ce filtre.
- **Chargement** : le script et la feuille du panneau ne se chargent que sur la page de la console
  (`Page::is_theme_console_page()`) — `appearance_page_g2rd-options` pour les thèmes 1.19 à 1.40
  (Apparence → Options G2RD), `toplevel_page_<slug>` à partir de la 1.41 (menu de premier niveau
  « G2RD », slug lu dans `\G2RD\AdminConsole::MENU_SLUG`, `g2rd` à défaut ; l'ancienne adresse
  `?page=g2rd-options` y est redirigée). Rien sur les sous-pages `g2rd_page_…` ni ailleurs.
- **Montage (JS)** : au `DOMContentLoaded`, `index.tsx` appelle
  `window.G2RDOptions.registerTab()` avec les mêmes clé, libellé, description et icône que le
  filtre (`tests/Admin/PageTest.php` vérifie qu'ils restent identiques). `mount` rend toujours sa
  fonction de nettoyage : quitter l'onglet démonte la racine React (démontage remis à la tâche
  suivante, la console l'appelant pendant son propre rendu). Les réglages renvoyés par le serveur
  (enregistrement, enrôlement, déconnexion) sont gardés d'un montage à l'autre : revenir sur
  l'onglet affiche les derniers réglages enregistrés, pas ceux du chargement de la page.

### Page de secours

En mode onglet, la page autonome reste enregistrée **hors du menu**, joignable par
`wp-admin/admin.php?page=g2rd-connector`, au cas où la console ne montrerait pas l'onglet (thème
modifié, erreur JavaScript). Elle est rattachée à `options.php` (écran « Toutes les options »,
qui n'est pas un menu de premier niveau, donc sans entrée de menu) : un parent `null` ou vide la
cacherait aussi, mais avec des avertissements de dépréciation sous PHP 8.1+ (détail dans
`Page::FALLBACK_PARENT`). Elle affiche le même formulaire PHP que la page autonome (mêmes
libellés que le panneau, correctif des cases cochées sous WordPress 7) ; sa feuille se charge sur
son propre hook (`admin_page_g2rd-connector`), seule : elle ne monte pas le panneau React (pas de
conteneur `#g2rd-connector-root`), donc ni son script, ni React, ni ses données n'y sont chargés.
Il en va de même de la page autonome.

### Contrat attendu du thème

Documenté côté thème dans `docs/plugin-wordpress.md` du dépôt g2rd-theme.

- **`window.G2RDOptions.registerTab( { key, label, description, icon, mount } )`** : présent dès
  l'exécution du bundle de la console, avant le `DOMContentLoaded` où le connecteur l'appelle.
  Absent (console non chargée), le connecteur n'enregistre rien et ne lève aucune erreur.
- **`mount( container, data )`** : appelé quand l'onglet devient actif, `data` étant le tableau
  `data` du filtre PHP. Il **rend une fonction de nettoyage**, que la console appelle quand on
  quitte l'onglet (seulement si `mount` en rend une ; le connecteur la rend toujours).
- **`window.G2RDOptions.console.version`** : contrat d'interface posé par le thème 1.41.0 et plus
  (`\G2RD\AdminConsole::CONTRACT_VERSION`, aujourd'hui 2), lisible au moment où `mount` est
  appelé. Le panneau le lit au montage (`detectUiMode()`) :
  - **≥ 2 → mode console** : le panneau n'emploie que les classes du contrat et a l'allure des
    autres onglets ; la feuille du connecteur n'y ajoute que de la mise en page ;
  - **absent ou < 2 → mode autonome** (thème 1.19 à 1.40, comme g2rd.fr en 1.39.0) : même
    structure, classes `g2rd-connector-…` dessinées par
    `assets/admin/src/style.css` sous `.g2rd-connector-panel--autonome` (couleurs de
    l'administration WordPress, `--wp-admin-theme-color`, et gris neutres ; interrupteurs
    cliquables sur 44 px de haut).
- **Classes stables (contrat version 2)** employées par le panneau : `g2rd-section`,
  `g2rd-section__head`, `__title`, `__desc`, `__footnote` ; `g2rd-setting-list` >
  `g2rd-setting-row` (modificateur `is-on`, enfants `__icon`, `__main`, `__title`, `__desc`,
  `__control`) ; `g2rd-toggle` (interrupteur `FormToggle`) ; `g2rd-chip`, `g2rd-chip--muted` ;
  `g2rd-status`, `--ok`, `--warn`, `__dot` ; `g2rd-field__label`, `g2rd-field__input`,
  `g2rd-field__hint` ; `g2rd-notice-inline`. La liste est le type `UiPart` de `ui.ts` ;
  `tests/Admin/ConnectorPanelTest.php` vérifie qu'en mode console le panneau n'emploie qu'elles et
  qu'en mode autonome chacune est dessinée par la feuille.
- **Icône** : `cloud`. Le thème 1.39 en fait la classe `dashicons-cloud` ; à partir de la 1.41, la
  console la prend dans son jeu interne (toute autre valeur y devient l'icône `plug`).

Côté thème, `version` n'augmente que si une classe du contrat change de sens. Si une version 3
paraît, revoir `CONSOLE_CONTRACT_MIN` et `detectUiMode()` dans `ui.ts` : le panneau prend
aujourd'hui toute version ≥ 2 pour la version 2.

## Routes REST exposées

| Méthode | Path | Auth | Description |
| --- | --- | --- | --- |
| GET | `/wp-json/g2rd/v1/health` | none | Ping public |
| GET | `/wp-json/g2rd/v1/snapshot` | Bearer SiteToken (+ signature) | Inventaire complet |
| POST | `/wp-json/g2rd/v1/command` | Bearer SiteToken (+ signature) | Exécute commande |

### Origine des thèmes dans l'inventaire

- **`themes[].wporg`** : vrai seulement si wordpress.org connaît le thème, d'après sa dernière
  réponse rangée dans le transient `update_themes` (entrées `response` ou `no_update`), et
  seulement pour une entrée dont la fiche et le paquet sont sur wordpress.org ou l'un de ses
  sous-domaines — une entrée ajoutée par un updater tiers ne compte pas. Thème enfant, premium
  ou sur mesure : faux. Aucun appel réseau de plus. Le champ est omis quand ce transient n'a pas
  de réponse de wordpress.org (réseau coupé) : le manager garde alors son ancien calcul.

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
`g2rd_connector_nonce_store_full`, `g2rd_connector_token_unavailable`)
et un message explicite dans `error`.
L'échec est compté dans le diagnostic de signature (page d'administration, battement de cœur).

Jeton du site inutilisable (`tokenState` `unreadable` ou `refused`) : le jeton déchiffré vaut `''`,
et la clé dérivée de `''` est calculable par n'importe qui. Aucune signature n'est alors jamais
acceptée (`token_unavailable`, sans calcul de HMAC), ni par la file ni par `RequestSignature::verify()` :
les trois commandes ci-dessus sont refusées, avec un message qui demande de ré-enrôler le site. Les
commandes historiques gardent leur chemin (rapport seul).

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
  par extension. La purge est un cron WordPress local, deux fois par jour, elle fonctionne hors
  connexion au manager ; elle est reportée au passage suivant tant qu'une mise à jour protégée
  est en cours (son point et son zip ne sont jamais supprimés sous elle). Une mise à jour
  protégée dont la requête a été tuée (sans passer par le shutdown) est reprise par un contrôle
  ponctuel à part (`g2rd_connector_update_recovery_check`, reprise seule, sans purge), programmé
  11 minutes après son ouverture puis suivi tant qu'elle reste ouverte. La mise à jour rafraîchit
  sa transaction après chaque étape longue : aucune étape ne doit dépasser 10 minutes d'horloge,
  sans quoi un contrôle la prendrait pour morte et la reprendrait. Une requête encore vivante ne
  recrée pas une transaction fermée par un contrôle (relecture sans cache avant chaque
  rafraîchissement), sauf dans une fenêtre de quelques millisecondes avec un cache objet
  persistant. Une mise à jour morte n'est reprise qu'une fois : si sa transaction ne peut
  pas être retirée, le contrôle n'est pas reprogrammé (jamais à une date passée), l'extension
  n'est pas restaurée à nouveau, et la tentative est tracée dans l'option
  `g2rd_update_txn_recovery`.
- Une nouvelle mise à jour protégée reprend d'abord une transaction morte pas encore reprise
  (extension désactivée, fichiers à moitié remplacés), au lieu de l'écraser. La reprise réserve
  la transaction (étape `recovering`) avant de restaurer : un contrôle simultané ne restaure pas
  une seconde fois, et une mise à jour protégée demandée pendant ce temps est refusée avec le
  message déjà connu de la plateforme (« another protected update is still running on this
  site… retry in a few minutes »). La fermeture et le filet de shutdown ne touchent qu'à la
  transaction de leur processus ; une mise à jour dont la transaction a été reprise pendant une
  étape de plus de 10 minutes s'arrête avant de toucher à l'extension, avec une erreur explicite ;
  un contrôle de reprise perdu est reprogrammé juste avant que les fichiers changent.
- Une reprise menée par le cron arme le filet de shutdown et garde un contrôle en attente avant
  de restaurer : une reprise tuée en route est reprise environ 11 minutes plus tard, au lieu
  d'attendre la purge (jusqu'à 12 h). Juste avant que WordPress remplace les fichiers (filtre
  `upgrader_pre_install`, avant la désactivation de l'extension), la mise à jour protégée
  rafraîchit sa transaction ; si une reprise l'a prise pendant un téléchargement ou un
  rafraîchissement des transients de plus de 10 minutes, elle s'arrête là, avec une erreur
  explicite (« protected update stopped before replacing the plugin files… the plugin was not
  updated, retry in a few minutes ») : la nouvelle version n'est jamais copiée après que la
  reprise a remis l'ancienne, ni pendant qu'elle la restaure. Une mise à jour dont la transaction
  a été prise pendant le remplacement des fichiers s'arrête juste après, avec une erreur
  explicite : ni mesure de santé, ni seconde restauration en même temps que la reprise, ni
  résultat `updated` / `not_updated` (la reprise restaure l'ancienne version et consigne son
  propre résultat) ; si la mise à jour elle-même échoue après une telle prise, son erreur le dit
  (« its transaction was taken over by a recovery, which reports its own result »). Prise pendant
  le contrôle de santé, elle ne restaure pas sans sa transaction : erreur explicite, point gardé
  pour un rollback depuis la plateforme (limite connue : il faut une mesure de santé de plus de
  10 minutes). Une reprise qui meurt pendant la restauration n'est plus relancée sans fin : après
  3 reprises inachevées (la même erreur fatale rejouée à chaque fois), la suivante ne restaure
  plus, consigne `recovery_failed` avec un détail explicite et garde le point, au lieu de laisser
  l'extension désactivée sans résultat pour la plateforme. Si l'extension était active, elle
  n'est réactivée que si elle se charge sans erreur (sinon elle reste inactive) : ses fichiers
  sont alors les plus douteux (peut-être à moitié extraits), et `active_plugins` n'est jamais
  écrite de force, ce qui ferait tomber toutes les pages du site en « erreur critique ». Une mise
  à jour protégée arrêtée parce qu'une reprise a pris sa transaction ne réactive plus l'extension,
  ni pendant ni après cette reprise (c'est la reprise qui décide de la réactiver). Le
  rollback manuel (`rollback_plugin`) est refusé avec le même message
  (« another protected update is still running on this site… retry in a few minutes ») tant
  qu'une mise à jour protégée ou une reprise est en cours ; une transaction morte ne le bloque pas.
  Une reprise qui a dépassé sa réservation ne fait plus passer la suivante pour faite ; la
  transaction est relue même lue absente plus tôt dans la requête (cache `notoptions` de
  WordPress) ; la trace `g2rd_update_txn_recovery` est retirée avec sa transaction.
- Une restauration qui échoue (rollback automatique, reprise ou rollback manuel) ne force plus
  l'extension active : elle n'est réactivée que par le bac à sable d'activation de WordPress,
  jamais par une écriture directe d'`active_plugins` (son dossier peut être à moitié remplacé, ce
  qui mettrait tout le site en « erreur critique »), et si elle ne s'y charge pas elle reste
  inactive et l'erreur remontée à la plateforme le dit (« the plugin was left inactive… »).
- Un rollback automatique en échec laisse l'extension désactivée. La requête de la mise à jour
  l'avait chargée à son démarrage (elle était active) : le bac à sable d'activation ne la
  rechargerait pas, et activerait sans l'essayer ce que la restauration manquée a laissé sur le
  disque. Après une restauration qui échoue, une extension dont le fichier principal est déjà
  chargé par la requête n'est plus réactivée (rollback automatique, filet de shutdown de la
  requête de mise à jour, rollback manuel) : elle reste désactivée, et l'erreur remontée à la
  plateforme le dit, avec le même code d'erreur (« the plugin was left inactive: … which could
  not be checked because its code was already loaded earlier in this request… »). Une
  restauration ne désactive plus l'extension qu'une fois son dossier mis de côté : un refus
  d'avant (`version_drift`, intégrité) la laisse active et intacte au lieu de la désactiver.
  Exception pour le rollback manuel (ci-dessous) : quand le dossier d'avant la commande a été
  remis en place tel quel, l'extension est réactivée comme avant.
- Un rollback manuel (`rollback_plugin`, point local ou archive téléchargée) qui échoue une fois
  le dossier de l'extension mis de côté (disque plein pendant l'extraction, par exemple) rétablit
  l'état d'avant la commande : le restaurateur a remis en place, tel quel, le dossier qui
  tournait au démarrage, et l'extension, active avant, est réactivée. Elle restait désactivée :
  une boutique WooCommerce dont le rollback manquait restait éteinte alors qu'elle fonctionnait
  avant. L'erreur le dit, avec le même code (« the plugin was put back as it was before this
  rollback… retry the rollback from the platform »). Si cette réactivation échoue, l'erreur dit
  que les fichiers d'avant sont en place mais l'extension inactive (« …but reactivating it
  failed, so it is inactive; reactivate it from the Plugins screen of WordPress… »). Si ce
  dossier n'a pas pu être remis en place, rien ne change : l'extension n'est réactivée que si
  elle se charge, sinon elle reste désactivée et l'erreur le dit. Le rollback automatique et les reprises ne sont pas concernés :
  le dossier qu'ils remettent en place est la nouvelle version mesurée cassée, ou des fichiers à
  moitié copiés.
- Le rollback manuel depuis une archive téléchargée suit désormais exactement le chemin du point
  local : l'extension est désactivée pendant l'extraction (elle restait active pendant que ses
  fichiers étaient remplacés), un refus d'avant la mise de côté ne la touche pas, et un échec est
  traité comme ci-dessus (état d'avant rétabli, ou réactivation seulement si elle se charge, et
  l'erreur dit ce qu'est devenue l'extension).
- Un rollback manuel depuis un point local qui échoue garde son point : il est retenu
  (`hold_max_seconds`, 7 jours par défaut) au lieu de garder sa date d'expiration, et la purge ne
  le supprime plus ; il reste disponible pour réessayer.
- Une mise à jour protégée ne réactive jamais son extension après une reprise faite par un
  autre processus. Quand une étape dure plus de 10 minutes, la reprise du cron prend la
  transaction, restaure l'extension et décide seule de la réactiver. Si sa restauration a
  échoué et l'a laissée inactive (elle ne se charge pas), la requête de mise à jour, encore
  vivante, la forçait active une fois la reprise finie (réactivation nominale ou filet de
  shutdown) : « erreur critique » sur toutes les pages. La requête ne réactive plus l'extension
  que tant que la transaction est encore la sienne, ou après l'avoir fermée elle-même ; son
  filet ne réactive plus une extension qu'une reprise a laissée inactive.
- Toute mise à jour d'extension (`update_plugin`, avec ou sans point de restauration, le
  connecteur compris) : le filet de shutdown ne réactive l'extension que si la mise à jour de
  WordPress (`Plugin_Upgrader::upgrade()`) a rendu la main. Après une erreur fatale ou un délai
  dépassé en pleine copie, il la forçait active : le fichier principal, déjà chargé par la
  requête, n'était pas rechargé et `active_plugins` était écrite quand même, des fichiers à
  moitié copiés pouvant mettre tout le site en « erreur critique ». L'extension reste désormais
  désactivée, le connecteur lui-même compris (la plateforme le verra injoignable). Une exception
  levée pendant la mise à jour, après la désactivation par WordPress, la laisse aussi
  désactivée, et l'erreur le dit (« the plugin was left inactive: the update stopped while
  WordPress was replacing it… »). Une mise à jour qui rend une erreur (WordPress a remis les
  fichiers d'origine) ne change pas : l'extension est réactivée.
- Une fois la copie des fichiers terminée (filtre `upgrader_post_install` de WordPress, sans
  erreur, pour cette extension), la mise à jour compte comme ayant rendu la main. WordPress
  exécute encore `upgrader_process_complete` avant de rendre la main (téléchargement des
  traductions, crochets d'extensions, dont celui du connecteur) : une erreur fatale ou un délai
  dépassé à cet endroit laissait l'extension désactivée, le connecteur compris (site injoignable
  par la plateforme), alors que ses fichiers étaient complets. Le filet de shutdown la réactive
  désormais. Une exception levée à cet endroit la réactive tout de suite, et l'erreur dit que la
  nouvelle version était déjà installée (« WordPress had already installed the new version when
  this error occurred (its files are complete), so the plugin was reactivated… »), au lieu de
  « left inactive… files may be incomplete », faux dans ce cas.
- Une mise à jour protégée ne saute plus son contrôle de santé quand WordPress lève une
  exception une fois les nouveaux fichiers copiés. Elle s'arrêtait là, nouvelle version
  réactivée mais jamais vérifiée : une version qui cassait le site restait en place, toutes les
  pages en « erreur critique », sans rollback automatique. L'exception n'arrête plus la mise à
  jour protégée : elle continue comme après une copie réussie (réactivation, contrôle de santé,
  rollback automatique si le site est cassé), et son résultat (`updated`, `auto_rolled_back`…)
  porte l'erreur dans un nouveau champ `upgrade_error` (« …so the protected update did not stop
  on it and went on as after a successful copy… »). Si une reprise a pris sa transaction
  entre-temps, elle s'arrête comme toute mise à jour prise en route, et son erreur reprend
  celle-ci (« WordPress had also raised an error once the new version was installed… »). La
  mise à jour simple et celle du connecteur ne changent pas (ci-dessus).
- En multisite, le rollback automatique réactive une extension active pour tout le réseau…
  pour tout le réseau. Elle n'était réactivée que pour le site courant : l'état réseau était lu
  dans une clé que le résultat de la mise à jour ne contient jamais.
- Une reprise dont la restauration échoue garde son point de restauration : il est retenu
  (7 jours au plus, comme après un rollback automatique manqué) au lieu de garder sa date
  d'expiration, et la purge ne le supprime plus. Il reste disponible pour remettre l'ancienne
  version à la main. Une reprise dont la restauration réussit n'est plus consignée
  `recovery_failed` quand la rétention du point échoue ensuite.
- Tout résultat `recovery_failed` dit ce qu'est devenue l'extension : nouveau champ
  `reactivation` (`reactivated`, `left_inactive_load_error` : son chargement a levé une erreur,
  `left_inactive_already_loaded` : son code était déjà chargé dans la requête, impossible de
  vérifier, `left_inactive_invalid` : WordPress a refusé de l'activer, `left_inactive_as_before` :
  inactive avant la mise à jour). À l'abandon d'une reprise (3 reprises inachevées), le détail,
  consigné avant l'essai de réactivation (il disait seulement « reactivated only if it loads
  without error »), est complété après l'essai avec la cause exacte et l'action à mener. Une
  reprise manquée sur une extension inactive avant la mise à jour le dit aussi (« the plugin was
  inactive before the update and stays inactive… »). Après toute restauration manquée, une
  extension que le bac à sable d'activation a rallumée n'est plus signalée par l'erreur brute :
  l'erreur dit qu'elle a été réactivée, que ses fichiers ne sont peut-être pas ceux du point, et
  quoi faire (« the plugin was reactivated: it loads without error, but… »). Une extension que
  WordPress a refusé d'activer avant de la charger (fichier principal introuvable, en-tête
  illisible, exigences non remplies) n'est plus présentée comme ne se chargeant pas
  (« WordPress refused to activate it before loading it… »).
- Une commande referme tous les tampons de sortie qu'elle a ouverts, même après une exception.
  Une exception levée par `upgrader_process_complete` saute la fin de la skin silencieuse de
  WordPress, dont le tampon restait ouvert. La commande ne refermait qu'un niveau : elle prenait
  celui de la skin pour le sien et laissait le sien ouvert, vidé en fin de requête ; un texte
  écrit là par du code tiers pouvait précéder le JSON de la réponse. Tous les niveaux ouverts
  pendant la commande sont désormais refermés (jamais un niveau ouvert avant elle), et leur
  contenu remonte dans `stray_output`, dans l'ordre d'écriture.
- Après un rollback, la version retirée est bloquée pour les mises à jour automatiques de
  WordPress jusqu'à la version suivante.
- Le plugin ne se rollback jamais lui-même. La capacité `restore_points` n'est annoncée dans
  l'inventaire que si le système de fichiers est en accès direct et qu'une bibliothèque zip existe.
- Limite connue : WordPress peut envoyer son e-mail « erreur critique » avant le rollback
  automatique (au plus une fois par jour).

#### Repli wordpress.org et empreinte (`source_sha256`)

Si le point local a disparu, `rollback_plugin` peut retélécharger la version depuis `source_url`
(HTTPS, hôte `downloads.wordpress.org` seulement, filtre `g2rd_connector_restore_source_hosts`).
Deux empreintes distinctes, à ne jamais confondre :

- `expected_sha256` : empreinte du zip du **point local** ; elle ne sert qu'à cette branche, même
  quand elle arrive dans la même commande que `source_url`.
- `source_sha256` : empreinte attendue de l'**archive téléchargée** (64 caractères hexadécimaux,
  casse et espaces autour ignorés). Différente : `rollback_failed_integrity` avec `via: download`,
  avant toute écriture (extension ni déplacée ni désactivée, fichier temporaire supprimé). Mal
  formée : même refus, sans téléchargement.

Sans `source_sha256` (managers actuels), l'archive est installée comme avant, mais le résultat le
dit (`source_integrity: unverified`, `source_sha256_actual`) et le site garde une trace bornée dans
l'option `g2rd_connector_unverified_downloads` (sans autoload : compteur et dernier cas), exposée
dans les données de la page d'administration (`unverifiedDownloads`). L'action
`g2rd_connector_rollback_source_unverified` reçoit `( $file, $url, $sha256 )`. En succès vérifié :
`source_integrity: verified`. Un échec d'intégrité porte désormais `via` (`restore_point` ou
`download`).

### Connexion directe depuis G2RD WP Manager

Le bouton « Se connecter à WordPress » du manager ouvre un onglet sur
`/wp-admin/admin-ajax.php?action=g2rd_login&ticket=…`. Le ticket est fabriqué par le manager
au moment du clic (aucun appel du manager vers le site) :
`v1.<charge base64url>.<signature base64url>`, signature HMAC-SHA256 avec une clé dérivée du
SiteToken et du contexte `g2rd-login-v1` (distinct de celui des commandes), charge
`{s, u, e, n, a}` = site, compte WordPress, expiration (60 s), nonce, utilisateur du manager.

Contrôles, dans l'ordre : forme et signature (temps constant) ; site ; expiration (30 s de
tolérance d'horloge, 90 s d'avance au plus) ; usage unique (option `g2rd_login_used_<nonce>`,
purgée au-delà de 10 minutes par le cron local, deux fois par jour — la validation n'en dépend
pas : l'expiration est contrôlée avant l'usage unique) ; case « Autoriser la connexion directe
depuis G2RD » (absente = cochée) ; le compte existe et a toujours `manage_options`.

Si tout passe, la session est ouverte **sans déclencher `wp_login`** (une extension de double
authentification du site redemanderait un code ; la double authentification est exigée côté
manager), avec `no-cache` et `Referrer-Policy: no-referrer`, puis redirection vers le tableau de
bord. Le connecteur envoie l'événement `direct_login` (`user_login`, `wp_user_id`,
`manager_user_id`) après la réponse, si les événements sont activés. Sinon : page WordPress en
403 avec un message explicite. Le lien vers la page de connexion n'y figure que si le ticket est
authentique (signature valide : autre site, expiré, en avance, déjà utilisé, case décochée,
compte sans droits). Sans ticket, ou avec un ticket mal formé ou mal signé, aucun lien : sur un
site à connexion masquée (WPS Hide Login, Solid Security…), `wp_login_url()` rend l'adresse
cachée, qu'un visiteur anonyme ne doit pas pouvoir obtenir par ce point d'entrée.

Le snapshot remonte `site.login_url` (`wp_login_url()`, ce qui détecte une connexion déplacée),
`site.direct_login_enabled`, `site.admins` (50 administrateurs au plus : identifiant,
identifiant de connexion, e-mail, date de création) et la capacité `direct_login`. Les vecteurs
de test du ticket (`tests/fixtures/direct-login-ticket-vectors.json`) sont une copie exacte de
ceux du manager.

## Charge sur le serveur

Les sites clients partagent souvent un même serveur mutualisé : ce que le connecteur fait sur
chacun s'additionne.

- **Tâches planifiées réparties au hasard** : la découverte des mises à jour et la purge des
  points de restauration partent à une heure tirée au hasard dans leur intervalle de 12 h
  (`Cron\Phase`), au lieu d'un décalage fixe après le premier démarrage suivant la mise à jour du
  connecteur — WordPress gardant ensuite cette phase, tous les sites mis à jour ensemble les
  lançaient à la même minute (purge à :33, découverte à 02:05 et 14:05 UTC, mesuré le
  11/10/2026). Les tâches déjà planifiées sont re-phasées une seule fois (option autochargée
  `g2rd_connector_cron_phase`, retirée à la désinstallation) ; un retrait refusé par WordPress
  laisse la tâche à sa date, jamais en double. Le heartbeat horaire n'est pas touché : planifié à
  l'enrôlement, sa phase suit déjà l'heure d'enrôlement de chaque site.
- **Inventaire allégé, mêmes données** (`GET /snapshot`). Chaque synchronisation vidait tout le
  cache objet du site (`wp_cache_flush()`), forçait trois appels à wordpress.org (cœur,
  extensions, thèmes, transients détruits d'abord) et relançait cinq requêtes de santé de la
  base, dont un `SHOW TABLE STATUS`. Désormais :
  - seul ce que l'inventaire relit est invalidé : liste des extensions (`plugins`/`plugins`),
    cache des thèmes (`wp_clean_themes_cache( false )`), options autochargées (`alloptions`,
    dont `active_plugins` et le thème actif) ;
  - un transient de mise à jour n'est gardé, sans appel, que s'il a été vérifié ET que
    wordpress.org y a réellement répondu il y a moins de 3 h
    (`PremiumUpdatesBridge::UPDATE_CHECK_MAX_AGE`), et qu'il couvre les extensions et thèmes
    installés (chacun présent dans `checked`, à la même version : une extension copiée ou mise à
    jour hors de WordPress le rend périmé, comme pour `wp_update_plugins()`). Le cœur doit en
    plus avoir été vérifié pour la version installée. Sinon (ou s'il manque), il est rafraîchi.
    `last_checked` seul ne suffit pas : WordPress l'écrit dans le transient existant AVANT
    d'appeler wordpress.org, et l'y laisse quand l'appel échoue — anciennes données sous une
    date toute neuve. Le connecteur note donc l'heure de chaque réponse 200 de api.wordpress.org
    aux trois vérifications (`Updates\WporgAnswers`, action `http_api_debug`, option de réseau
    non autochargée `g2rd_connector_wporg_answers`, une écriture par vérification réussie,
    retirée à la désinstallation), si son code est exactement l'entier 200 (comme
    `update.php`) et son corps du JSON qui porte les données de la
    vérification (`offers`, `plugins` ou `themes`) : après un 200 au corps HTML (page de défi
    d'un CDN), WordPress écrit un transient vide, qui ne doit pas passer pour frais. La
    réponse reste en attente en mémoire et n'est notée que si WordPress écrit ensuite, dans la
    même requête PHP, le transient qui la porte (filtre `pre_set_site_transient_{$transient}`
    puis action `set_site_transient_{$transient}`, ou réécriture identique) : un 200 que
    WordPress n'écrit pas (filtre `http_response` qui le transforme, appel d'update-check fait
    par une autre extension) ne compte pas, et l'écriture de `last_checked` d'avant l'appel ne
    note jamais rien. Une requête court-circuitée par un filtre `pre_http_request` compte aussi :
    sur les sites hébergés chez Hostinger, le mu-plugin `hostinger-auto-updates.php` refait
    chaque requête vers api.wordpress.org vers son proxy (wpapi.hostinger.io par défaut) et rend
    la réponse du proxy à WordPress sans action `http_api_debug` pour l'adresse d'origine — en
    1.14.0-rc.1, rien n'y était donc noté (pilote g2rd.fr, 16/10/2026). Le connecteur observe la
    valeur finale de `pre_http_request` (filtre branché en dernier, valeur rendue telle quelle),
    celle que WordPress utilise : quand l'adresse d'ORIGINE est exactement l'une des trois
    vérifications, elle compte comme la réponse, quel que soit l'hôte qui l'a servie, avec les
    mêmes règles (en attente jusqu'à l'écriture du transient qui la porte, une écriture par
    vérification réussie) ; la requête imbriquée vers le proxy n'est pas comptée. Sans
    réponse notée (connecteur tout juste mis à jour,
    wordpress.org ou son proxy injoignable), l'inventaire rafraîchit à chaque fois, comme avant. La commande
    `check_updates` (« Vérifier les MAJ ») force toujours. Contrepartie : une version publiée
    sur wordpress.org dans les 3 h qui précèdent une synchronisation, après la dernière
    vérification répondue, peut n'apparaître qu'à la synchronisation suivante, jusqu'à un
    intervalle de synchronisation (environ 7 h) plus tard qu'avant ; pire cas, environ 10 h
    après sa publication au lieu d'environ 7 h. « Vérifier les MAJ » force toujours le
    rafraîchissement. Les mises à jour des updaters tiers
    absents du contexte REST restent annoncées (rejeu de la capture). Une vérification du cœur
    en échec garde la dernière réponse connue au lieu d'un transient vide ;
  - `db_health` est calculé au plus une fois par jour (`Rest\DbHealth`, option non autochargée
    `g2rd_connector_db_health`, oubliée après chaque commande de nettoyage de la base, retirée à
    la désinstallation, sur chaque blog en multisite) ; `collected_at` donne la date de ce calcul.
    Un calcul dont une requête a échoué n'est pas gardé. **Plateforme : G2RD WP Manager 1.60.0
    ou plus récent** pour afficher la vraie date des compteurs : elle lit `collected_at` (date
    ISO 8601 bornée à maintenant, repli sur l'heure de la synchronisation). Une plateforme plus
    ancienne ignore ce champ et date les compteurs de l'heure de la synchronisation
    (« Mesuré il y a … ») : elle afficherait « mesuré il y a 2 minutes » pour des compteurs
    vieux de près de 24 h ;
  - l'OPcache n'est invalidé que pour les fichiers du connecteur : les versions des extensions
    et des thèmes sont lues dans les fichiers eux-mêmes (`get_file_data()`), que l'OPcache ne
    sert jamais.

  Les champs JSON sont inchangés.

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
