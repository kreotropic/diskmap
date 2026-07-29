<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

/**
 * Contract for reading aggregated storage usage. Every method takes an
 * explicit Scope — never "the current user" — so the same implementation
 * serves both the admin and the user-facing controllers (plan §8).
 *
 * FilecacheUsageSource (SQL over oc_filecache) is the only implementation for
 * now (plan §5, Option B — confirmed against a live instance: the
 * fs_storage_size index serves the top-N query, and folder totals are
 * already aggregated, no traversal needed). The interface exists so a future
 * IRootFolder-based implementation could be swapped in without touching any
 * controller.
 */
interface IUsageSource {
    /**
     * @return int|null aggregated size in bytes at the scope's root, or null
     *                   if the scope doesn't resolve to a known storage/path.
     */
    public function totalSize(Scope $scope): ?int;

    /**
     * @return int|null the filecache mtime for the scope's root — the
     *                   "last scan" timestamp the UI must always show
     *                   (plan §10) — or null if unresolved.
     */
    public function lastUpdated(Scope $scope): ?int;

    /**
     * The immediate (one level deep, not recursive) children of $scope's
     * path — both files and folders — ordered by size descending. Folder
     * nodes carry their own recursive size total, which is free: filecache
     * already stores it aggregated.
     *
     * Deliberately cheap: every figure here comes from the rows themselves,
     * so the cost is one indexed lookup bounded by $limit no matter how large
     * the subtree below is. The recursive per-folder aggregates that used to
     * be part of this response now live in childComposition(), which is where
     * the "why is this folder taking seconds to open" cost actually was.
     *
     * @return array{
     *     root: ?UsageNode,   // the folder at $scope's own path, or null if unresolved
     *     items: UsageNode[], // its immediate children, size DESC, capped at $limit
     *     truncated: bool,    // true if more children exist beyond $limit
     * }
     */
    public function children(Scope $scope, int $limit): array;

    /**
     * The recursive aggregates for the same level children() returns:
     * descendant file count and per-mimetype size breakdown, per immediate
     * child folder plus the parent itself (plan Phase 3b + the "Composição"
     * stacked bar).
     *
     * Separate from children() because it is the one read in this app whose
     * cost is linear in the whole subtree rather than bounded by $limit —
     * seconds on a large folder, against tens of milliseconds for the listing
     * — so the UI fetches the two concurrently and fills these columns in
     * when they arrive instead of holding the rows back for them.
     *
     * @param int $limit must match the children() call it accompanies: on the
     *     whole-instance root this decides which top-level entries are
     *     covered, and the parent aggregate reflects exactly those.
     * @return array{
     *     root: ?array{count: int, composition: array<string, int>}, // null if the scope doesn't resolve
     *     items: array<string, array{count: int, composition: array<string, int>}>, // keyed by child name; folders only
     * }
     */
    public function childComposition(Scope $scope, int $limit): array;

    /**
     * A recursive tree of $scope (files nested inside folders, like a real
     * WinDirStat map — plan Phase 3c) instead of a flat top-N list: folders
     * are expanded largest-first, breadth-first-by-size, until $maxNodes'
     * worth of tiles have been laid out, so a huge tree still renders in
     * roughly bounded time/size — the parts that don't fit the budget stay
     * as a single unexpanded folder tile (using its own precomputed
     * recursive size, no accuracy lost, just less visual detail). Within any
     * expanded folder, individually tiny children are folded into one
     * synthetic 'other' child instead of each getting a sliver tile.
     *
     * @return array{root: ?UsageNode}
     */
    public function mapTree(Scope $scope, int $maxNodes): array;
}
