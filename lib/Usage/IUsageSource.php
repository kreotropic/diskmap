<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
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
     * @return UsageNode[] the $limit largest files under $scope (recursive,
     *                      folders excluded), ordered by size descending.
     */
    public function largestFiles(Scope $scope, int $limit): array;
}
