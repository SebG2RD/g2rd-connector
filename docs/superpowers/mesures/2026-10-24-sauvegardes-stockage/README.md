# Mémoire de l'envoi d'une partie (connecteur, phase 3, tâche 20)

Relevé du 24/10/2026, recopié dans le dépôt selon le rappel transverse n° 12 du plan de la
phase 3 (`docs/superpowers/plans/2026-10-25-sauvegardes-phase-3-stockage.md`, dépôt de la
plateforme). Les relevés de l'émulateur S3 (tâches 3, 4, 10 et 21) sont dans le dossier du même nom
du dépôt de la plateforme. Ce dossier-ci ne garde que la mesure de mémoire du connecteur.

Ce qu'il fallait mesurer : la mémoire que l'envoi d'une partie de 16 Mio et de 64 Mio ajoute à un
site, à travers le vrai code HTTP de WordPress. Seuil d'acceptation du plan : pic ajouté inférieur
à 2,5 fois la partie.

## Poste et versions

- Poste : Windows 11 Pro 10.0.26300, Intel Core i7-13700H, 31,8 Go ; Docker 29.9.2.
- Image : `wordpress:latest` présente localement,
  `sha256:b2d7e3153c8a96f90305a3102fb6439335237fb1a9655b617d15c5168ce2f7a3` (aucun
  téléchargement) : WordPress 7.0.2, PHP 8.3.33, cURL 8.14.1.
- Émulateur S3 : **aucun**. La mesure ne porte que sur la mémoire du site : un faux point d'accès
  S3 (`serveur.php.txt`) reçoit la partie dans le même conteneur. L'image Ceph RGW du lot A n'a pas
  servi.
- Connecteur : copie `sauvegardes-envoi`, branche `ajout/sauvegardes-envoi`, montée en lecture
  seule ; `includes/` identique à `ae84b69` (le commit `d76bb72` de la tâche 20 ne touche que
  `tests/` et `README.md`).
- Conteneur jetable `g2rd-p3-envoi-mesure`, `--network none` (aucun accès extérieur), supprimé à
  la fin du passage avec `--rm`.

## Méthode

WordPress est amorcé en `SHORTINIT` avec une base de données d'appoint sans connexion
(`wp-content/db.php`), puis son code HTTP est chargé dans l'ordre de `wp-settings.php`
(`WP_Http`, Requests 2, transport cURL, `WP_HTTP_Requests_Hooks`). Le connecteur est chargé par son
propre autochargeur. `PartUploader( false, true )` envoie la seconde partie d'un fichier de deux
parties (décalage non nul) vers le faux point d'accès S3 servi par `php -S` sur `127.0.0.1:9311`.
Ce point d'accès vérifie le `Content-MD5` des octets reçus et rend un ETag. Chaque mesure tourne
dans son propre processus PHP.

- `pic PHP` : `memory_get_peak_usage( true )` après l'envoi, moins `memory_get_usage( true )`
  avant (pic remis à zéro par `memory_reset_peak_usage()` juste avant). C'est ce que compte
  `memory_limit`.
- `RSS` : pic de mémoire résidente du processus (`VmHWM`, remis à zéro par
  `/proc/self/clear_refs`), moins `VmRSS` avant. Il compte aussi la copie du corps que l'extension
  cURL de PHP confie à libcurl (`CURLOPT_COPYPOSTFIELDS`), hors de `memory_limit`.
- « Site simulé » : 48 Mio déjà occupés avant l'envoi (chaîne de lest), pour approcher un site
  WordPress chargé de ses extensions.

## Résultats

Une ligne JSON par mesure. `resultats.txt` : relevé d'origine, deux passages identiques.
`resultats-rejeu.txt` : rejeu du même jour, lancé depuis les fichiers de ce dossier (renommés comme
dit plus bas), pour prouver que la méthode recopiée se rejoue. Les deux relevés donnent les mêmes
rapports.

