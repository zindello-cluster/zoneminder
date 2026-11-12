#!/bin/sh
# The files our commits touch (upstream's <release> tag against <commit>), exported into <out dir>
# for the Dockerfile, after two checks against the base image: a file we change is byte for byte upstream's at that
# tag in the base image (so a base image that changed it fails here rather than being silently
# overwritten), and a file we add isn't there at all. Then PHP lint inside the base image (it has
# php) and JavaScript syntax with node.
#
#   ai-live-detection/tests/check-files.sh <release> <commit> <base image> <out dir>
set -eu
release=$1
branch=$2
base=$3
out=$4
www=/usr/share/zoneminder/www

rm -rf "$out"
mkdir -p "$out"
out=$(cd "$out" && pwd)  # absolute, so docker run -v mounts the folder, not a named volume
files=$(git diff --name-only "$release" "$branch" -- web/)
test -n "$files" || { echo "no changed files between $release and $branch" >&2; exit 1; }

cid=$(docker create "$base")
trap 'docker rm -f "$cid" >/dev/null' EXIT
status=0
for f in $files; do
  rel=${f#web/}
  if git cat-file -e "$release:$f" 2>/dev/null; then
    # A file we change: the base image's copy must be upstream's at this tag.
    if ! docker cp -q "$cid:$www/$rel" "$out/.base" 2>/dev/null; then
      echo "MISSING in the base image: $www/$rel (we change it)" >&2; status=1; continue
    fi
    if ! git show "$release:$f" | cmp -s - "$out/.base"; then
      echo "CHANGED in the base image: $www/$rel differs from upstream's at $release" >&2; status=1
    fi
  else
    # A file we add must not already be there.
    if docker cp -q "$cid:$www/$rel" "$out/.base" 2>/dev/null; then
      echo "ALREADY in the base image: $www/$rel (we add it)" >&2; status=1
    fi
  fi
  rm -f "$out/.base"
done
test "$status" = 0 || exit "$status"

git archive "$branch" $files | tar -x -C "$out"
mv "$out/web/"* "$out/" && rmdir "$out/web"
echo "exported into $out:"
(cd "$out" && find . -type f | sort)

# Lint: PHP with the base image's own php, JavaScript with node.
for f in $files; do
  case "$f" in
    *.php) docker run --rm -v "$out:/w:ro" --entrypoint php "$base" -l "/w/${f#web/}" ;;
    *.js) node --check "$out/${f#web/}" && echo "$f: syntax OK" ;;
  esac
done
