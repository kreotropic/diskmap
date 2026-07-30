<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Dumps every read path of the app over the tree that seed-fixture.php
 * creates, in a form two instances backed by different databases can be
 * diffed on. Run it on each, redirect to a file, `diff` the two.
 *
 * Storage ids and file ids are deliberately never printed: they come from
 * auto-increment and sequences, so they differ between instances by
 * construction and would swamp the diff without saying anything about the
 * engine. Everything that does depend on the database — sizes, counts,
 * per-mimetype composition, the order rows come back in, the shape of the map
 * tree — is printed in full.
 *
 * Expect exactly one legitimate difference between two instances: the account
 * home's own mtime is the timestamp of its `files:scan`, so it varies unless
 * both scans landed in the same second. The fixture's own folders and files
 * carry fixed mtimes and must match exactly.
 *
 *   docker exec -u www-data <app-container> \
 *       php /var/www/html/custom_apps/diskmap/build/dump-readpaths.php fixture > out.txt
 *
 * Usage: dump-readpaths.php <uid>
 */

require_once '/var/www/html/lib/base.php';
\OC_App::loadApps();

use OCA\DiskMap\Usage\FilecacheUsageSource;
use OCA\DiskMap\Usage\Scope;
use OCA\DiskMap\Usage\UsageNode;

$uid = $argv[1] ?? null;
if ($uid === null) {
    fwrite(STDERR, "usage: dump-readpaths.php <uid>\n");
    exit(1);
}

$source = \OCP\Server::get(FilecacheUsageSource::class);

function nodeLine(UsageNode $node, string $indent = ''): string {
    $parts = [
        'name=' . $node->name,
        'path=' . $node->path,
        'size=' . $node->size,
        'type=' . $node->type,
        'mime=' . var_export($node->mimetype, true),
        'mtime=' . var_export($node->mtime, true),
        'fileCount=' . var_export($node->fileCount, true),
        'countExact=' . var_export($node->countExact, true),
        'kind=' . var_export($node->kind, true),
    ];
    if ($node->composition !== null) {
        $composition = $node->composition;
        ksort($composition);
        $parts[] = 'composition=' . json_encode($composition);
    }
    return $indent . implode(' ', $parts);
}

function dumpTree(?UsageNode $node, string $indent = ''): void {
    if ($node === null) {
        echo $indent . "NULL\n";
        return;
    }
    echo nodeLine($node, $indent) . "\n";
    foreach ($node->children ?? [] as $child) {
        dumpTree($child, $indent . '  ');
    }
}

function dumpComposition(array $entry): void {
    ksort($entry['composition']);
    echo '    count=' . $entry['count']
        . ' composition=' . json_encode($entry['composition']) . "\n";
}

// Every folder of the fixture, so the path-segment expression is exercised at
// each depth rather than only at the trivial one. Keep in step with
// seed-fixture.php.
$paths = [
    '',
    'files',
    'files/diskmap-fixture',
    'files/diskmap-fixture/Documentos',
    'files/diskmap-fixture/Documentos/Contratos',
    'files/diskmap-fixture/Documentos/Relatórios',
    'files/diskmap-fixture/Documentos/Relatórios/Arquivo Morto',
    'files/diskmap-fixture/Média',
    'files/diskmap-fixture/Média/Fotos',
    'files/diskmap-fixture/Média/Vídeos',
    'files/diskmap-fixture/Arquivos',
    'files/diskmap-fixture/Arquivos/Antigos',
    'files/diskmap-fixture/Projetos',
    'files/diskmap-fixture/Projetos/Cliente A',
    'files/diskmap-fixture/Projetos/Cliente A/Fase 1',
    'files/diskmap-fixture/Projetos/Cliente A/Fase 1/Anexos',
    'files/diskmap-fixture/Projetos/Cliente A/Fase 2',
    'files/diskmap-fixture/Projetos/Cliente B',
    'files/diskmap-fixture/Vazia',
];

foreach ($paths as $path) {
    $scope = Scope::forUser($uid, $path);
    echo "########## SCOPE user:$uid path='$path'\n";
    echo 'totalSize=' . var_export($source->totalSize($scope), true) . "\n";
    echo 'lastUpdated=' . var_export($source->lastUpdated($scope), true) . "\n";

    $children = $source->children($scope, 200);
    echo '-- children (truncated=' . var_export($children['truncated'], true) . ")\n";
    echo '  root: ' . ($children['root'] ? nodeLine($children['root']) : 'NULL') . "\n";
    foreach ($children['items'] as $item) {
        echo '  ' . nodeLine($item) . "\n";
    }

    // The composition path: this is where the per-engine path-segment
    // expression and the mimetype CASE both land.
    $composition = $source->childComposition($scope, 200);
    echo "-- childComposition\n  root:\n";
    if ($composition['root'] === null) {
        echo "    NULL\n";
    } else {
        dumpComposition($composition['root']);
    }
    $items = $composition['items'];
    ksort($items);
    foreach ($items as $name => $entry) {
        echo "  child '$name':\n";
        dumpComposition($entry);
    }

    echo "-- mapTree(400)\n";
    dumpTree($source->mapTree($scope, 400)['root']);
    echo "\n";
}

// The whole-instance scope is the only caller of the batched composition
// query. The two instances hold different account sets, so only the fixture's
// own row is comparable — but it still travels the GROUP BY storage path.
echo "########## INSTANCE scope, fixture row only\n";
$instanceScope = Scope::forInstance();
foreach ($source->children($instanceScope, 500)['items'] as $item) {
    if ($item->name === $uid) {
        echo 'child: ' . nodeLine($item) . "\n";
    }
}
$instanceComposition = $source->childComposition($instanceScope, 500);
if (isset($instanceComposition['items'][$uid])) {
    echo "bulk composition:\n";
    dumpComposition($instanceComposition['items'][$uid]);
} else {
    echo "MISSING $uid in instance composition\n";
}

// One level deeper through the instance scope, which delegates into the same
// per-storage browsing with a rewritten path.
echo "-- instance delegate into the fixture\n";
$deep = Scope::forInstance($uid . '/files/diskmap-fixture');
$deepChildren = $source->children($deep, 200);
echo '  root: ' . ($deepChildren['root'] ? nodeLine($deepChildren['root']) : 'NULL') . "\n";
foreach ($deepChildren['items'] as $item) {
    echo '  ' . nodeLine($item) . "\n";
}
$deepItems = $source->childComposition($deep, 200)['items'];
ksort($deepItems);
foreach ($deepItems as $name => $entry) {
    echo "  child '$name':\n";
    dumpComposition($entry);
}
