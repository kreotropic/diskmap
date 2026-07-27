<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

use OCA\DiskMap\GroupFolders\LayoutDetector;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
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
        private IConfig $config,
    ) {
    }

    /** @return InstanceTopLevelEntry[] */
    public function listAll(): array {
        $teamFolders = $this->listTeamFolders();
        $teamFolderStorageIds = array_map(static fn (InstanceTopLevelEntry $e) => $e->storageId, $teamFolders);

        return [...$this->listUsers(), ...$teamFolders, ...$this->listExternalStorages($teamFolderStorageIds)];
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
        while ($row = $result->fetchAssociative()) {
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
        $qb = $this->db->getQueryBuilder();
        $qb->select('folder_id')->from('group_folders');
        $result = $qb->executeQuery();
        $folderIds = array_map(static fn (array $row) => (int)$row['folder_id'], $result->fetchAllAssociative());
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
        $row = $result->fetchAssociative();
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
            ->andWhere($qb->expr()->notLike('s.id', $qb->createNamedParameter('object::user:%')));

        $result = $qb->executeQuery();
        $entries = [];
        while ($row = $result->fetchAssociative()) {
            $storageKey = (string)$row['id'];
            $numericId = (int)$row['numeric_id'];
            if ($storageKey === $dataDirStorageKey || in_array($numericId, $excludeStorageIds, true)) {
                continue;
            }

            $entries[] = new InstanceTopLevelEntry(
                name: $this->externalDisplayName($storageKey),
                kind: 'external',
                storageId: $numericId,
                path: '',
                fileid: (int)$row['fileid'],
                size: (int)$row['size'],
                mtime: $row['mtime'] !== null ? (int)$row['mtime'] : null,
            );
        }
        $result->closeCursor();

        return $entries;
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
        $row = $result->fetchAssociative();
        $result->closeCursor();

        return $row ? [
            'fileid' => (int)$row['fileid'],
            'size' => (int)$row['size'],
            'mtime' => (int)$row['mtime'],
        ] : null;
    }
}
