<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Controller;

use OCA\DiskMap\AppInfo\Application;
use OCA\DiskMap\Service\UserStorageService;
use OCA\DiskMap\Usage\InstanceIndex;
use OCA\DiskMap\Usage\IUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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
        private InstanceIndex $instanceIndex,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * The caller's own storage overview (files/trash/versions + quota
     * occupancy) — plan Phase 2. Always scoped to the logged-in account;
     * there is no identifier parameter to request someone else's.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function myOverview(): JSONResponse {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['message' => 'Not logged in'], Http::STATUS_FORBIDDEN);
        }

        return new JSONResponse($this->userStorageService->overview($user));
    }

    /**
     * One level of immediate children (files and folders) under $scope's
     * path — NOT recursive, unlike map(). Powers the WinDirStat-style
     * expandable tree pane (plan Phase 3b): each expand click fetches
     * exactly one more level.
     *
     * Every figure in the response comes straight from the rows themselves,
     * so this is a bounded, indexed read whatever the subtree below holds.
     * The tree's two recursive columns are served by composition() below.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 300, period: 60)]
    public function children(string $scope, string $identifier, string $path = '', int $limit = 200): JSONResponse {
        return $this->respondForScope($scope, $identifier, $path, $limit, function (Scope $scopeObj, int $limit) {
            $result = $this->usageSource->children($scopeObj, $limit);
            return [
                'root' => $result['root'],
                'items' => $result['items'],
                'truncated' => $result['truncated'],
            ];
        });
    }

    /**
     * The recursive descendant file count + per-mimetype breakdown for the
     * same level children() returns, keyed by child name.
     *
     * Its own endpoint because it is the one read here whose cost grows with
     * the whole subtree instead of with $limit — on a large folder that is
     * seconds, against tens of milliseconds for the listing. Splitting them
     * lets the tree pane paint its rows from children() straight away and
     * fill the "Composition" and "File count" columns in when this answers,
     * rather than the whole level waiting on the biggest folder in it.
     *
     * Rate-limited like children() rather than like the heavier map(): the
     * frontend fires exactly one of these per one of those, and the cache
     * behind it (FilecacheUsageSource + CompositionCache) means the repeat
     * cost of browsing back over the same folders is a lookup, not a scan.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 300, period: 60)]
    public function composition(string $scope, string $identifier, string $path = '', int $limit = 200): JSONResponse {
        return $this->respondForScope($scope, $identifier, $path, $limit, function (Scope $scopeObj, int $limit) {
            $result = $this->usageSource->childComposition($scopeObj, $limit);
            return [
                'root' => $result['root'] !== null ? $this->serializeAggregate($result['root']) : null,
                // Cast to an object so this is always a JSON map keyed by
                // child name. PHP turns decimal-string array keys into ints,
                // and a level whose folders happen to be named 0, 1, 2 would
                // otherwise be a list to json_encode() and come out as a JSON
                // array — a different response shape for nothing but the
                // folder names involved.
                'items' => (object)array_map($this->serializeAggregate(...), $result['items']),
            ];
        });
    }

    /**
     * IUsageSource speaks of a descendant 'count'; every JSON response this
     * app serves calls that field 'fileCount' (see UsageNode), and the tree
     * pane reads it under that name on rows that came from children(). Rename
     * it here, at the HTTP boundary, so one level's two requests describe the
     * same row with the same vocabulary.
     *
     * @param array{count: int, composition: array<string, int>} $aggregate
     * @return array{fileCount: int, composition: array<string, int>}
     */
    private function serializeAggregate(array $aggregate): array {
        return [
            'fileCount' => $aggregate['count'],
            'composition' => $aggregate['composition'],
        ];
    }

    /**
     * The shared preamble of children() and composition(), which must agree
     * on all three of it: the same $limit clamp (so the two halves of one
     * level describe the same set of rows), the same scope parsing, and the
     * same authorization check.
     *
     * @param callable(Scope, int): array $handler
     */
    private function respondForScope(string $scope, string $identifier, string $path, int $limit, callable $handler): JSONResponse {
        // Bounds the most expensive request this app can be asked to serve,
        // not just how many rows come back. Lowered from 1000: the frontend
        // has only ever asked for 200 (FolderTree's CHILD_LIMIT), and the
        // rate limits above are sized against this worst case.
        $limit = max(1, min($limit, 500));

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
            ...$handler($scopeObj, $limit),
        ]);
    }

    /**
     * The recursive, folder-nested tree the map (Treemap.vue) renders (plan
     * Phase 3c) — files nested inside folders, like a real WinDirStat map.
     * Replaced an earlier flat top-N-files-by-size endpoint (usage#largest,
     * removed): that version couldn't cluster files by folder, so selecting
     * a folder in the tree pane had no matching region to highlight in the
     * map. Node-budgeted (see IUsageSource::mapTree()) so a huge scope still
     * returns in roughly bounded time.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function map(string $scope, string $identifier, string $path = '', int $maxNodes = 1200): JSONResponse {
        // Ceiling raised from 800 to 2000 (the plan's own original "SVG up to
        // ~2000 rectangles" target) once production confirmed this stays
        // fast even on a 300GB+/360k-file team folder — see
        // MAX_TREE_QUERIES's matching bump, which is what actually lets a
        // higher node budget get spent instead of hitting the query cap first.
        $maxNodes = max(20, min($maxNodes, 2000));

        try {
            $scopeObj = Scope::fromRequest($scope, $identifier, $path);
        } catch (\InvalidArgumentException $e) {
            return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }

        $denied = $this->enforceScopeAccess($scopeObj);
        if ($denied !== null) {
            return $denied;
        }

        $result = $this->usageSource->mapTree($scopeObj, $maxNodes);

        return new JSONResponse([
            'scope' => $scopeObj->type,
            'identifier' => $scopeObj->identifier,
            'path' => $scopeObj->path,
            'root' => $result['root'],
            'lastUpdated' => $this->usageSource->lastUpdated($scopeObj),
        ]);
    }

    /**
     * The whole-instance header total (plan Phase 3d follow-up) — files +
     * trash + versions across every user and team folder, matching the
     * "used" convention myOverview()/adminApi#teamFolders already use, plus
     * the files-only figure the tree/map below actually browse. Deliberately
     * NOT part of map()'s own response: mapTree()'s root.size is a real
     * structural total (the sum its rendered tiles must add up to), while
     * this is a header-only aggregate that intentionally includes space the
     * map never draws a tile for.
     */
    #[NoAdminRequired]
    #[UserRateLimit(limit: 60, period: 60)]
    public function instanceOverview(): JSONResponse {
        $denied = $this->enforceScopeAccess(Scope::forInstance());
        if ($denied !== null) {
            return $denied;
        }

        $totals = $this->instanceIndex->totals();

        return new JSONResponse([
            'used' => $totals['used'],
            'filesSize' => $totals['filesOnly'],
            'lastUpdated' => $this->usageSource->lastUpdated(Scope::forInstance()),
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
