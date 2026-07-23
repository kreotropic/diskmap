<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Tests\Unit;

use OCA\DiskMap\Service\UserStorageService;
use OCA\DiskMap\Usage\IUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCA\DiskMap\Usage\UserHomeResolver;
use OCP\Files\FileInfo;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserStorageServiceTest extends TestCase {

    private IUsageSource&MockObject $usageSource;
    private UserHomeResolver&MockObject $userHomeResolver;
    private UserStorageService $service;

    protected function setUp(): void {
        $this->usageSource = $this->createMock(IUsageSource::class);
        $this->userHomeResolver = $this->createMock(UserHomeResolver::class);
        $this->service = new UserStorageService($this->usageSource, $this->userHomeResolver);
    }

    private function user(string $uid, int|float $quotaBytes): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getQuotaBytes')->willReturn($quotaBytes);
        return $user;
    }

    public function testOverviewSumsFilesTrashAndVersions(): void {
        $this->userHomeResolver->method('resolveStorageId')->with('alice')->willReturn(42);
        $this->usageSource->method('totalSize')->willReturnCallback(
            static fn (Scope $scope) => match ($scope->path) {
                'files' => 1000,
                'files_trashbin' => 200,
                'files_versions' => 50,
            },
        );
        $this->usageSource->method('lastUpdated')->willReturn(123456);

        $overview = $this->service->overview($this->user('alice', 10000));

        $this->assertSame(1000, $overview['filesSize']);
        $this->assertSame(200, $overview['trashSize']);
        $this->assertSame(50, $overview['versionsSize']);
        $this->assertSame(1250, $overview['used']);
        $this->assertSame(123456, $overview['lastUpdated']);
    }

    public function testOverviewComputesOccupancyAgainstQuota(): void {
        $this->userHomeResolver->method('resolveStorageId')->willReturn(1);
        $this->usageSource->method('totalSize')->willReturn(500);

        $overview = $this->service->overview($this->user('bob', 1500));

        // used = 500 (files) + 500 (trash) + 500 (versions) = 1500 of a 1500 quota.
        $this->assertSame(1500, $overview['quota']);
        $this->assertSame(100.0, $overview['occupancyPercent']);
    }

    public function testOverviewTreatsUnlimitedQuotaAsNull(): void {
        $this->userHomeResolver->method('resolveStorageId')->willReturn(1);
        $this->usageSource->method('totalSize')->willReturn(0);

        $overview = $this->service->overview($this->user('carol', FileInfo::SPACE_UNLIMITED));

        $this->assertNull($overview['quota']);
        $this->assertNull($overview['occupancyPercent']);
    }

    public function testOverviewTreatsUnknownQuotaAsNull(): void {
        $this->userHomeResolver->method('resolveStorageId')->willReturn(1);
        $this->usageSource->method('totalSize')->willReturn(0);

        $overview = $this->service->overview($this->user('dave', FileInfo::SPACE_UNKNOWN));

        $this->assertNull($overview['quota']);
    }

    /**
     * A brand-new account with no home storage yet (resolveStorageId returns
     * null) must report zero usage rather than crash — mirrors
     * TeamFolderService::sizeFor()'s handling of a not-yet-created subtree.
     */
    public function testOverviewHandlesMissingHomeStorage(): void {
        $this->userHomeResolver->method('resolveStorageId')->willReturn(null);
        $this->usageSource->expects($this->never())->method('totalSize');

        $overview = $this->service->overview($this->user('ghost', 1000));

        $this->assertSame(0, $overview['used']);
        $this->assertNull($overview['lastUpdated']);
    }
}
