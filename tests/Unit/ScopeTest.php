<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Tests\Unit;

use OCA\DiskMap\Usage\Scope;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase {

    public function testForUserBuildsUserScope(): void {
        $scope = Scope::forUser('alice', 'Documents');
        $this->assertSame(Scope::TYPE_USER, $scope->type);
        $this->assertSame('alice', $scope->identifier);
        $this->assertSame('Documents', $scope->path);
    }

    public function testFromRequestBuildsUserScopeWithArbitraryIdentifier(): void {
        // Unlike teamfolder/storage, a uid is never required to be numeric.
        $scope = Scope::fromRequest('user', 'alice');
        $this->assertSame(Scope::TYPE_USER, $scope->type);
        $this->assertSame('alice', $scope->identifier);
    }

    public function testFromRequestBuildsTeamFolderScope(): void {
        $scope = Scope::fromRequest('teamfolder', '7');
        $this->assertSame(Scope::TYPE_TEAM_FOLDER, $scope->type);
        $this->assertSame('7', $scope->identifier);
    }

    public function testFromRequestBuildsStorageScope(): void {
        $scope = Scope::fromRequest('storage', '42');
        $this->assertSame(Scope::TYPE_STORAGE, $scope->type);
        $this->assertSame('42', $scope->identifier);
    }

    public function testFromRequestTrimsSlashesFromPath(): void {
        $scope = Scope::fromRequest('user', 'alice', '/Documents/Reports/');
        $this->assertSame('Documents/Reports', $scope->path);
    }

    public function testFromRequestRejectsUnknownScopeType(): void {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromRequest('bogus', '1');
    }

    /**
     * A team folder or storage id is used directly as a numeric filter in a
     * SQL query (FilecacheUsageSource) — rejecting non-numeric input here,
     * before it ever reaches the query builder, is the guard that matters.
     */
    public function testFromRequestRejectsNonNumericTeamFolderIdentifier(): void {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromRequest('teamfolder', 'not-a-number');
    }

    public function testFromRequestRejectsNonNumericStorageIdentifier(): void {
        $this->expectException(\InvalidArgumentException::class);
        Scope::fromRequest('storage', 'not-a-number');
    }
}
