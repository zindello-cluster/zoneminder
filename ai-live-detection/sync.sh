#!/usr/bin/env bash
# Merges an upstream ZoneMinder release into a new branch off ai-live-detection, ready for a pull
# request (AI-LIVE-DETECTION.md, cluster-docs O377):
#   ai-live-detection/sync.sh 1.38.7
# Upstream's workflows stay off this branch, so any the merge brings back are removed again. The
# Dockerfile's FROM moves to the ZoneMinder team's image of the release, by digest, read from the
# registry (nothing pulled). Any other conflict stops here, for a person to resolve. Run from a
# clone of this repo with a clean tree; it pushes nothing.
set -euo pipefail
release=${1:?usage: ai-live-detection/sync.sh <release>}
[[ $release =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "not a release: $release" >&2; exit 1; }
cd "$(git rev-parse --show-toplevel)"
[ -z "$(git status --porcelain)" ] || { echo "the working tree isn't clean" >&2; exit 1; }

image="ghcr.io/zoneminder/zoneminder:$release-es"
token=$(curl -sf "https://ghcr.io/token?scope=repository:zoneminder/zoneminder:pull" | sed 's/.*"token":"\([^"]*\)".*/\1/')
digest=$(curl -sfI -H "Authorization: Bearer $token" -H 'Accept: application/vnd.oci.image.index.v1+json' \
  "https://ghcr.io/v2/zoneminder/zoneminder/manifests/$release-es" | tr -d '\r' | awk -F': ' 'tolower($1)=="docker-content-digest" {print $2}')
[ -n "$digest" ] || { echo "no $image on ghcr.io yet" >&2; exit 1; }

git fetch -q origin ai-live-detection
git fetch -q --no-tags https://github.com/ZoneMinder/zoneminder.git "refs/tags/$release:refs/tags/$release"
git switch -q -c "upstream-$release" origin/ai-live-detection

git merge -q --no-ff --no-commit "$release" || echo "the merge conflicts; checking where"
# Upstream's own workflows: everything in .github/workflows that isn't ours, conflicting or not.
git ls-files -- .github/workflows | { grep -v '^\.github/workflows/ai-live-detection-' || true; } | sort -u | xargs -r git rm -qf --
if [ -n "$(git diff --name-only --diff-filter=U)" ]; then
  echo "these still conflict; resolve them, then git commit, and re-run the steps after the merge in this script by hand:" >&2
  git diff --name-only --diff-filter=U >&2
  exit 1
fi
git commit -q -m "Merge upstream ZoneMinder $release"

echo "$release" > ai-live-detection/UPSTREAM_RELEASE
sed -i.bak "s|^FROM ghcr.io/zoneminder/zoneminder:.*|FROM $image@$digest|" ai-live-detection/Dockerfile
rm ai-live-detection/Dockerfile.bak
git commit -q -m "ai-live-detection: upstream $release, built on $image@$digest" ai-live-detection/UPSTREAM_RELEASE ai-live-detection/Dockerfile

echo "ready: push upstream-$release and open a pull request into ai-live-detection; its CI checks, builds and smoke-tests it"