| `memory_limit` | Lest | Partie | Issue | Pic PHP ajouté | RSS ajouté |
| --- | --- | --- | --- | --- | --- |
| 1G | 0 | 1 Mio | `uploaded`, ETag conforme | 2,0 Mio (granularité de 2 Mio du gestionnaire de PHP) | 2,4 à 2,8 × |
| 1G | 0 | 16 Mio | `uploaded`, ETag conforme | 16,0 Mio (1,00 ×) | 2,05 × |
| 1G | 0 | 64 Mio | `uploaded`, ETag conforme | 64,0 Mio (1,00 ×) | 2,01 × |
| 128M | 48 Mio | 16 / 24 / 32 / 64 Mio | `uploaded` dans les quatre cas | 1,00 × | 2,0 × |
| 256M | 48 Mio | 16 / 24 / 32 / 64 Mio | `uploaded` dans les quatre cas | 1,00 × | 2,0 × |

À 128M avec 48 Mio de lest, la partie de 64 Mio passe avec un pic de 122,0 Mio sur 128. Il ne
reste que 6 Mio de marge.

**Verdict** : seuil tenu. Le pic PHP ajouté vaut 1,00 fois la partie : les octets ne sont copiés
qu'une fois dans la mémoire de PHP, car la chaîne lue passe sans recopie à `wp_remote_request`,
puis à `curl_setopt`. La mémoire du processus vaut environ 2 fois la partie, à cause de la copie
de libcurl, qui est hors de `memory_limit`.

## Taille de partie conseillée (pour la phase 4)

- `memory_limit` 128 Mo : **16 Mio** (pic PHP + 16 Mio, processus + 32 Mio). Une partie de 64 Mio
  ne laisse que 6 Mio de marge sur un site qui occupe déjà 48 Mio.
- `memory_limit` 256 Mo : **32 Mio** (pic PHP + 32 Mio, processus + 64 Mio). Une partie de 64 Mio
  passe, mais ajoute 128 Mio à la mémoire du processus. Cela compte chez un hébergeur qui borne la
  mémoire du processus (LiteSpeed) et pas seulement `memory_limit`.
- Règle possible : au plus un quart de la marge (`memory_limit` moins `memory_get_usage( true )`
  au moment de l'envoi), plafonnée à 64 Mio, jamais sous **5 Mio** (taille minimale d'une partie
  S3, sauf la dernière).

## Limites du relevé

- WordPress 7.0.2 seulement : aucun WordPress 6.4 n'est présent localement, et rien n'a été
  téléchargé. Requests 2 et son transport cURL sont ceux de WordPress depuis la 6.2, donc les
  octets suivent le même chemin. À revérifier sur 6.4 si la phase 4 le juge utile.
- Les durées (1,0 à 1,6 s) ne disent rien du réseau. La mesure tourne en boucle locale, et elle
  inclut une seconde d'attente sur `Expect: 100-continue` : Requests envoie cet en-tête dès 1 Mio,
  et `php -S` n'y répond pas.

## Rôle de chaque fichier

Les scripts portent le suffixe `.txt`. Plugin Check (`.github/workflows/plugin-check.yml`) examine
tout le dossier de l'extension, `docs/` compris ; seul le ZIP de `release.yml` exclut `docs/`.
Sans ce suffixe, ces scripts de banc seraient examinés comme du code de l'extension. Ils sont
recopiés à l'octet près depuis le dossier de travail du relevé.

- `lancer.sh.txt` : démarre le faux point d'accès, lance les mesures, l'arrête.
- `mesure.php.txt` : amorce WordPress et le connecteur, crée le fichier, envoie, mesure (une ligne
  JSON).
- `serveur.php.txt` : faux point d'accès S3 (`UploadPart`) ; vérifie le `Content-MD5`, rend un
  ETag.
- `wp-content/db.php.txt` : base de données d'appoint sans connexion, pour l'amorçage
  `SHORTINIT`.
- `resultats.txt` : sortie brute du relevé d'origine.
- `resultats-rejeu.txt` : sortie brute du rejeu depuis ce dossier.

## Rejouer la mesure

Dans un dossier de travail hors du dépôt, recopier les quatre scripts sans le suffixe `.txt`
(`lancer.sh`, `mesure.php`, `serveur.php`, `wp-content/db.php`). Lancer ensuite, depuis ce dossier
(Git Bash) :

```bash
MSYS_NO_PATHCONV=1 docker run --rm --name g2rd-p3-envoi-mesure --network none --entrypoint sh \
  -v "<copie sauvegardes-envoi>:/connector:ro" -v "$(pwd -W):/mesure:ro" \
  wordpress:latest /mesure/lancer.sh > resultats.txt
```
