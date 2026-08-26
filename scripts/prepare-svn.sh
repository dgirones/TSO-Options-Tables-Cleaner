#!/usr/bin/env bash
# Copy plugin tree into a WordPress.org SVN trunk checkout (plugin subfolder).
# Usage: bash scripts/prepare-svn.sh /path/to/svn/tso-options-tables-cleaner/trunk
set -euo pipefail

if [[ $# -lt 1 ]]; then
	echo "Usage: bash scripts/prepare-svn.sh /path/to/svn/tso-options-tables-cleaner/trunk"
	exit 1
fi

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TRUNK="$(cd "$1" && pwd)"
SLUG="tso-options-tables-cleaner"
DEST="${TRUNK}/${SLUG}"
VERSION="$(grep -m1 '^Version:' "$ROOT/tso-options-tables-cleaner.php" | sed 's/.*:[[:space:]]*//')"

bash "$ROOT/scripts/release-check.sh"

rm -rf "$DEST"
mkdir -p "$DEST"

tar -C "$ROOT" -cf - \
	--exclude='.git' \
	--exclude='dist' \
	--exclude='docs' \
	--exclude='.github' \
	--exclude='scripts/wp-stubs' \
	--exclude='scripts/run-detection-regression.php' \
	--exclude='scripts/build-zip.sh' \
	--exclude='includes/tso-detection-regression.php' \
	--exclude='languages/compile-mo.cjs' \
	--exclude='node_modules' \
	. | tar -C "$DEST" -xf -

echo ""
echo "Synced plugin to: $DEST"
echo ""
echo "Next (from SVN repo root):"
echo "  cd $(dirname "$TRUNK")"
echo "  svn status"
echo "  svn add trunk/${SLUG} --force 2>/dev/null || true"
echo "  svn copy trunk tags/${VERSION} 2>/dev/null || svn add tags/${VERSION} --force"
echo "  svn commit -m \"Release ${VERSION}\""
