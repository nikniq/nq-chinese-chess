#!/usr/bin/env bash
# Stop hook: commit all changes and push to origin on the current branch.
set -u
cd "${CLAUDE_PROJECT_DIR:-$(git rev-parse --show-toplevel)}" || exit 0
git add -A
if ! git diff --cached --quiet; then
  files=$(git diff --cached --name-only | head -5 | tr '\n' ' ')
  git commit -q -m "Auto-commit: ${files}" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>" || exit 0
fi
branch=$(git rev-parse --abbrev-ref HEAD)
if [ -n "$(git log "origin/$branch..HEAD" 2>/dev/null || echo unpushed)" ]; then
  if out=$(git push -q -u origin "$branch" 2>&1); then
    echo "{\"systemMessage\": \"Pushed to origin/$branch\"}"
  else
    msg=$(printf '%s' "$out" | tr '\n"' ' \047' | cut -c1-200)
    echo "{\"systemMessage\": \"Auto-push failed: $msg\"}"
  fi
fi
exit 0
