#!/usr/bin/env bash
#
# Vérifie que la version passée en argument est alignée dans TOUTES les sources
# de vérité du plugin. Inspiré de tools/verify-release-version.sh du G2RD-theme,
# adapté aux fichiers du plugin (en-tête + constante + readme.txt + package.json).
#
# Usage : bash tools/verify-release-version.sh 0.1.0
set -euo pipefail

VERSION="${1:-}"
if [ -z "$VERSION" ]; then
  echo "Usage: $0 <version>  (ex. 0.1.0)" >&2
  exit 2
fi

fail=0
check() {
  local label="$1" found="$2"
  if [ "$found" != "$VERSION" ]; then
    echo "ERREUR: $label = '${found:-<introuvable>}' (attendu '$VERSION')"
    fail=1
  else
    echo "OK   : $label = $found"
  fi
}

HEADER=$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9][0-9.]*' g2rd-connector.php || true)
CONST=$(grep -m1 -oP "G2RD_CONNECTOR_VERSION'\s*,\s*'\K[0-9][0-9.]*" g2rd-connector.php || true)
STABLE=$(grep -m1 -oP '^Stable tag:\s*\K[0-9][0-9.]*' readme.txt || true)
PKG=$(grep -m1 -oP '"version"\s*:\s*"\K[0-9][0-9.]*' package.json || true)

echo "Vérification de l'alignement des versions sur $VERSION"
check "g2rd-connector.php  (en-tête Version)"        "$HEADER"
check "g2rd-connector.php  (G2RD_CONNECTOR_VERSION)" "$CONST"
check "readme.txt          (Stable tag)"             "$STABLE"
check "package.json        (version)"                "$PKG"

if [ "$fail" -ne 0 ]; then
  echo ""
  echo "Au moins une source n'est pas alignée. Mets à jour les fichiers ci-dessus avant de taguer."
  exit 1
fi
echo ""
echo "Toutes les sources sont alignées sur $VERSION."
