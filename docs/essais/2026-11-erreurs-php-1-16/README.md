# Essais du relevé des erreurs PHP (connecteur 1.16)

Comptes rendus des essais du relevé des erreurs PHP sur de vrais WordPress, au banc du manager
(`tools/banc-rejeu`). Rien de ce dossier ne part dans le paquet publié (`docs/` est exclu du zip
par `.github/workflows/release.yml`). La tâche 18 du plan C1 (essais E-1 et suivants) complète ce
fichier.

## Preuve préalable (tâche 8 bis)

**Question.** `PhpErrors\FatalCatcher` passe **après** les fonctions d'arrêt que WordPress
enregistre au chargement (son gestionnaire d'erreurs fatales, l'action `shutdown`). Voit-il
encore l'erreur fatale sur un vrai WordPress, sous les deux serveurs du banc, en page publique,
en administration et en REST, avec et sans gabarit `wp-content/php-error.php` ?

**Réponse : oui, hors de trois cas où PHP s'arrête avant lui.** Les cinq cas qui doivent relever
(P-1 à P-4, P-7) ont chacun un groupe, juste et filtré ; le cas complémentaire d'une vraie page
d'administration (P-2 bis) et le témoin de P-8 aussi. Sans groupe : le relevé coupé (P-6), et ces
trois cas : un gabarit `php-error.php` qui finit par `exit` (P-5), une session du mode de
récupération (P-8, P-8 bis), une extension qui demande l'arrêt par le filtre `wp_php_error_args`
(P-9). Ce sont les limites décrites plus bas. Le lot B peut continuer (tâche 9), après relecture
du lead dev.

### Conditions

| Élément | Valeur |
| --- | --- |
| Date | 31/10/2026, 21 h 37 à 21 h 42 UTC ; complément de la relecture (P-8, P-8 bis, P-9, témoin de P-8) : 31/10/2026, 22 h 00 à 22 h 01 UTC, même banc, mêmes conteneurs (redémarrés par `docker start`, sans recréation) |
| Connecteur | branche `integration/1.16`, `d14ced0567d9879e2c575b8979596716166a4bfd` (en-tête encore `Version: 1.15.0` : le numéro change à la publication), monté depuis la copie `connecteur-1-16` |
| Banc | dépôt du manager, `origin/main` = `acb11e9328a46b1fb8020f38aa3dcdfaeb0d66cd` (1.65.0), copie détachée `.claude/worktrees/banc-c1`, **rien n'y a été modifié** (`git status` : seul `tools/banc-rejeu/var/`, ignoré, porte les jetons du banc) |
| Projet Compose | `g2rd-banc-c1` (`COMPOSE_PROJECT_NAME`), à part de la plateforme locale (`g2rd-wp-manager`, ses volumes `database_data`, `backend_var`, `backend_jwt`) et du banc d'un autre chantier (`g2rd-banc-async`) ; aucun port publié |
| Profil `wp-ols` | WordPress 7.1.4, OpenLiteSpeed 1.8.5, lsphp 8.3.30 (`litespeedtech/openlitespeed:1.8.5-lsphp83`) ; WP-CLI sous PHP 8.3.35 |
| Profil `wp-fpm` | WordPress 7.1.4, nginx 1.30.5, PHP-FPM 8.3.35 (`wordpress:7.1.3-php8.3-fpm`, WordPress installé par WP-CLI dans le volume) |
| Réglages de WordPress du banc | `WP_DEBUG` et `WP_DEBUG_LOG` vrais, `WP_DEBUG_DISPLAY` faux : le journal PHP est `wp-content/debug.log` |
| Site « enrôlé » | jeton du banc dans `var/jeton-ols` et `var/jeton-fpm`, `site_id` 990001 et 990002, `manager_url` = `http://plateforme.banc-rejeu.invalid` posée dans la même écriture (aucun envoi ne peut atteindre la production) |
| Relevé | `php_errors_enabled` mis à vrai par WP-CLI (la commande signée n'existe pas encore) ; relu après écriture : `{"releve":true,"enrole":true}` sur les deux profils ; coupé pour P-6, rallumé pour P-8 à P-9 (relu : `{"releve":true,"enrole":true,"arme":true}`, `arme` = `FatalCatcher::enabled()`), puis recoupé |
| Extension jetable | `zz-banc-fatale`, écrite **dans le volume du conteneur seulement** : appelle une fonction absente sur le crochet choisi par `?banc_fatale=` (`public` → `template_redirect`, `admin` → `admin_init`, `rest` → `rest_api_init`) ; sans en-tête `Version` |
| Seconde extension jetable (P-8, P-9) | `zz-banc-fatale-2`, écrite elle aussi **dans le volume seulement**, par WP-CLI sous l'utilisateur du serveur (65534) : même principe sur `?banc_fatale2=` (fonction absente `banc_fonction_absente_2()`) ; sur `?banc_sortie=1`, ajoute un filtre `wp_php_error_args` qui met `'exit'` à vrai ; sans en-tête `Version` ; désactivée après l'essai (fichier gardé) |

**Ordre des fonctions d'arrêt, relu dans le WordPress 7.1.4 du banc.** `wp-settings.php` l.69
`wp_register_fatal_error_handler()` (qui enregistre `WP_Fatal_Error_Handler::handle`,
`wp-includes/error-protection.php` l.95), puis l.166 `register_shutdown_function( 'shutdown_action_hook' )`,
puis l.582 la boucle qui inclut les extensions actives : `FatalCatcher::arm()`, dans
`g2rd-connector.php`, enregistre donc la troisième fonction d'arrêt, après le gestionnaire
d'erreurs fatales et l'action `shutdown`, comme la conception le suppose.

**Ce que fait le gestionnaire d'erreurs fatales, relu dans le même WordPress.** `FatalCatcher` ne
passe que si `WP_Fatal_Error_Handler::handle()` rend la main sans arrêter PHP. Deux étapes :

1. **Mode de récupération** (`class-wp-fatal-error-handler.php` l.54-56) : hors multisite, une
   fois le mode initialisé (`wp-settings.php` l.574-576, juste avant les extensions), le
   gestionnaire appelle `wp_recovery_mode()->handle_error()`. Cette méthode
   (`class-wp-recovery-mode.php` l.168-197) rend la main tout de suite si l'erreur ne vient ni
   d'une extension ni du thème (l.172-174). **Hors session**, elle tente au plus d'envoyer le
   courriel du mode de récupération sur un point d'entrée protégé, puis rend la main (l.176-186 :
   c'est P-2 bis). **Pendant une session** (`is_active()` vrai : cookie de récupération valide,
   lu à l'initialisation, l.110-113 et l.270-290, sans aucune vérification de connexion), elle
   enregistre l'erreur, ce qui met l'extension ou le thème en pause pour cette session
   (`store_error()`, l.432-447 ; si l'enregistrement échoue, elle rend la main, l.188-190), puis,
   si les en-têtes ne sont pas encore partis, appelle
   `redirect_protected()` (l.188-196), qui finit par `wp_safe_redirect()` vers la même adresse
   puis `exit` (l.461-471), quel que soit le point d'entrée, page publique comprise : **les
   fonctions d'arrêt suivantes ne passent pas** (P-8, P-8 bis). Si les en-têtes sont déjà partis,
   elle rend `true` sans arrêter PHP (l.192-194). Pendant la session, une extension ou un thème en
   pause n'est plus chargé (`wp-includes/load.php` l.1044-1045) : la page renvoyée s'affiche sans
   lui.
2. **Affichage de l'erreur** (`class-wp-fatal-error-handler.php` l.58-61, puis
   `display_error_template()`, l.144-157) :
   `php-error.php` est inclus s'il existe (s'il finit par `exit`, rien ne passe après lui : P-5),
   sinon le gabarit par défaut appelle `wp_die()` avec `'exit' => false` (l.211-214, l.245), et le
   gestionnaire `wp_die` par défaut ne s'arrête que si `exit` est vrai (`functions.php` l.4092-4094).
   Mais le filtre `wp_php_error_args` (l.235) permet à n'importe quelle extension de passer
   `'exit' => true` : PHP s'arrête alors à la fin du gabarit (P-9). Le filtre `wp_die_handler`
   (`functions.php` l.3887) permet de même de remplacer le gestionnaire par un autre qui s'arrête
   (lu dans le code, non mené au banc).

