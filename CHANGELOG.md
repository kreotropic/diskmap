<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Changelog

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
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

