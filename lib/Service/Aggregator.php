<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Service;

use OCA\DiskMap\Usage\UsageNode;

/**
 * Cross-cutting helpers for turning raw sizes into presentable aggregates:
 * occupancy percentages and an "others" bucket for long tails (treemap /
 * mimetype views, Phase 3-4). The per-group attributed/real/overlap
 * reconciliation (plan §6) lands here too once the group view (Phase 4) is
 * built; for now this only carries what Phase 1 (team folder quotas) needs.
 */
class Aggregator {

    /** Sentinel node type/path for the synthetic "everything else" bucket. */
    public const OTHERS_TYPE = 'other';
    public const OTHERS_KEY = '__others__';

    /**
     * @return float|null percentage (0-100+, can exceed 100 over quota), or
     *                     null when there's no quota to compare against
     *                     (unlimited or unknown — plan convention: a
     *                     negative or missing quota means unlimited).
     */
    public static function occupancyPercent(int $used, ?int $quota): ?float {
        if ($quota === null || $quota <= 0) {
            return null;
        }
        return round(($used / $quota) * 100, 1);
    }

    /**
     * Sorts nodes by size descending and collapses everything below
     * $minShare of the total into a single "others" bucket (key
     * self::OTHERS_KEY — the frontend supplies the translated label), so
     * treemaps and legends stay readable when there are hundreds of small
     * entries.
     *
     * @param UsageNode[] $nodes
     * @return UsageNode[]
     */
    public static function withOthersBucket(array $nodes, float $minShare = 0.01): array {
        if (count($nodes) <= 1) {
            return $nodes;
        }

        usort($nodes, static fn (UsageNode $a, UsageNode $b) => $b->size <=> $a->size);

        $total = array_sum(array_map(static fn (UsageNode $n) => $n->size, $nodes));
        if ($total <= 0) {
            return $nodes;
        }

        $kept = [];
        $othersSize = 0;
        foreach ($nodes as $node) {
            if (($node->size / $total) >= $minShare) {
                $kept[] = $node;
            } else {
                $othersSize += $node->size;
            }
        }

        if ($othersSize > 0) {
            $kept[] = new UsageNode(
                name: self::OTHERS_KEY,
                path: '',
                size: $othersSize,
                type: self::OTHERS_TYPE,
            );
        }

        return $kept;
    }
}