Dans tous les autres cas, les fonctions d'arrêt suivantes passent, et `FatalCatcher` voit
l'erreur.

**Protocole de chaque cas.** Requête depuis le conteneur du serveur, sans le mandataire
(`curl -H "Host: ols.test" http://127.0.0.1/…` dans `wordpress-ols` ;
`curl -H "Host: fpm.test" http://127.0.0.1:8080/…` dans `nginx-fpm`), puis
`wp option get g2rd_php_errors --format=json`, puis les lignes ajoutées au journal PHP pendant la
requête, puis `wp option delete g2rd_php_errors` (tampon créé par l'essai ; « Could not delete »
est la réponse attendue d'un cas sans groupe). Le tampon ne contient aucun jeton (relu à chaque
cas). P-8 à P-9 : `curl --resolve ols.test:80:127.0.0.1 http://ols.test/…` dans `wordpress-ols`
(même chemin, sans mandataire) ; tampon relu après P-8 et P-8 bis, après P-9, après le témoin de
P-8, et remis à zéro une seule fois, après ce témoin, le seul des quatre qui le crée.

### Groupe relevé (identique dans chaque cas qui relève, hors témoin de P-8)

Le témoin de P-8 porte sur la seconde extension : son groupe est donné dans le tableau des cas.

Sortie de `wp option get g2rd_php_errors --format=json` au cas P-1 (`wp-ols`) ; les autres cas
ne diffèrent que par les horodatages :

```json
{"format":1,"groups":{"8712ca1c1859d0a79f9ba8f194b6b64d0fc541c4a02823555b78b41f6973c8d2":{"level":"fatal","type":"plugin","slug":"zz-banc-fatale","version":null,"file":"wp-content\/plugins\/zz-banc-fatale\/zz-banc-fatale.php","line":16,"message":"Uncaught Error: Call to undefined function banc_fonction_absente()","first_seen":1793482668,"last_seen":1793482668,"count":1,"source":"fatal_catcher","written_at":1793482668}},"truncated_at":null}
```

- `level` `fatal`, `type` `plugin`, `slug` `zz-banc-fatale`, `count` 1, `source` `fatal_catcher` ;
- `file` relatif à WordPress, **sans** `/var/www/vhosts/wordpress/html/` (OpenLiteSpeed) ni
  `/var/www/html/` (PHP-FPM) ;
- `message` sans pile d'appels ni suffixe `in <fichier>:<ligne>` ; le journal PHP du site, lui,
  porte la ligne complète (chemin du serveur et pile d'appels), voir ci-dessous ;
- `version` `null` : l'extension jetable n'a pas d'en-tête `Version` (contrat : version au moment
  du relevé, `null` si elle est inconnue) ;
- **même empreinte** `8712ca1c…c8d2` sur les deux profils, alors que les chemins du serveur
  diffèrent : l'empreinte ne dépend pas du serveur ;
- `first_seen` 1793482668 = 31/10/2026 21:37:48 UTC, la seconde de la ligne du journal.

Ligne du journal PHP correspondante (`wp-content/debug.log`, P-1, `wp-ols`), qui prouve que
l'erreur fatale a bien eu lieu :

```text
[31-Oct-2026 21:37:48 UTC] PHP Fatal error:  Uncaught Error: Call to undefined function banc_fonction_absente() in /var/www/vhosts/wordpress/html/wp-content/plugins/zz-banc-fatale/zz-banc-fatale.php:16
Stack trace:
#0 /var/www/vhosts/wordpress/html/wp-includes/class-wp-hook.php(353): {closure}()
…
#3 /var/www/vhosts/wordpress/html/wp-includes/template-loader.php(23): do_action()
…
  thrown in /var/www/vhosts/wordpress/html/wp-content/plugins/zz-banc-fatale/zz-banc-fatale.php on line 16
```

`docker compose logs wordpress-ols` ne montre que le démarrage du serveur : avec `WP_DEBUG_LOG`,
PHP écrit dans `debug.log`. Décompte final des lignes « PHP Fatal error » : 11 dans le journal de
`wp-ols` (P-1, P-2, P-2 bis, P-3, P-4, P-5, P-6, puis P-8, P-8 bis, P-9 et le témoin de P-8 : une
par cas qui provoque l'erreur ; la page suivie après P-8 n'en ajoute aucune), 3 dans celui de
`wp-fpm` (P-7) ; aucune autre ligne (ni avertissement, ni notice) dans les deux journaux.

