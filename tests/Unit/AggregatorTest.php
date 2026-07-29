<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Tests\Unit;

use OCA\DiskMap\Service\Aggregator;
use PHPUnit\Framework\TestCase;

class AggregatorTest extends TestCase {

    public function testOccupancyPercentComputesRatio(): void {
        $this->assertSame(50.0, Aggregator::occupancyPercent(50, 100));
    }

    public function testOccupancyPercentCanExceed100WhenOverQuota(): void {
        $this->assertSame(150.0, Aggregator::occupancyPercent(150, 100));
    }

    public function testOccupancyPercentIsNullWhenQuotaIsNull(): void {
        // null quota is the plan's "unlimited" convention (plan §10).
        $this->assertNull(Aggregator::occupancyPercent(1000, null));
    }

    public function testOccupancyPercentIsNullWhenQuotaIsNegative(): void {
        // groupfolders convention: a negative quota also means unlimited.
        $this->assertNull(Aggregator::occupancyPercent(1000, -3));
    }

    public function testOccupancyPercentIsNullWhenQuotaIsZero(): void {
        $this->assertNull(Aggregator::occupancyPercent(0, 0));
    }
}
