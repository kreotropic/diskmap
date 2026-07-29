<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

use OCA\DiskMap\GroupFolders\LayoutDetector;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUserManager;

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

    /** @var InstanceTopLevelEntry[]|null */
    private ?array $listAllCache = null;

    public function __construct(
        private IDBConnection $db,
        private LayoutDetector $layoutDetector,
        private IConfig $config,
        private IUserManager $userManager,
    ) {
    }

    /**
     * Cached per request: a single instance-scope request calls this several
     * times over (totals() and lastUpdated() each want the full list, and
     * every deeper tree/map navigation re-resolves its delegate through it),
     * and rebuilding it costs a bulk user query plus a query per team folder
     * every time. The underlying data can't change mid-request — this app
     * only ever reads — so the same caching assumption LayoutDetector already
     * makes for its own lookups holds here.
     *
     * @return InstanceTopLevelEntry[]
     */
    public function listAll(): array {
        if ($this->listAllCache !== null) {
            return $this->listAllCache;
        }

        $teamFolders = $this->listTeamFolders();
        $teamFolderStorageIds = array_map(static fn (InstanceTopLevelEntry $e) => $e->storageId, $teamFolders);
        $entries = [...$this->listUsers(), ...$teamFolders, ...$this->listExternalStorages($teamFolderStorageIds)];

        return $this->listAllCache = $this->disambiguateNames($entries);
    }

    /**
     * A top-level entry's name doubles as its path segment — FolderTree's
     * navPath, Treemap's pathFor() and FilecacheUsageSource's delegate
     * resolution all key off it — so two entries sharing a name (a uid equal
     * to a team folder's mount point, say, or two external storages whose
     * ids differ only in their backend prefix) leave one of them permanently
     * unreachable: the delegate lookup always resolves to whichever came
     * first, and the tree renders duplicate keys. Rare, but it fails
     * silently, so suffix the later duplicates with their kind to keep every
     * entry addressable.
     *
     * displayName follows the same suffix only when it would otherwise start
     * disagreeing with name — the tree shows a separate "raw uid" chip
     * whenever the two differ, and a name that never differed before
     * shouldn't grow one now.
     *
     * @param InstanceTopLevelEntry[] $entries
     * @return InstanceTopLevelEntry[]
     */
    private function disambiguateNames(array $entries): array {
        $seen = [];
        $result = [];

        foreach ($entries as $entry) {
            if (!isset($seen[$entry->name])) {
                $seen[$entry->name] = true;
                $result[] = $entry;
                continue;
            }

            $candidate = $entry->name . ' (' . $entry->kind . ')';
            $suffix = 2;
            while (isset($seen[$candidate])) {
                $candidate = $entry->name . ' (' . $entry->kind . ' ' . $suffix . ')';
                $suffix++;
            }
            $seen[$candidate] = true;

            $result[] = new InstanceTopLevelEntry(
                name: $candidate,
                displayName: $entry->displayName === $entry->name ? $candidate : $entry->displayName,
                kind: $entry->kind,
                storageId: $entry->storageId,
                path: $entry->path,
                fileid: $entry->fileid,
                size: $entry->size,
                mtime: $entry->mtime,
            );
        }

        return $result;
    }

    /**
     * The instance-wide header total (plan Phase 3d follow-up): listAll()'s
     * entries only sum each user/team-folder's *files* — trash and versions
     * live at different top-level paths within the same storage and were
     * never part of that sum, so a user whose trash dwarfs their files (a
     * very on-mission thing for this app to surface!) looked, confusingly,
     * absent from the whole-server total. 'used' here matches the same
     * files+trash+versions convention UserStorageService::overview() /
     * TeamFolderService::listAll() already use for the per-user and
     * per-team-folder headers — 'filesOnly' is what the tree/map below
     * actually browse (they never descend into trash/versions), so the two
     * figures together explain any gap instead of just presenting one
     * number that mismatches every other view.
     *
     * @return array{used: int, filesOnly: int}
     */
    public function totals(): array {
        $filesOnly = array_sum(array_map(static fn (InstanceTopLevelEntry $e) => max(0, $e->size), $this->listAll()));
        $used = $filesOnly + $this->sumUserTrashAndVersions() + $this->sumTeamFolderTrashAndVersions();
        return ['used' => $used, 'filesOnly' => $filesOnly];
    }

    /**
     * One query for every user's trash + versions combined (same "one bulk
     * join, not a loop per uid" shape as listUsers()) — files_trashbin and
     * files_versions are just two more top-level paths in the same home
     * storage as "files", not separate storages.
     */
    private function sumUserTrashAndVersions(): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('f.size')
            ->from('storages', 's')
            ->innerJoin('s', 'filecache', 'f', $qb->expr()->eq('f.storage', 's.numeric_id'))
            ->where($qb->expr()->orX(
                $qb->expr()->like('s.id', $qb->createNamedParameter('home::%')),
                $qb->expr()->like('s.id', $qb->createNamedParameter('object::user:%')),
            ))
            ->andWhere($qb->expr()->in(
                'f.path',
                $qb->createNamedParameter(['files_trashbin', 'files_versions'], IQueryBuilder::PARAM_STR_ARRAY),
            ));

        $result = $qb->executeQuery();
        $sum = 0;
        while ($row = $result->fetch()) {
            $sum += max(0, (int)$row['size']);
        }
        $result->closeCursor();

        return $sum;
    }

    /**
     * One query per team folder (LayoutDetector already resolves its trash/
     * versions storage+path per folder — same per-folder cost
     * listTeamFolders() and TeamFolderService::listAll() already pay).
     */
    private function sumTeamFolderTrashAndVersions(): int {
        if (!$this->layoutDetector->teamFoldersAvailable()) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id')->from('group_folders');
        $result = $qb->executeQuery();
        $folderIds = array_map(static fn (array $row) => (int)$row['folder_id'], $result->fetchAll());
        $result->closeCursor();

        $sum = 0;
        foreach ($folderIds as $folderId) {
            $layout = $this->layoutDetector->resolve($folderId);
            $sum += $this->sizeAtExactPath($layout->trashStorageId, $layout->trashPath);
            $sum += $this->sizeAtExactPath($layout->versionsStorageId, $layout->versionsPath);
        }

        return $sum;
    }

    private function sizeAtExactPath(?int $storageId, string $path): int {
        if ($storageId === null) {
            return 0;
        }
        $qb = $this->db->getQueryBuilder();
        $qb->select('size')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? max(0, (int)$row['size']) : 0;
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
        while ($row = $result->fetch()) {
            $storageKey = (string)$row['id'];
            $uid = str_starts_with($storageKey, 'home::')
                ? substr($storageKey, strlen('home::'))
                : substr($storageKey, strlen('object::user:'));
            if ($uid === '') {
                continue;
            }

            $entries[] = new InstanceTopLevelEntry(
                name: $uid,
                displayName: $this->displayNameForUid($uid),
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
        if (!$this->layoutDetector->teamFoldersAvailable()) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id', 'mount_point')->from('group_folders');
        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
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
                displayName: (string)$row['mount_point'],
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
     * Every remaining storage — anything that isn't a user home or a live
     * team folder — read as "external storage": SMB/S3/WebDAV/local
     * mounts configured via the files_external app, but also anything else
     * this app doesn't have a name for yet (e.g. an orphaned team-folder
     * storage whose oc_group_folders row was deleted but the storage
     * itself wasn't — genuinely worth an admin's attention, not hidden).
     *
     * Deliberately NOT joined against oc_external_mounts: that table has no
     * direct storage-id column (each backend type constructs its own
     * oc_storages.id string from its own config columns at runtime, so
     * there's no single stable join path across backend types). Reading
     * oc_storages directly instead means this works for every backend
     * without backend-specific parsing, at the cost of a less friendly
     * display name (the raw storage id minus its "backend::" prefix,
     * rather than the admin-configured mount point).
     *
     * @param int[] $excludeStorageIds team-folder storage ids, already
     *     listed by listTeamFolders() — excluded here so a folder never
     *     appears twice under two different categories.
     * @return InstanceTopLevelEntry[]
     */
    private function listExternalStorages(array $excludeStorageIds): array {
        // Nextcloud's own internal "whole data directory" storage — every
        // user's home already exposes the same files under home::<uid>, so
        // listing this too would double-count everyone's data as "external".
        $dataDir = rtrim($this->config->getSystemValueString('datadirectory', '/var/www/html/data'), '/');
        $dataDirStorageKey = 'local::' . $dataDir . '/';

        $qb = $this->db->getQueryBuilder();
        $qb->select('s.id', 's.numeric_id', 'f.fileid', 'f.size', 'f.mtime')
            ->from('storages', 's')
            ->innerJoin('s', 'filecache', 'f', $qb->expr()->andX(
                $qb->expr()->eq('f.storage', 's.numeric_id'),
                $qb->expr()->eq('f.path', $qb->createNamedParameter('')),
            ))
            ->where($qb->expr()->notLike('s.id', $qb->createNamedParameter('home::%')))
            ->andWhere($qb->expr()->notLike('s.id', $qb->createNamedParameter('object::user:%')))
            // The primary object store's own bucket, the object-storage
            // counterpart of the local data directory excluded below: it
            // holds appdata and other instance-internal content, never a
            // configured external mount. Excluding it keeps an
            // object-storage instance consistent with a local one, which has
            // never listed its data root here either. (A files_external S3
            // mount is unaffected — those get a backend-specific storage id
            // like "amazon::<bucket>", not this prefix.)
            ->andWhere($qb->expr()->notLike('s.id', $qb->createNamedParameter('object::store:%')));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $candidates = [];
        foreach ($rows as $row) {
            $numericId = (int)$row['numeric_id'];
            if ((string)$row['id'] === $dataDirStorageKey || in_array($numericId, $excludeStorageIds, true)) {
                continue;
            }
            $candidates[$numericId] = (string)$row['id'];
        }

        $dataRoots = $this->dataRootStorageIds(array_keys($candidates));

        $entries = [];
        foreach ($rows as $row) {
            $numericId = (int)$row['numeric_id'];
            if (!isset($candidates[$numericId]) || in_array($numericId, $dataRoots, true)) {
                continue;
            }

            $displayName = $this->externalDisplayName($candidates[$numericId]);
            $entries[] = new InstanceTopLevelEntry(
                name: $displayName,
                displayName: $displayName,
                kind: 'external',
                storageId: $numericId,
                path: '',
                fileid: (int)$row['fileid'],
                size: (int)$row['size'],
                mtime: $row['mtime'] !== null ? (int)$row['mtime'] : null,
            );
        }

        return $entries;
    }

    /**
     * Which of $storageIds host a Nextcloud data root, identified by the
     * "appdata_<instanceid>" folder every instance keeps at the top of one.
     *
     * The *current* data directory is already excluded by its exact storage
     * id, but an instance that has moved its data directory leaves the old
     * root behind as a still-cached local:: storage holding a stale copy of
     * every user's home — listing that as an external storage would
     * double-count the entire instance in the header totals and the map.
     * Matching the marker folder catches it without having to know what the
     * old path was, and it's one indexed (storage, path) lookup over the
     * handful of storages that got this far, not a scan.
     *
     * @param int[] $storageIds
     * @return int[]
     */
    private function dataRootStorageIds(array $storageIds): array {
        if (empty($storageIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('storage')
            ->from('filecache')
            ->where($qb->expr()->in(
                'storage',
                $qb->createNamedParameter($storageIds, IQueryBuilder::PARAM_INT_ARRAY),
            ))
            ->andWhere($qb->expr()->eq(
                'path',
                $qb->createNamedParameter('appdata_' . $this->config->getSystemValueString('instanceid')),
            ));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['storage'];
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * IUserManager has no bulk display-name form, and listUsers() needs one
     * per account it found — so on a large instance this, not the usage
     * queries, is what the whole-server view's cost scales with. Measured
     * cold on the dev instance: 53 of the 61 queries a whole-server tree load
     * issued were this lookup.
     *
     * getDisplayName() rather than get()?->getDisplayName(): it reads through
     * core's DisplayNameCache (memory + distributed cache, so Redis on any
     * instance configured for it) instead of constructing a full IUser and
     * hitting the account backend for each uid. Falls back to the uid itself
     * when the account can't be resolved (deleted between the filecache read
     * and now, or a backend hiccup) — never leaves the UI with an empty label.
     */
    private function displayNameForUid(string $uid): string {
        return $this->userManager->getDisplayName($uid) ?: $uid;
    }

    /**
     * The raw storage id minus its "backend::" prefix, e.g.
     * "local::/tmp/nc-exttest/" → "tmp/nc-exttest". Trimmed of leading/
     * trailing slashes deliberately — this name becomes a single path
     * *segment* the tree/map build navPaths and ancestor chains out of
     * (FolderTree.vue's navPath, Treemap.vue's pathFor()), and a leading or
     * trailing slash there would produce a malformed "//" once a real child
     * segment gets appended. Internal slashes (still possible for a nested
     * path like this) are fine — resolveInstanceDelegate() matches the
     * whole name as a path prefix, not by splitting on '/' first.
     */
    private function externalDisplayName(string $storageKey): string {
        $sep = strpos($storageKey, '::');
        $rest = $sep !== false ? substr($storageKey, $sep + 2) : $storageKey;
        return trim($rest, '/');
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
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? [
            'fileid' => (int)$row['fileid'],
            'size' => (int)$row['size'],
            'mtime' => (int)$row['mtime'],
        ] : null;
    }
}
