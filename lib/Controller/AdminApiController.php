<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Controller;

use OCA\DiskMap\AppInfo\Application;
use OCA\DiskMap\Service\TeamFolderService;
use OCA\DiskMap\Usage\InstanceIndex;
use OCA\DiskMap\Usage\InstanceTopLevelEntry;
use OCA\DiskMap\Usage\IUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Instance-wide admin views. Team folder totals are admin-only (plan §7): a
 * user restricted by advanced ACL to part of a folder must never learn its
 * full size, since that would reveal the existence of data they can't reach.
 */
class AdminApiController extends AdminController {

    public function __construct(
        IRequest $request,
        IUserSession $userSession,
        IGroupManager $groupManager,
        private TeamFolderService $teamFolderService,
        private InstanceIndex $instanceIndex,
        private IUsageSource $usageSource,
    ) {
        parent::__construct(Application::APP_ID, $request, $userSession, $groupManager);
    }

    public function teamFolders(): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }

        return new JSONResponse([
            'teamFolders' => $this->teamFolderService->listAll(),
        ]);
    }

    /**
     * Every external storage (files_external mounts: S3, SMB, WebDAV, local
     * …) as its own sidebar entry, so one is reachable directly instead of
     * only as a row buried in the whole-server tree.
     *
     * Admin-only for the same reason team folders are: a mount's total size
     * describes data the caller may have no access to.
     *
     * Deliberately thinner than teamFolders(): a mount has no quota and no
     * group assignment to report, and its trash/versions live in the *user's*
     * home storage rather than its own, so there is no files/trash/versions
     * split to make either — 'used' is the whole storage.
     */
    public function externalStorages(): JSONResponse {
        if (($guard = $this->requireAdmin()) !== null) {
            return $guard;
        }

        $storages = array_map(fn (InstanceTopLevelEntry $entry) => [
            'storageId' => $entry->storageId,
            'name' => $entry->name,
            // Clamped for the same reason every other size here is: a
            // filecache -1 is "not calculated", not negative space. It should
            // already be a lower bound by the time it reaches here (see
            // InstanceIndex::listExternalStorages()); the clamp is the
            // belt-and-braces the rest of this app applies uniformly.
            'used' => max(0, $entry->size),
            'sizeExact' => $entry->sizeExact,
            'lastUpdated' => $this->usageSource->lastUpdated(Scope::forStorage($entry->storageId)),
        ], $this->instanceIndex->externalStorages());

        return new JSONResponse([
            'externalStorages' => $storages,
        ]);
    }
}
