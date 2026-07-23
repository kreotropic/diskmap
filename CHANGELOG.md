# Changelog

All notable changes to this project are documented in this file.
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added
- Admin team-folder overview: used/quota with occupancy %, files/trash/versions
  breakdown, and linked groups/circles — resolved directly from `oc_filecache`
  and `oc_storages`, with no filesystem scan.
- Top-N largest files, scoped to a user, team folder, or raw storage.
- Layout-agnostic team-folder storage resolution (`LayoutDetector`), handling
  both the legacy root-jail layout and the newer per-folder separate-storage
  layout transparently.
