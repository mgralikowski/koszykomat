#!/usr/bin/env bash
# PostToolUse (Write|Edit) — format the edited PHP file with Pint inside ddev.
# Never blocks: formatting is a fix, not a verdict. Always exits 0.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FILE="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"

[ -n "$FILE" ] || exit 0
case "$FILE" in
    "$ROOT"/*) REL="${FILE#"$ROOT"/}" ;;
    *) exit 0 ;;                       # edited outside this repo
esac
case "$REL" in
    *.php) ;;
    *) exit 0 ;;                       # Pint only handles PHP
esac
case "$REL" in
    vendor/*|node_modules/*) exit 0 ;;
esac
[ -f "$FILE" ] || exit 0

cd "$ROOT" || exit 0
ddev exec pint "$REL" >/dev/null 2>&1
exit 0
