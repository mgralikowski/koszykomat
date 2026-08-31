#!/usr/bin/env bash
# PostToolUse (Write|Edit) — run only the tests guarding the risk area the edited file belongs to.
#
# The directory map below mirrors context/foundation/test-plan.md §2 (Risk Map).
# An edit outside a risk area runs nothing: per-edit hooks must stay cheap.
#
# Trap, documented in test-plan.md §5: --exclude-group must be repeated per group.
# `--exclude-group=known-defect,mysql` parses fine and silently filters nothing.
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
    app/Pricing/*)                   SUITES="tests/Unit/Pricing tests/Feature/Pricing" ;;  # risk #1, #3
    app/Ingestion/*)                 SUITES="tests/Feature/Ingestion" ;;                   # risk #4
    app/Http/Controllers/*|routes/*) SUITES="tests/Feature/Basket" ;;                      # risk #5, #7
    database/factories/*)            SUITES="tests/Feature/Database" ;;                    # risk #6
    tests/*)                         SUITES="$REL" ;;                                      # the test itself
    *) exit 0 ;;                                                                           # not a risk area
esac

cd "$ROOT" || exit 0
# --compact prints dots for passes and details only for failures: less noise in the
# agent's context, fewer tokens. Colours are stripped for the same reason.
# Options MUST precede the path arguments: after a variadic positional, Symfony Console
# hands the flag to PHPUnit, which ignores --compact without complaining.
# shellcheck disable=SC2086
OUT="$(ddev artisan test \
    --compact --no-ansi \
    --exclude-group=known-defect --exclude-group=mysql \
    $SUITES 2>&1 | sed -r 's/\x1B\[[0-9;]*[a-zA-Z]//g')"
STATUS=$?   # correct because of `set -o pipefail` above

[ "$STATUS" -eq 0 ] && exit 0
case "$OUT" in
    *"No tests executed"*) exit 0 ;;   # e.g. an edited test that lives in an excluded group
esac

# On exit 2 Claude Code feeds STDERR back into the agent's context, not stdout.
# Print to stdout and the agent only learns that something failed, never what.
{
    echo "Scoped tests failed for $REL (risk area from test-plan.md §2):"
    echo "$OUT" | tail -n 60
} >&2
exit 2
