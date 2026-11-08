# Essais du module de sauvegarde sur le banc : PHP-FPM puis OpenLiteSpeed (tâche 37, phase 4)

Date : 08/11/2026. Aucune clé, aucun jeton, aucune URL signée n'est recopiée dans ce dossier : seuls
figurent les codes de retour, les durées, les empreintes publiques et les sommes de contrôle des
fichiers d'essai (hasards sans valeur).

## Versions

| Élément | Valeur |
| --- | --- |
| Connecteur | `git log -1 --format=%h` de la copie du lot 4-0 avant ce dossier : `9b0fc89` (branche `ajout/sauvegardes-module`, en-tête `1.16.0-rc.1`) |
| Banc et pilote (plateforme, branche `ajout/sauvegardes-module`) | `de5bdbd5` (pilote, tâche 36), `e03f7b1a` (lecture du manifeste, corps de la tranche suivante gardé), `9fb06ede` (crochet de lenteur après le filet du connecteur, constante sans `GET_LOCK`, page du panneau React) |
| Émulateur de stockage | Ceph RGW v20.2.4, `quay.io/ceph/ceph@sha256:6bb1c8a42fbc0bf87938946990b65174466997bc11c31eb5a323225a779fd8f9` (voir « Incident du stockage ») |
| Serveurs | PHP-FPM 8.3 derrière nginx (`fpm`) ; OpenLiteSpeed et lsphp, mêmes réglages que l'hébergeur (`ols`) ; MariaDB 10.11.19 ; WordPress 7.1.4 |

## Montage du connecteur dans le banc

Le connecteur est monté dans le banc depuis **une copie au format du zip publié** (mêmes exclusions
que `build-zip.ps1` : `.git`, `.github`, `node_modules`, `vendor`, `tests`, caches), 170 fichiers, et
non depuis la copie de travail. Raison mesurée : le dossier de travail pèse 56 000 fichiers (dont 51 173
de `node_modules`), lus par le montage 9p de Docker Desktop à 33 fichiers par seconde ; le premier
passage de E1 sur `fpm` avec cet arbre (`mesures-brutes/e01-fpm-premier-bon.txt`) a duré **2 469 s**
(126 segments, 130 tranches de 20 s, 67 544 fichiers, résultat exact), contre **32 s** avec l'arbre du
zip (4 610 entrées). Le temps était dans la lecture de petits fichiers à travers le montage, pas dans
le module : il ne se retrouvera pas sur un hébergement sans montage réseau, mais il dit ce que coûte
chaque fichier sur un disque lent (environ 30 ms) et pourquoi le nombre de fichiers compte autant que
leur volume.

## Contenu du site du banc

