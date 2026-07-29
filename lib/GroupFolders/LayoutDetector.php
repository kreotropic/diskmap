<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\GroupFolders;

use OCP\App\IAppManager;
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

    /** @var array<int, ResolvedTeamFolderStorage> */
    private array $resolveCache = [];

    public function __construct(
        private IDBConnection $db,
        private IAppManager $appManager,
    ) {
    }

    /**
     * Whether this instance has team folders at all.
     *
     * The metadata this class's callers read lives in the groupfolders app's
     * own tables, and on an instance that never installed it those tables do
     * not exist — querying them is a hard SQL error, not an empty result. The
     * app is an optional companion rather than a declared dependency, so every
     * entry point into those tables has to ask first. (resolve() itself is
     * safe: it only ever touches core's own storages/filecache.)
     *
     * "Enabled for anyone" rather than merely installed: a disabled app's
     * folders are not mounted, so they are not part of the instance's live
     * storage picture even while its tables sit there still populated.
     */
    public function teamFoldersAvailable(): bool {
        return $this->appManager->isEnabledForAnyone('groupfolders');
    }

    /**
     * Cached per request on the same grounds as
     * legacyRootStorageCandidates(): a single instance-scope request resolves
     * the same folder several times over (the team-folder listing, the
     * trash/versions totals and each browse of the folder itself all ask),
     * and every resolution costs at least one query.
     */
    public function resolve(int $folderId): ResolvedTeamFolderStorage {
        if (isset($this->resolveCache[$folderId])) {
            return $this->resolveCache[$folderId];
        }
        return $this->resolveCache[$folderId] = $this->resolveUncached($folderId);
    }

    private function resolveUncached(int $folderId): ResolvedTeamFolderStorage {
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
     *
     * It *can*, however, match more than one storage for the same folder id,
     * for exactly the reason findLegacyRootStorageId() documents below: an
     * instance that has changed its data directory keeps the old, orphaned
     * "local::/old/path/__groupfolders/{id}/" storage alongside the live one,
     * and an unqualified LIMIT 1 has no ordering guarantee over which it
     * returns — picking the stale one silently reports the folder as empty.
     * So the ambiguity is resolved the same way: whichever candidate actually
     * holds the folder's "files" row is the live one.
     */
    private function findSeparateStorageId(int $folderId): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
            ->from('storages')
            ->where($qb->expr()->like(
                'id',
                $qb->createNamedParameter('%__groupfolders/' . $folderId . '/%'),
            ))
            // Newest first: a storage created after a data-directory change
            // outranks the one left behind, so even the fallback below is
            // deterministic rather than whatever order the table returns.
            ->orderBy('numeric_id', 'DESC');

        $result = $qb->executeQuery();
        $candidates = [];
        while ($row = $result->fetch()) {
            $candidates[] = (int)$row['numeric_id'];
        }
        $result->closeCursor();

        // The overwhelmingly common case — one storage, no ambiguity to
        // resolve and no extra query to pay for.
        if (count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }

        foreach ($candidates as $storageId) {
            if ($this->hasPathRow($storageId, 'files')) {
                return $storageId;
            }
        }

        // A folder created but never written to has no "files" row on any
        // candidate yet — still resolve it (to the newest) rather than
        // reporting it as having no storage at all.
        return $candidates[0];
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
            if ($this->hasPathRow($storageId, '__groupfolders/' . $folderId)) {
                return $storageId;
            }
        }
        return null;
    }

    /**
     * Whether a storage carries a filecache row at exactly this path — the
     * probe both ambiguity resolutions above are built on. An exact
     * (storage, path) match, so it rides the fs_storage_path_prefix index.
     */
    private function hasPathRow(int $storageId, string $path): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('fileid')
            ->from('filecache')
            ->where($qb->expr()->eq('storage', $qb->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('path', $qb->createNamedParameter($path)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return $row !== false && $row !== null;
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
