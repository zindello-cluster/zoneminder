# CLAUDE.md — zindello-cluster/zoneminder

## Our fork (zindello-cluster)

This repository is zindello-cluster's fork of
[ZoneMinder/zoneminder](https://github.com/ZoneMinder/zoneminder) (GPL-2.0). It's kept only while
changes we need aren't upstream: live detection's web pages, and the alert rules' Event Server
page and API. It's in cluster-docs' `spec/layout.md` (the `zoneminder` row; O366, O374–O377), and
[`AI-LIVE-DETECTION.md`](AI-LIVE-DETECTION.md) describes our commits, our build, how a new upstream
release comes in, and how the fork is retired.

- **Our work goes on `ai-live-detection`,** the default branch: an upstream release, plus our
  commits and our build. `master` is upstream's and is only ever synced from upstream.
- **In our own style, under cluster-docs' spec and rules.** Read
  [`zindello-cluster/cluster-docs`](https://github.com/zindello-cluster/cluster-docs) before doing
  anything here: its `CLAUDE.md`, the spec (`spec/design.md`), the binding layout
  (`spec/layout.md`), the rules (`spec/rules.md`) and their exceptions (`spec/exceptions.md`). An
  agent prepares a pull request into `ai-live-detection`; merging is the owner's, apart from the
  merges they name for a session. Commits and pull requests follow cluster-docs' style, not
  upstream's workflow below.
- **Upstream's rules apply to anything sent upstream.** A change offered to ZoneMinder/zoneminder
  (an issue or a pull request) is ported onto upstream's `master` and follows upstream's guide
  below in full, and only with the owner's go-ahead.

Upstream's guide, `AGENTS.md`, follows unchanged. On this branch `CLAUDE.md` is this file, which
includes it, rather than a symlink to it.

---

@AGENTS.md