`wp-content/uploads/banc/` : un fichier de 150 Mio (réparti sur trois segments), un fichier de 20 Mio
dont **le nom n'est pas de l'UTF-8** (`\xe9t\xe9.bin`), un fichier de 5 Mio au nom UTF-8, 125 fichiers
de 1 Mio, un fichier de **bourrage de 77 Mio** (`lot/zz-bourrage.bin`, ajouté pour que le fichier de
20 Mio soit bien réparti sur **deux** segments : sans lui il tombait en entier dans un seul, car les
segments se coupent à 64 Mio écrits), un lien vers `wp-content/plugins` et un lien vers `/etc`. Base :
`wp_banc_lignes` (20 000 lignes : BLOB, texte avec `'`, `\`, NUL, NULL), `wp_banc_gros` (300 lignes de
2 Mio, 564 732 000 caractères), et l'installation voisine `wp_new_site_*` (trois tables, une ligne
chacune). `banc-extension` remise en 1.0 avant chaque essai qui la met à jour.

## Écarts avec le plan, et leur sort

| Écart | Cause | Sort |
| --- | --- | --- |
| Le pilote s'arrêtait sur la relecture du manifeste (`JSONDecodeError`) | `relire_charge.py` coupait chaque enregistrement `manifest` en lignes : une ligne peut tenir sur deux enregistrements (`offset` = position dans le manifeste entier, `docs/backup-payload.md` § 5), et `str.splitlines()` coupe aussi à U+0085 et U+2028 | Corrigé dans le banc, tests d'abord (`tools/banc-sauvegardes/tests/relire_charge_test.py`, échec puis réussite), commit plateforme `e03f7b1a` |
| `--reprendre` rejoue le départ ; le corps de la tranche suivante n'était pas gardé | Le plan prévoit un corps « tiré de `var/<id>/pilote.json »`, qui ne porte que les envois ouverts | `--arreter-apres=N` écrit aussi `var/<id>/prochaine-tranche.json` (envois présignés compris, ignoré par git), commit `e03f7b1a` |
| E17 : la sauvegarde commencée pendant la mise à jour n'était pas interrompue à la fin | Artefact du banc : le crochet de lenteur de `g2rd-banc.php` (priorité 1, enregistré avant le connecteur) attendait **avant** `UpdateWatcher::on_upgrade_start` (priorité 1) : le début de la mise à jour était noté à la fin de l'attente. Le connecteur est correct | Crochet du banc passé à la priorité 2, commit plateforme `9fb06ede` ; essai rejoué depuis le début (relevés d'avant : `mesures-brutes` n'en garde pas, voir E17) |
| E14 : `backup_lock_unavailable` attendu pour un verrou tenu à la main | Le code distingue exprès `BUSY` (verrou tenu : `backup_slice_running`, `retry_after` 25) et `UNAVAILABLE` (`GET_LOCK` refusé ou NULL : `backup_lock_unavailable`, 30 s) ; une session `GET_LOCK('<nom>', 0)` donne `BUSY` | Les deux cas sont joués : verrou tenu à la main (`backup_slice_running`) et hébergeur sans `GET_LOCK` (constante `G2RD_BANC_SANS_VERROU` du nouveau mu-plugin du banc, `backup_lock_unavailable`, `retry_after` 30), commit `9fb06ede` |
| E6 / E23 : « une sur `/command-runs` » : 409 `backup_slice_running` attendu | Le 409 à l'acceptation ne vaut que pour les **mises à jour** (`UpdateGate::COMMANDS`, `CommandRunsController::accept()`) ; un `backup_step` accepté par `/command-runs` pendant une tranche passée par `/command` est accepté (202) puis refusé à l'exécution (`failed`, `backup_slice_running`, `retry_after` 23, jamais exécuté) | Joué avec une mise à jour (409 `g2rd_connector_backup_slice_running`, `retry_after` 24) **et** avec un `backup_step` (202 puis `failed`) |
| E13 : le second envoi devait recevoir `backup_slice_running` ou le même rapport | Le rejeu du mandataire arrive avec le même nonce : le connecteur le rejette avant tout (401 `signature_replayed`) | Conforme à l'intention (la tranche n'est **jamais** jouée deux fois : `segments_written` 2 après la tranche) ; à lire avec la note CDN déjà connue |
| E15 : « aucune requête `GET_LOCK` » | Un `GET_LOCK('g2rd_sig_…')` est émis par la mesure de signature du connecteur, sans rapport avec le module ; aucun `GET_LOCK('g2rd_backup_…')`, aucune écriture `g2rd_backup_*`, deux lectures de `g2rd_backup_state` | Relevé tel quel |
| E15 : commandes `backup_*` « refusées `backup_disabled` » | `backup_start` et `backup_set_key` : `backup_disabled` ; `backup_step` sans sauvegarde en cours : `backup_unknown` ; `backup_abort` : accepté (rien à arrêter) ; `backup_step` d'une sauvegarde en cours case décochée : `backup_disabled`, sauvegarde close `aborted` | Relevé tel quel |
| E18 : la connexion « en IPv4 » ne se distingue pas d'une connexion sans réglage | Le réseau du banc est IPv4 seulement | Relevé : toutes les connexions du site vers `stockage:9000` sont dans `/proc/net/tcp` (IPv4), aucune dans `tcp6` ; le réglage ne casse rien. La preuve que `CURLOPT_IPRESOLVE` est posé reste celle des tests unitaires |
| E21 : `wp plugin update` met le site en maintenance | WP-CLI passe par `bulk_upgrade` : `.maintenance` dès le début, tout le REST répond 503 « Briefly unavailable », donc `backup_start` n'atteint pas le connecteur | Joué avec WP-CLI (la tranche en vol finit `backup_interrupted`, témoin « sans verrou » posé, vide à la fin), puis avec `Plugin_Upgrader::upgrade()` lancé par `wp eval` (chemin de l'administration, sans maintenance) pour voir `backup_start` refusé `update_in_progress` ; relevés de la première variante : `mesures-brutes` ne les garde pas, la ligne E21 les résume |
| E22 : `.htaccess` du dossier de travail sans effet sous `ols` | Le vhost du banc ne lit le `.htaccess` que pour les réécritures (`autoLoadHtaccess`), pas pour le contrôle d'accès | Les chemins devinables répondent 404 et la racine n'est pas listée comme prévu ; avec le nom privé connu, le journal et l'état se lisent sur `fpm` **et** `ols` (le nom est aléatoire, absent de toute réponse). Sur l'hébergeur (LiteSpeed qui lit `.htaccess`) la règle est à vérifier : non faite ici (aucune action sur la production) |
| E2 : `wp_options` | Le site continue de vivre : `cron`, les transients, l'état des signatures et les réponses `wporg` changent entre la copie et la comparaison ; `g2rd_backup_*` n'est jamais dans le dump | `CHECKSUM` de `wp_options` comparé des deux côtés **sans** ces lignes (132 lignes comparées de chaque côté) ; les 13 autres tables : sommes égales sans filtre |
| E12 sous `ols` | LiteSpeed rejoue la requête quand lsphp meurt : le client reçoit un 401 `signature_replayed`, pas un 502 | Relevé tel quel ; tranche suivante acceptée, sauvegarde terminée, relecture exacte |
| Stockage plein | Voir ci-dessous | Nouvel émulateur, rien de supprimé |

## Incident du stockage

La première grappe de l'émulateur (OSD de 10 Gio) s'est remplie à 95 % après une trentaine de
sauvegardes complètes (objets verrouillés 8 jours, voulu) : les envois de parties ont échoué en
`network` (HTTP 5xx de RGW) pendant la reprise de E12 sous `ols` (relevé : `ceph -s`,
`HEALTH_ERR 1 full osd(s)`). Rien n'a été supprimé : l'ancien conteneur est arrêté avec son volume, un
nouvel émulateur a été démarré dans un autre projet compose (`g2rd-p4s`, volume neuf) et relié au réseau
du banc sous l'alias `stockage` ; E12 `ols` a été rejoué depuis le début avec lui. Les identités de
l'ancien émulateur sont gardées hors dépôt. À retenir pour la suite : environ 25 sauvegardes complètes
du banc par émulateur de 10 Gio.

## Résultats

« fpm » et « ols » : relevé complet dans `eNN-fpm.txt` et `eNN-ols.txt`.

| N° | Obtenu |
| --- | --- |
| E1 | **fpm** : `OK sauvegarde`, 9 segments, 12 tranches, 32 s ; `OK arbre reconstruit identique au site : 4080 fichiers` (nom non UTF-8 compris, réparti sur 2 segments ; `gros-150.bin` sur 3) ; manifeste : 4 080 fichiers, 529 dossiers, 2 liens (`lien-etc`, `lien-plugins`, jamais suivis : aucun fichier dessous) = `totals.files` 4 611 ; `g2rd-backups`, `g2rd-snapshots`, `wp-content/cache` absents ; 14 tables = `SHOW TABLES LIKE 'wp\_%'` moins `wp_new_site_*`. **ols** : même résultat sur `/command` et avec `--route=async` : les 12 comptes rendus relus sous la borne de 64 Kio, 17 exécutions `done`, aucune `interrupted` |
| E2 | **fpm** et **ols** : dump de 606 081 394 octets, import 30 s ; 13 tables aux sommes égales, `wp_banc_lignes` et `wp_banc_gros` comprises, `wp_options` égale hors lignes vivantes ; 0 ligne `g2rd_backup_%` restaurée (3 sur le site) ; 0 table `wp_new_site_*` ; tête : `SET NAMES utf8mb4` (jeu de la connexion du banc) |
| E3 | **fpm** et **ols** : 0 enregistrement `file`, `scope` `db`, 14 tables ; 3 tranches de copie (7 s, puis 0,1 s) et une de clôture |
| E4 | **fpm**, **ols**, **ols async** : exactement les 4 fichiers changés, SHA-256 égaux à ceux du site, `deleted` = le fichier supprimé, rien d'autre ; `downgraded` nul (la ligne « complète : … » n'apparaît pas) ; `OK arbre reconstruit identique au site` (complète + incrémentale) |
| E5 | **fpm** et **ols** : `type full (complète : baseline_other_backup)`, sauvegarde complète réussie |
| E6 | **fpm** et **ols** : `/command-runs` × 2 : 202 et 409 `site_busy` ; `/command` puis mise à jour sur `/command-runs` : 409 `g2rd_connector_backup_slice_running`, `data.retry_after` 24 ; `/command` puis `backup_step` sur `/command-runs` : 202 puis `failed` `backup_slice_running` (`retry_after` 23) ; `/command` × 2 : la seconde `failed`, `error_code` `backup_slice_running`, `retry_after` 24 ; `--reprendre` termine la sauvegarde, relecture exacte |
| E7 | **fpm** et **ols** : `update_plugin` pendant la tranche : `failed`, `backup_slice_running`, `retry_after` 24 ; extension restée en 1.0 ; rejouée après la tranche : réussie (1.0 → 1.1) |
| E8 | **fpm** et **ols** (suite de E7, mise à jour entre deux tranches en phase files) : tranche suivante `failed` `backup_interrupted`, dernier état `aborted` / `interrupted_by_update`, état `idle` ; nouvelle sauvegarde sous un nouvel identifiant, relecture exacte |
| E9 | **fpm** et **ols** : mise à jour réussie en phase `upload`, la sauvegarde se termine ; seul écart avec le site actuel : `banc-extension.php` (copie d'avant la mise à jour) |
| E10 | **fpm** et **ols**, sur `/command` puis `/command-runs` : mise à jour lente de 41 s ; `backup_start` pendant elle : `failed` `update_in_progress`, `retry_after` 60 ; accepté après ; `update_theme` (reason `upgrade_failed` : thème sans mise à jour et WordPress hors ligne), `update_core` et `update_translations` (`no_updates_available`) : `done`, état de sauvegarde inchangé |
| E11 | **fpm** et **ols** : `update_plugin` (`/command`) et `update_theme` (`/command-runs`) passent tous deux ; le témoin a compté **2 entrées** en même temps (`update_plugin` tenant le verrou, `update_theme` « sans verrou ») |
| E12 | **fpm** (kill -9 du worker php-fpm : 502 côté client) et **ols** (kill -9 de lsphp : 401 `signature_replayed`) : verrou rendu, tranche suivante acceptée aussitôt (13 s : la copie de la base refaite), aucun `.part` restant, sauvegarde terminée, `OK arbre reconstruit identique au site` |
| E13 | **fpm** et **ols** (mandataire à **8 s**, le plan disait 15 s : une tranche de base dure ici 13 s, 15 s ne coupait rien) : le second envoi reçoit 401 `signature_replayed` ; tranche jouée **une seule fois** (`segments_written` 2), reprise exacte |
| E14 | **fpm** et **ols** : verrou tenu à la main : `backup_start` et `update_plugin` `backup_slice_running` (`retry_after` 25) ; sans `GET_LOCK` : `backup_start` `backup_lock_unavailable`, `retry_after` 30 ; case décochée et verrou tenu : `update_plugin` passe (1.0 → 1.1) |
| E15 | **fpm** et **ols** : journal général de MariaDB pendant `update_plugin` : 63 requêtes, 0 `GET_LOCK('g2rd_backup_…')` (un `g2rd_sig_…` d'un autre module), 0 écriture `g2rd_backup_*`, 2 lectures de `g2rd_backup_state` ; `encrypted_backup` absent ; `backup_start` et `backup_set_key` `backup_disabled` ; case décochée en pleine sauvegarde : `backup_disabled`, close `aborted` |
| E16 | **fpm** et **ols** : les quatre commandes `backup_*` de la file : `failed`, `backup_queue_unsupported`, sans `retry_after`, état inchangé (chemin `HeartbeatJob::queue_outcome` puis `result_of`, appelé par `wp eval` : le banc n'a pas de plateforme pour remplir la file) |
| E17 | **fpm** et **ols** (après correction du crochet du banc) : `backup_start` accepté pendant la mise à jour ; copie finie avant sa fin (phase `upload`) ; à la fin, état `abort` ; rapport suivant `failed` `backup_interrupted` (`interrupted_by_update`), état `idle` |
| E18 | **fpm** (E3 `db`, puis envoi `files`) et **ols** (envoi `files`) : réglage coché, envois réussis ; connexions vers `stockage:9000` vues côté stockage en IPv4 (voir l'écart) |
| E19 | **fpm** et **ols** : `encrypted_backup` retiré, `backup_start` et `backup_set_key` `backup_disabled`, `disabled_by_constant` vrai ; constante retirée : tout revient |
| E20 | **fpm** et **ols** : empreinte affichée en huit groupes de quatre, **égale** à `Fingerprint::display()` de la clé du snapshot (`bdb9 7780 8056 774b b275 db3d 33fb 8669` sous `fpm`, `7c7c 0d9b 0a0a cd1c 70e1 d6a1 0b76 e478` sous `ols`) sur la page de secours et sur le panneau React ; douze captures dans `captures/` (page de secours et panneau, case cochée, décochée, constante) |
| E21 | **fpm** et **ols** : la tranche en vol finit `backup_interrupted` (`interrupted_by_update`), jamais `finalize` ; `backup_start` pendant la mise à jour : `update_in_progress`, `retry_after` 60 ; témoin : une entrée « sans verrou » (`held` faux) pendant, vide après ; nouvelle sauvegarde acceptée ensuite |
| E22 | **fpm** et **ols** : `404` pour `baseline/current.json` et `job-<id>/manifest.jsonl` sous la racine du dossier ; la racine et `index.php` : `200` de 0 octet ; nom du dossier privé absent de toute réponse ; avec ce nom connu, `current.json` (163 octets) et `manifest.jsonl` (≈ 307 Ko) se lisent (voir l'écart) |
| E23 | **ols** : tranche par `/command-runs` : seconde tranche et mise à jour par `/command-runs` : 409 `site_busy` (rien d'enregistré) ; seconde tranche par `/command` : `failed` `backup_slice_running` ; mise à jour par `/command` : `failed` `backup_slice_running`, `retry_after` 22 ; une seule tranche exécutée (`segments_written` 2) ; mise à jour rejouée après : réussie |

## Mesures (étape 3)

`mesures.txt` et `mesures-brutes/` (échantillons de `docker stats` toutes les 5 s, `VmHWM` du plus gros
processus du conteneur toutes les secondes ; conteneur PHP redémarré avant chaque essai mesuré).

| Essai | Durée de la sauvegarde | Tranches | Plus longue tranche (envois compris) | Pic mémoire d'un processus | CPU conteneur (de 100 % = un cœur) | Octets envoyés |
| --- | --- | --- | --- | --- | --- | --- |
| E1 fpm | 32 s | 12 | 13,6 s | 110 Mio | 44 % au plus, 12 % en moyenne | 422,4 Mio |
| E1 ols | 35 s | 12 | 14,0 s | 178 Mio | 97 % au plus | 422,4 Mio |
| E1 ols async | 41 s | 12 | 13,1 s | 181 Mio | 102 % au plus | 422,4 Mio |
| E4 fpm | 12 s | 6 | 7,9 s | 65 Mio | 48 % au plus | 10,3 Mio |
| E4 ols | 12 s | 6 | 8,2 s | 66 Mio | 49 % au plus | 10,3 Mio |
| E4 ols async | 17 s | 6 | 8,3 s | 67 Mio | 50 % au plus | 10,3 Mio |

La plus longue tranche (14 s) tient largement dans la tranche réglée de 20 s plus 5 s. La tranche qui
copie `wp_banc_gros` (564 Mio de texte) est la première : elle porte les pics de mémoire. Le rapport
final ne porte pas `memory_get_peak_usage` (le plan le supposait) : le pic est lu dans `/proc`. Rapportée
à la vue d'ensemble (≈ 1 Gio en moins d'une heure de travail cumulé), la charge du banc (340 Mio de
fichiers incompressibles plus 565 Mo de base très compressible, 422 Mio envoyés) coûte 26 s de travail
cumulé de tranches : le banc tourne sur un disque local rapide et sans autre trafic ; le seul cas lent
mesuré est celui des 56 000 petits fichiers lus à travers un montage réseau (2 469 s, voir plus haut).
`ols` pèse plus lourd que `fpm` en mémoire (178 contre 110 Mio) et en processeur (un cœur plein
contre la moitié), sans écart de comportement.

## Observations hors périmètre des essais

- Un `backup_start` rejoué avec l'identifiant d'une sauvegarde **terminée** la fait repartir de zéro
  (état `files` après quelques tranches, `last` écrasé de `done` à `aborted` par un abandon) ; la
  plateforme ne réutilise jamais un identifiant, mais le connecteur pourrait refuser ce cas.
- Écrans (revue UX/UI de la tâche 32) : avec la constante, l'interrupteur « Sauvegardes chiffrées »
  s'affiche **actif et grisé** alors que le texte dit « Désactivées » (panneau et page de secours) ;
  la page de secours montre l'empreinte même case décochée, le panneau seulement case cochée.
- `update_theme` rend `done` avec `reason` `upgrade_failed` quand le thème n'a pas de mise à jour et que
  WordPress ne peut pas interroger wordpress.org : à relire du côté des statuts vrais (hors module).
