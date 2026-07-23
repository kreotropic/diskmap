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
 * Reads aggregated usage data directly from oc_filecache (Option B from the
 * plan, §5) instead of walking the IRootFolder/Node API. Ground truth
 * confirmed live against the NC33 dev instance:
 *  - the `fs_storage_size (storage, size, fileid)` index serves
 *    `WHERE storage = ? ORDER BY size DESC LIMIT n` with no filesort;
 *  - a folder's own filecache row already carries its recursive total in
 *    `size`, so totals never require traversal.
 *
 * Pure reads only — this class must never trigger a filesystem scan.
 */
class FilecacheUsageSource implements IUsageSource {

    private ?int $folderMimetypeIdCache = null;

    public function __construct(
        private IDBConnection $db,
        private LayoutDetector $layoutDetector,
        private UserHomeResolver $userHomeResolver,
    ) {
    }

    public function totalSize(Scope $scope): ?int {
        $root = $this->rootPath($scope);
        if ($root === null) {
            return null;
        }
        [$storageId, $path] = $root;
        return $this->sizeAtExactPath($storageId, $path);
    }

    public function lastUpdated(Scope $scope): ?int {
        $root = $this->rootPath($scope);
        if ($root === null) {
            return null;
        }
        [$storageId, $path] = $root;

        $qb = $this->db->getQueryBuilder();
        $qb->select('mtime')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetchAssociative();
        $result->closeCursor();

        return $row ? (int)$row['mtime'] : null;
    }

    public function largestFiles(Scope $scope, int $limit): array {
        $root = $this->rootPath($scope);
        if ($root === null) {
            return [];
        }
        [$storageId, $path] = $root;

        $qb = $this->db->getQueryBuilder();
        $qb->select('f.path', 'f.name', 'f.size', 'f.mtime', 'm.mimetype')
            ->from('filecache', 'f')
            ->leftJoin('f', 'mimetypes', 'm', $qb->expr()->eq('f.mimetype', 'm.id'))
            ->where($qb->expr()->eq('f.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->neq(
                'f.mimetype',
                $qb->createNamedParameter($this->folderMimetypeId(), IQueryBuilder::PARAM_INT),
            ));

        if ($path !== '') {
            $qb->andWhere($qb->expr()->like(
                'f.path',
                $qb->createNamedParameter($this->db->escapeLikeParameter($path) . '/%'),
            ));
        }

        $qb->orderBy('f.size', 'DESC')->setMaxResults($limit);

        $result = $qb->executeQuery();
        $nodes = [];
        while ($row = $result->fetchAssociative()) {
            $nodes[] = new UsageNode(
                name: (string)$row['name'],
                path: (string)$row['path'],
                size: (int)$row['size'],
                type: 'file',
                mimetype: $row['mimetype'] !== null ? (string)$row['mimetype'] : null,
                mtime: (int)$row['mtime'],
            );
        }
        $result->closeCursor();

        return $nodes;
    }

    /**
     * Resolves a scope to [numericStorageId, internalPath], or null when the
     * scope doesn't correspond to any known storage (e.g. a team folder id
     * that no longer exists, or a user with no home storage yet).
     *
     * @return array{0: int, 1: string}|null
     */
    private function rootPath(Scope $scope): ?array {
        return match ($scope->type) {
            Scope::TYPE_STORAGE => [(int)$scope->identifier, $scope->path],
            Scope::TYPE_USER => $this->userRoot($scope->identifier, $scope->path),
            Scope::TYPE_TEAM_FOLDER => $this->teamFolderRoot((int)$scope->identifier, $scope->path),
        };
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function userRoot(string $uid, string $subPath): ?array {
        $storageId = $this->userHomeResolver->resolveStorageId($uid);
        if ($storageId === null) {
            return null;
        }
        $path = 'files' . ($subPath !== '' ? '/' . $subPath : '');
        return [$storageId, $path];
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private function teamFolderRoot(int $folderId, string $subPath): ?array {
        $layout = $this->layoutDetector->resolve($folderId);
        if ($layout->filesStorageId === null) {
            return null;
        }
        $path = $layout->filesPath . ($subPath !== '' ? '/' . $subPath : '');
        return [$layout->filesStorageId, $path];
    }

    private function sizeAtExactPath(int $storageId, string $path): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('size')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetchAssociative();
        $result->closeCursor();

        return $row ? (int)$row['size'] : null;
    }

    private function folderMimetypeId(): int {
        if ($this->folderMimetypeIdCache === null) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('id')
                ->from('mimetypes')
                ->where($qb->expr()->eq('mimetype', $qb->createNamedParameter('httpd/unix-directory')))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetchAssociative();
            $result->closeCursor();

            // -1 never matches a real mimetype id, so a missing row safely
            // excludes nothing instead of crashing.
            $this->folderMimetypeIdCache = $row ? (int)$row['id'] : -1;
        }
        return $this->folderMimetypeIdCache;
    }
}
