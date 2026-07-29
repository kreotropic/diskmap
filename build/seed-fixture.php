<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Seeds a byte-for-byte deterministic file tree into one account's home, so the
 * identical fixture can be created on instances backed by different databases
 * and the app's output diffed between them. Names, sizes, mtimes and nesting
 * are all fixed — nothing is randomised, or the diff would be meaningless.
 *
 * Files are sparse (ftruncate), so a tree of any size costs no real disk.
 *
 * The shape deliberately covers what the aggregation queries are sensitive to:
 *  - non-ASCII folder names, where a byte offset and a character offset differ;
 *  - .pst and .dwg under application/octet-stream, the two extensions the
 *    composition query rewrites into synthetic mimetypes;
 *  - four levels of nesting, so path-segment counting is exercised past the
 *    depth where an off-by-one would still look right;
 *  - a folder of many small files, and two empty folders.
 *
 * Run it inside the container, as root (it chowns afterwards), then scan:
 *
 *   docker exec <app-container> php /var/www/html/custom_apps/diskmap/build/seed-fixture.php <uid>
 *   docker exec -u www-data <app-container> php occ files:scan <uid>
 *
 * Usage: seed-fixture.php <uid> [data-dir]
 */

$uid = $argv[1] ?? null;
if ($uid === null) {
    fwrite(STDERR, "usage: seed-fixture.php <uid> [data-dir]\n");
    exit(1);
}
$dataDir = rtrim($argv[2] ?? '/var/www/html/data', '/');
$home = "$dataDir/$uid";
$root = "$home/files/diskmap-fixture";

// Fixed clock: mtimes must not vary between instances.
const BASE_MTIME = 1750000000;

/** @var array<string, int> relative path => size in bytes */
$files = [];

$files['ficheiro-raiz.txt'] = 4096;

$files['Documentos/Contratos/contrato-01.pdf'] = 512000;
$files['Documentos/Contratos/contrato-02.docx'] = 87000;
$files['Documentos/Contratos/contrato-03.pdf'] = 240500;
$files['Documentos/Relatórios/anual.xlsx'] = 156000;
$files['Documentos/Relatórios/trimestral.xlsx'] = 61000;
$files['Documentos/Relatórios/Arquivo Morto/backup.pst'] = 1450000;
$files['Documentos/Relatórios/Arquivo Morto/plano.dwg'] = 890000;
$files['Documentos/Relatórios/Arquivo Morto/notas.txt'] = 1200;

for ($i = 1; $i <= 40; $i++) {
    $files[sprintf('Média/Fotos/img-%03d.jpg', $i)] = 12000 + ($i * 137);
}
$files['Média/Fotos/capa.png'] = 340000;
$files['Média/Vídeos/clip-intro.mp4'] = 2100000;
$files['Média/Vídeos/clip-final.mp4'] = 1750000;

$files['Arquivos/pacote.zip'] = 640000;
$files['Arquivos/setup.exe'] = 980000;
$files['Arquivos/Antigos/arquivo-2019.zip'] = 410000;

$files['Projetos/Cliente A/Fase 1/Anexos/planta.dwg'] = 725000;
$files['Projetos/Cliente A/Fase 1/resumo.pdf'] = 33000;
$files['Projetos/Cliente A/Fase 2/orçamento.xlsx'] = 45000;

$emptyDirs = ['Vazia', 'Projetos/Cliente B'];

if (is_dir($root)) {
    exec('rm -rf ' . escapeshellarg($root));
}
foreach ($emptyDirs as $d) {
    mkdir("$root/$d", 0755, true);
}

foreach ($files as $rel => $size) {
    $full = "$root/$rel";
    if (!is_dir(dirname($full))) {
        mkdir(dirname($full), 0755, true);
    }
    $fh = fopen($full, 'w');
    ftruncate($fh, $size);
    fclose($fh);
    chmod($full, 0644);
}

// Stamp mtimes deepest-first, so creating a child cannot re-stamp its parent.
$paths = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($it as $item) {
    $paths[] = $item->getPathname();
}
$paths[] = $root;
sort($paths);
foreach ($paths as $p) {
    // Distinct per path but derived from it, so it reproduces exactly.
    touch($p, BASE_MTIME + (crc32($p) % 100000));
}

// The whole home, not just the fixture: when the account has never logged in,
// its home and files/ directories get created here as root, and files:scan
// then refuses with "User folder is not writable".
exec('chown -R www-data:www-data ' . escapeshellarg($home));

echo 'seeded ' . count($files) . ' files + ' . count($emptyDirs) . " empty dirs under $root\n";
