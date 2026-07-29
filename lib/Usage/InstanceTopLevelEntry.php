<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

/**
 * One top-level entry of the whole-instance scope (plan Phase 3d): either a
 * user's home or a team folder, already resolved to a real [storage, path,
 * fileid] a mapTree()/children() expansion can query directly.
 */
final class InstanceTopLevelEntry {
    public function __construct(
        // The uid, or the team folder's mount_point — this becomes the path
        // segment children live under (e.g. "alice/Documents/…"), so it's
        // what FolderTree.vue's navPath and Treemap.vue's pathFor() both use.
        // Stays the raw uid even on an LDAP/AD-backed instance where that's
        // an opaque UUID — navigation must stay stable even if a display
        // name changes. $displayName below is the human-readable label.
        public readonly string $name,
        // What the UI actually shows for this entry. For team folders and
        // external storages this is the same as $name (already human names).
        // For users it's IUserManager::get($uid)?->getDisplayName(), falling
        // back to the uid itself when the account can't be resolved — same
        // contract as share_audit_dashboard's DisplayNameResolver.
        public readonly string $displayName,
        public readonly string $kind, // 'user' | 'teamfolder'
        public readonly int $storageId,
        public readonly string $path,
        public readonly int $fileid,
        public readonly int $size,
        public readonly ?int $mtime,
    ) {
    }
}