### Cas

| N° | Cas | Préparation | Requête | HTTP | Sortie utile | Conclusion |
| --- | --- | --- | --- | --- | --- | --- |
| P-1 | Page publique, gabarit de WordPress (`wp-ols`) | aucun `php-error.php` | `/?banc_fatale=public` | 500 (gabarit « WordPress › Error ») | un groupe, champs ci-dessus ; journal : `template-loader.php(23)` | CONFORME |
| P-2 | Administration, `admin_init` (`wp-ols`) | idem | `/wp-admin/admin-ajax.php?action=banc_essai&banc_fatale=admin` (voir l'écart ci-dessous) | 500 (« There has been a critical error on this website. ») | un groupe, mêmes champs ; journal : `admin-ajax.php(45): do_action()` | CONFORME |
| — | Requête du plan, sans `action` (`wp-ols`) | idem | `/wp-admin/admin-ajax.php?banc_fatale=admin` | 400, corps `0` | aucun groupe, **aucune erreur fatale au journal** : WordPress refuse la requête avant `admin_init` | sans objet (rien à relever) |
| P-2 bis | Complément : vraie page d'administration, administrateur connecté (`wp-ols`) | mené après P-5, `php-error.php` déjà retiré (`unlink`, préparation de P-6) ; cookie d'authentification de l'administrateur du banc, produit par WP-CLI (`wp_generate_auth_cookie`), non affiché | `/wp-admin/?banc_fatale=admin` | 500 (gabarit « WordPress › Error ») | un groupe, mêmes champs ; journal : `wp-admin/admin.php(180)` ; `recovery_mode_email_last_sent` posé à la même seconde (1793482811) : le mode de récupération a tenté d'envoyer son courriel (point d'entrée protégé), sans masquer l'erreur fatale | CONFORME |
| P-3 | REST, `rest_api_init` (`wp-ols`) | idem | `/wp-json/?banc_fatale=rest` | 500 (gabarit « WordPress › Error ») | un groupe, mêmes champs ; journal : `rest-api.php(657)` | CONFORME |
| P-4 | Gabarit `php-error.php` sans `exit` (`wp-ols`) | `wp eval 'file_put_contents( WP_CONTENT_DIR . "/php-error.php", "<?php echo \"Erreur du site.\";" ) or exit( 1 );'` | `/?banc_fatale=public` | 500, corps « Erreur du site. » | un groupe, mêmes champs | CONFORME |
| P-5 | Gabarit `php-error.php` qui finit par `exit` (`wp-ols`) | même écriture, avec `exit;` à la fin | `/?banc_fatale=public` | 500, corps « Erreur du site. » | **aucun groupe** (option absente) ; l'erreur fatale est bien au journal | LIMITE PRÉVUE |
| P-6 | Relevé coupé (`wp-ols`) | `php-error.php` retiré (`unlink`, avant P-2 bis), puis `php_errors_enabled` mis à faux (relu : `{"releve":false,"php_error_php":false}`) | `/?banc_fatale=public` | 500 (gabarit « WordPress › Error ») | aucun groupe ; `SELECT COUNT(*)` des options `g2rd_php_errors%` : **0** ; l'erreur fatale est au journal | CONFORME |
| P-7 / P-1 | Page publique (`wp-fpm`) | profil `wp-fpm` démarré, jeton `jeton-fpm`, `site_id` 990002, relevé allumé, extension jetable posée | `/?banc_fatale=public` | 500 (gabarit « WordPress › Error ») | un groupe, mêmes champs et même empreinte ; journal : `template-loader.php(23)` | CONFORME |
| P-7 / P-2 | Administration (`wp-fpm`) | idem | `/wp-admin/admin-ajax.php?action=banc_essai&banc_fatale=admin` | 500 (« There has been a critical error on this website. ») | un groupe, mêmes champs ; journal : `admin-ajax.php(45)` | CONFORME |
| P-7 / P-3 | REST (`wp-fpm`) | idem | `/wp-json/?banc_fatale=rest` | 500 (gabarit « WordPress › Error ») | un groupe, mêmes champs ; journal : `rest-api.php(657)` | CONFORME |
| P-8 | Session du mode de récupération active, vraie page d'administration, seconde extension (`wp-ols`) | relevé rallumé, aucun `php-error.php`, `zz-banc-fatale-2` posée et activée ; lien du courriel produit par WP-CLI (`WP_Recovery_Mode_Link_Service::generate_url()`, l'adresse même que porte le courriel), ouvert par `curl` comme l'administrateur qui clique : 302 vers `wp-login.php?action=entered_recovery_mode` et cookie `wordpress_rec_…` reçu ; cookie de connexion de l'administrateur produit par WP-CLI (`wp_generate_auth_cookie`) ; **ni le lien ni les cookies ne sont affichés ni écrits sur disque** (variables du script, passées au conteneur par l'entrée standard) | `/wp-admin/?banc_fatale2=admin`, avec les deux cookies | **302**, `Location` = la même adresse, corps vide | **aucun groupe** (option absente) ; l'erreur fatale est au journal (`zz-banc-fatale-2.php:16`) ; `zz-banc-fatale-2` en pause dans l'option `<session>_paused_extensions` ; la page suivie avec les mêmes cookies répond 200, sans erreur au journal, avec l'avis « One or more plugins failed to load properly » et le bandeau du mode de récupération | LIMITE PRÉVUE |
| P-8 bis | Même session, page publique, première extension (`wp-ols`) | cookie de récupération seul (aucune connexion n'est vérifiée pendant la session) | `/?banc_fatale=public` | **302**, `Location` = la même adresse, corps vide | **aucun groupe** ; l'erreur fatale est au journal (`zz-banc-fatale.php:16`) ; `zz-banc-fatale` en pause à son tour dans la même option | LIMITE PRÉVUE |
| Témoin de P-8 | Même requête que P-8, **sans** le cookie de récupération (`wp-ols`) | cookie de connexion de l'administrateur seul (nouveau, produit de même) ; l'option de pause de la session reste en base | `/wp-admin/?banc_fatale2=admin` | 500 (« There has been a critical error on this website. Please check your site admin email inbox for instructions. … ») | un groupe : `fatal` / `plugin` / `zz-banc-fatale-2`, `file` `wp-content/plugins/zz-banc-fatale-2/zz-banc-fatale-2.php`, `line` 16, `message` « Uncaught Error: Call to undefined function banc_fonction_absente_2() » (sans chemin du serveur ni pile), `count` 1, `source` `fatal_catcher`, `version` `null`, empreinte `b9835e06…9b16` ; `recovery_mode_email_last_sent` inchangé (1793482811, un courriel par jour au plus) : hors session, la pause ne joue pas et le relevé voit l'erreur | CONFORME |
| P-9 | Une extension demande l'arrêt de PHP à l'affichage de l'erreur (`wp-ols`) | aucun `php-error.php` ; `zz-banc-fatale-2` ajoute, sur `?banc_sortie=1`, un filtre `wp_php_error_args` qui met `'exit'` à vrai ; visiteur sans cookie | `/?banc_fatale=public&banc_sortie=1` | 500 (gabarit « WordPress › Error », « There has been a critical error on this website. ») | **aucun groupe** (option absente) ; l'erreur fatale est au journal (`zz-banc-fatale.php:16`) | LIMITE PRÉVUE |

### Décision (étape 5)

P-1 à P-4, P-7 et le témoin de P-8 ont un groupe chacun ; P-5, P-6, P-8, P-8 bis et P-9 n'en
ont pas : **conforme**. Le relevé à l'arrêt de PHP, troisième fonction d'arrêt de la requête, voit
l'erreur fatale après le gestionnaire de WordPress et l'action `shutdown`, sous OpenLiteSpeed comme
sous PHP-FPM, sur une page publique, en administration (requête AJAX et vraie page, administrateur
connecté) et en REST, tant que ni le gestionnaire ni le gabarit n'arrêtent PHP.

**Limites à décrire** (fiche E1, signification de `coverage: after_connector_load`, et readme,
tâche 16), toutes trois prouvées sur le banc. Dans chacune, l'erreur fatale reste dans le journal
PHP du site. Mots simples, réutilisables tels quels :

- **Gabarit d'erreur qui arrête PHP** (P-5) : « un gabarit `wp-content/php-error.php` qui finit
  par `exit` (ou `die`) empêche le relevé : l'erreur fatale n'est pas relevée ».
- **Mode de récupération** (P-8, P-8 bis) : « pendant une session du mode de récupération (lien
  du courriel ouvert), une nouvelle erreur fatale d'une extension ou du thème n'est pas relevée :
  WordPress la met en pause et recharge la page en arrêtant PHP ». Seules les pages ouvertes dans
  cette session (le navigateur qui a suivi le lien, pages publiques comprises) sont concernées :
  la même erreur chez un visiteur, ou hors de la session, est relevée (témoin de P-8). Pendant la
  session, WordPress signale l'extension en pause à l'administrateur (avis « One or more plugins
  failed to load properly »). La session dure jusqu'à ce que l'administrateur la quitte ou se
  déconnecte, une semaine au plus (`class-wp-recovery-mode-cookie-service.php` l.46,
  `class-wp-recovery-mode.php` l.95 : lu dans le code, non mené au banc).
- **Extension qui arrête PHP à l'affichage de l'erreur** (P-9) : « une extension qui demande
  l'arrêt de PHP à l'affichage de l'erreur (filtre `wp_php_error_args`) a le même effet qu'un
  gabarit d'erreur qui finit par `exit` ».

**Ce que cette preuve ne couvre pas** (contre-épreuve aux essais E-3 à E-5 de la tâche 18) :

- un vrai envoi de courriel par le mode de récupération : le conteneur n'a pas de `sendmail`, et
  l'envoi tenté en P-2 bis échoue sans avertissement PHP. Une extension d'envoi (SMTP, par
  exemple) qui lèverait un avertissement pendant cet envoi remplacerait l'erreur fatale dans
  `error_get_last()`, et rien ne serait relevé. En P-8, le lien vient de WP-CLI
  (`generate_url()`, la même fonction que le courriel), pas d'un courriel reçu ;
- un gestionnaire `wp_die` d'extension (filtre `wp_die_handler`) qui appelle `exit` : lu dans le
  code, non mené au banc, même effet que P-5 et P-9 ;
- une session du mode de récupération où les en-têtes sont déjà partis au moment de l'erreur
  (WordPress n'arrête alors pas PHP, l.192-194), et le multisite (le mode de récupération n'y est
  pas initialisé) : lus dans le code, non menés au banc ;
- P-8, P-8 bis et P-9 sous `wp-fpm` : menés sous `wp-ols` seulement (le code en cause est celui
  de WordPress, le même sous les deux serveurs) ;
- LiteSpeed Enterprise de l'hébergeur (le banc tourne sous OpenLiteSpeed).

### Écarts avec le plan

- **P-2, requête.** `wp-admin/admin-ajax.php` de WordPress 7.1.4 refuse une requête sans
  paramètre `action` (`wp_die( '0', 400 )`, l.31-32) **avant** `do_action( 'admin_init' )`
  (l.45) : la requête du plan reçoit 400 et l'erreur fatale n'a jamais lieu (ligne « Requête du
  plan, sans `action` » ci-dessus). La requête menée ajoute `action=banc_essai` (aucune action
  enregistrée sous ce nom : WordPress passe `admin_init`, où l'extension jetable lève l'erreur).
- **P-2 bis**, ajouté : la requête AJAX n'est pas un point d'entrée « protégé » du mode de
  récupération ; une vraie page d'administration l'est, et c'est là que WordPress tente
  d'envoyer son courriel (deuxième comportement signalé par le plan).
- **P-8, P-8 bis, P-9 et le témoin de P-8**, ajoutés à la relecture : la première lecture du
  gestionnaire d'erreurs fatales omettait l'appel à `handle_error()` et le filtre
  `wp_php_error_args`. Ils demandent une seconde extension jetable (`zz-banc-fatale-2`), pour
  qu'une **autre** extension que celle du premier courriel lève l'erreur pendant la session.
- **Projet Compose `g2rd-banc-c1`** : `compose.yaml` fixe `name: g2rd-wp-manager`, le projet de
  la plateforme locale ; lancé tel quel, le banc aurait partagé ce projet.
- **Journal PHP** lu dans `wp-content/debug.log` (et non dans `docker compose logs`, voir
  ci-dessus).
- **Étape 6 (remise à zéro du banc, retrait de la copie) non faite** : rien n'est supprimé sans
  l'accord du propriétaire. Les conteneurs du projet `g2rd-banc-c1` sont arrêtés
  (`docker compose … stop`, rien de perdu ; après le complément de la relecture, les deux
  conteneurs redémarrés ont été arrêtés de même par `docker stop`) ; restent ses quatre volumes
  (`g2rd-banc-c1_wordpress_ols_data`, `…_ols_db_data`, `…_fpm_data`, `…_fpm_db_data`, qui portent
  les extensions jetables, `zz-banc-fatale` active et `zz-banc-fatale-2` désactivée, le relevé
  coupé, le tampon remis à zéro, l'option de pause de la session de P-8, sans effet hors de cette
  session dont le cookie n'a existé qu'en mémoire, et les sessions de connexion ouvertes par les
  cookies produits par WP-CLI, valables une heure) et la copie détachée `banc-c1` du manager. Pour
  les retirer, depuis cette copie, avec `COMPOSE_PROJECT_NAME=g2rd-banc-c1` : la « Remise à zéro »
  du README du banc (par profil : `rm --stop --force` des services, puis `docker volume rm` des
  deux volumes), puis `git worktree remove .claude/worktrees/banc-c1` depuis le dépôt du manager.

## Essais sur le banc (tâche 18)

**Question.** Le connecteur de `integration/1.16`, livré éteint puis allumé par la commande signée,
fait-il ce que le contrat promet sur un vrai WordPress, sous les deux serveurs du banc : rien
d'envoyé ni d'écrit tant que le relevé est coupé, commande refusée sans signature, erreur fatale
relevée et filtrée pendant une mise à jour (par `/command` et en arrière-plan) sans changer l'issue
de la mise à jour, rafale bornée, lecture du journal confinée à WordPress et sans double compte,
bloc borné à 16 Ko sans coût mesurable, manager actuel (sans E1) qui synchronise sans erreur ?
C'est aussi la contre-épreuve de la preuve préalable (tâche 8 bis).

**Réponse : oui, les onze essais sont CONFORMES** (E-11 : E-1, E-3 et E-5 rejoués sous PHP-FPM).
Une limite de plus est constatée, hors des essais du plan : une erreur sans gravité levée
**après** l'erreur fatale par l'action `shutdown` d'une autre extension la cache au relevé (voir
« Limites constatées »). Aucun écart avec la preuve préalable.

### Conditions

| Élément | Valeur |
| --- | --- |
| Date | 06/11/2026, 18 h 44 à 19 h 17 UTC |
| Connecteur | branche `integration/1.16`, `4d15bfce10e3fe7b77d600265b1eeb969ab0dc10` (en-tête encore `Version: 1.15.0` : le numéro change à la publication), monté depuis la copie `connecteur-1-16` (`CONNECTOR_PATH`), sur le serveur comme dans WP-CLI |
| Banc | dépôt du manager, copie détachée `.claude/worktrees/banc-c1` (celle de la tâche 8 bis, gardée), placée sur `origin/main` = `4064cb23bc2b57ea2436621f02ca7af74f7794e4` (1.65.1) ; `tools/banc-rejeu/` et `compose.yaml` identiques à ceux de la 1.65.0 de la tâche 8 bis (`git diff --stat acb11e93 4064cb23` : aucun de ces fichiers) ; **rien n'y a été modifié** (`git status` : seul `tools/banc-rejeu/var/`, ignoré) |
| Projet Compose | `g2rd-banc-c1` (`COMPOSE_PROJECT_NAME`), comme à la tâche 8 bis ; aucun port publié |
| Profil `wp-ols` | WordPress 7.1.4, OpenLiteSpeed 1.8.5, lsphp 8.3.30 (`litespeedtech/openlitespeed:1.8.5-lsphp83`), MariaDB 10.11.19, mandataire `nginx:1.30.5-alpine` à 60 s ; WP-CLI `wordpress:cli-2.12.0-php8.3` (PHP 8.3.35) ; outil d'envoi `php:8.3.35-cli` |
| Profil `wp-fpm` | WordPress 7.1.4, nginx 1.30.5, PHP-FPM 8.3.35 (`wordpress:7.1.3-php8.3-fpm`), mêmes base, mandataire, WP-CLI et outil d'envoi |
| Plateforme locale (E-10) | backend du manager 1.65.1 construit depuis `banc-c1/backend` sous une étiquette propre (`g2rd-banc-c1-backend:1.65.1`, celle de la démo n'est pas réécrite), `mariadb:10.11`, `axllent/mailpit:v1.21`, même projet que le banc, aucun port publié (surcharge Compose hors dépôt : `image` du backend, `ports: !reset []` de la base et du courrier) |
| Réglages de WordPress du banc | `WP_DEBUG` et `WP_DEBUG_LOG` vrais, `WP_DEBUG_DISPLAY` faux : journal PHP dans `wp-content/debug.log` (sauf pendant E-8) ; politique de signature `report` |
| Site « enrôlé » | jeton du banc (`var/jeton-ols`, `var/jeton-fpm`, fichiers inchangés), `site_id` 990001 et 990002, `manager_url` = `http://plateforme.banc-rejeu.invalid` ; pendant E-10 seulement, `wp-ols` est enrôlé auprès de la plateforme locale, puis le jeton du banc lui est rendu |
| Envois | `bin/envoyer.php` dans `banc-outils`, par le mandataire (`http://ols.test`, `http://fpm.test`), signature v1 ; le jeton n'est affiché nulle part |
| Extensions jetables | dans les volumes du banc seulement : `zz-banc-fatale` (tâche 8 bis, gardée telle quelle : fonction absente sur `init` pour `?banc_fatale=1`), `zz-banc-avertissements` (E-7 : avertissement, dépréciation et notice sur `init` pour `?banc_avertissement=…`), `zz-banc-arret` (limites : avertissement sur l'action `shutdown` pour `?banc_avertissement_arret=1`) ; les deux dernières, posées par WP-CLI sous l'utilisateur du serveur, sont désactivées après usage (fichiers gardés) |

Sortie de chaque envoi lue par un petit script du poste (statut, durée, blocs du snapshot, taille du
bloc `php_errors` mesurée comme le connecteur : `json_encode()` aux drapeaux par défaut, comme
`wp_json_encode()` sans option ; recherche de `/var/`, `/tmp/`, `/usr/`, `/home/`, `/srv/` dans
`php_errors` et `php_logging`). Tampon lu par `wp option get g2rd_php_errors --format=json`,
comparé octet pour octet (`cmp`) avant et après quand rien ne doit changer.

### Essais

| N° | Essai | Action | Sortie utile | Conclusion |
| --- | --- | --- | --- | --- |
| E-1 | Connecteur 1.16 éteint (`wp-ols`) | `GET /g2rd/v1/snapshot` (relevé coupé, comme livré) | `200` en 0,97 s ; clés `…, pending_outcomes, environment, php_errors, php_logging` ; `capabilities` = `signed_commands, direct_login, php_errors, restore_points, async_commands` ; `php_errors` **identique** à l'exemple `collecte_desactivee` du contrat (110 octets) ; `php_logging` = `{"version":1,"log_errors":true,"display_errors":false,"wp_debug":true,"wp_debug_log":true,"wp_debug_display":false,"error_log":"inside_wordpress","error_log_readable":true,"error_log_bytes":9335}`, clés du contrat, aucun chemin ; `environment` aux clés et à l'ordre de la fixture v1 (`php_sapi` `litespeed`) ; aucun chemin du serveur dans le snapshot | CONFORME |
| E-2 | Activation signée, puis sans signature (`wp-ols`) | `POST /g2rd/v1/command` `set_php_errors_capture` `{"capture":true,"log_tail":false}` signé ; puis la même par `curl` depuis `banc-outils`, jeton seul dans `Authorization` ; puis, sans signature, `{"capture":false,"log_tail":false}` | signée : `200`, `{"php_errors_enabled":true,"php_errors_log_tail":false,"log_file":"inside_wordpress"}`, `signature_check` `ok`, relevé armé (`FatalCatcher::enabled()` vrai) ; sans signature, les deux : `401` `g2rd_connector_signature_missing` (« Signature de la requête absente ou invalide. »), `X-G2RD-Signature-Check: absent`, relevé **toujours activé** (la coupure non signée n'a rien changé), sous la politique `report` | CONFORME |
| E-3 | Erreur fatale pendant une mise à jour par `/command` (`wp-ols`) | `scenario.sh preparer ols` (banc-extension 1.0) ; `wp option update g2rd_banc_fatale 1` ; `POST /g2rd/v1/command` `update_plugin` `{"file":"banc-extension/banc-extension.php","snapshot":true}` (charge de S5), relevé activé ; puis tout rejoué relevé coupé (la 1.15 ne relève rien) | relevé activé : `500` (`internal_server_error`, « There has been a critical error on this website. ») en 2,8 s ; banc-extension remise en 1.0 et active ; 1 démarrage, 1 installation ; **un groupe** `{"fp":"1c6940b3…ff1b","level":"fatal","component":{"type":"mu","slug":"g2rd-banc","version":null},"file":"wp-content/mu-plugins/g2rd-banc.php","line":172,"message":"Erreur fatale volontaire du banc (option g2rd_banc_fatale) pendant la mise à jour de banc-extension.","count":1}` (`source` `fatal_catcher`), sans `/var/www` ; `debug.log` garde la ligne complète (`… in /var/www/vhosts/wordpress/html/wp-content/mu-plugins/g2rd-banc.php on line 172`) ; snapshot : `sources` `["fatal_catcher"]`, 579 octets. Relevé coupé : même réponse `500` (3,7 s), même 1.0 active, mêmes 1 démarrage et 1 installation, erreur au journal, **tampon inchangé** (octet pour octet) : issue identique | CONFORME |
| E-4 | Même chose en arrière-plan (`/command-runs`, `wp-ols`) | `bash tools/banc-rejeu/bin/scenario.sh s5 ols`, relevé activé, puis relevé coupé | les deux : `S5 (wp-ols) : CONFORME — filets passés dans l'ordre : exécution « interrupted » avec la reprise rattachée, extension remise.`, les cinq critères `OK` ; acceptation `202` en 0,26 s et 0,19 s ; cause `fatal_error` ; `interruption.error` : « La commande s'est arrêtée sur une erreur fatale de PHP avant d'avoir rendu son résultat : […] Erreur : Erreur fatale volontaire du banc (option g2rd_banc_fatale) pendant la mise à jour de banc-extension. (wp-content/mu-plugins/g2rd-banc.php, ligne 172) » : **aucun chemin du serveur** ; reprise rattachée (`recovered_rolled_back`) ; relevé activé : le groupe d'E-3 passe à `count` 2 (2 min 32 s après la première écriture) ; relevé coupé : tampon inchangé (octet pour octet) | CONFORME |
| E-5 | Rafale (`wp-ols`) | relevé activé ; 10 requêtes de la page d'accueil (`/?banc_mesure=n`), puis 120 requêtes `http://ols.test/?banc_fatale=1`, une par seconde, de 18:54:13 à 18:56:12, puis 10 requêtes de la page d'accueil ; référence : 10 requêtes relevé coupé | 120 réponses `500`, 120 lignes « PHP Fatal error » de plus au journal ; **un groupe** `fatal` / `plugin` / `zz-banc-fatale`, empreinte `8712ca1c…c8d2` (celle de la tâche 8 bis), `count` **2** (écritures à 18:54:13 et 18:55:13 ; la dernière requête, à 18:56:12, tombe 59 s après la seconde) ; médianes des requêtes sans erreur : 93,7 ms (relevé coupé), **97,3 ms avant** et **100,1 ms après** la rafale (relevé activé) | CONFORME |
| E-6 | Collecte coupée (`wp-ols`) | `set_php_errors_capture` `{"capture":false}` ; `?banc_fatale=1` à 18:57:01 (plus d'une minute après la dernière écriture : le relevé aurait écrit) ; `wp option get g2rd_php_errors --format=json` avant et après ; snapshot | réponse `500`, erreur au journal ; option **inchangée** (octet pour octet) ; `php_errors` = « collecte coupée », `groups: []`, 110 octets, alors que le tampon garde deux groupes | CONFORME |
| E-7 | Journal dans WordPress (`wp-ols`) | `wp config set WP_DEBUG true --raw && wp config set WP_DEBUG_LOG true --raw` ; `zz-banc-avertissements` activée, 3 requêtes `?banc_avertissement=n` ; `set_php_errors_capture` `{"capture":true,"log_tail":true}` ; `wp cron event run g2rd_connector_restore_points_purge` ; snapshot ; même tâche une seconde fois | commande : `log_file: inside_wordpress` ; journal de 126 780 octets lu en entier (moins de 256 Ko, `truncated_at` null) ; décalage enregistré `{"offset":126780,"head":"682ccd7a…","at":1793991498}` (empreinte du fichier, jamais son chemin) ; snapshot : `sources` `["fatal_catcher","log_tail"]`, 2 659 octets, six groupes du plus récent au plus ancien : `warning` « Undefined array key "banc_cle_absente" », `deprecated` et `notice` de `zz-banc-avertissements` (`count` 3 chacun, `file` relatif, ligne 11, 12, 13), les deux groupes `fatal` du relevé **inchangés** (`count` 2 et 2 : leurs 130 et 4 lignes de journal ne sont pas recomptées), et `fatal` / `zz-banc-fatale-2`, `count` 2, vu le 31/10 (lignes de la tâche 8 bis), venu du journal avec l'empreinte `b9835e06…9b16` que le relevé lui avait donnée à la tâche 8 bis ; second passage : décalage inchangé (seul `at` avance), **tampon inchangé** (octet pour octet) | CONFORME |
| E-8 | Journal hors de WordPress (`wp-ols`) | `wp config set WP_DEBUG_LOG /tmp/hors.log` (4 s d'attente pour OPcache) ; `?banc_avertissement=8` (écrit 607 octets dans `/tmp/hors.log` du conteneur) ; `LogTail::run( time() )` par `wp eval`, puis la tâche biquotidienne ; snapshot ; puis `wp config set WP_DEBUG_LOG true --raw` ; requête de contrôle `?banc_avertissement` à 19:00:13 après le rétablissement | `LogTail::run()` : `no_log` ; décalage et tampon **inchangés** (octet pour octet) ; `php_logging` : `"error_log":"outside_wordpress","error_log_readable":null,"error_log_bytes":null`, aucun chemin ; `sources` `["fatal_catcher"]` ; rétabli : `inside_wordpress`, `sources` de nouveau `["fatal_catcher","log_tail"]` | CONFORME |
| E-9 | Taille et durée du snapshot (`wp-ols`) | `wp eval-file` : `Buffer::record_many()` avec 50 empreintes distinctes, messages de 480 caractères ; puis 10 snapshots relevé coupé et 10 relevé activé (journal compris) | `record_many` vrai ; le tampon garde 35 groupes (32 206 octets sérialisés, borne de 32 Ko), `truncated_at` posé, option non autochargée (`autoload` `off`) ; relevé activé : bloc `php_errors` de **16 037 octets** (≤ 16 384), 19 groupes, `truncated: true`, les dix fois ; relevé coupé : 110 octets ; médianes de `duree_s` : **653 ms** (coupé) et **639 ms** (activé), écart −14 ms (< 50 ms) ; durées en deux paliers (0,4 à 0,8 s, ou 1,7 à 2,4 s) dans les deux séries | CONFORME |
| E-10 | Manager sans E1 (`wp-ols`) | plateforme locale 1.65.1 (voir « Conditions »), compte de la démo ; site `http://ols.test` créé et invitation générée par l'API (mêmes appels que l'interface) ; enrôlement par WP-CLI (`ManagerClient::enroll()`, invitation passée par l'entrée standard, jamais affichée ni écrite) ; « Synchroniser » (`POST /api/sites/11/sync`) ; site relevé et journal **activés**, 35 groupes au tampon (bloc de 16 Ko envoyé) | enrôlé (`site_id` 11) ; synchronisation `200` en 1,31 s, `syncRun` `success`, `errorMessage` null ; fiche : `connectionStatus` `connected`, `lastSyncError` null, `hosting` remonté (`phpSapi` `litespeed`, extensions, mémoire, disque, tailles, `reportedAt` de la synchronisation) ; en base, `connector_capabilities` garde la liste telle quelle (`php_errors` comprise, sans effet) ; mandataire : un `GET /wp-json/g2rd/v1/snapshot` `200` en 0,80 s ; journal du backend (`var/log/dev.log`) : aucune ligne `WARNING`, `ERROR` ni `CRITICAL` ; blocs `php_errors` et `php_logging` ignorés | CONFORME |
| E-11 / E-1 | Connecteur éteint (`wp-fpm`) | relevé coupé par `set_php_errors_capture` `{"capture":false}` (il était resté allumé depuis la tâche 8 bis, posé par WP-CLI) ; snapshot | mêmes clés et capacités ; `php_errors` identique à `collecte_desactivee` (110 octets) ; `php_logging` `inside_wordpress`, aucun chemin ; `environment` aux clés de la fixture (`php_sapi` `fpm-fcgi`) | CONFORME |
| E-11 / E-3 | Erreur fatale pendant une mise à jour par `/command` (`wp-fpm`) | comme E-3 | relevé activé : `500` en 1,6 s, 1.0 active, 1 démarrage, 1 installation, un groupe `fatal` / `mu` / `g2rd-banc`, `wp-content/mu-plugins/g2rd-banc.php`, **même empreinte** `1c6940b3…ff1b` que sous OpenLiteSpeed (chemins du serveur différents : `/var/www/html/…`) ; relevé coupé : même issue, tampon inchangé (octet pour octet) | CONFORME |
| E-11 / E-5 | Rafale (`wp-fpm`) | comme E-5, depuis le conteneur PHP-FPM ; puis mesures alternées (voir ci-dessous) | 120 réponses `500` (19:13:11 à 19:15:10 ; la dernière requête tombe 59 s après la seconde écriture) ; un groupe `zz-banc-fatale`, même empreinte, `count` **2** ; médianes : 88,6 ms (coupé), 78,7 ms avant, 118,4 ms après la rafale ; contre-mesure alternée, trois tours de 10 requêtes coupé puis 10 activé : **168,9 ms coupé**, **164,9 ms activé** | CONFORME (voir la note) |

**Note sur E-11 / E-5.** L'écart avant / après (+40 ms) ne vient pas du relevé : après les deux
séries relevé activé qui suivent la rafale (118,4 ms chacune), 10 requêtes relevé coupé donnent
97,1 ms, puis 10 relevé activé 97,8 ms ; la contre-mesure alternée (30 requêtes de chaque, relevé
basculé entre chaque série de 10) ne montre aucune différence (−4 ms). Le niveau général du
banc a dérivé pendant ces mesures (de 80 à 165 ms environ, relevé
coupé comme activé) : le processeur du poste était chargé à 71 % par d'autres travaux. Une
requête sans erreur ne fait, pour le relevé, qu'une lecture de réglage déjà en mémoire et un
`error_get_last()` à l'arrêt.

### Limites constatées

- **Une erreur sans gravité levée après l'erreur fatale cache celle-ci au relevé.** Essai mené sous
  `wp-ols`, relevé et journal activés : `zz-banc-arret` lève un avertissement (`E_USER_WARNING`)
  sur l'action `shutdown`. `/?banc_fatale=1&banc_avertissement_arret=1` : `500`, journal
  `PHP Fatal error: … banc_fonction_absente()` puis `PHP Warning: Avertissement voulu du banc sur
  l action shutdown …`, à la même seconde ; **tampon inchangé** (octet pour octet) : rien n'est
  relevé. Témoin, 12 s plus tard, `/?banc_fatale=1` seul : groupe `zz-banc-fatale` écrit (`count`
  1). Cause : WordPress lance l'action `shutdown` par sa propre fonction d'arrêt
  (`shutdown_action_hook`), enregistrée avant celle du connecteur ; l'avertissement y remplace
  l'erreur fatale dans `error_get_last()`, que `FatalCatcher` lit ensuite. Seule la lecture du
  journal, quand elle est activée, retrouve alors l'erreur fatale (ligne gardée au journal). Le
  même effet est attendu de toute notice, dépréciation ou avertissement levé à ce moment, par une
  extension ou par WordPress (l'envoi du courriel du mode de récupération, signalé à la tâche
  8 bis, en est un cas). Cette limite n'est ni dans le readme (tâche 16) ni dans la fiche E1 :
  remise au lead dev.
- **Une erreur fatale vue par le relevé garde le compte du relevé, même pour ses occurrences que le
  relevé n'a pas écrites.** En E-7, aucune des 130 lignes de journal de `zz-banc-fatale` (9 de la
  tâche 8 bis, les 120 de la rafale dont 118 non écrites par la borne d'une écriture par minute,
  celle d'E-6, relevé coupé) n'est ajoutée à son groupe (`count` 2, `last_seen` 18:55:13 alors que
  le journal a une ligne à 18:57:01). C'est la décision n° 13 (aucun double compte, borne basse vraie) ; le
  contrat (`count` borne basse) le couvre, `last_seen` peut donc être en retard sur le journal.
- **Contre-épreuve de la tâche 8 bis** : la page publique (E-5, sous les deux
  serveurs ; E-6 montre que rien n'est écrit relevé coupé), la mise à jour par `/command` (E-3,
  sous les deux serveurs) et l'exécution en arrière-plan (E-4, sous `wp-ols` seulement, comme le
  prévoit le plan) relèvent l'erreur fatale ; les cas sans groupe de la tâche 8 bis (gabarit `php-error.php` qui finit par `exit`,
  mode de récupération, `wp_php_error_args`) n'ont pas été rejoués ici.

### Écarts avec le plan

- **Copie du banc** : `.claude/worktrees/banc-c1` existait déjà (gardée par la tâche 8 bis, rien
  n'est supprimé) ; elle a été reprise telle quelle, sans être recréée, et sa tête détachée placée
  sur `origin/main` (`acb11e93` → `4064cb23`, aucun fichier du banc changé), pour que la plateforme
  d'E-10 soit la version actuelle du manager. Mêmes volumes et même installation de WordPress que
  la tâche 8 bis (journal PHP et extensions jetables compris).
- **E-2** : la requête non signée part de `banc-outils` par `curl`, jeton lu dans `var/jeton-ols`
  sans être affiché ; une seconde requête non signée, qui tente de **couper** le relevé, est
  ajoutée : la première ne pouvait pas montrer un réglage changé, puisqu'elle demandait la valeur
  déjà posée.
- **E-3 et E-4** : « connecteur 1.15 » = le même essai relevé coupé, comme le plan le précise ; la
  mise à jour par `/command` reprend la charge de S5 (`snapshot: true`).
- **E-5** : l'extension `zz-banc-fatale` de la tâche 8 bis sert telle quelle (erreur sur `init`
  pour `?banc_fatale=1`, et non au chargement du fichier) ; les 120 requêtes partent du conteneur
  du serveur, par le mandataire, une par seconde.
- **E-7** : le journal du banc ne portait aucun avertissement ; l'extension jetable
  `zz-banc-avertissements` en écrit, par le serveur lui-même (3 requêtes) ; la première lecture
  part de la fin du journal (256 Ko au plus) et lit donc aussi les lignes de la tâche 8 bis.
- **E-8** : un appel direct à `LogTail::run()` est ajouté pour lire l'issue (`no_log`).
- **E-11** : le relevé du site `wp-fpm`, resté allumé depuis la tâche 8 bis, est d'abord coupé
  par la commande signée.
- **Limites** : l'essai de l'action `shutdown` (extension jetable `zz-banc-arret`) est ajouté pour
  répondre à la question du plan.
- **Étape 3 (remise à zéro du banc, retrait de la copie) non faite** : rien n'est supprimé sans
  l'accord du propriétaire. Tous les conteneurs du projet `g2rd-banc-c1` sont arrêtés
  (`docker compose … stop`, rien de perdu). Restent ses sept volumes : les quatre des deux profils
  (WordPress 7.1.4, extensions jetables désactivées sauf `zz-banc-fatale`, relevé et journal
  **coupés** sur les deux sites, jeton du banc en place, tampon de `wp-ols` rempli par E-9, jamais
  envoyé relevé coupé et purgé à 7 jours, `WP_DEBUG_LOG` rétabli à `true`) et les trois de la
  plateforme locale (`g2rd-banc-c1_database_data`, `…_backend_var`, `…_backend_jwt` : compte de
  la démo, site 11 `http://ols.test`, qui n'est plus enrôlé côté site) ; l'image
  `g2rd-banc-c1-backend:1.65.1` ; la copie détachée `banc-c1`. Pour les retirer : la « Remise à
  zéro » du README du banc (profils), les trois volumes et l'image de la plateforme, puis
  `git worktree remove .claude/worktrees/banc-c1` depuis le dépôt du manager.
- **Autres restes du banc** : dans le `wp-config.php` de `wp-ols`, la définition de repli de
  `WP_DEBUG` (protégée par `defined()`) est passée à `true` par E-7, sans effet sur la valeur
  effective ; `debug.log` porte, après le décalage enregistré, 3 lignes de `zz-banc-avertissements`
  (19:00:13, contrôle après E-8) et les 3 lignes de l'essai des limites, non encore lues.
