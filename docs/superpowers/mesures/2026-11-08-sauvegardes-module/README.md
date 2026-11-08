# Mesures du lot 4-B : module de contenu des sauvegardes (connecteur, phase 4, tâche 26)

Relevé du 08/11/2026, recopié dans le dépôt selon le rappel transverse n° 12 du plan de la phase 4
(`docs/superpowers/plans/2026-10-29-sauvegardes-phase-4-module-connecteur.md`, dépôt de la plateforme).

Ce qu'il fallait mesurer (tâche 26, point « Performance ») : le débit d'un segment de 64 Mio de texte et de
64 Mio de médias (compression puis chiffrement) ; la mémoire ajoutée par un segment à blocs de 8 Mio
(seuil : pic ajouté inférieur à 40 Mio) ; le dump de 100 000 petites lignes (durée, mémoire) et d'une table
aux grosses valeurs (300 lignes de 2 Mio, contenus de constructeurs de pages) sous `memory_limit=128M` :
aucune erreur fatale, pic ajouté inférieur à 40 Mio, LIMIT relevés.

## Poste et versions

- Poste : Windows 11 Pro 10.0.26300, Intel Core i7-13700H, 31,8 Go ; Docker 29.9.2.
- Image du site : `wordpress:7.1.3-php8.3-fpm` (WordPress 7.1.3, PHP 8.3.35), présente localement.
- Base : `mariadb:10.11.19` (présente localement), conteneur jetable, `--tmpfs` pour ses données (aucun
  volume), réseau Docker **interne** (aucun accès extérieur). Le conteneur et le réseau ont été arrêtés et
  retirés après le passage.
- Connecteur : copie `sauvegardes-module`, montée en lecture seule, branche `ajout/sauvegardes-module`.

## Méthode

WordPress est amorcé en `SHORTINIT` sur la vraie base (`$wpdb` réel, connexion MariaDB), le connecteur par
son autochargeur. Une mesure par processus PHP (`php -d memory_limit=128M mesure.php <mode>`), deux
passages. « Ajouté » = `memory_get_peak_usage( true )` après l'opération, moins `memory_get_usage( true )`
juste avant (pic remis à zéro par `memory_reset_peak_usage()`), une fois le site amorcé (base : 6 à 8 Mio
dans ce banc ; un vrai site charge davantage de WordPress et d'extensions avant d'arriver là).

- `texte` : 64 fichiers PHP de 1 Mio (texte compressible), `FileArchiver` puis `SegmentProducer` (blocs de 8 Mio,
  autotest compris).
- `medias` : 16 fichiers `.jpg` de 4 Mio d'octets aléatoires (stockés tels quels).
- `petites` : `wp_petites`, 100 000 lignes (clé entière, titre, entier, court texte), segments arrêtés à 64 Mio
  de charge utile.
- `grosses` : `wp_grosses`, 300 lignes de 2 Mio de JSON de constructeur de pages (LONGTEXT).
- `perimees` : même contenu, mais `information_schema` annonce 54 octets par ligne (statistiques périmées,
  `STATS_AUTO_RECALC=0` : cas d'un MySQL 8, qui garde ses statistiques 24 h).
- `lots` : `grosses`, un lot par appel de `advance()`, pour relever le LIMIT à chaque lot.

Le disque et le processeur sont ceux d'un poste de développement sous Docker Desktop : les débits d'un
hébergement mutualisé seront plus bas. Les mesures de mémoire, elles, ne dépendent pas de la machine.

## Résultats (second passage, identique au premier à 1 Mio près)

| Mesure | Résultat | Seuil du plan |
| --- | --- | --- |
| Segment de 64 Mio de texte | 0,81 à 0,85 s, 75 à 79 Mio/s ; segment de 19 Mio ; 3 blocs ; **+28 Mio** | < 40 Mio |
| Segment de 64 Mio de médias | 0,63 à 0,67 s, 96 à 102 Mio/s ; segment de 64 Mio ; 9 blocs ; **+26 à +28 Mio** | < 40 Mio |
| Dump de 100 000 petites lignes | 100 000 lignes en 0,45 à 0,64 s ; segment de 1,7 Mio ; **+6 Mio** | < 40 Mio |
| Dump de 300 lignes de 2 Mio | 300 lignes (600 Mio de SQL) en 8,0 à 8,2 s, 3 segments de 64 Mio de charge utile ; LIMIT 1 ; **+38,4 Mio** ; aucune erreur fatale | < 40 Mio |
| Même table, statistiques périmées | 301 lignes en 7,9 à 8,2 s ; LIMIT 2 ; **+42,4 Mio** ; aucune erreur fatale | (cas ajouté) |

Le dernier cas dépasse de 2,4 Mio le seuil de 40 Mio : le LIMIT monte à 2 (4,2 Mio de valeurs, sous les 8 Mio
visés), là où la table aux statistiques exactes reste à 1. Le pic total (48 Mio) reste très en dessous de
128 Mio ; le seuil du plan vise la table du cas nominal, qu'il respecte.

## Ce que le relevé a changé dans le code

`resultats-avant-correctif.txt` : avec le code du commit `617c452`, la table de 300 lignes de 2 Mio aux
statistiques périmées **faisait épuiser la mémoire** (premier lot de 200 lignes, 400 Mio, erreur fatale sous
128 Mo), et la table aux statistiques exactes montait à +46,4 Mio (premier lot de 3 lignes). Correctif : le
premier lot d'une table qui a une colonne MEDIUMTEXT, LONGTEXT, MEDIUMBLOB, LONGBLOB ou JSON est d'une ligne
(`DatabaseDumper::LARGE_TYPE`), puis se règle sur la taille lue, comme avant. Les petites tables à texte long
(options, articles) coûtent 8 requêtes de plus au lancement.

Revue du 08/11 (corrigé) : le lot suivant ne se règle plus sur la taille du lot lu pour ces tables. Une table
de 300 lignes d'un octet puis de lignes de 256 Kio doublait son LIMIT jusqu'à 200 et lisait 38 Mio d'un coup (près
de 80 Mio avec la conversion de `$wpdb`, une erreur fatale avec des lignes de 2 Mio). Avant chaque lot d'une table à
colonne large, `DatabaseDumper::bounded_limit()` lit les longueurs des lignes (mêmes WHERE, ORDER BY et LIMIT, sans
les valeurs) et réduit le LIMIT pour que le lot reste sous 8 Mio, une ligne seule exceptée. Coût : une requête de
longueurs de plus par lot de ces tables. Test : `test_aucun_lot_d_une_table_large_ne_depasse_batch_bytes_apres_de_petites_lignes`.
Mesure du mode « mixte » sous `memory_limit=128M` sur MariaDB réelle : non refaite dans cette passe.

## Fichiers

- `lancer.sh.txt`, `amorce.php.txt`, `mesure.php.txt` : le banc (extension `.txt` pour que ni la CI ni les
  outils de style ne les prennent pour du code du connecteur).
- `resultats.txt` : sortie brute des deux passages et de la suite des LIMIT.
- `resultats-avant-correctif.txt` : les deux relevés qui ont motivé le correctif.
