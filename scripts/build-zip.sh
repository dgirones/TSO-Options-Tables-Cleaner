#!/usr/bin/env bash
# Build WordPress.org distribution ZIP (folder slug = tso-options-tables-cleaner).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="tso-options-tables-cleaner"
VERSION="$(grep -m1 '^Version:' "$ROOT/tso-options-tables-cleaner.php" | sed 's/.*:[[:space:]]*//')"
OUT_DIR="${ROOT}/dist"
STAGE="${OUT_DIR}/${SLUG}"
ZIP="${OUT_DIR}/${SLUG}-${VERSION}.zip"

rm -rf "$STAGE" "$ZIP"
mkdir -p "$STAGE"

if command -v node >/dev/null 2>&1 && [[ -f "$ROOT/languages/compile-mo.cjs" ]]; then
	( cd "$ROOT" && node languages/compile-mo.cjs )
fi

tar -C "$ROOT" -cf - \
	--exclude='.git' \
	--exclude='dist' \
	--exclude='docs' \
	--exclude='scripts/wp-stubs' \
	--exclude='scripts/run-detection-regression.php' \
	--exclude='scripts/build-zip.sh' \
	--exclude='includes/tso-detection-regression.php' \
	--exclude='languages/compile-mo.cjs' \
	--exclude='node_modules' \
	. | tar -C "$STAGE" -xf -

mkdir -p "$OUT_DIR"
( cd "$OUT_DIR" && zip -rq "${SLUG}-${VERSION}.zip" "$SLUG" )

echo "Built: $ZIP"
ls -lh "$ZIP"
