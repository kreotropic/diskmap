<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
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
 * Phase 1 (team folder overview, top-N largest) and Phase 2 (personal
 * storage overview) endpoints are wired up so far (plan §11).
 * usage#tree (Phase 3), usage#mimetypes (Phase 4) and adminApi#groups
 * reconciliation (Phase 4) are added when their controllers land.
 */
return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

        // The caller's own files/trash/versions breakdown + quota occupancy.
        ['name' => 'usage#myOverview', 'url' => '/api/v1/my/overview', 'verb' => 'GET'],

        // Top-N largest files/folders in an explicit scope (user|teamfolder|storage).
        ['name' => 'usage#largest', 'url' => '/api/v1/largest', 'verb' => 'GET'],

        // Admin-only: team folder overview (used/quota, files/trash/versions, linked groups).
        ['name' => 'adminApi#teamFolders', 'url' => '/api/v1/admin/teamfolders', 'verb' => 'GET'],
    ],
];
