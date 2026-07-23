<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Tests\Unit;

use OCA\DiskMap\Service\Aggregator;
use OCA\DiskMap\Usage\UsageNode;
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

    private function node(string $name, int $size): UsageNode {
        return new UsageNode(name: $name, path: $name, size: $size, type: 'file');
    }

    public function testWithOthersBucketPassesThroughSingleNode(): void {
        $nodes = [$this->node('a', 100)];
        $this->assertSame($nodes, Aggregator::withOthersBucket($nodes));
    }

    public function testWithOthersBucketSortsBySizeDescending(): void {
        $nodes = [$this->node('small', 10), $this->node('big', 90)];
        $result = Aggregator::withOthersBucket($nodes, minShare: 0.0);
        $this->assertSame(['big', 'small'], array_map(static fn ($n) => $n->name, $result));
    }

    public function testWithOthersBucketCollapsesSmallEntries(): void {
        $nodes = [
            $this->node('big', 970),
            $this->node('tiny1', 15),
            $this->node('tiny2', 15),
        ];

        // Each tiny node is 1.5% of the 1000 total — below the 5% threshold.
        $result = Aggregator::withOthersBucket($nodes, minShare: 0.05);

        $this->assertCount(2, $result);
        $this->assertSame('big', $result[0]->name);
        $this->assertSame(Aggregator::OTHERS_KEY, $result[1]->name);
        $this->assertSame(Aggregator::OTHERS_TYPE, $result[1]->type);
        $this->assertSame(30, $result[1]->size);
    }

    public function testWithOthersBucketKeepsEverythingAboveThreshold(): void {
        $nodes = [$this->node('a', 60), $this->node('b', 40)];
        $result = Aggregator::withOthersBucket($nodes, minShare: 0.05);

        $this->assertCount(2, $result);
        $this->assertSame(['a', 'b'], array_map(static fn ($n) => $n->name, $result));
    }

    public function testWithOthersBucketHandlesZeroTotalSize(): void {
        $nodes = [$this->node('a', 0), $this->node('b', 0)];
        $result = Aggregator::withOthersBucket($nodes, minShare: 0.05);
        $this->assertCount(2, $result);
    }
}
