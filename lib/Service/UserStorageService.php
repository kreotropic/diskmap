<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Service;

use OCA\DiskMap\Usage\IUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCA\DiskMap\Usage\UserHomeResolver;
use OCP\IUser;

/**
 * The personal mirror of TeamFolderService: a files/trash/versions breakdown
 * plus quota occupancy for a single account's home storage (plan Phase 2).
 *
 * Quota is a genuinely independent figure to reconcile against (an
 * admin-configured allowance, unrelated to how the size was measured) —
 * unlike comparing our total against Nextcloud's own reported "used space"
 * for the account, which bottoms out to the exact same filecache row
 * DiskMap already reads (OC\Files\Config\UserMountCache::getUsedSpaceForUsers()
 * runs the same "size at path='files'" query), so that comparison would
 * never actually catch anything.
 */
class UserStorageService {
    public function __construct(
        private IUsageSource $usageSource,
        private UserHomeResolver $userHomeResolver,
    ) {
    }

    /**
     * @return array{
     *   quota: int|null, used: int, filesSize: int, trashSize: int,
     *   versionsSize: int, occupancyPercent: float|null, lastUpdated: int|null,
     * }
     */
    public function overview(IUser $user): array {
        $storageId = $this->userHomeResolver->resolveStorageId($user->getUID());

        $filesSize = $this->sizeFor($storageId, 'files');
        $trashSize = $this->sizeFor($storageId, 'files_trashbin');
        $versionsSize = $this->sizeFor($storageId, 'files_versions');
        $used = $filesSize + $trashSize + $versionsSize;

        // FileInfo::SPACE_UNLIMITED / SPACE_UNKNOWN are both negative;
        // Aggregator's "no quota to compare against" convention applies.
        $quotaBytes = $user->getQuotaBytes();
        $quota = $quotaBytes > 0 ? (int)$quotaBytes : null;

        $lastUpdated = $storageId !== null
            ? $this->usageSource->lastUpdated(Scope::forStorage($storageId, 'files'))
            : null;

        return [
            'quota' => $quota,
            'used' => $used,
            'filesSize' => $filesSize,
            'trashSize' => $trashSize,
            'versionsSize' => $versionsSize,
            'occupancyPercent' => Aggregator::occupancyPercent($used, $quota),
            'lastUpdated' => $lastUpdated,
        ];
    }

    private function sizeFor(?int $storageId, string $path): int {
        if ($storageId === null) {
            return 0;
        }
        return $this->usageSource->totalSize(Scope::forStorage($storageId, $path)) ?? 0;
    }
}
