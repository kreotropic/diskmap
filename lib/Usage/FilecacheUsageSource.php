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

    public function children(Scope $scope, int $limit): array {
        $root = $this->rootPath($scope);
        if ($root === null) {
            return ['root' => null, 'items' => [], 'truncated' => false];
        }
        [$storageId, $path] = $root;

        $rootRow = $this->rowAtExactPath($storageId, $path);
        if ($rootRow === null) {
            return ['root' => null, 'items' => [], 'truncated' => false];
        }

        [$rows, $truncated] = $this->fetchChildRows($storageId, $rootRow['fileid'], $limit);

        $folderMimetypeId = $this->folderMimetypeId();
        $items = array_map(function (array $row) use ($storageId, $folderMimetypeId) {
            $isFolder = (int)$row['mimetype'] === $folderMimetypeId;
            $size = (int)$row['size'];
            return new UsageNode(
                name: (string)$row['name'],
                path: (string)$row['path'],
                size: $size,
                type: $isFolder ? 'folder' : 'file',
                mimetype: !$isFolder && $row['mimetype_name'] !== null ? (string)$row['mimetype_name'] : null,
                mtime: (int)$row['mtime'],
                fileCount: $isFolder ? $this->recursiveFileCount($storageId, (string)$row['path'], $size) : null,
            );
        }, $rows);

        return [
            'root' => new UsageNode(
                name: $rootRow['name'],
                path: $path,
                size: $rootRow['size'],
                type: 'folder', // every rootPath() resolution is a directory
                mimetype: null,
                mtime: $rootRow['mtime'],
                fileCount: $this->recursiveFileCount($storageId, $path, $rootRow['size']),
            ),
            'items' => $items,
            'truncated' => $truncated,
        ];
    }

    /**
     * A recursive, node-budgeted tree for the WinDirStat-style map (plan
     * Phase 3c). Expands folders largest-first across the *whole* pending
     * frontier (not level by level) — a best-first search, not a BFS/DFS —
     * so the budget is always spent on the biggest, most map-relevant
     * content regardless of depth, mirroring which folders WinDirStat itself
     * would give the most screen space. Each expansion is one query (the
     * same parent-fileid lookup children() uses), bounded separately by
     * self::MAX_TREE_QUERIES so a pathologically wide/deep tree can't spend
     * an unbounded number of round trips even before the node budget runs out.
     */
    public function mapTree(Scope $scope, int $maxNodes): array {
        $root = $this->rootPath($scope);
        if ($root === null) {
            return ['root' => null];
        }
        [$storageId, $path] = $root;

        $rootRow = $this->rowAtExactPath($storageId, $path);
        if ($rootRow === null) {
            return ['root' => null];
        }

        $builderRoot = $this->makeBuilderNode(
            fileid: $rootRow['fileid'],
            name: $rootRow['name'],
            size: $rootRow['size'],
            type: 'folder',
            mimetype: null,
            mtime: $rootRow['mtime'],
        );

        $budget = $maxNodes;
        $queries = 0;
        $frontier = [$builderRoot];

        while ($budget > 1 && $queries < self::MAX_TREE_QUERIES && !empty($frontier)) {
            usort($frontier, static fn (array $a, array $b) => $b['size'] <=> $a['size']);
            $node = array_shift($frontier);
            if ($node['size'] <= 0) {
                continue;
            }

            [$rows, $truncated] = $this->fetchChildRows($storageId, $node['fileid'], self::TREE_LEVEL_LIMIT);
            $queries++;
            if (empty($rows)) {
                continue; // stays a leaf tile — already attached to its parent
            }

            $folderMimetypeId = $this->folderMimetypeId();
            $threshold = max(1, (int)floor($node['size'] * self::SMALL_FILE_RATIO));
            $big = [];
            $bigSum = 0;
            $smallCount = 0;
            foreach ($rows as $row) {
                $isFolder = (int)$row['mimetype'] === $folderMimetypeId;
                $size = (int)$row['size'];
                if ($size >= $threshold) {
                    $big[] = $this->makeBuilderNode(
                        fileid: (int)$row['fileid'],
                        name: (string)$row['name'],
                        size: $size,
                        type: $isFolder ? 'folder' : 'file',
                        mimetype: !$isFolder && $row['mimetype_name'] !== null ? (string)$row['mimetype_name'] : null,
                        mtime: (int)$row['mtime'],
                    );
                    $bigSum += max(0, $size);
                } else {
                    $smallCount++;
                }
            }

            // Whatever isn't individually represented by a "big" child —
            // fetched-but-small files AND (if $truncated) whatever exists
            // beyond TREE_LEVEL_LIMIT that was never even fetched. Computed
            // by subtraction from the folder's own known-accurate total
            // rather than summed from the small rows alone, so a truncated
            // level can never silently under-report size (children always
            // sum back to exactly $node['size'], truncated or not).
            $otherSize = max(0, $node['size'] - $bigSum);
            $newChildren = $big;
            if ($otherSize > 0 || $smallCount > 0) {
                $newChildren[] = [
                    'fileid' => 0,
                    'name' => '',
                    'size' => $otherSize,
                    'type' => 'other',
                    'mimetype' => null,
                    'mtime' => null,
                    'children' => null,
                    'fileCount' => $smallCount,
                    'countExact' => !$truncated,
                ];
            }

            $node['children'] = $newChildren;
            // Net node-count change: this folder is no longer its own tile
            // (-1) but contributes count($newChildren) new ones.
            $budget -= count($newChildren) - 1;

            foreach ($big as $child) {
                if ($child['type'] === 'folder' && $child['size'] > 0) {
                    $frontier[] = $child;
                }
            }

            // $node was passed by value (array), so the mutation above is
            // local — thread it back into whichever list is holding the
            // original reference (the root, or a parent's children array)
            // via the shared object wrapper each builder node carries.
            $node['ref']->children = $node['children'];
        }

        return ['root' => $this->toUsageNode($builderRoot['ref'])];
    }

    private const TREE_LEVEL_LIMIT = 500;
    private const MAX_TREE_QUERIES = 200;
    private const SMALL_FILE_RATIO = 0.003;

    /**
     * mapTree()'s in-progress nodes need to be both (a) sortable/poppable in
     * a plain array (the best-first frontier) and (b) mutable-by-reference
     * so filling in a node's children is visible through whatever parent
     * array already holds it. A plain PHP array can't do both at once
     * cleanly, so each builder "node" is an array (for sorting) carrying a
     * 'ref' key to a small mutable object (for the shared mutation) —
     * toUsageNode() walks 'ref' objects into the final readonly tree.
     */
    private function makeBuilderNode(int $fileid, string $name, int $size, string $type, ?string $mimetype, ?int $mtime): array {
        $ref = new \stdClass();
        $ref->fileid = $fileid;
        $ref->name = $name;
        $ref->size = $size;
        $ref->type = $type;
        $ref->mimetype = $mimetype;
        $ref->mtime = $mtime;
        $ref->children = null;
        $ref->fileCount = null;
        $ref->countExact = null;
        return [
            'fileid' => $fileid,
            'size' => $size,
            'type' => $type,
            'children' => null,
            'ref' => $ref,
        ];
    }

    private function toUsageNode(\stdClass $ref): UsageNode {
        return new UsageNode(
            name: $ref->name,
            path: '', // unused by mapTree() consumers — the client walks the
            // nested structure itself (same name-chain convention FolderTree
            // already uses for its navPath), so no relative-path computation
            // is duplicated here.
            size: $ref->size,
            type: $ref->type,
            mimetype: $ref->mimetype,
            mtime: $ref->mtime,
            fileCount: $ref->fileCount,
            children: $ref->children !== null ? array_map(
                fn (array $child) => $this->toUsageNode($child['ref'] ?? $this->wrapSyntheticChild($child)),
                $ref->children,
            ) : null,
            countExact: $ref->countExact,
        );
    }

    /**
     * The synthetic 'other' bucket built inline in mapTree()'s loop is a
     * plain array (no builder node / no further expansion possible), unlike
     * every other child which came from makeBuilderNode(). Wrap it in the
     * same stdClass shape toUsageNode() expects so both kinds convert the
     * same way.
     */
    private function wrapSyntheticChild(array $child): \stdClass {
        $ref = new \stdClass();
        $ref->name = $child['name'];
        $ref->size = $child['size'];
        $ref->type = $child['type'];
        $ref->mimetype = $child['mimetype'];
        $ref->mtime = $child['mtime'];
        $ref->children = $child['children'];
        $ref->fileCount = $child['fileCount'];
        $ref->countExact = $child['countExact'];
        return $ref;
    }

    /**
     * @return array{0: array<int, array{fileid:int,path:string,name:string,size:int,mtime:int,mimetype:int,mimetype_name:?string}>, 1: bool}
     */
    private function fetchChildRows(int $storageId, int $parentFileId, int $limit): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.fileid', 'f.path', 'f.name', 'f.size', 'f.mtime', 'f.mimetype')
            ->selectAlias('m.mimetype', 'mimetype_name')
            ->from('filecache', 'f')
            ->leftJoin('f', 'mimetypes', 'm', $qb->expr()->eq('f.mimetype', 'm.id'))
            ->where($qb->expr()->eq('f.storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('f.parent', $qb->createNamedParameter($parentFileId, IQueryBuilder::PARAM_INT)))
            ->orderBy('f.size', 'DESC')
            ->setMaxResults($limit + 1); // one extra row cheaply reveals truncation, no separate COUNT(*)

        $result = $qb->executeQuery();
        $rows = $result->fetchAllAssociative();
        $result->closeCursor();

        $truncated = count($rows) > $limit;
        if ($truncated) {
            array_pop($rows);
        }

        return [$rows, $truncated];
    }

    /**
     * Recursive descendant file count for one folder (plan Phase 3b — a
     * real, non-free query, accepted as a performance risk not yet
     * validated at production scale; see plan). Deliberately one query per
     * folder with a literal path, NOT a correlated subquery: EXPLAIN
     * confirmed live that a correlated `path LIKE CONCAT(outer.path, '/%')`
     * cannot get the fs_storage_path_prefix range scan (the bound isn't a
     * compile-time constant), while the exact same pattern with a literal
     * path does (`type: range`, confirmed at key_len 267 — the full
     * (storage, path) prefix). USE INDEX pins that choice so the optimizer
     * can't silently fall back to scanning every row for the storage.
     */
    private function recursiveFileCount(int $storageId, string $path, int $size): int {
        // A folder's own aggregate size already tells us whether it has any
        // descendants at all — skip the query for an empty folder.
        if ($size <= 0) {
            return 0;
        }

        $sql = 'SELECT COUNT(*) AS c FROM `*PREFIX*filecache` USE INDEX (fs_storage_path_prefix)
                WHERE storage = ? AND path LIKE ?';
        $result = $this->db->executeQuery($sql, [
            $storageId,
            $this->db->escapeLikeParameter($path) . '/%',
        ]);
        $row = $result->fetchAssociative();
        $result->closeCursor();

        return $row ? (int)$row['c'] : 0;
    }

    /**
     * @return array{fileid: int, name: string, size: int, mtime: int}|null
     */
    private function rowAtExactPath(int $storageId, string $path): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'name', 'size', 'mtime')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetchAssociative();
        $result->closeCursor();

        return $row ? [
            'fileid' => (int)$row['fileid'],
            'name' => (string)$row['name'],
            'size' => (int)$row['size'],
            'mtime' => (int)$row['mtime'],
        ] : null;
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
