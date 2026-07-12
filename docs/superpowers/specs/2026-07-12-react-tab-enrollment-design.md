# Enrôlement dans l'onglet React « Manager G2RD »

- **Date** : 2026-07-12
- **Repo** : `g2rd-connector`
- **Statut** : design validé, prêt pour le plan d'implémentation

## Problème

Le plugin expose deux interfaces d'administration :

1. **Panneau React** (`assets/admin/src/ConnectorPanel.tsx`) — affiché comme onglet
   *Manager G2RD* dans **Apparence → Options G2RD** dès que le thème G2RD ≥ 1.19
   est actif.
2. **Formulaire PHP autonome** (`includes/Admin/Page.php::render_standalone_page`)
   — menu top-level *G2RD Connector*, enregistré **uniquement** quand le thème ne
   supporte pas les onglets externes (`theme_supports_external_tabs()` faux).

Le panneau React est **incomplet** : il affiche le message « Renseignez l'URL du
manager et le token d'invitation » mais ne rend **ni champ Token, ni bouton
d'enrôlement**, et ne persiste même pas ses changements (aucun appel REST). Le
seul écran capable d'enrôler (le formulaire PHP) est inaccessible tant que le
thème G2RD ≥ 1.19 est actif.

**Conséquence** : impossible de connecter un nouveau site au manager via l'UI
quand le thème G2RD est actif — cas nominal pour tout site client.

## Objectif

Rendre l'onglet React réellement fonctionnel : permettre d'enrôler, sauvegarder
les réglages et se déconnecter directement depuis l'onglet *Manager G2RD*, sans
changer de thème ni passer par le formulaire PHP.

Non-objectif : refondre le formulaire PHP (il reste le fallback pour les thèmes
non-G2RD) ou modifier le flux Bearer côté manager.

## Approche retenue

Compléter le panneau React + ajouter un **endpoint REST admin** dédié côté
connecteur, gardé par capability + nonce (auth WordPress standard, distincte du
Bearer SiteToken réservé au manager).

Alternatives écartées :
- *Ré-exposer le formulaire PHP comme sous-menu sous le thème G2RD* : diff
  minuscule mais laisse l'onglet React incomplet et scinde l'UX en deux écrans.
- *POST vers `admin-post.php`* : mélange React et cycle POST/redirect PHP, awkward.

## Composants

### 1. `includes/Rest/AdminController.php` (nouveau)

Contrôleur REST exposant 3 routes sous le namespace `g2rd/v1`, préfixe
`/admin/`. **Toutes** gardées par le même `permission_callback` :
`current_user_can( 'manage_options' )` — la nonce `X-WP-Nonce` (action `wp_rest`)
est vérifiée par le cœur REST de WordPress pour les requêtes authentifiées par
cookie. Même posture de sécurité que `check_admin_referer` + `current_user_can`
du formulaire PHP.

| Route (POST) | Paramètres | Effet |
|---|---|---|
| `/admin/save` | `manager_url`, `heartbeat_enabled`, `events_enabled`, `remote_commands_enabled` | `Settings::update()` (URL + toggles). Miroir de « Enregistrer ». |
| `/admin/enroll` | idem `save` + `invitation_token` | `Settings::update()` (URL + toggles) **puis** `ManagerClient::enroll( token, url )`. Si succès → `HeartbeatJob::schedule()`. |
| `/admin/unenroll` | — | `Settings::update()` vide `site_id`/`site_token`/`enrolled_at` + `HeartbeatJob::unschedule()`. |

- Validation/sanitisation des `args` via `register_rest_route` (`manager_url` en
  `esc_url_raw`, `invitation_token` en `sanitize_text_field`, toggles en `bool`).
- Chaque route renvoie le **payload boot frais** (voir §3) en JSON, pour que
  React rafraîchisse son état sans rechargement.
- Sur échec d'enrôlement, renvoie un `WP_Error` avec le message du manager et le
  code HTTP d'origine (ex. token expiré → 4xx).

Réutilise intégralement `Settings`, `Outbound\ManagerClient` et
`Cron\HeartbeatJob` : **aucune duplication** de la logique métier d'enrôlement,
qui reste dans `ManagerClient::enroll()`.

