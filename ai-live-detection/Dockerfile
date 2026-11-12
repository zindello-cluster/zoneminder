# ai-live-detection: the ZoneMinder team's own image of one release, with the web files our commits
# change laid over it (AI-LIVE-DETECTION.md; cluster-docs O366, O374–O377). Nothing is compiled:
# upstream's image is built from Debian packages, and our changes are PHP, JavaScript and CSS under
# /usr/share/zoneminder/www.
#
# The FROM is the team's image of the release this branch is on (UPSTREAM_RELEASE), by digest;
# sync.sh moves it with each upstream release. CI checks the two agree, checks that the base image's
# copies of the files we change are byte for byte upstream's at that release (tests/check-files.sh),
# and copies the files our commits touch into web/ before building; the build context is this
# folder.
FROM ghcr.io/zoneminder/zoneminder:1.38.6-es@sha256:a3dd9b50b3e8bd3cac45316450b6681ca183146816899a9c68cac2ebae3030cc

# web/ is written by CI: only the files our commits add or change, at their paths under web/.
COPY --chown=root:root web/ /usr/share/zoneminder/www/
