<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Service;

use OCA\DiskMap\GroupFolders\LayoutDetector;
use OCA\DiskMap\Usage\IUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserSession;

/**
 * Reads team-folder metadata (name, quota, linked groups/circles) directly
 * from the groupfolders app's own tables via SQL, rather than depending on
 * its PHP classes (OCA\GroupFolders\*), which are an internal app API and
 * not guaranteed stable across versions (plan: reuse the "read core tables
 * directly" pattern, not the app's classes). Sizes come from IUsageSource +
 * LayoutDetector, which resolve the per-folder files/trash/versions storage
 * regardless of layout (plan §4).
 */
class TeamFolderService {
    public function __construct(
        private IDBConnection $db,
        private IUsageSource $usageSource,
        private LayoutDetector $layoutDetector,
        private IUserSession $userSession,
        private IGroupManager $groupManager,
    ) {
    }

    /**
     * @return array<int, array{
     *   id: int, name: string, quota: int|null, used: int,
     *   filesSize: int, trashSize: int, versionsSize: int,
     *   occupancyPercent: float|null, separateStorage: bool,
     *   groups: array<int, array{id: string, type: string, permissions: int}>,
     *   lastUpdated: int|null,
     *   filesAccessible: bool|null,
     * }>
     */
    public function listAll(): array {
        // Guards every read below: fetchFolders() and fetchGroupsByFolder()
        // both go straight at the groupfolders app's tables, which are absent
        // entirely on an instance that never installed it.
        if (!$this->layoutDetector->teamFoldersAvailable()) {
            return [];
        }

        $groupsByFolder = $this->fetchGroupsByFolder();
        $callerUid = $this->userSession->getUser()?->getUID();

        $result = [];
        foreach ($this->fetchFolders() as $folder) {
            $folderId = (int)$folder['folder_id'];
            $layout = $this->layoutDetector->resolve($folderId);

            $filesSize = $this->sizeFor($layout->filesStorageId, $layout->filesPath);
            $trashSize = $this->sizeFor($layout->trashStorageId, $layout->trashPath);
            $versionsSize = $this->sizeFor($layout->versionsStorageId, $layout->versionsPath);
            $used = $filesSize + $trashSize + $versionsSize;

            // Convention shared with groupfolders: a negative (or absent)
            // quota means unlimited, so there's nothing to occupy a % of.
            $quotaRaw = $folder['quota'] !== null ? (int)$folder['quota'] : null;
            $quota = ($quotaRaw !== null && $quotaRaw >= 0) ? $quotaRaw : null;

            $lastUpdated = $layout->filesStorageId !== null
                ? $this->usageSource->lastUpdated(Scope::forStorage($layout->filesStorageId, $layout->filesPath))
                : null;

            $result[] = [
                'id' => $folderId,
                'name' => (string)$folder['mount_point'],
                'quota' => $quota,
                'used' => $used,
                'filesSize' => $filesSize,
                'trashSize' => $trashSize,
                'versionsSize' => $versionsSize,
                'occupancyPercent' => Aggregator::occupancyPercent($used, $quota),
                'separateStorage' => $layout->separateStorage,
                'groups' => $groupsByFolder[$folderId] ?? [],
                'lastUpdated' => $lastUpdated,
                'filesAccessible' => $this->filesAccessible($groupsByFolder[$folderId] ?? [], $callerUid),
            ];
        }

        return $result;
    }

    /**
     * Clamped at 0 for the same reason UserStorageService::sizeFor() is: a
     * filecache size of -1 means "not yet calculated", not "negative space".
     */
    private function sizeFor(?int $storageId, string $path): int {
        if ($storageId === null) {
            return 0;
        }
        return max(0, $this->usageSource->totalSize(Scope::forStorage($storageId, $path)) ?? 0);
    }

    /**
     * DiskMap reads every team folder's usage directly from the filecache
     * regardless of who is asking (admin-only, see the class-level plan
     * reference), but the admin viewing it may well not be a *member* of the
     * folder — being a server admin does not, by itself, mount a team folder
     * into anyone's Files view. This tells the frontend whether its own
     * "Open in Files" deep link can actually be expected to work, so it can
     * warn instead of linking somewhere that 404s.
     *
     * true only when the caller matches one of the folder's *group*
     * assignments — the one case this can check without depending on the
     * Circles app. false when every assignment is a checkable group and none
     * matched, OR when there are no assignments at all: group-folders' base
     * mount mechanism is what puts a folder into anyone's Files view, and an
     * empty assignment list means it mounts for nobody — confirmed live
     * against a "Grupo ou equipa: Nenhum" folder in the admin settings, which
     * this method originally (wrongly) treated as "can't tell". Advanced
     * permissions only refines what already-mounted members can do within
     * the folder; it does not mount it for anyone outside the assigned
     * groups/circles. null ("can't tell") only when a circle is involved —
     * this app deliberately avoids the Circles API, same reasoning as
     * avoiding OCA\GroupFolders\* (see the class docblock) — the frontend
     * treats null the same as true rather than raising a false alarm on
     * something it can't actually rule out.
     *
     * @param array<int, array{id: string, type: string, permissions: int}> $groups
     */
    private function filesAccessible(array $groups, ?string $callerUid): ?bool {
        if ($callerUid === null) {
            return null;
        }
        $hasCircle = false;
        foreach ($groups as $assignment) {
            if ($assignment['type'] === 'circle') {
                $hasCircle = true;
                continue;
            }
            if ($this->groupManager->isInGroup($callerUid, $assignment['id'])) {
                return true;
            }
        }
        return $hasCircle ? null : false;
    }

    /**
     * @return array<int, array{folder_id: mixed, mount_point: mixed, quota: mixed}>
     */
    private function fetchFolders(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point', 'quota')
            ->from('group_folders')
            ->orderBy('mount_point', 'ASC');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        return $rows;
    }

    /**
     * @return array<int, array<int, array{id: string, type: string, permissions: int}>>
     */
    private function fetchGroupsByFolder(): array {
        try {
            return $this->fetchGroupsByFolderRaw(withCircles: true);
        } catch (\Throwable $e) {
            // Older groupfolders releases (pre-Teams support, still current
            // on some NC 32 installs) lack the circle_id column — fall back
            // to group-only mappings instead of hard-failing the whole view.
            return $this->fetchGroupsByFolderRaw(withCircles: false);
        }
    }

    /**
     * @return array<int, array<int, array{id: string, type: string, permissions: int}>>
     */
    private function fetchGroupsByFolderRaw(bool $withCircles): array {
        $qb = $this->db->getQueryBuilder();
        $columns = $withCircles
            ? ['folder_id', 'group_id', 'circle_id', 'permissions']
            : ['folder_id', 'group_id', 'permissions'];

        $qb->select(...$columns)->from('group_folders_groups');

        $result = $qb->executeQuery();
        $byFolder = [];
        while ($row = $result->fetch()) {
            $folderId = (int)$row['folder_id'];
            $circleId = $withCircles ? (string)($row['circle_id'] ?? '') : '';

            $byFolder[$folderId][] = [
                'id' => $circleId !== '' ? $circleId : (string)$row['group_id'],
                'type' => $circleId !== '' ? 'circle' : 'group',
                'permissions' => (int)$row['permissions'],
            ];
        }
        $result->closeCursor();

        return $byFolder;
    }
}
