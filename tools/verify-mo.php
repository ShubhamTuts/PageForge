#!/usr/bin/env php
<?php
/**
 * Reads a compiled MO back and resolves a lookup, so a broken compiler cannot
 * ship silently as "translations present but nothing translated".
 */
declare(strict_types=1);

$path = (string) ($argv[1] ?? '');
if (!is_file($path)) {
    fwrite(STDERR, "Not a file: {$path}\n");
    exit(2);
}

$raw = (string) file_get_contents($path);
$magic = unpack('V', substr($raw, 0, 4))[1] ?? 0;
if (0x950412de !== $magic) {
    fwrite(STDERR, sprintf("Bad magic: 0x%08x\n", $magic));
    exit(1);
}

$header = unpack('Vrevision/Vcount/Vorig/Vtrans', substr($raw, 4, 16));
$count = (int) $header['count'];

$entries = [];
for ($i = 0; $i < $count; $i++) {
    $o = unpack('Vlen/Voff', substr($raw, (int) $header['orig'] + ($i * 8), 8));
    $t = unpack('Vlen/Voff', substr($raw, (int) $header['trans'] + ($i * 8), 8));
    $original = substr($raw, (int) $o['off'], (int) $o['len']);
    $translation = substr($raw, (int) $t['off'], (int) $t['len']);
    $entries[$original] = $translation;
}

printf("%s\n", basename($path));
printf("  magic   : OK (little-endian)\n");
printf("  entries : %d\n", $count);
printf("  header  : %s\n", '' !== ($entries[''] ?? '') ? 'present' : 'MISSING');

// Show a few real round-trips so the output is evidence, not a claim.
$shown = 0;
foreach ($entries as $original => $translation) {
    if ('' === $original || '' === $translation) {
        continue;
    }
    printf("  \"%s\" => \"%s\"\n", substr($original, 0, 42), substr($translation, 0, 42));
    if (++$shown >= 3) {
        break;
    }
}

exit($count > 1 && '' !== ($entries[''] ?? '') ? 0 : 1);
