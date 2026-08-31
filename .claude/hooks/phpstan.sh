#!/usr/bin/env bash
# PostToolUse (Write|Edit) — static analysis (Larastan/PHPStan, level 5) after a PHP edit.
#
# Analyses the WHOLE project rather than just the edited file, on purpose:
#   * PHPStan's result cache makes a warm run ~2 s — no cheaper to scope it;
#   * a whole-project run also catches breakage in the edited file's CALLERS,
#     which a single-file run structurally cannot see.
# phpstan-baseline.neon records the 36 errors that predate this hook, so anything
# reported here is new — introduced by the edit that just happened.
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FILE="$(jq -r '.tool_input.file_path // empty' 2>/dev/null)"

[ -n "$FILE" ] || exit 0
case "$FILE" in
    "$ROOT"/*) REL="${FILE#"$ROOT"/}" ;;
    *) exit 0 ;;
esac
case "$REL" in
    *.php) ;;
    *) exit 0 ;;
esac
case "$REL" in
    app/*|database/*|tests/*) ;;   # the paths phpstan.neon analyses
    *) exit 0 ;;
esac

cd "$ROOT" || exit 0
OUT="$(ddev exec vendor/bin/phpstan analyse \
    --no-progress --no-ansi --memory-limit=512M 2>&1 \
    | sed -r 's/\x1B\[[0-9;]*[a-zA-Z]//g')"
STATUS=$?   # correct because of `set -o pipefail` above

[ "$STATUS" -eq 0 ] && exit 0

# Exit 2 feeds STDERR back into the agent's context; stdout would be invisible to it.
{
    echo "PHPStan (level 5) reports errors introduced by the edit to $REL:"
    echo "$OUT" | tail -n 60
} >&2
exit 2
