#!/usr/bin/env bash
# TSO Options & Tables Cleaner — pre-release PHP checks.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FAIL=0

echo "== php -l (all plugin PHP) =="
while IFS= read -r -d '' file; do
	if ! php -l "$file" >/dev/null; then
		php -l "$file" || true
		FAIL=1
	fi
done < <(find . -name '*.php' ! -path './.git/*' -print0)

echo "== duplicate tsootc_ function definitions =="
DUPS="$(grep -Rh '^function tsootc_' includes tso-options-tables-cleaner.php uninstall.php 2>/dev/null \
	| sed 's/function \([a-zA-Z0-9_]*\).*/\1/' | sort | uniq -d || true)"
if [[ -n "$DUPS" ]]; then
	echo "$DUPS"
	FAIL=1
else
	echo "ok"
fi

echo "== direct \$_POST / \$_GET outside storage (feature files) =="
RAW="$(grep -Rn '\$_POST\|\$_GET' includes assets tso-options-tables-cleaner.php uninstall.php 2>/dev/null \
	| grep -v 'tsootc-storage.php' \
	| grep -v 'tso-cron.php' \
	| grep -v 'phpcs:ignore' \
	| grep -v '\* @param' \
	| grep -v '^\s*//' || true)"
if [[ -n "$RAW" ]]; then
	echo "$RAW"
	FAIL=1
else
	echo "ok"
fi

if command -v phpcs >/dev/null 2>&1; then
	echo "== PHPCS (WordPress standard) =="
	if ! phpcs --standard=WordPress includes tso-options-tables-cleaner.php uninstall.php; then
		FAIL=1
	fi
else
	echo "== PHPCS skipped (phpcs not in PATH) =="
fi

exit "$FAIL"
