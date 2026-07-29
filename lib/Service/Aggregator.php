<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Service;

/**
 * Cross-cutting helpers for turning raw sizes into presentable aggregates.
 * The per-group attributed/real/overlap reconciliation (plan §6) lands here
 * once the group view (Phase 4) is built; for now this only carries what
 * Phase 1-2 (team folder and personal quotas) need.
 */
class Aggregator {

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
}
