<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <ricardo.ferreira@jofebar.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Resolves a user's home numeric storage id. A user's storage id is
 * "home::<uid>" for local/database-backed accounts or "object::user:<uid>"
 * for primary object storage — only one can match for a given account.
 *
 * Shared by FilecacheUsageSource (browsing within "files") and
 * UserStorageService (the files/trash/versions breakdown), so the
 * home-storage naming convention lives in exactly one place.
 */
class UserHomeResolver {
    public function __construct(private IDBConnection $db) {
    }

    public function resolveStorageId(string $uid): ?int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('numeric_id')
            ->from('storages')
            ->where($qb->expr()->in('id', $qb->createNamedParameter(
                ['home::' . $uid, 'object::user:' . $uid],
                IQueryBuilder::PARAM_STR_ARRAY,
            )))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetchAssociative();
        $result->closeCursor();

        return $row ? (int)$row['numeric_id'] : null;
    }
}
