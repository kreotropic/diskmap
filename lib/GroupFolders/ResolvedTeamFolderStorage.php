<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\GroupFolders;

/**
 * Where a team folder's files/trash/versions actually live, as resolved by
 * LayoutDetector. Any *StorageId can be null when that subtree has never
 * been created (e.g. a folder that has never had anything trashed).
 */
final class ResolvedTeamFolderStorage {
    public function __construct(
        public readonly ?int $filesStorageId,
        public readonly string $filesPath,
        public readonly ?int $trashStorageId,
        public readonly string $trashPath,
        public readonly ?int $versionsStorageId,
        public readonly string $versionsPath,
        public readonly bool $separateStorage,
    ) {
    }
}
