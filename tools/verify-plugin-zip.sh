#!/usr/bin/env bash
#
# Valide le ZIP de release du plugin : intégrité, dossier racine unique
# wp.org-compatible, fichiers requis présents, fichiers de dev absents.
# Inspiré de tools/verify-theme-zip.sh du G2RD-theme.
#
# Usage : bash tools/verify-plugin-zip.sh dist/g2rd-connector.zip
set -euo pipefail

ZIP="${1:?Usage: $0 <zip>}"
SLUG="g2rd-connector"

echo "Vérification de $ZIP"

# Liste des entrées lue UNE fois dans un fichier temporaire : sous `pipefail`, un
# `unzip -Z1 | grep -q` faisait sortir unzip en 141 (SIGPIPE) dès que grep trouvait
# sa ligne et s'arrêtait, et le `if !` prenait ce code pour un fichier manquant.
LISTE=$(mktemp)
trap 'rm -f "$LISTE"' EXIT

# 1) Intégrité de l'archive
unzip -t "$ZIP" >/dev/null
echo "OK   : intégrité de l'archive"
unzip -Z1 "$ZIP" >"$LISTE"

# 2) Dossier racine unique = g2rd-connector/ (exigence wp.org)
TOP=$(awk -F/ 'NF>1 || $0 ~ /\/$/ {print $1}' "$LISTE" | sort -u)
if [ "$(echo "$TOP" | wc -l)" -ne 1 ] || [ "$TOP" != "$SLUG" ]; then
  echo "ERREUR: dossier racine attendu unique '$SLUG/', trouvé :"
  echo "$TOP"
  exit 1
fi
echo "OK   : dossier racine unique = $SLUG/"

# 3) Fichiers requis
required=(
  "$SLUG/$SLUG.php"
  "$SLUG/readme.txt"
  "$SLUG/uninstall.php"
  "$SLUG/includes/Plugin.php"
  "$SLUG/includes/autoload.php"
)
for f in "${required[@]}"; do
  if ! grep -qxF -- "$f" "$LISTE"; then
    echo "ERREUR: fichier requis manquant dans le ZIP : $f"
    exit 1
  fi
  echo "OK   : présent   $f"
done

# 4) Le bundle admin compilé doit être présent
if ! grep -q -- "^$SLUG/assets/admin/build/" "$LISTE"; then
  echo "ERREUR: bundle admin compilé absent ($SLUG/assets/admin/build/) — npm run build n'a pas tourné ?"
  exit 1
fi
echo "OK   : bundle admin compilé présent"

# 5) Aucun fichier de dev / VCS ne doit fuiter
forbidden=(
  "$SLUG/.git"
  "$SLUG/.github"
  "$SLUG/node_modules"
  "$SLUG/build-zip.ps1"
  "$SLUG/composer.json"
  "$SLUG/package.json"
  "$SLUG/phpcs.xml.dist"
  "$SLUG/phpstan.neon.dist"
  "$SLUG/tools"
  "$SLUG/tests"
  "$SLUG/phpunit.xml"
  "$SLUG/patchwork.json"
  "$SLUG/vendor"
)
for f in "${forbidden[@]}"; do
  if grep -q -- "^$f" "$LISTE"; then
    echo "ERREUR: contenu de dev présent dans le ZIP : $f"
    exit 1
  fi
  echo "OK   : absent    $f"
done

echo ""
echo "ZIP valide ($(du -h "$ZIP" | cut -f1))."
