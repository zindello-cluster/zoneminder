# ai-live-detection: the ZoneMinder team's own image of one release, with the web files our commits
# change laid over it (AI-LIVE-DETECTION.md; cluster-docs O366, O374–O377), and our zmc (item 15;
# cluster-docs O635). Upstream's image is built from zmrepo's Debian packages; our web changes are
# PHP, JavaScript and CSS under /usr/share/zoneminder/www, and zmc is rebuilt from the same source
# package the image's zoneminder package came from, with our C++ files laid over it.
#
# The FROMs are the team's image of the release this branch is on (UPSTREAM_RELEASE), by digest,
# both the same; sync.sh moves them with each upstream release. CI checks they agree with it,
# checks that the base image's copies of the web files we change are byte for byte upstream's at
# that release (tests/check-files.sh), and copies the files our commits touch into web/ and zmc/
# before building (tests/check-files.sh, tests/export-zmc.sh); the build context is this folder.

# --- zmc: the source package of the image's own zoneminder package, our C++ files over it -------
FROM ghcr.io/zoneminder/zoneminder:1.38.6-es@sha256:a3dd9b50b3e8bd3cac45316450b6681ca183146816899a9c68cac2ebae3030cc AS zmc
SHELL ["/bin/bash", "-o", "pipefail", "-c"]
# zmc/ is written by CI: upstream/ the files our commits change as upstream has them at the release,
# ours/ as we have them.
COPY zmc/ /zmc/
WORKDIR /build
RUN set -eux; \
    version=$(dpkg-query -W -f='${Version}' zoneminder); \
    sed -n 's/^deb /deb-src /p' /etc/apt/sources.list.d/zoneminder.list > /etc/apt/sources.list.d/zoneminder-src.list; \
    apt-get update; \
    apt-get install -y --no-install-recommends dpkg-dev build-essential fakeroot catch2; \
    apt-get source --only-source "zoneminder=$version"; \
    apt-get build-dep -y --no-install-recommends "zoneminder=$version"; \
    cd zoneminder-*/; \
    # The source package's copies of the files we change must be upstream's at the release, so a
    # package that changed them fails here rather than being overwritten.
    status=0; \
    for f in $(cd /zmc/upstream && find . -type f | sed 's|^\./||'); do \
      cmp -s "/zmc/upstream/$f" "$f" || { echo "CHANGED in the source package: $f differs from upstream's" >&2; status=1; }; \
    done; \
    for f in $(cd /zmc/ours && find . -type f | sed 's|^\./||'); do \
      if [ ! -e "/zmc/upstream/$f" ] && [ -e "$f" ]; then echo "ALREADY in the source package: $f (we add it)" >&2; status=1; fi; \
    done; \
    test "$status" = 0; \
    cp -a /zmc/ours/. .; \
    DEB_BUILD_OPTIONS="nocheck nodoc parallel=$(nproc)" dpkg-buildpackage -B -uc -us; \
    dpkg-deb -x ../zoneminder_*_amd64.deb /out; \
    install -D -m 0755 /out/usr/bin/zmc /zmc-built; \
    # Our tests for it, built against the same tree.
    cmake -S . -B /tests -DBUILD_TEST_SUITE=ON -DCMAKE_BUILD_TYPE=Release; \
    cmake --build /tests --target tests -j"$(nproc)"; \
    /tests/tests/tests "[db]"

# --- The image ----------------------------------------------------------------------------------
FROM ghcr.io/zoneminder/zoneminder:1.38.6-es@sha256:a3dd9b50b3e8bd3cac45316450b6681ca183146816899a9c68cac2ebae3030cc

# web/ is written by CI: only the files our commits add or change, at their paths under web/.
COPY --chown=root:root web/ /usr/share/zoneminder/www/

# zmc, rebuilt with our C++ change (item 15).
COPY --from=zmc --chown=root:root /zmc-built /usr/bin/zmc