### 2. `includes/Plugin.php` (modifié)

Enregistrer `AdminController` sur `rest_api_init` **avant** le gate
`Settings::is_enrolled()` (comme `HealthController`) — il faut pouvoir enrôler un
site pas encore enrôlé. L'exposition n'est pas un « phoning home » : routes
capability-gated, aucune requête sortante tant que l'admin ne clique pas.

### 3. Payload boot partagé

`Admin\Page::initial_data()` (déjà existant) est la source de vérité du payload
injecté à React (`managerUrl`, `enrolled`, `siteId`, `enrolledAt`,
`lastHeartbeatAt`, toggles, `restUrl`, `nonce`, `connectorVersion`).

`AdminController` doit renvoyer **le même schéma** après chaque action. Pour
éviter la divergence, extraire ce tableau dans une méthode réutilisable
(ex. `Settings::boot_payload()` ou une fonction partagée) appelée à la fois par
`Page::initial_data()` et par `AdminController`. Cela évite deux constructions
du payload qui pourraient dériver.

### 4. `assets/admin/src/ConnectorPanel.tsx` (modifié)

Miroir fonctionnel du formulaire PHP :

- **Non enrôlé** : champ *URL du manager*, **nouveau champ *Token d'invitation***
  (`TextControl`, `autocomplete=off`, aide « Généré dans le manager → fiche du
  site → Inviter ce site à se connecter, valide 15 min »), les 3 toggles, et les
  boutons **[Enrôler le site]** (primaire) + **[Enregistrer]** (secondaire) +
  [Tester la connectivité].
- **Enrôlé** : notice succès actuelle (site #id, dernier heartbeat), URL,
  toggles, boutons **[Enregistrer]** + **[Déconnecter du manager]** (destructif,
  `isDestructive`) + [Tester].
- Appels via `@wordpress/api-fetch` en POST (`{ path, method: 'POST', data }`).
  La nonce `X-WP-Nonce` est injectée automatiquement par le `wp-api-fetch` de
  l'admin ; `state.nonce` du boot reste disponible en secours via un
  `createNonceMiddleware` si besoin.
- États locaux : `invitationToken`, `saving`, `enrolling`, `unenrolling`,
  `notice` (succès/erreur).
- Après action réussie : `setState` depuis la réponse (bascule enrôlé/non,
  vide le champ token), notice de succès. Sur erreur : afficher le message
  renvoyé (token expiré, HTTP 4xx, réseau).

## Sécurité

- Endpoints admin **jamais anonymes** : `manage_options` + nonce CSRF — parité
  exacte avec le formulaire PHP audité.
- Le `invitation_token` transite en **corps POST** (jamais en query), n'est pas
  journalisé, et le `site_token` renvoyé par le manager reste **chiffré au repos**
  via `Settings::encrypt_token()` (inchangé).
- Les endpoints Bearer (`SnapshotController`, `CommandController`) et le gate
  d'enrôlement **ne sont pas modifiés** : aucune nouvelle surface exposée au
  manager, seulement à l'admin local déjà authentifié.

## Tests / validation

- **PHPCS + PHPStan** sur `AdminController` et les modifs (gates CI du repo).
- **Rebuild** du bundle admin (`assets/admin/build/index.js` + `index.css`) —
  indispensable, sinon la prod continue de servir l'ancien JS incomplet.
- **Smoke manuel** :
  1. Manager → fiche du site → *Inviter ce site* → générer un token.
  2. Onglet *Manager G2RD* du WP cible → coller URL + token → *Enrôler le site*.
  3. Vérifier bascule « Site enrôlé » + heartbeat qui remonte au manager.
  4. *Déconnecter du manager* → retour à « Site non enrôlé ».
- Release via le skill `production` : bump version (cible **1.9.0**), checks
  locaux + push CI verte, puis commit `version:` déclenchant l'auto-tag.

## Périmètre exclu (YAGNI)

- Pas de re-planification du cron heartbeat sur simple « Enregistrer » : on
  conserve le comportement actuel (planification au boot/enroll). Hors sujet.
- Formulaire PHP autonome inchangé (fallback thème non-G2RD).
