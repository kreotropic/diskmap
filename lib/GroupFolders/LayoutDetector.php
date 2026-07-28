<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\GroupFolders;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The single place in DiskMap that knows how team folders are laid out on
 * storage (plan §4). groupfolders' own PHP classes are deliberately not used
 * here — this reads oc_storages/oc_filecache directly, the same pattern
 * folder_protection's WidgetDataService uses for its group-folder sizes.
 *
 * Ground truth (verified against the groupfolders 21 source, NC33): the
 * layout is a **per-folder** flag (`separate-storage` in the JSON `options`
 * column of oc_group_folders), not something tied to the Nextcloud version —
 * a single instance can have both layouts at once, depending on whether a
 * folder predates the storage-restructure migration. Rather than depend on
 * that column — which may not exist at all on the older groupfolders shipped
 * with NC 32 (root_id/storage_id/options were added in a groupfolders-21-era
 * migration) — this detects the layout by probing which storage actually
 * exists for the folder:
 *
 *  - separate-storage (new): each folder is its own storage, whose
 *    oc_storages.id contains "__groupfolders/{id}/"; files/trash/versions
 *    are subfolders of it.
 *  - root-jail (legacy): every folder shares one storage (the one holding
 *    the top-level "__groupfolders" directory); a folder's files live at
 *    "__groupfolders/{id}", and trash/versions under the shared
 *    "__groupfolders/trash|versions/{id}".
 *
 * Pure reads only — never touches the filesystem.
 */
class LayoutDetector {

    /** @var int[]|null */
    private ?array $legacyRootStorageCandidatesCache = null;

    public function __construct(private IDBConnection $db) {
    }

    public function resolve(int $folderId): ResolvedTeamFolderStorage {
        $separateStorageId = $this->findSeparateStorageId($folderId);
        if ($separateStorageId !== null) {
            return new ResolvedTeamFolderStorage(
                filesStorageId: $separateStorageId,
                filesPath: 'files',
                trashStorageId: $separateStorageId,
                trashPath: 'trash',
                versionsStorageId: $separateStorageId,
                versionsPath: 'versions',
                separateStorage: true,
            );
        }

        $rootStorageId = $this->findLegacyRootStorageId($folderId);

        return new ResolvedTeamFolderStorage(
            filesStorageId: $rootStorageId,
            filesPath: '__groupfolders/' . $folderId,
            trashStorageId: $rootStorageId,
            trashPath: '__groupfolders/trash/' . $folderId,
            versionsStorageId: $rootStorageId,
            versionsPath: '__groupfolders/versions/' . $folderId,
            separateStorage: false,
        );
    }

    /**
     * folder_protection's proven pattern: the dedicated storage for a
     * separate-storage team folder has an oc_storages.id containing
     * "__groupfolders/{id}/". This can't collide between folder ids (e.g.
     * folder 1 vs. folder 11) because the pattern requires a "/" immediately
     * after the numeric id.
     */
    private function findSeparateStorageId(int $folderId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
            ->from('storages')
            ->where($qb->expr()->like(
                'id',
                $qb->createNamedParameter('%__groupfolders/' . $folderId . '/%'),
            ))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row ? (int)$row['numeric_id'] : null;
    }

    /**
     * The storage hosting the shared, top-level "__groupfolders" directory
     * used by the legacy layout, verified per folder id rather than trusted
     * from a single global lookup. Found live in production: an instance
     * that has gone through a data-directory change (or similar storage
     * renumbering) can carry more than one filecache row named
     * "__groupfolders" — an old, orphaned one alongside the real, live one —
     * with no ordering guarantee over which a bare `setMaxResults(1)` query
     * returns. Picking the wrong one silently zeroed out every legacy-layout
     * folder's size (they all resolved to a storage that has no
     * "__groupfolders/{id}" row for them). Checking each candidate storage
     * for this specific folder id's own path sidesteps the ambiguity
     * entirely — whichever storage actually has it is the right one, no
     * matter how many stale "__groupfolders" rows also happen to exist.
     */
    private function findLegacyRootStorageId(int $folderId): ?int {
        foreach ($this->legacyRootStorageCandidates() as $storageId) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('fileid')
                ->from('filecache')
                ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter('__groupfolders/' . $folderId)))
                ->setMaxResults(1);

            $result = $qb->executeQuery();
            $row = $result->fetch();
            $result->closeCursor();

            if ($row) {
                return $storageId;
            }
        }
        return null;
    }

    /**
     * Every storage carrying a top-level "__groupfolders" filecache row —
     * normally just one, but see findLegacyRootStorageId()'s docblock for
     * why more than one can exist. Cached per request; the underlying data
     * doesn't change mid-request.
     *
     * @return int[]
     */
    private function legacyRootStorageCandidates(): array {
        if ($this->legacyRootStorageCandidatesCache !== null) {
            return $this->legacyRootStorageCandidatesCache;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('storage')
            ->from('filecache')
            ->where($qb->expr()->eq('path_hash', $qb->createNamedParameter(md5('__groupfolders'))));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int)$row['storage'];
        }
        $result->closeCursor();

        return $this->legacyRootStorageCandidatesCache = $ids;
    }
}
