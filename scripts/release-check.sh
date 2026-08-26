#!/usr/bin/env bash
# Chain TSO release gates (Unix). Exit 0 = ready for ZIP / SVN.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "=== TSO Options & Tables Cleaner — release-check ==="

bash "$ROOT/scripts/phpcs-check.sh"
bash "$ROOT/scripts/prefix-audit.sh"
php "$ROOT/scripts/run-detection-regression.php" >/tmp/tsootc-regression.log
tail -3 /tmp/tsootc-regression.log
if ! grep -q '0 failed' /tmp/tsootc-regression.log; then
	echo "Regression failed"
	exit 1
fi

VERSION="$(grep -m1 '^Version:' tso-options-tables-cleaner.php | sed 's/.*:[[:space:]]*//')"
STABLE="$(grep -m1 '^Stable tag:' readme.txt | sed 's/.*:[[:space:]]*//')"
if [[ "$VERSION" != "$STABLE" ]]; then
	echo "FAIL: Version ($VERSION) != Stable tag ($STABLE)"
	exit 1
fi
echo "ok: Version == Stable tag ($VERSION)"

bash "$ROOT/scripts/build-zip.sh"

ZIP="$ROOT/dist/tso-options-tables-cleaner-${VERSION}.zip"
for forbidden in regression wp-stubs compile-mo.cjs 'docs/'; do
	if unzip -l "$ZIP" | grep -qi "$forbidden"; then
		echo "FAIL: ZIP contains forbidden path: $forbidden"
		exit 1
	fi
done
echo "ok: ZIP excludes dev-only paths"

echo ""
echo "Release check passed. ZIP: $ZIP"
