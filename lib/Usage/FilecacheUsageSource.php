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
        private InstanceIndex $instanceIndex,
    ) {
    }

    public function totalSize(Scope $scope): ?int {
        if ($scope->type === Scope::TYPE_INSTANCE) {
            return $this->instanceTotalSize();
        }

        $root = $this->rootPath($scope);
        if ($root === null) {
            return null;
        }
        [$storageId, $path] = $root;
        return $this->sizeAtExactPath($storageId, $path);
    }

    public function lastUpdated(Scope $scope): ?int {
        if ($scope->type === Scope::TYPE_INSTANCE) {
            return $this->instanceLastUpdated();
        }

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
        if ($scope->type === Scope::TYPE_INSTANCE) {
            return $this->instanceChildren($scope, $limit);
        }

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
        if ($scope->type === Scope::TYPE_INSTANCE) {
            return $this->instanceMapTree($maxNodes);
        }

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
            storageId: $storageId,
            fileid: $rootRow['fileid'],
            name: $rootRow['name'],
            size: $rootRow['size'],
            type: 'folder',
            mimetype: null,
            mtime: $rootRow['mtime'],
        );

        $this->expandFrontier([$builderRoot], $maxNodes);

        return ['root' => $this->toUsageNode($builderRoot['ref'])];
    }

    /**
     * The whole-instance map (plan Phase 3d, admin-only): every user's home
     * + every team folder as top-level siblings — WinDirStat's own "each
     * drive is its own top-level entry" convention, since there's no real
     * common parent folder across independent storages to nest them under.
     * Past the top level this is identical to the single-storage case
     * (expandFrontier() doesn't know or care that its frontier started from
     * several different storages instead of one).
     */
    private function instanceMapTree(int $maxNodes): array {
        $entries = $this->instanceIndex->listAll();
        usort($entries, static fn (InstanceTopLevelEntry $a, InstanceTopLevelEntry $b) => $b->size <=> $a->size);
        $totalSize = array_sum(array_map(static fn (InstanceTopLevelEntry $e) => max(0, $e->size), $entries));

        // Same node-budget principle as everywhere else in this tree: if the
        // instance has more top-level entries than the budget allows, keep
        // the biggest ones individually and fold the rest into one "other"
        // tile rather than blowing the budget before any real expansion happens.
        $keepCount = max(1, $maxNodes - 1);
        $kept = array_slice($entries, 0, $keepCount);
        $overflow = array_slice($entries, $keepCount);

        $builderRoot = $this->makeBuilderNode(storageId: 0, fileid: 0, name: '', size: $totalSize, type: 'folder', mimetype: null, mtime: null);

        $topNodes = array_map(fn (InstanceTopLevelEntry $e) => $this->makeBuilderNode(
            storageId: $e->storageId,
            fileid: $e->fileid,
            name: $e->name,
            size: $e->size,
            type: 'folder',
            mimetype: null,
            mtime: $e->mtime,
        ), $kept);

        if (!empty($overflow)) {
            $overflowSum = array_sum(array_map(static fn (InstanceTopLevelEntry $e) => max(0, $e->size), $overflow));
            $topNodes[] = $this->makeOtherNode($overflowSum, count($overflow), true);
        }

        $builderRoot['ref']->children = $topNodes;

        $this->expandFrontier($topNodes, max(1, $maxNodes - count($topNodes)));

        return ['root' => $this->toUsageNode($builderRoot['ref'])];
    }

    private const TREE_LEVEL_LIMIT = 500;
    private const MAX_TREE_QUERIES = 200;
    private const SMALL_FILE_RATIO = 0.003;

    /**
     * The best-first expansion loop shared by mapTree() and
     * instanceMapTree(): repeatedly pops the single largest still-expandable
     * node from $frontier (regardless of which storage it came from — each
     * builder node carries its own storageId) and fetches its children,
     * until the node budget or the hard query cap is spent. See mapTree()'s
     * own docblock for why best-first (not BFS/DFS) is what makes the
     * budget land on the biggest, most map-relevant content.
     */
    private function expandFrontier(array $frontier, int $budget): void {
        $queries = 0;
        while ($budget > 1 && $queries < self::MAX_TREE_QUERIES && !empty($frontier)) {
            usort($frontier, static fn (array $a, array $b) => $b['size'] <=> $a['size']);
            $node = array_shift($frontier);
            if ($node['size'] <= 0) {
                continue;
            }

            [$rows, $truncated] = $this->fetchChildRows($node['storageId'], $node['fileid'], self::TREE_LEVEL_LIMIT);
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
                        storageId: $node['storageId'],
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
                $newChildren[] = $this->makeOtherNode($otherSize, $smallCount, !$truncated);
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
    }

    /**
     * mapTree()'s in-progress nodes need to be both (a) sortable/poppable in
     * a plain array (the best-first frontier) and (b) mutable-by-reference
     * so filling in a node's children is visible through whatever parent
     * array already holds it. A plain PHP array can't do both at once
     * cleanly, so each builder "node" is an array (for sorting) carrying a
     * 'ref' key to a small mutable object (for the shared mutation) —
     * toUsageNode() walks 'ref' objects into the final readonly tree.
     */
    private function makeBuilderNode(int $storageId, int $fileid, string $name, int $size, string $type, ?string $mimetype, ?int $mtime): array {
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
            'storageId' => $storageId,
            'fileid' => $fileid,
            'size' => $size,
            'type' => $type,
            'children' => null,
            'ref' => $ref,
        ];
    }

    /**
     * The synthetic "other" bucket, built as a plain array with no builder
     * 'ref' object (see wrapSyntheticChild()) since it's never itself a
     * candidate for further expansion — used both for small-file folding
     * within one folder and for instance-level user/team-folder overflow.
     */
    private function makeOtherNode(int $size, int $count, bool $countExact): array {
        return [
            'fileid' => 0,
            'name' => '',
            'size' => $size,
            'type' => 'other',
            'mimetype' => null,
            'mtime' => null,
            'children' => null,
            'fileCount' => $count,
            'countExact' => $countExact,
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

        // path='' only happens for an external storage's own root (plan
        // Phase 3d — its root filecache row lives at the literal empty
        // path, unlike a user/team-folder root which always starts under
        // "files"). Its children's paths have no leading slash to match, so
        // the pattern must be a bare '%' there instead of '<path>/%'.
        $likePattern = $path !== '' ? $this->db->escapeLikeParameter($path) . '/%' : '%';

        $sql = 'SELECT COUNT(*) AS c FROM `*PREFIX*filecache` USE INDEX (fs_storage_path_prefix)
                WHERE storage = ? AND path LIKE ?';
        $result = $this->db->executeQuery($sql, [
            $storageId,
            $likePattern,
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

    private function instanceTotalSize(): int {
        return array_sum(array_map(
            static fn (InstanceTopLevelEntry $e) => max(0, $e->size),
            $this->instanceIndex->listAll(),
        ));
    }

    private function instanceLastUpdated(): ?int {
        $entries = $this->instanceIndex->listAll();
        if (empty($entries)) {
            return null;
        }
        return max(array_map(static fn (InstanceTopLevelEntry $e) => $e->mtime ?? 0, $entries));
    }

    /**
     * children() for the whole-instance scope: at path='' every user + team
     * folder is a top-level row (same flat "each storage is its own
     * top-level entry" convention as instanceMapTree()); any deeper path
     * delegates entirely to the real per-storage scope that top segment
     * resolves to, via Scope::forStorage() — both a user's home and a team
     * folder are, past their own root, indistinguishable from any other
     * storage+path this class already knows how to browse.
     */
    private function instanceChildren(Scope $scope, int $limit): array {
        if ($scope->path === '') {
            $entries = $this->instanceIndex->listAll();
            usort($entries, static fn (InstanceTopLevelEntry $a, InstanceTopLevelEntry $b) => $b->size <=> $a->size);
            $totalSize = array_sum(array_map(static fn (InstanceTopLevelEntry $e) => max(0, $e->size), $entries));

            $truncated = count($entries) > $limit;
            $shown = $truncated ? array_slice($entries, 0, $limit) : $entries;

            $items = array_map(fn (InstanceTopLevelEntry $e) => new UsageNode(
                name: $e->name,
                path: $e->name,
                size: $e->size,
                type: 'folder',
                mimetype: null,
                mtime: $e->mtime,
                fileCount: $this->recursiveFileCount($e->storageId, $e->path, $e->size),
                kind: $e->kind,
            ), $shown);

            return [
                'root' => new UsageNode(name: '', path: '', size: $totalSize, type: 'folder'),
                'items' => $items,
                'truncated' => $truncated,
            ];
        }

        $delegate = $this->resolveInstanceDelegate($scope->path);
        if ($delegate === null) {
            return ['root' => null, 'items' => [], 'truncated' => false];
        }
        return $this->children($delegate, $limit);
    }

    /**
     * The start of the path under the instance scope always names one
     * top-level entry — a user's uid, a team folder's mount point, or an
     * external storage's display name — resolve it to the real,
     * already-known [storageId, path] from InstanceIndex and hand the rest
     * to Scope::forStorage(), a plain passthrough rootPath() already
     * handles. Matched as a path *prefix*, not just the first '/'-segment:
     * an external storage's display name can itself contain slashes (e.g.
     * "tmp/nc-exttest" from a local-backend mount), so splitting on the
     * first '/' would never match it. Picks the longest matching entry name
     * in case one entry's name happens to be a prefix of another's.
     */
    private function resolveInstanceDelegate(string $path): ?Scope {
        $best = null;
        foreach ($this->instanceIndex->listAll() as $entry) {
            $isMatch = $path === $entry->name || str_starts_with($path, $entry->name . '/');
            if ($isMatch && ($best === null || strlen($entry->name) > strlen($best->name))) {
                $best = $entry;
            }
        }
        if ($best === null) {
            return null;
        }

        $rest = $path === $best->name ? '' : substr($path, strlen($best->name) + 1);
        // entry->path is '' for external storages (their root is at the
        // literal empty filecache path, unlike a user/team-folder root
        // which always starts at "files") — only join with '/' when
        // there's a real prefix to join onto, or an external storage's own
        // top-level children would get a bogus leading slash that doesn't
        // match any real filecache path.
        $subPath = $best->path !== '' ? $best->path . ($rest !== '' ? '/' . $rest : '') : $rest;
        return Scope::forStorage($best->storageId, $subPath);
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
