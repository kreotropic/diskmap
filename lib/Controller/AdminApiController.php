<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Controller;

use OCA\DiskMap\AppInfo\Application;
use OCA\DiskMap\Service\TeamFolderService;
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
}
