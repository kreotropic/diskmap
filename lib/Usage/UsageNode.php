<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

/**
 * A single row in a usage listing: a file, a folder, or a synthetic bucket
 * such as the "others" node produced by Aggregator::withOthersBucket().
 */
final class UsageNode implements \JsonSerializable {
    public function __construct(
        public readonly string $name,
        public readonly string $path,
        public readonly int $size,
        public readonly string $type, // 'file' | 'folder' | 'other'
        public readonly ?string $mimetype = null,
        public readonly ?int $mtime = null,
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
        ];
    }
}
