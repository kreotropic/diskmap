<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

/**
 * Explicit read scope for IUsageSource. Every call states exactly what it
 * wants to read (a user, a team folder, or a raw storage) — never "the
 * current user" implicitly. This is what lets the admin and user-facing
 * controllers share the same aggregation code (plan §8).
 */
final class Scope {
    public const TYPE_USER = 'user';
    public const TYPE_TEAM_FOLDER = 'teamfolder';
    public const TYPE_STORAGE = 'storage';

    private function __construct(
        public readonly string $type,
        public readonly string $identifier,
        public readonly string $path = '',
    ) {
    }

    public static function forUser(string $uid, string $path = ''): self {
        return new self(self::TYPE_USER, $uid, $path);
    }

    public static function forTeamFolder(int $folderId, string $path = ''): self {
        return new self(self::TYPE_TEAM_FOLDER, (string)$folderId, $path);
    }

    public static function forStorage(int $numericStorageId, string $path = ''): self {
        return new self(self::TYPE_STORAGE, (string)$numericStorageId, $path);
    }

    /**
     * Builds a scope from raw request input.
     *
     * @throws \InvalidArgumentException on an unknown scope type or a
     *                                    non-numeric identifier for a
     *                                    teamfolder/storage scope.
     */
    public static function fromRequest(string $type, string $identifier, string $path = ''): self {
        $path = trim($path, '/');

        return match ($type) {
            self::TYPE_USER => self::forUser($identifier, $path),
            self::TYPE_TEAM_FOLDER => self::forTeamFolder(self::requireInt($type, $identifier), $path),
            self::TYPE_STORAGE => self::forStorage(self::requireInt($type, $identifier), $path),
            default => throw new \InvalidArgumentException("Unknown scope type: {$type}"),
        };
    }

    private static function requireInt(string $type, string $identifier): int {
        if (!ctype_digit($identifier)) {
            throw new \InvalidArgumentException("Scope '{$type}' requires a numeric identifier");
        }
        return (int)$identifier;
    }
}
