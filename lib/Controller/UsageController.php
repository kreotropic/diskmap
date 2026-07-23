<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Controller;

use OCA\DiskMap\AppInfo\Application;
use OCA\DiskMap\Service\UserStorageService;
use OCA\DiskMap\Usage\IUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Scope-based usage endpoints shared by the admin and user-facing views
 * (plan §8/§9): every request states an explicit scope, and this controller
 * — not the frontend — enforces who is allowed to read it.
 *
 *  - scope=user: only the caller's own uid, unless the caller is an admin.
 *  - scope=teamfolder / scope=storage: admin-only for now. A user restricted
 *    by advanced ACL to part of a team folder must never learn the total or
 *    largest-files list for the parts they can't see (plan §7); reconciling
 *    ACL-aware partial views is deferred, so exposing these scopes to
 *    non-admins is deferred with it. This still means the same aggregation
 *    code (IUsageSource) is ready for Phase 2's user-facing team-folder
 *    view without any rework — only this guard needs to relax.
 */
class UsageController extends Controller {

    public function __construct(
        IRequest $request,
        private IUsageSource $usageSource,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
        private UserStorageService $userStorageService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * The caller's own storage overview (files/trash/versions + quota
     * occupancy) — plan Phase 2. Always scoped to the logged-in account;
     * there is no identifier parameter to request someone else's.
     */
    #[NoAdminRequired]
    public function myOverview(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not logged in'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse($this->userStorageService->overview($user));
    }

    #[NoAdminRequired]
    public function largest(string $scope, string $identifier, string $path = '', int $limit = 50): JSONResponse {
        $limit = max(1, min($limit, 200));

        try {
            $scopeObj = Scope::fromRequest($scope, $identifier, $path);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $denied = $this->enforceScopeAccess($scopeObj);
        if ($denied !== null) {
            return $denied;
        }

        return new JSONResponse([
            'scope' => $scopeObj->type,
            'identifier' => $scopeObj->identifier,
            'path' => $scopeObj->path,
            'items' => $this->usageSource->largestFiles($scopeObj, $limit),
            'lastUpdated' => $this->usageSource->lastUpdated($scopeObj),
        ]);
    }

    private function enforceScopeAccess(Scope $scope): ?JSONResponse {
        $user = $this->userSession->getUser();
        $isAdmin = $user !== null && $this->groupManager->isAdmin($user->getUID());

        if ($scope->type === Scope::TYPE_USER) {
            if ($isAdmin || ($user !== null && $user->getUID() === $scope->identifier)) {
                return null;
            }
            return new JSONResponse(
                ['message' => 'You may only inspect your own storage'],
                Http::STATUS_FORBIDDEN,
            );
        }

        if (!$isAdmin) {
            return new JSONResponse(
                ['message' => 'Administrator privileges required'],
                Http::STATUS_FORBIDDEN,
            );
        }
        return null;
    }
}
