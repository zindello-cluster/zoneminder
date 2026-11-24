#!/bin/sh
# The C++ and CMake files our commits touch (upstream's <release> tag against <commit>), for the
# Dockerfile's zmc stage (AI-LIVE-DETECTION.md, item 15): each as upstream has it at the tag under
# <out dir>/upstream/ (none for a file we add) and as we have it under <out dir>/ours/. The stage
# checks the source package's copies are upstream's before laying ours over them.
#
#   ai-live-detection/tests/export-zmc.sh <release> <commit> <out dir>
set -eu
release=$1
branch=$2
out=$3

rm -rf "$out"
mkdir -p "$out/upstream" "$out/ours"
files=$(git diff --name-only "$release" "$branch" -- src/ tests/ CMakeLists.txt cmake/)
test -n "$files" || { echo "no changed C++ files between $release and $branch" >&2; exit 1; }
for f in $files; do
  if git cat-file -e "$release:$f" 2>/dev/null; then
    mkdir -p "$out/upstream/$(dirname "$f")"
    git show "$release:$f" > "$out/upstream/$f"
  fi
  if git cat-file -e "$branch:$f" 2>/dev/null; then
    mkdir -p "$out/ours/$(dirname "$f")"
    git show "$branch:$f" > "$out/ours/$f"
  else
    echo "$f: deleted by our commits, which the zmc stage doesn't do" >&2; exit 1
  fi
done
echo "exported into $out:"
(cd "$out" && find . -type f | sort)
