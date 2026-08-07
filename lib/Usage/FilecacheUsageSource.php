<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
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
        private CompositionCache $compositionCache,
    ) {
    }

    /** The aggregate of a folder with nothing under it — see recursiveComposition(). */
    private const EMPTY_COMPOSITION = ['count' => 0, 'composition' => []];

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
        $row = $result->fetch();
        $result->closeCursor();

        if (!$row) {
            return null;
        }

        // An external storage's root row is created with mtime 0 and only
        // gets a real one once something scans it — rendering that verbatim
        // dated the whole view to January 1970. Null is the "unknown" the
        // callers already handle.
        $mtime = (int)$row['mtime'];

        return $mtime > 0 ? $mtime : null;
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
        [$rootSize, $rootExact] = $this->resolveRootSize($storageId, $path, $rootRow['size']);

        $folderMimetypeId = $this->folderMimetypeId();
        $items = array_map(function (array $row) use ($folderMimetypeId) {
            $isFolder = (int)$row['mimetype'] === $folderMimetypeId;
            // A folder inside an unscanned external storage is itself
            // unscanned, so it has no cached descendants to bound its size
            // with — 0 with the inexact flag is the honest answer, and it is
            // what tells the tree to keep offering the expand arrow. (No
            // per-row lower-bound query here for exactly that reason: it
            // would sum an empty set every time.)
            $rawSize = (int)$row['size'];
            $sizeKnown = $rawSize >= 0;

            return new UsageNode(
                name: (string)$row['name'],
                path: (string)$row['path'],
                size: $sizeKnown ? $rawSize : 0,
                type: $isFolder ? 'folder' : 'file',
                mimetype: !$isFolder && $row['mimetype_name'] !== null ? (string)$row['mimetype_name'] : null,
                mtime: (int)$row['mtime'],
                sizeExact: $sizeKnown ? null : false,
            );
        }, $rows);

        return [
            'root' => new UsageNode(
                // A storage root (path '') has an empty name in the cache —
                // it is the storage, not a folder inside one. Callers that
                // render the root from the response rather than from their
                // own label would show a blank heading, so fall back to the
                // scope's own identifier.
                name: $rootRow['name'] !== '' ? $rootRow['name'] : $scope->identifier,
                path: $path,
                size: $rootSize,
                type: 'folder', // every rootPath() resolution is a directory
                mimetype: null,
                mtime: $rootRow['mtime'],
                sizeExact: $rootExact ? null : false,
            ),
            'items' => $items,
            'truncated' => $truncated,
        ];
    }

    /**
     * The recursive per-folder aggregates children() deliberately leaves out:
     * descendant file count and per-mimetype size breakdown, for one level's
     * worth of folders at once.
     *
     * Split off from children() because the two have completely different
     * cost profiles and the UI can use them at different times. Listing a
     * level is a bounded, indexed lookup (~40 ms even mid-tree on a large
     * instance); these aggregates are linear in the *whole subtree* below
     * each row, which on the whole-server view is what made opening a folder
     * take seconds. Serving them separately lets the tree render its rows
     * immediately and fill the two aggregate columns in when they arrive,
     * instead of the whole level waiting on the slowest folder in it.
     *
     * Three things keep the cost down, in order of how much they matter:
     *  1. CompositionCache — a hit costs nothing and needs no query at all.
     *  2. compositionByChild() — one query for the level instead of one per
     *     folder in it.
     *  3. the provablyEmpty() short-circuit, shared with recursiveComposition().
     *
     * @return array{
     *     root: ?array{count: int, composition: array<string, int>},
     *     items: array<string, array{count: int, composition: array<string, int>}>, // keyed by child name
     * }
     */
    public function childComposition(Scope $scope, int $limit): array {
        if ($scope->type === Scope::TYPE_INSTANCE) {
            if ($scope->path === '') {
                return $this->instanceRootComposition($limit);
            }
            // Past its own root the instance scope is just some other
            // storage+path, exactly as instanceChildren() treats it.
            $delegate = $this->resolveInstanceDelegate($scope->path);
            if ($delegate === null) {
                return ['root' => null, 'items' => []];
            }
            return $this->childComposition($delegate, $limit);
        }

        $root = $this->rootPath($scope);
        if ($root === null) {
            return ['root' => null, 'items' => []];
        }
        [$storageId, $path] = $root;

        $rootRow = $this->rowAtExactPath($storageId, $path);
        if ($rootRow === null) {
            return ['root' => null, 'items' => []];
        }

        // Only folders get an aggregate: a file's own mimetype already says
        // everything about its composition, and its "descendant count" is
        // meaningless. Fetching the child rows again (rather than having
        // children() pass them over) costs one indexed lookup and keeps this
        // endpoint independently callable — the two requests are concurrent,
        // not chained, so the tree isn't waiting on this one either way.
        [$rows] = $this->fetchChildRows($storageId, $rootRow['fileid'], $limit);
        $folderMimetypeId = $this->folderMimetypeId();
        // A list of records, NOT a name-keyed map: PHP silently converts a
        // decimal-string array key to an int, so a folder called "2024" would
        // come back out of a foreach as int 2024 and blow up the first
        // string-typed call it reaches. (It did — caught by the equivalence
        // check against a real team folder whose subfolders are years.)
        $folders = [];
        foreach ($rows as $row) {
            if ((int)$row['mimetype'] !== $folderMimetypeId) {
                continue;
            }
            $folders[] = ['name' => (string)$row['name'], 'size' => (int)$row['size'], 'mtime' => (int)$row['mtime']];
        }

        $items = [];
        $missing = false;
        foreach ($folders as $folder) {
            if (self::provablyEmpty($folder['size'])) {
                $items[$folder['name']] = self::EMPTY_COMPOSITION;
                continue;
            }
            $hit = $this->compositionCache->get($storageId, $this->joinPath($path, $folder['name']), $folder['size'], $folder['mtime']);
            if ($hit === null) {
                $missing = true;
            } else {
                $items[$folder['name']] = $hit;
            }
        }

        $rootAggregate = !self::provablyEmpty($rootRow['size'])
            ? $this->compositionCache->get($storageId, $path, $rootRow['size'], $rootRow['mtime'])
            : self::EMPTY_COMPOSITION;
        if ($rootAggregate === null) {
            $missing = true;
        }

        // One miss anywhere in the level costs the same query as all of them
        // missing (it scans the parent's whole subtree either way), so there
        // is nothing to gain from being selective — and in practice the two
        // travel together: the parent's own size/mtime stamp moves whenever
        // any descendant changes, so a child miss is almost always
        // accompanied by a root miss.
        if ($missing) {
            $grouped = $this->compositionByChild($storageId, $path);

            // Every descendant row falls into exactly one immediate-child
            // group, so the parent's own aggregate is their sum — no separate
            // query, and it stays exact even when the level was truncated
            // (compositionByChild() knows nothing about $limit).
            $rootAggregate = self::EMPTY_COMPOSITION;
            foreach ($grouped as $aggregate) {
                $rootAggregate['count'] += $aggregate['count'];
                foreach ($aggregate['composition'] as $mimetype => $size) {
                    $rootAggregate['composition'][$mimetype] = ($rootAggregate['composition'][$mimetype] ?? 0) + $size;
                }
            }
            $this->compositionCache->set($storageId, $path, $rootRow['size'], $rootRow['mtime'], $rootAggregate);

            foreach ($folders as $folder) {
                if (self::provablyEmpty($folder['size'])) {
                    continue;
                }
                // compositionByChild() keys by full path, not by bare name.
                // A folder holding nothing but empty subfolders produces no
                // group of its own (folder rows are excluded from the
                // aggregate) — that's a real empty result, not a lookup miss.
                $childPath = $this->joinPath($path, $folder['name']);
                $items[$folder['name']] = $grouped[$childPath] ?? self::EMPTY_COMPOSITION;
                $this->compositionCache->set($storageId, $childPath, $folder['size'], $folder['mtime'], $items[$folder['name']]);
            }
        }

        return ['root' => $rootAggregate, 'items' => $items];
    }

    /**
     * childComposition() for the whole-instance root, whose "children" are
     * whole storages rather than folders in one — already batched by
     * compositionsForEntries(), which is also where their caching lives.
     *
     * @return array{root: array{count: int, composition: array<string, int>}, items: array<string, array{count: int, composition: array<string, int>}>}
     */
    private function instanceRootComposition(int $limit): array {
        $entries = $this->instanceIndex->listAll();
        // Same ordering and truncation instanceChildren() applies, so the
        // names here line up with the rows the tree actually rendered.
        usort($entries, static fn (InstanceTopLevelEntry $a, InstanceTopLevelEntry $b) => $b->size <=> $a->size);
        $shown = array_slice($entries, 0, $limit);
        $compositions = $this->compositionsForEntries($shown);

        $items = [];
        $root = self::EMPTY_COMPOSITION;
        foreach ($shown as $entry) {
            $aggregate = $compositions[$this->compositionKey($entry->storageId, $entry->path)];
            $items[$entry->name] = $aggregate;
            $root['count'] += $aggregate['count'];
            foreach ($aggregate['composition'] as $mimetype => $size) {
                $root['composition'][$mimetype] = ($root['composition'][$mimetype] ?? 0) + $size;
            }
        }

        // Reflects $shown only — a truncated top-level list leaves out
        // whatever is beyond $limit, the same honesty tradeoff
        // instanceChildren()'s own root size already makes.
        return ['root' => $root, 'items' => $items];
    }

    /**
     * Joins a parent path and a child name, handling the one path that isn't
     * a normal folder path: an external storage's root lives at the literal
     * empty string, so its children have no leading slash to join onto.
     */
    private function joinPath(string $parent, string $name): string {
        return $parent !== '' ? $parent . '/' . $name : $name;
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

        // Without this the map of an unscanned external storage came back
        // completely empty: every tile area and expansion threshold here is a
        // ratio against the root's own size, and a root of -1 makes each one
        // of them collapse to nothing. The known-rows lower bound is a real
        // number those ratios work against.
        [$rootSize, $rootExact] = $this->resolveRootSize($storageId, $path, $rootRow['size']);

        $builderRoot = $this->makeBuilderNode(
            storageId: $storageId,
            fileid: $rootRow['fileid'],
            name: $rootRow['name'],
            size: $rootSize,
            type: 'folder',
            mimetype: null,
            mtime: $rootRow['mtime'],
            sizeExact: $rootExact ? null : false,
        );

        $this->expandFrontier([$builderRoot], $maxNodes, $rootSize);

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
        //
        // Capped at MAX_INSTANCE_TOP_TILES (not just maxNodes - 1): on an
        // instance with hundreds of accounts, "maxNodes - 1" alone reserves
        // almost the *entire* node budget just for flat top-level breadth,
        // leaving expandFrontier() with essentially nothing to recurse with
        // — every tile stays a flat, unexpanded leaf (folders, never files).
        // Confirmed live: an instance with ~300+ top-level entries produced
        // a map with zero recursion under the old formula. Capping breadth
        // keeps the biggest accounts individually named while guaranteeing
        // most of the budget goes to actually drilling into their contents.
        $keepCount = max(1, min($maxNodes - 1, self::MAX_INSTANCE_TOP_TILES));
        $kept = array_slice($entries, 0, $keepCount);
        $overflow = array_slice($entries, $keepCount);

        $builderRoot = $this->makeBuilderNode(storageId: 0, fileid: 0, name: '', size: $totalSize, type: 'folder', mimetype: null, mtime: null);

        $keptNodes = array_map(fn (InstanceTopLevelEntry $e) => $this->makeBuilderNode(
            storageId: $e->storageId,
            fileid: $e->fileid,
            name: $e->name,
            size: $e->size,
            type: 'folder',
            mimetype: null,
            mtime: $e->mtime,
            displayName: $e->displayName,
            sizeExact: $e->sizeExact ? null : false,
        ), $kept);

        // The overflow "other" tile aggregates many different accounts, so
        // unlike every other node here it has no single real storage/fileid
        // to expand — it must stay a flat leaf. Only $keptNodes (one real
        // entry each) go into expandFrontier()'s frontier; the "other" tile
        // is added to $topNodes (what's actually rendered) afterwards, same
        // as the per-folder "other" bucket expandFrontier() itself builds
        // inline and never re-queues.
        $topNodes = $keptNodes;
        if (!empty($overflow)) {
            $overflowSum = array_sum(array_map(static fn (InstanceTopLevelEntry $e) => max(0, $e->size), $overflow));
            $topNodes[] = $this->makeOtherNode($overflowSum, count($overflow), true);
        }

        $builderRoot['ref']->children = $topNodes;

        $this->expandFrontier($keptNodes, max(1, $maxNodes - count($topNodes)), $totalSize);

        return ['root' => $this->toUsageNode($builderRoot['ref'])];
    }

    private const TREE_LEVEL_LIMIT = 500;
    // Raised from 200 alongside UsageController::map()'s node-budget ceiling
    // (400 -> 1200 default, 800 -> 2000 max) — each query here is a single
    // indexed fs_parent lookup, confirmed fast in production even against a
    // 300GB+/360k-file team folder, so the extra round trips are cheap; this
    // is what actually lets the higher node budget get spent on real
    // expansion instead of getting cut off by the query cap first.
    private const MAX_TREE_QUERIES = 500;
    private const SMALL_FILE_RATIO = 0.005;
    /**
     * A second, absolute fold-in floor, expressed as a share of the *whole
     * map* rather than of the immediate parent.
     *
     * SMALL_FILE_RATIO alone can't keep tiles legible, because it is measured
     * against each folder's own size: a file can be a comfortable 5% of a
     * small folder and still be invisible once that folder is itself a sliver
     * of the map. Measured on the dev instance, that left 324 of 389 rendered
     * tiles under 200px² — smaller than a favicon, and only 24 tiles large
     * enough to carry a label at all. Since a tile's on-screen area is its
     * share of the ROOT total (the canvas is a fixed size), a root-relative
     * floor is what actually bounds legibility.
     */
    private const MIN_TILE_ROOT_RATIO = 0.0025;
    private const MAX_INSTANCE_TOP_TILES = 40;

    /**
     * The best-first expansion loop shared by mapTree() and
     * instanceMapTree(): repeatedly pops the single largest still-expandable
     * node from $frontier (regardless of which storage it came from — each
     * builder node carries its own storageId) and fetches its children,
     * until the node budget or the hard query cap is spent. See mapTree()'s
     * own docblock for why best-first (not BFS/DFS) is what makes the
     * budget land on the biggest, most map-relevant content.
     */
    private function expandFrontier(array $frontier, int $budget, int $rootSize = 0): void {
        // Absolute floor below which a child can never earn its own tile,
        // however large a share of its immediate parent it happens to be —
        // see MIN_TILE_ROOT_RATIO.
        $minTileSize = (int)floor(max(0, $rootSize) * self::MIN_TILE_ROOT_RATIO);
        $queries = 0;
        while ($budget > 1 && $queries < self::MAX_TREE_QUERIES && !empty($frontier)) {
            usort($frontier, static fn (array $a, array $b) => $b['size'] <=> $a['size']);
            $node = array_shift($frontier);
            // Uses the shared predicate for consistency, but nothing with an
            // unmeasured size actually reaches this point: a -1 child never
            // clears the $threshold test below, so it is folded into the
            // level's "other" bucket instead of ever joining the frontier.
            // That bucket is sized by subtraction from the parent's own
            // total, so the *area* an unscanned subtree occupies is still
            // correct — it just isn't broken out per folder. Giving those
            // folders their own tiles would need the fold-in arithmetic to
            // know what an expansion is about to reveal, which it cannot.
            if (self::provablyEmpty($node['size'])) {
                continue;
            }

            [$rows, $truncated] = $this->fetchChildRows($node['storageId'], $node['fileid'], self::TREE_LEVEL_LIMIT);
            $queries++;
            if (empty($rows)) {
                continue; // stays a leaf tile — already attached to its parent
            }

            $folderMimetypeId = $this->folderMimetypeId();
            $threshold = max(1, (int)floor($node['size'] * self::SMALL_FILE_RATIO), $minTileSize);
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

            // The budget bounded the tree as a whole, but nothing bounded a
            // single expansion: one folder with hundreds of big children
            // could add all of them at once and overshoot maxNodes (confirmed
            // live — a 200-node budget produced 202 tiles). Trim the smallest
            // "big" children back into the fold-in bucket (they arrive size
            // DESC from fetchChildRows(), so this drops exactly the least
            // map-relevant ones) and the ceiling holds. One slot is reserved
            // for the 'other' node the trimmed children now land in.
            $maxBig = max(1, $budget);
            if (count($big) > $maxBig) {
                foreach (array_slice($big, $maxBig) as $dropped) {
                    $bigSum -= max(0, $dropped['size']);
                    $smallCount++;
                }
                $big = array_slice($big, 0, $maxBig);
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
    private function makeBuilderNode(int $storageId, int $fileid, string $name, int $size, string $type, ?string $mimetype, ?int $mtime, ?string $displayName = null, ?bool $sizeExact = null): array {
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
        $ref->displayName = $displayName;
        $ref->sizeExact = $sizeExact;
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
            'displayName' => null,
            'sizeExact' => null,
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
            displayName: $ref->displayName ?? null,
            sizeExact: $ref->sizeExact ?? null,
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
        $ref->displayName = $child['displayName'] ?? null;
        $ref->sizeExact = $child['sizeExact'] ?? null;
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
        $rows = $result->fetchAll();
        $result->closeCursor();

        $truncated = count($rows) > $limit;
        if ($truncated) {
            array_pop($rows);
        }

        return [$rows, $truncated];
    }

    /**
     * Recursive descendant file count AND per-mimetype size breakdown for
     * one folder, in a single query (plan Phase 3b + the "Composição"
     * stacked bar follow-up). Used to be two separate queries (a plain
     * COUNT(*), then a second one added for composition) until it became
     * clear a GROUP BY gives both at once for the same cost as the original
     * COUNT(*) alone.
     *
     * This is the single-folder form, now only reached by
     * compositionsForEntries()' collision fallback — the listing paths go
     * through compositionByChild() (whole level, one query) or the cache in
     * front of it. On MySQL its cost is linear in the subtree: measured 4.5 ms
     * over 1k descendants, 57 ms over 15k, 1421 ms over 300k, which is why no
     * request calls it once per row any more.
     *
     * Deliberately one query per folder with a literal path, NOT a
     * correlated subquery: EXPLAIN confirmed live that a correlated `path
     * LIKE CONCAT(outer.path, '/%')` cannot get the fs_storage_path_prefix
     * range scan (the bound isn't a compile-time constant), while the exact
     * same pattern with a literal path does (`type: range`, confirmed at
     * key_len 267 — the full (storage, path) prefix). USE INDEX biases the
     * optimizer toward that plan but does NOT pin it: it is a hint, and a
     * cost-based fallback to a full scan is still allowed (measured — the
     * batched form in bulkComposition() drops to `type: ALL` on a small
     * table even with the hint, and only FORCE INDEX would forbid that).
     * Leaving it as a hint is deliberate: on a table small enough for the
     * optimizer to prefer a scan, the scan really is cheaper.
     *
     * PostgreSQL reaches a different plan, and the reason is not the SQL: the
     * fs_storage_path_prefix index does not exist there. It is a
     * (storage, path(64)) prefix index, which PostgreSQL has no equivalent
     * for, so core skips it explicitly rather than by accident — see the
     * `getDatabaseProvider() !== PLATFORM_POSTGRES` guard around it in core's
     * AddMissingIndicesListener (still present and unchanged in NC 34).
     * With nothing to range-scan, every form here becomes a parallel seq scan
     * filtered on the LIKE, so cost tracks the size of the whole storage
     * rather than the subtree. Measured on a synthetic 300k-row storage:
     * aggregating one 300-file folder took 51 ms (against MySQL's few ms —
     * it scans everything to find those 300), while aggregating all 301k rows
     * took 176 ms, comfortably *beating* MySQL's 1421 ms since a parallel scan
     * is the right plan when the answer needs every row anyway. Both are
     * bounded by one storage, and CompositionCache fronts them, so this is a
     * real difference but not a pathological one. An admin who wants the
     * bounded behaviour back can add a pattern-ops index by hand — see the
     * PostgreSQL note in README.md, which carries the exact statement and the
     * before/after numbers. The app deliberately does not create it:
     * oc_filecache is core's table, not this app's, to migrate.
     *
     * @return array{count: int, composition: array<string, int>} composition
     *     maps mimetype string ("image/png") to summed size — categorizing
     *     that into the 5 UI buckets (document/image/video/archive/other) is
     *     the frontend's job (utils/mimetypeCategory.js). The one exception:
     *     Outlook .pst and AutoCAD .dwg have no dedicated Nextcloud mimetype
     *     (both scan as the generic application/octet-stream, same as any
     *     other file type Nextcloud doesn't recognize), so once grouped into
     *     this aggregate there's no per-file name left for the frontend to
     *     fall back on the way categoryForFile() does for a single file's own
     *     tile. The query below reclassifies just those two extensions into
     *     synthetic pseudo-mimetypes ("application/x-diskmap-pst/dwg") that
     *     mimetypeCategory.js's categoryForMimetype() knows to bucket as
     *     archive — everything else keeps its real mimetype untouched.
     */
    private const COMPOSITION_MIMETYPE_CASE = "CASE
                        WHEN m.mimetype = 'application/octet-stream' AND LOWER(f.name) LIKE '%.pst' THEN 'application/x-diskmap-pst'
                        WHEN m.mimetype = 'application/octet-stream' AND LOWER(f.name) LIKE '%.dwg' THEN 'application/x-diskmap-dwg'
                        ELSE m.mimetype
                    END";

    /**
     * The three composition queries below are the only raw SQL in the app —
     * everything else goes through the portable QueryBuilder — and so are its
     * entire dialect-dependent surface. Two differences separate MySQL/MariaDB
     * from PostgreSQL here, one per helper below; the rest of the SQL is
     * spelled so both engines read it the same way:
     *
     *  - identifiers are left unquoted (backticks are MySQL-only syntax, and
     *    these all-lowercase names need no quoting on either engine);
     *  - GROUP BY repeats the full expression instead of naming the SELECT
     *    alias. MySQL resolves an alias there, but PostgreSQL prefers input
     *    columns, and `mimetype` is one on both sides of the join — it would
     *    be rejected as ambiguous rather than silently grouping by the wrong
     *    thing. Repeating costs nothing since the expressions bind no
     *    parameters.
     *
     * Non-strict getDatabaseProvider() is what we want: it reports MariaDB as
     * PLATFORM_MYSQL, and the SQL genuinely is the same for both.
     */
    private function isPostgres(): bool {
        return $this->db->getDatabaseProvider() === IDBConnection::PLATFORM_POSTGRES;
    }

    /**
     * Biases MySQL's optimizer toward the (storage, path) range scan these
     * queries are built around — see recursiveComposition() for what the hint
     * does and, more importantly, does not guarantee. PostgreSQL has no
     * index-hint syntax at all (the query would not parse), so it gets none
     * and relies on the planner reaching fs_storage_path_prefix by itself.
     */
    private function pathPrefixIndexHint(): string {
        return $this->isPostgres() ? '' : ' USE INDEX (fs_storage_path_prefix)';
    }

    /**
     * SQL for "the first $segments segments of f.path" — the grouping key
     * compositionByChild() attributes each descendant to its own top-level
     * ancestor with.
     *
     * The two spellings agree on the case that matters: given fewer than
     * $segments segments, both return the path unchanged rather than padding
     * or failing.
     *
     * $segments is inlined rather than bound. It comes from substr_count() so
     * it is provably an integer, and inlining keeps the expression repeatable
     * in GROUP BY without a duplicate parameter — while also sidestepping
     * PostgreSQL having to accept a bound parameter as an array subscript.
     */
    private function firstPathSegmentsExpr(int $segments): string {
        if ($this->isPostgres()) {
            return "array_to_string((string_to_array(f.path, '/'))[1:" . $segments . "], '/')";
        }
        return "SUBSTRING_INDEX(f.path, '/', " . $segments . ')';
    }

    /**
     * Whether a filecache size proves a folder has nothing below it worth
     * querying.
     *
     * Only an exact 0 proves that. -1 means "not calculated yet", which is
     * the normal state of a folder inside an external storage nobody has
     * scanned — and such a folder can perfectly well have cached
     * descendants, because browsing its parent recorded them. Every site
     * here used to test `size <= 0`, which folded the two cases together
     * and reported "0 files" with an empty composition bar for folders
     * whose contents were listed on the very same screen.
     */
    private static function provablyEmpty(int $size): bool {
        return $size === 0;
    }

    private function recursiveComposition(int $storageId, string $path, int $size): array {
        // A folder's own aggregate size already tells us whether it has any
        // descendants at all — skip the query for an empty folder.
        if (self::provablyEmpty($size)) {
            return self::EMPTY_COMPOSITION;
        }

        // path='' only happens for an external storage's own root (plan
        // Phase 3d — its root filecache row lives at the literal empty
        // path, unlike a user/team-folder root which always starts under
        // "files"). Its children's paths have no leading slash to match, so
        // the pattern must be a bare '%' there instead of '<path>/%'.
        $likePattern = $path !== '' ? $this->db->escapeLikeParameter($path) . '/%' : '%';
        $folderMimetypeId = $this->folderMimetypeId();

        $sql = 'SELECT ' . self::COMPOSITION_MIMETYPE_CASE . ' AS mimetype,
                    COUNT(*) AS c, SUM(f.size) AS total
                FROM *PREFIX*filecache f' . $this->pathPrefixIndexHint() . '
                LEFT JOIN *PREFIX*mimetypes m ON f.mimetype = m.id
                WHERE f.storage = ? AND f.path LIKE ? AND f.mimetype != ?
                GROUP BY ' . self::COMPOSITION_MIMETYPE_CASE;
        $result = $this->db->executeQuery($sql, [$storageId, $likePattern, $folderMimetypeId]);

        $count = 0;
        $composition = [];
        while ($row = $result->fetch()) {
            $count += (int)$row['c'];
            if ($row['mimetype'] !== null) {
                $composition[(string)$row['mimetype']] = (int)$row['total'];
            }
        }
        $result->closeCursor();

        return ['count' => $count, 'composition' => $composition];
    }

    /**
     * recursiveComposition() for many instance top-level entries at once.
     *
     * The instance root lists every user home + team folder + external
     * storage side by side, and each row's composition bar is a full subtree
     * aggregate — so one query apiece meant a single "Whole server" tree load
     * fired one scan-this-account's-whole-subtree query per account, scaling
     * with the number of accounts rather than being bounded. Batching works
     * because every entry sharing a path produces an identical LIKE pattern
     * (every user home and every separate-storage team folder sits at
     * "files"), and GROUP BY storage tells their rows apart again afterwards.
     * Verified on the dev instance: 60 entries went from 60 queries to 2,
     * with byte-identical results.
     *
     * EXPLAIN confirms the batched form keeps the same treatment as the
     * single-entry query — `type: range` on fs_storage_path_prefix at the
     * full key_len 267, as a multi-range scan over the IN list.
     *
     * @param InstanceTopLevelEntry[] $entries
     * @return array<string, array{count: int, composition: array<string, int>}>
     *     keyed by compositionKey()
     */
    private function compositionsForEntries(array $entries): array {
        $result = [];
        $byPath = [];
        $computed = [];
        foreach ($entries as $entry) {
            $key = $this->compositionKey($entry->storageId, $entry->path);
            // The same short-circuit recursiveComposition() applies per
            // folder: an aggregate size of 0 already proves there are no
            // descendants to break down, so this entry needs no query at all.
            if (self::provablyEmpty($entry->size)) {
                $result[$key] = self::EMPTY_COMPOSITION;
                continue;
            }
            // A cached account drops out of the batch entirely — on a settled
            // instance that leaves nothing to query, which is what makes
            // re-opening the whole-server root cost nothing the second time.
            $hit = $this->compositionCache->get($entry->storageId, $entry->path, $entry->size, $entry->mtime);
            if ($hit !== null) {
                $result[$key] = $hit;
                continue;
            }
            $byPath[$entry->path][] = $entry;
            $computed[] = $entry;
        }

        foreach ($byPath as $path => $group) {
            $path = (string)$path;
            $storageIds = array_values(array_unique(array_map(
                static fn (InstanceTopLevelEntry $e) => $e->storageId,
                $group,
            )));

            // GROUP BY storage can only separate entries that don't share a
            // storage. Nothing in the layouts this app knows puts two entries
            // at the same path on one storage (legacy root-jail team folders
            // do share a storage, but at distinct "__groupfolders/{id}"
            // paths, so they never land in the same group) — rather than
            // depend on that holding forever, fall back to the per-entry
            // query if a group ever does collide, since the batched result
            // genuinely could not attribute those rows correctly.
            if (count($storageIds) !== count($group)) {
                foreach ($group as $entry) {
                    $result[$this->compositionKey($entry->storageId, $entry->path)]
                        = $this->recursiveComposition($entry->storageId, $entry->path, $entry->size);
                }
                continue;
            }

            foreach ($this->bulkComposition($storageIds, $path) as $storageId => $composition) {
                $result[$this->compositionKey($storageId, $path)] = $composition;
            }
        }

        // A storage whose subtree matched nothing produces no group at all;
        // fill in the empty result its entry's key is still expected to have.
        foreach ($entries as $entry) {
            $result[$this->compositionKey($entry->storageId, $entry->path)] ??= self::EMPTY_COMPOSITION;
        }

        // Only what this call actually computed — re-storing a cache hit
        // would just rewrite the same value under the same key.
        foreach ($computed as $entry) {
            $this->compositionCache->set(
                $entry->storageId,
                $entry->path,
                $entry->size,
                $entry->mtime,
                $result[$this->compositionKey($entry->storageId, $entry->path)],
            );
        }

        return $result;
    }

    /**
     * One grouped composition query covering several storages at the same
     * path prefix. See compositionsForEntries() for why this is safe.
     *
     * @param int[] $storageIds
     * @return array<int, array{count: int, composition: array<string, int>}> keyed by storage id
     */
    private function bulkComposition(array $storageIds, string $path): array {
        $params = $storageIds;
        $params[] = $this->folderMimetypeId();

        // path='' is an external storage's own root: every row in the storage
        // is a descendant of it, so there is no prefix to filter on. (The
        // per-folder query expresses the same thing as LIKE '%', which
        // matches every row too but can't serve as an index range bound.)
        $pathClause = '';
        if ($path !== '') {
            $pathClause = ' AND f.path LIKE ?';
            $params[] = $this->db->escapeLikeParameter($path) . '/%';
        }

        $sql = 'SELECT f.storage, ' . self::COMPOSITION_MIMETYPE_CASE . ' AS mimetype,
                    COUNT(*) AS c, SUM(f.size) AS total
                FROM *PREFIX*filecache f' . $this->pathPrefixIndexHint() . '
                LEFT JOIN *PREFIX*mimetypes m ON f.mimetype = m.id
                WHERE f.storage IN (' . implode(',', array_fill(0, count($storageIds), '?')) . ')
                    AND f.mimetype != ?' . $pathClause . '
                GROUP BY f.storage, ' . self::COMPOSITION_MIMETYPE_CASE;

        $result = $this->db->executeQuery($sql, $params);
        $byStorage = [];
        while ($row = $result->fetch()) {
            $storageId = (int)$row['storage'];
            $byStorage[$storageId] ??= ['count' => 0, 'composition' => []];
            $byStorage[$storageId]['count'] += (int)$row['c'];
            if ($row['mimetype'] !== null) {
                $byStorage[$storageId]['composition'][(string)$row['mimetype']] = (int)$row['total'];
            }
        }
        $result->closeCursor();

        return $byStorage;
    }

    /**
     * recursiveComposition() for every immediate child of one folder at once
     * — the per-parent counterpart to compositionsForEntries()'s per-storage
     * batching, and what childComposition() runs on a cache miss.
     *
     * Both forms scan exactly the same rows (the parent's whole subtree), so
     * this is not a way to do less work: measured on a 300k-row synthetic
     * tree, expanding a 15-folder level went from 236.6 ms as 15 separate
     * queries to 140.6 ms as this one — a real 1.7x, entirely from paying the
     * round trip, the LIKE range setup and the grouping once instead of
     * fifteen times. It is the cache above it that removes the cost; this
     * only makes the miss cheaper.
     *
     * Attribution is by segment count, not by string offset: truncating every
     * descendant's path to its first (depth of $path) + 1 segments leaves its
     * own top-level ancestor under $path, which IS that child's full path — so
     * the grouping key needs no arithmetic over the parent prefix and, unlike
     * a SUBSTRING(path, <offset>) form, cannot be thrown off by the difference
     * between PHP's byte offsets and the database's character ones on a
     * non-ASCII folder name.
     *
     * That truncation has no spelling both supported engines share, so it
     * comes from firstPathSegmentsExpr() — see the dialect note there.
     *
     * @return array<string, array{count: int, composition: array<string, int>}>
     *     keyed by the child's full filecache path
     */
    private function compositionByChild(int $storageId, string $path): array {
        // Depth of the parent in segments: 'files/foo' is 2, and the external
        // storage root ('') is 0. One more than that is where its children sit.
        $childSegments = ($path === '' ? 0 : substr_count($path, '/') + 1) + 1;
        $likePattern = $path !== '' ? $this->db->escapeLikeParameter($path) . '/%' : '%';

        $childExpr = $this->firstPathSegmentsExpr($childSegments);

        $sql = 'SELECT ' . $childExpr . ' AS child,
                    ' . self::COMPOSITION_MIMETYPE_CASE . ' AS mimetype,
                    COUNT(*) AS c, SUM(f.size) AS total
                FROM *PREFIX*filecache f' . $this->pathPrefixIndexHint() . '
                LEFT JOIN *PREFIX*mimetypes m ON f.mimetype = m.id
                WHERE f.storage = ? AND f.path LIKE ? AND f.mimetype != ?
                GROUP BY ' . $childExpr . ', ' . self::COMPOSITION_MIMETYPE_CASE;

        $result = $this->db->executeQuery($sql, [$storageId, $likePattern, $this->folderMimetypeId()]);

        $byChild = [];
        while ($row = $result->fetch()) {
            $child = (string)$row['child'];
            $byChild[$child] ??= self::EMPTY_COMPOSITION;
            $byChild[$child]['count'] += (int)$row['c'];
            if ($row['mimetype'] !== null) {
                $byChild[$child]['composition'][(string)$row['mimetype']] = (int)$row['total'];
            }
        }
        $result->closeCursor();

        return $byChild;
    }

    /**
     * Entries are identified by storage AND path (a legacy-layout instance
     * has several team folders on one storage), so neither alone is a usable
     * key for a precomputed-composition lookup.
     */
    private function compositionKey(int $storageId, string $path): string {
        return $storageId . "\0" . $path;
    }

    /**
     * @return array{fileid: int, name: string, size: int, mtime: int}|null
     */
    /**
     * The size to treat a scope root as having, plus whether that is the real
     * total.
     *
     * A filecache size of -1 means "not calculated yet", which is normal for
     * an external storage: Nextcloud reads its contents live from the remote
     * backend and only records sizes when something scans it. -1 is not a
     * size, though, and every ratio in this class (tile areas, expansion
     * thresholds) treats it as smaller than empty — which is why an
     * unscanned mount produced an empty map. The rows the cache already
     * holds are a true lower bound, so use those and say the figure is
     * inexact. InstanceIndex does the same for its own top-level entries.
     *
     * @return array{0: int, 1: bool} [size, isExact]
     */
    private function resolveRootSize(int $storageId, string $path, int $rawSize): array {
        if ($rawSize >= 0) {
            return [$rawSize, true];
        }

        return [$this->knownSizeBelow($storageId, $path), false];
    }

    /**
     * Sum of every *file* row the cache holds under $path. Folders are
     * excluded so one whose own recursive total is already known isn't
     * counted on top of the files inside it; rows still at -1 drop out via
     * the same "size > 0" test.
     */
    private function knownSizeBelow(int $storageId, string $path): int {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->func()->sum('size'), 'known_size')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->neq(
                'mimetype',
                $qb->createNamedParameter($this->folderMimetypeId(), IQueryBuilder::PARAM_INT),
            ));

        // An empty $path is the storage root — every row already qualifies,
        // and a LIKE '/%' would match none of them.
        if ($path !== '') {
            $qb->andWhere($qb->expr()->like(
                'path',
                $qb->createNamedParameter($this->db->escapeLikeParameter($path) . '/%'),
            ));
        }

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row && $row['known_size'] !== null ? (int)$row['known_size'] : 0;
    }

    private function rowAtExactPath(int $storageId, string $path): ?array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid', 'name', 'size', 'mtime')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
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
            // The instance scope spans every storage at once, so it has no
            // single (storage, path) root — InstanceIndex handles it before
            // anything gets here. Falling through to match's implicit
            // \UnhandledMatchError would turn that into a 500; null is the
            // documented "no known storage" answer and every caller already
            // renders it as an empty result.
            default => null,
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

            // No composition here: this is the one listing where a per-row
            // subtree aggregate means scanning every account on the instance,
            // and it is exactly what made the whole-server root slow to open.
            // childComposition() serves it separately (see there).
            $items = array_map(static fn (InstanceTopLevelEntry $e) => new UsageNode(
                name: $e->name,
                path: $e->name,
                size: $e->size,
                type: 'folder',
                mimetype: null,
                mtime: $e->mtime,
                kind: $e->kind,
                displayName: $e->displayName,
                sizeExact: $e->sizeExact ? null : false,
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
        $row = $result->fetch();
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
            $row = $result->fetch();
            $result->closeCursor();

            // -1 never matches a real mimetype id, so a missing row safely
            // excludes nothing instead of crashing.
            $this->folderMimetypeIdCache = $row ? (int)$row['id'] : -1;
        }
        return $this->folderMimetypeIdCache;
    }
}
