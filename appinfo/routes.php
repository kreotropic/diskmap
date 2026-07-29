<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Routes for DiskMap.
 *
 * `page#index` renders the SPA shell (nav entry). Data endpoints take an
 * explicit `scope` (see OCA\DiskMap\Usage\Scope) so the admin and user views
 * can share the same aggregation code; admin-only endpoints are additionally
 * guarded in-controller via AdminController::requireAdmin().
 *
 * Phase 1 (team folder overview) and Phase 2 (personal storage overview)
 * endpoints are wired up so far (plan §11). usage#children (Phase 3b) is a
 * NOT recursive, one-level "children of a path" endpoint powering the
 * WinDirStat-style expandable tree pane. usage#map (Phase 3c) is the
 * recursive, folder-nested tree the map renders — it replaced an earlier
 * flat usage#largest (top-N files by size, no folder structure), removed
 * once the map needed real folder clustering for tree→map folder
 * highlighting to have anything to highlight. usage#mimetypes and
 * adminApi#groups reconciliation (Phase 4) are added when their controllers
 * land.
 */
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // The caller's own files/trash/versions breakdown + quota occupancy.
        ['name' => 'usage#myOverview', 'url' => '/api/v1/my/overview', 'verb' => 'GET'],

        // One level of children (files + folders) under a scope+path — not recursive.
        ['name' => 'usage#children', 'url' => '/api/v1/children', 'verb' => 'GET'],

        // The recursive file-count + mimetype breakdown for that same level.
        // Split out of usage#children because it's the only read whose cost
        // scales with the subtree rather than with the row limit — the tree
        // pane renders the rows first and fills these two columns in after.
        ['name' => 'usage#composition', 'url' => '/api/v1/composition', 'verb' => 'GET'],

        // Recursive, folder-nested tree for the map (Phase 3c) — files nested
        // inside folders, node-budgeted, so a folder click in the tree pane
        // can highlight its whole region in the map (real WinDirStat behavior).
        ['name' => 'usage#map', 'url' => '/api/v1/map', 'verb' => 'GET'],

        // Admin-only: team folder overview (used/quota, files/trash/versions, linked groups).
        ['name' => 'adminApi#teamFolders', 'url' => '/api/v1/admin/teamfolders', 'verb' => 'GET'],

        // Admin-only: whole-instance header total (files+trash+versions across
        // everyone) — separate from map()'s own root.size, which is a
        // structural files-only total the rendered tiles must sum to.
        ['name' => 'usage#instanceOverview', 'url' => '/api/v1/instance/overview', 'verb' => 'GET'],
    ],
];
