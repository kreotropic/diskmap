<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\DiskMap\Tests\Unit;

use OCA\DiskMap\GroupFolders\LayoutDetector;
use OCA\DiskMap\Usage\CompositionCache;
use OCA\DiskMap\Usage\FilecacheUsageSource;
use OCA\DiskMap\Usage\InstanceIndex;
use OCA\DiskMap\Usage\UserHomeResolver;
use OCP\DB\IResult;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The composition queries are the app's only raw SQL, so they are the only
 * place a MySQL-ism can reach the database. These tests capture the SQL each
 * one builds under each supported provider and assert on the four things that
 * differ between MySQL/MariaDB and PostgreSQL — a real database is not needed
 * to catch a dialect regression, only the generated text.
 *
 * What this cannot prove is that the two path-segment expressions agree on
 * real data; that is what the cross-engine output diff covers.
 */
class FilecacheDialectTest extends TestCase {

    /** @var array<int, array{sql: string, params: array}> */
    private array $captured = [];

    private function sourceFor(string $provider): FilecacheUsageSource {
        $this->captured = [];

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturn(false);

        $db = $this->createMock(IDBConnection::class);
        $db->method('getDatabaseProvider')->willReturn($provider);
        $db->method('escapeLikeParameter')->willReturnArgument(0);
        $db->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params = []) use ($result): IResult {
                $this->captured[] = ['sql' => $sql, 'params' => $params];
                return $result;
            },
        );

        // CompositionCache is final, so it is built for real over a mocked
        // factory. None of the queries under test consult it anyway.
        $cacheFactory = $this->createMock(ICacheFactory::class);
        $cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));

        $source = new FilecacheUsageSource(
            $db,
            $this->createMock(LayoutDetector::class),
            $this->createMock(UserHomeResolver::class),
            $this->createMock(InstanceIndex::class),
            new CompositionCache($cacheFactory),
        );

        // Pre-seed the folder-mimetype lookup so the queries under test are the
        // only ones that run — resolving it for real needs a QueryBuilder,
        // which is well past what a dialect test should have to mock.
        $cache = new \ReflectionProperty($source, 'folderMimetypeIdCache');
        $cache->setValue($source, 2);

        return $source;
    }

    private function invoke(FilecacheUsageSource $source, string $method, array $args): void {
        $m = new \ReflectionMethod($source, $method);
        $m->invokeArgs($source, $args);
    }

    /**
     * Runs all three composition queries and returns the SQL each produced.
     *
     * @return array<int, array{sql: string, params: array}>
     */
    private function allCompositionQueries(string $provider): array {
        $source = $this->sourceFor($provider);
        $this->invoke($source, 'recursiveComposition', [7, 'files/docs', 1024]);
        $this->invoke($source, 'bulkComposition', [[7, 9], 'files']);
        $this->invoke($source, 'compositionByChild', [7, 'files/docs']);

        $this->assertCount(3, $this->captured, 'each call should issue exactly one query');
        return $this->captured;
    }

    public static function providerProvider(): array {
        return [
            'mysql' => [IDBConnection::PLATFORM_MYSQL],
            'postgres' => [IDBConnection::PLATFORM_POSTGRES],
        ];
    }

    /**
     * Backticks are MySQL-only syntax; PostgreSQL would reject them outright.
     * The identifiers involved are all unquoted-safe on both engines.
     *
     * @dataProvider providerProvider
     */
    public function testNoQueryQuotesIdentifiersWithBackticks(string $provider): void {
        foreach ($this->allCompositionQueries($provider) as $query) {
            $this->assertStringNotContainsString('`', $query['sql']);
        }
    }

    /**
     * PostgreSQL has no index-hint syntax, so the hint must not merely be
     * ignored there — it must not be emitted at all.
     */
    public function testIndexHintIsMysqlOnly(): void {
        foreach ($this->allCompositionQueries(IDBConnection::PLATFORM_MYSQL) as $query) {
            $this->assertStringContainsString('USE INDEX (fs_storage_path_prefix)', $query['sql']);
        }
        foreach ($this->allCompositionQueries(IDBConnection::PLATFORM_POSTGRES) as $query) {
            $this->assertStringNotContainsString('USE INDEX', $query['sql']);
        }
    }

    /**
     * MySQL resolves a SELECT alias in GROUP BY, PostgreSQL prefers input
     * columns — and `mimetype` is a column on both sides of the join, so
     * naming the alias there is an ambiguity error rather than a portable
     * shorthand. Every GROUP BY must therefore repeat the full expression.
     *
     * @dataProvider providerProvider
     */
    public function testGroupByRepeatsTheExpressionInsteadOfNamingTheAlias(string $provider): void {
        foreach ($this->allCompositionQueries($provider) as $query) {
            $groupBy = substr($query['sql'], (int)strrpos($query['sql'], 'GROUP BY'));
            $this->assertStringContainsString('CASE', $groupBy);
            $this->assertDoesNotMatchRegularExpression(
                '/\bmimetype\s*$/',
                $groupBy,
                'GROUP BY must not end on the bare `mimetype` alias',
            );
        }
    }

    /**
     * The "first N path segments" grouping key, the one expression with no
     * spelling the two engines share. N is inlined (not bound), so the
     * parameter list must stay at storage / path pattern / folder mimetype.
     */
    public function testChildSegmentExpressionMatchesTheProvider(): void {
        $mysql = $this->allCompositionQueries(IDBConnection::PLATFORM_MYSQL)[2];
        $this->assertStringContainsString("SUBSTRING_INDEX(f.path, '/', 3)", $mysql['sql']);
        $this->assertStringNotContainsString('string_to_array', $mysql['sql']);
        $this->assertSame([7, 'files/docs/%', 2], $mysql['params']);

        $postgres = $this->allCompositionQueries(IDBConnection::PLATFORM_POSTGRES)[2];
        $this->assertStringContainsString(
            "array_to_string((string_to_array(f.path, '/'))[1:3], '/')",
            $postgres['sql'],
        );
        $this->assertStringNotContainsString('SUBSTRING_INDEX', $postgres['sql']);
        $this->assertSame([7, 'files/docs/%', 2], $postgres['params']);
    }

    /**
     * An external storage's own root sits at the empty path, where there is no
     * prefix to match on and the segment count restarts at 1 — the edge case
     * that made the LIKE pattern a bare '%' in the first place.
     *
     * @dataProvider providerProvider
     */
    public function testExternalStorageRootKeepsItsBarePatternOnBothEngines(string $provider): void {
        $source = $this->sourceFor($provider);
        $this->invoke($source, 'compositionByChild', [7, '']);

        $this->assertSame([7, '%', 2], $this->captured[0]['params']);
        $this->assertStringContainsString(
            $provider === IDBConnection::PLATFORM_POSTGRES ? '[1:1]' : "'/', 1)",
            $this->captured[0]['sql'],
        );
    }
}
