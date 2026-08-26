#!/usr/bin/env bash
# TSO Options & Tables Cleaner — WordPress.org prefix / review blockers grep.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

FAIL=0
SCAN=(includes assets tso-options-tables-cleaner.php uninstall.php)

check() {
	local label="$1"
	local pattern="$2"
	local extra_grep="${3:-}"
	local hits
	hits="$(grep -REn "$pattern" "${SCAN[@]}" 2>/dev/null $extra_grep || true)"
	if [[ -n "$hits" ]]; then
		echo "FAIL: $label"
		echo "$hits"
		FAIL=1
	else
		echo "ok: $label"
	fi
}

echo "== prefix audit (tsootc_ / TSOOTC_) =="

check 'bare function tso_' '\bfunction tso_[a-z]'
check 'wp_ajax without tsootc prefix' "wp_ajax_tso_"
LDT="$(grep -REn 'load_plugin_textdomain\s*\(' "${SCAN[@]}" 2>/dev/null | grep -v '^\s*//' | grep -v '\*' || true)"
if [[ -n "$LDT" ]]; then
	echo "FAIL: load_plugin_textdomain call"
	echo "$LDT"
	FAIL=1
else
	echo "ok: load_plugin_textdomain call"
fi
check 'inline script in PHP' '<(script|style)\b|echo\s+['\''"]<script'
check 'bootstrap wp-load' 'wp-load\.php|wp-config\.php|wp-blog-header\.php'

# Legacy map strings are allowed only in storage/maps with comment.
LEGACY_OPTS="$(grep -REn "update_option\s*\(\s*'tso_" includes 2>/dev/null | grep -v 'tsootc-storage.php' || true)"
if [[ -n "$LEGACY_OPTS" ]]; then
	echo "FAIL: update_option with raw tso_ key outside storage"
	echo "$LEGACY_OPTS"
	FAIL=1
else
	echo "ok: update_option tso_ keys centralized"
fi

# Short TSO_ constants (3-char) as runtime defines/classes.
SHORT_TSO="$(grep -REn '\b(class|define)\s+TSO_[A-Z]' includes tso-options-tables-cleaner.php uninstall.php 2>/dev/null || true)"
if [[ -n "$SHORT_TSO" ]]; then
	echo "FAIL: TSO_ three-letter runtime symbols"
	echo "$SHORT_TSO"
	FAIL=1
else
	echo "ok: no bare TSO_ runtime symbols"
fi

exit "$FAIL"
