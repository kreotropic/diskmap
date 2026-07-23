<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Service;

use OCA\DiskMap\GroupFolders\LayoutDetector;
use OCA\DiskMap\Usage\IUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCP\IDBConnection;

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
    ) {
    }

    /**
     * @return array<int, array{
     *   id: int, name: string, quota: int|null, used: int,
     *   filesSize: int, trashSize: int, versionsSize: int,
     *   occupancyPercent: float|null, separateStorage: bool,
     *   groups: array<int, array{id: string, type: string, permissions: int}>,
     *   lastUpdated: int|null,
     * }>
     */
    public function listAll(): array {
        $groupsByFolder = $this->fetchGroupsByFolder();

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
            ];
        }

        return $result;
    }

    private function sizeFor(?int $storageId, string $path): int {
        if ($storageId === null) {
            return 0;
        }
        return $this->usageSource->totalSize(Scope::forStorage($storageId, $path)) ?? 0;
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
        $rows = $result->fetchAllAssociative();
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
        while ($row = $result->fetchAssociative()) {
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
