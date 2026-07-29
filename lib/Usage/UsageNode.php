<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

/**
 * A single row in a usage listing: a file, a folder, or a synthetic
 * "everything else" bucket built on top of one.
 */
final class UsageNode implements \JsonSerializable {
    /**
     * @param UsageNode[]|null $children Populated only by IUsageSource::mapTree():
     *     null means "not expanded" (this node is drawn as its own tile), a
     *     non-null array (possibly empty) means this folder's own tile is
     *     replaced by these children in the map (WinDirStat-style: an
     *     expanded folder is a spatial container, not a tile itself).
     * @param int|null $countExact For a synthetic 'other' node folding many
     *     small files together: true if $fileCount is the exact number
     *     folded in, false if more exist beyond what was fetched (so the
     *     frontend should render it as a "N+" lower bound). Null for every
     *     non-synthetic node.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly int $size,
        public readonly string $type, // 'file' | 'folder' | 'other'
        public readonly ?string $mimetype = null,
        public readonly ?int $mtime = null,
        // Recursive descendant file count for a children()/mapTree() 'folder'
        // node (plan Phase 3b), or the fold-in count for a mapTree() 'other'
        // synthetic node. null for files and every node from largestFiles().
        public readonly ?int $fileCount = null,
        public readonly ?array $children = null,
        public readonly ?bool $countExact = null,
        // 'user' | 'teamfolder' | 'external' — only set on a top-level row
        // of the whole-instance scope (plan Phase 3d), so the tree pane can
        // tell the three apart at a glance. Null everywhere else, including
        // every deeper folder inside one of them (those are just folders).
        public readonly ?string $kind = null,
        // The human-readable label for a top-level instance row — set
        // alongside $kind, null everywhere else. $name stays the raw uid
        // (path segments/navigation must stay stable even on an LDAP/AD
        // instance where the uid is an opaque UUID); this is what the UI
        // actually displays instead. Falls back to $name on the frontend
        // when null, so every caller can just do `displayName ?? name`.
        public readonly ?string $displayName = null,
        // Recursive per-mimetype size breakdown for a children() 'folder'
        // node — e.g. ['image/png' => 4096, 'application/pdf' => 2048] —
        // powers the tree pane's "Composição" stacked bar. Categorizing raw
        // mimetypes into the 5 UI buckets is deliberately left to the
        // frontend (utils/mimetypeCategory.js) so that mapping lives in one
        // place. Null for files (their own $mimetype already says everything)
        // and every node from mapTree().
        public readonly ?array $composition = null,
    ) {
    }

    public function jsonSerialize(): array {
        return [
            'name' => $this->name,
            'path' => $this->path,
            'size' => $this->size,
            'type' => $this->type,
            'mimetype' => $this->mimetype,
            'mtime' => $this->mtime,
            'fileCount' => $this->fileCount,
            'children' => $this->children,
            'countExact' => $this->countExact,
            'kind' => $this->kind,
            'composition' => $this->composition,
            'displayName' => $this->displayName,
        ];
    }
}
