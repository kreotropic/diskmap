<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Changelog

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [0.1.0] - 2026-07-30

First public release.

### Added
- **Nextcloud 34 support** (`max-version` raised from 33), verified on both
  engines. On PostgreSQL upgraded 33 → 34, every read path produced output
  byte-identical to the same instance before the upgrade; on a fresh MariaDB 34
  install, output was in turn identical to PostgreSQL 34 over the same fixture.
  The unit suite passes on the PHP 8.5 that Nextcloud 34 ships, and both views
  render on both engines with no application console errors. Core's
  `fs_storage_path_prefix` index is unchanged in 34 and confirmed present on a
  new MariaDB 34 install, which matters because MySQL raises an error — not a
  warning — when `USE INDEX` names an index that does not exist.
- **PostgreSQL support.** The three composition queries were the app's only
  raw SQL and its only MySQL-specific code; they now generate portable SQL,
  with the two genuinely engine-specific pieces (an index hint MySQL
  understands, and the "first N path segments" grouping expression) behind a
  single seam. Verified by running every read path over a byte-identical
  fixture on a MariaDB and a PostgreSQL instance of the same Nextcloud version
  and diffing the output: identical across all 253 lines.
- Admin team-folder overview: used/quota with occupancy %, files/trash/versions
  breakdown, and linked groups/teams — resolved directly from `oc_filecache`
  and `oc_storages`, with no filesystem scan.
- Personal "My storage" view: the same breakdown and quota occupancy for the
  logged-in account.
- Whole-server view (admin only): every account, team folder and external
  storage as a top-level entry, browsable down to individual files.
- WinDirStat-style treemap and expandable folder tree, synced both ways — a map
  click reveals the file in the tree, a tree click highlights the folder's
  region on the map.
- Recursive per-folder composition (documents / images / video / archives /
  other) shown as a stacked bar on every folder row, plus a legend that filters
  the map by category.
- Layout-agnostic team-folder storage resolution (`LayoutDetector`), handling
  both the legacy root-jail layout and the newer per-folder separate-storage
  layout transparently.

### Fixed
- Every view that touches team folders crashed with an SQL error on any
  instance without the Team Folders (groupfolders) app installed. DiskMap reads
  that app's tables directly by design, but never checked whether they exist —
  and on an instance that never installed it, they do not. The whole-server
  view and the team-folder overview now treat "no Team Folders app" as "no team
  folders". Found while testing on a clean PostgreSQL instance; it was never
  engine-specific.
- Opening a folder on the whole-server view took seconds. The recursive
  per-folder aggregates behind the "Composition" and "File count" columns are
  the only read whose cost grows with the whole subtree — measured at 1.4 s for
  a single folder with 300k descendants — and the row listing was waiting on
  them. They now come from their own endpoint, so rows render immediately and
  the two columns fill in underneath; the aggregates themselves are batched one
  query per level instead of one per folder, and cached under a key that
  includes the folder's own propagated `size` and `mtime`, which invalidates
  itself whenever anything below it changes. On a 300k-file level: 1928 ms
  before the tree showed anything, now 2 ms, with the columns following in
  1202 ms cold and 4 ms once cached.
- Whole-server view issued one recursive aggregation query per account, so its
  cost scaled with the number of accounts. Entries are now batched into a
  single grouped query per path prefix, and display names are read through
  Nextcloud's distributed cache instead of loading each account object — a
  whole-server tree load went from ~112 queries to 8 on a 55-entry instance.
- A team folder could resolve to a stale storage left behind by a data
  directory change, silently reporting the folder as empty; candidates are now
  probed for the one that actually holds the folder's files.
- A moved-away data directory was listed as an external storage, double-
  counting every account's home in the instance totals.
- The treemap could exceed its own node budget by a full level of children.
- Two top-level entries sharing a name (a uid equal to a team folder mount
  point) left one of them unreachable.
- Switching scopes while a tree or map load was still in flight could let the
  slower response win and render the wrong scope's data.
- Folder sizes reported as "not yet calculated" by the file cache no longer
  subtract from quota totals.

### Security
- Rate-limited the usage endpoints and bounded the per-request aggregation work
  of the children endpoint.

