<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Usage;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Memoizes the one genuinely expensive read this app performs: the recursive
 * file-count + per-mimetype breakdown of a folder's whole subtree
 * (FilecacheUsageSource::recursiveComposition() and friends).
 *
 * Everything else the app reads is either a single indexed row lookup or a
 * bounded child listing; this aggregate is the only figure whose cost is
 * linear in subtree size, and it therefore sets the latency of opening a
 * folder. Measured on a synthetic tree: 4.5 ms over 1k descendants, 57 ms
 * over 15k, 1421 ms over 300k. On the whole-server view that is exactly the
 * "takes a few seconds" the tree pane shows.
 *
 * The interesting part is invalidation, and it is free. Nextcloud propagates
 * both `size` and `mtime` up every ancestor of a changed file (OC\Files\Cache\
 * Propagator), so a folder's own filecache row already carries a version
 * stamp for its entire subtree: if anything below it changed, at least one of
 * the two changed with it. Making that pair part of the cache KEY rather than
 * something to compare against means a stale entry is never looked up in the
 * first place — there is nothing to invalidate, and no write path in this
 * read-only app that would have to remember to do so.
 *
 * Two honest limits, both bounded by the TTL rather than silently permanent:
 *
 *  - mtime has one-second granularity, so two changes inside the same second
 *    that leave the folder's total size identical (delete a 100-byte image,
 *    add a 100-byte PDF) produce the same key. The size/mtime pair is a
 *    coarser stamp than `etag` would be, but etag is not currently read
 *    anywhere in this app and the miss is a slightly stale composition bar,
 *    not a wrong total.
 *  - a folder's mtime on an external storage comes from the storage itself
 *    (POSIX directory mtimes only move when their *direct* entries change),
 *    so a same-size replacement deep inside one may not move either half of
 *    the stamp.
 *
 * Both are acceptable here because the view is explicitly a snapshot — every
 * header in this app already states "Reflects the file cache as of {date}" —
 * and because the failure mode is a bar that lags, never a size that lies
 * (sizes come straight from filecache, never from this cache).
 */
final class CompositionCache {

    /**
     * The key already handles correctness for anything that propagates, so
     * this exists for two secondary reasons: keeping evicted-by-mutation keys
     * from accumulating forever, and bounding the external-storage edge case
     * above to a day rather than to "whenever Redis next runs out of memory".
     */
    private const TTL = 86400;

    private ICache $cache;

    public function __construct(ICacheFactory $cacheFactory) {
        // Distributed rather than local: on a multi-server install the point
        // is precisely that the second admin to open a folder doesn't re-pay
        // the scan. With no memcache configured at all, core hands back a
        // request-local ArrayCache, which still de-duplicates within a single
        // request and is otherwise a harmless no-op.
        $this->cache = $cacheFactory->createDistributed('diskmap-composition');
    }

    /**
     * @return array{count: int, composition: array<string, int>}|null null on
     *     a miss, on an unstampable folder (see key()), or on a value that
     *     doesn't round-trip to the expected shape.
     */
    public function get(int $storageId, string $path, int $size, ?int $mtime): ?array {
        $key = $this->key($storageId, $path, $size, $mtime);
        if ($key === null) {
            return null;
        }

        $cached = $this->cache->get($key);
        // Backends serialize differently (Redis round-trips through JSON,
        // ArrayCache stores the array as-is), and a cache can hold anything a
        // previous version of this app wrote. Treat any unexpected shape as a
        // miss rather than trusting it into the UI.
        if (!is_array($cached) || !isset($cached['count']) || !isset($cached['composition']) || !is_array($cached['composition'])) {
            return null;
        }

        $composition = [];
        foreach ($cached['composition'] as $mimetype => $bytes) {
            $composition[(string)$mimetype] = (int)$bytes;
        }
        return ['count' => (int)$cached['count'], 'composition' => $composition];
    }

    /**
     * @param array{count: int, composition: array<string, int>} $value
     */
    public function set(int $storageId, string $path, int $size, ?int $mtime, array $value): void {
        $key = $this->key($storageId, $path, $size, $mtime);
        if ($key === null) {
            return;
        }
        $this->cache->set($key, $value, self::TTL);
    }

    /**
     * storage + path identify the folder; size + mtime version it. The path
     * is hashed rather than embedded: it is attacker-influenced (any user can
     * name a folder), unbounded in length, and may contain whatever bytes the
     * storage allows — none of which belongs unescaped in a cache key.
     *
     * Returns null when there is no usable version stamp (a null mtime, as an
     * instance entry whose root row couldn't be read has), because a key with
     * no version in it could never be invalidated by a change.
     */
    private function key(int $storageId, string $path, int $size, ?int $mtime): ?string {
        if ($mtime === null) {
            return null;
        }
        return $storageId . '-' . md5($path) . '-' . $size . '-' . $mtime;
    }
}
