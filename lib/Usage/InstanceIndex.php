<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

use OCA\DiskMap\GroupFolders\LayoutDetector;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Lists every top-level entry of the whole-instance scope (plan Phase 3d):
 * every user's home + every team folder, each already resolved to a real
 * [storage, path, fileid, size] — what FilecacheUsageSource::mapTree()/
 * children() need to treat them exactly like any other expandable folder,
 * without special-casing past the top level.
 *
 * Deliberately its own class rather than more private methods on
 * FilecacheUsageSource (already large after the recursive map rewrite) —
 * same "one focused class per concern" pattern as LayoutDetector/
 * UserHomeResolver/TeamFolderService. Pure reads only.
 */
class InstanceIndex {
    public function __construct(
        private IDBConnection $db,
        private LayoutDetector $layoutDetector,
    ) {
    }

    /** @return InstanceTopLevelEntry[] */
    public function listAll(): array {
        return [...$this->listUsers(), ...$this->listTeamFolders()];
    }

    /**
     * One query for every user's home, regardless of how many accounts
     * exist — a storages↔filecache join on the "files" row, rather than
     * looping IUserManager and resolving each uid individually. Matches
     * home::<uid> (database-backed accounts) and object::user:<uid>
     * (primary object storage), the same two prefixes UserHomeResolver
     * checks for a single uid.
     *
     * @return InstanceTopLevelEntry[]
     */
    private function listUsers(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.id', 's.numeric_id', 'f.fileid', 'f.size', 'f.mtime')
            ->from('storages', 's')
            ->innerJoin('s', 'filecache', 'f', $qb->expr()->andX(
                $qb->expr()->eq('f.storage', 's.numeric_id'),
                $qb->expr()->eq('f.path', $qb->createNamedParameter('files')),
            ))
            ->where($qb->expr()->orX(
                $qb->expr()->like('s.id', $qb->createNamedParameter('home::%')),
                $qb->expr()->like('s.id', $qb->createNamedParameter('object::user:%')),
            ));

        $result = $qb->executeQuery();
        $entries = [];
        while ($row = $result->fetchAssociative()) {
            $storageKey = (string)$row['id'];
            $uid = str_starts_with($storageKey, 'home::')
                ? substr($storageKey, strlen('home::'))
                : substr($storageKey, strlen('object::user:'));
            if ($uid === '') {
                continue;
            }

            $entries[] = new InstanceTopLevelEntry(
                name: $uid,
                kind: 'user',
                storageId: (int)$row['numeric_id'],
                path: 'files',
                fileid: (int)$row['fileid'],
                size: (int)$row['size'],
                mtime: (int)$row['mtime'],
            );
        }
        $result->closeCursor();

        return $entries;
    }

    /**
     * One query per team folder (same cost the existing admin team-folder
     * overview already pays via TeamFolderService::listAll(), which this
     * mirrors) — typically far fewer than users, so unlike listUsers() this
     * isn't worth bulk-joining across LayoutDetector's per-folder logic.
     *
     * @return InstanceTopLevelEntry[]
     */
    private function listTeamFolders(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')->from('group_folders');
        $result = $qb->executeQuery();
        $rows = $result->fetchAllAssociative();
        $result->closeCursor();

        $entries = [];
        foreach ($rows as $row) {
            $layout = $this->layoutDetector->resolve((int)$row['folder_id']);
            if ($layout->filesStorageId === null) {
                continue;
            }
            $rootRow = $this->rowAtExactPath($layout->filesStorageId, $layout->filesPath);
            if ($rootRow === null) {
                continue;
            }
            $entries[] = new InstanceTopLevelEntry(
                name: (string)$row['mount_point'],
                kind: 'teamfolder',
                storageId: $layout->filesStorageId,
                path: $layout->filesPath,
                fileid: $rootRow['fileid'],
                size: $rootRow['size'],
                mtime: $rootRow['mtime'],
            );
        }

        return $entries;
    }

    /**
     * @return array{fileid: int, size: int, mtime: int}|null
     */
    private function rowAtExactPath(int $storageId, string $path): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'size', 'mtime')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetchAssociative();
        $result->closeCursor();

        return $row ? [
            'fileid' => (int)$row['fileid'],
            'size' => (int)$row['size'],
            'mtime' => (int)$row['mtime'],
        ] : null;
    }
}
