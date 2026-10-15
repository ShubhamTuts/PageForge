#!/usr/bin/env php
<?php
/**
 * Compiles PO files to MO binaries.
 *
 * gettext's msgfmt is not available on this machine, and WordPress loads the
 * binary catalog, not the PO source, so a regenerated PO with no matching MO
 * would silently ship untranslated. This writes the MO format directly:
 * little-endian magic, sorted original/translation tables, per the GNU gettext
 * file format.
 *
 * Untranslated entries are omitted, which is what msgfmt does: an empty
 * translation in the catalog would otherwise mask the English original.
 *
 * Usage: php tools-mo.php <languages-dir>
 */

declare(strict_types=1);

$dir = rtrim((string) ($argv[1] ?? ''), "/\\");
if (!is_dir($dir)) {
    fwrite(STDERR, "Not a directory: {$dir}\n");
    exit(2);
}

/**
 * @return array{0: array<string,string>, 1: string} entries, header block
 */
function pf_mo_parse_po(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [[], ''];
    }

    $entries = [];
    $header = '';
    $ctx = null;
    $id = null;
    $plural = null;
    $strs = [];
    $mode = null;

    $unescape = static function (string $v): string {
        return str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $v);
    };

    $flush = static function () use (&$entries, &$header, &$ctx, &$id, &$plural, &$strs): void {
        if (null === $id) {
            return;
        }
        if ('' === $id && null === $ctx) {
            $header = (string) ($strs[0] ?? '');
        } else {
            $translated = false;
            foreach ($strs as $s) {
                if ('' !== $s) {
                    $translated = true;
                    break;
                }
            }
            if ($translated) {
                // MO key format: context\4msgid, plural forms joined by \0.
                $key = (null !== $ctx && '' !== $ctx) ? $ctx . "\4" . $id : $id;
                if (null !== $plural) {
                    $key .= "\0" . $plural;
                    ksort($strs);
                    $entries[$key] = implode("\0", array_map('strval', $strs));
                } else {
                    $entries[$key] = (string) ($strs[0] ?? '');
                }
            }
        }
        $ctx = null;
        $id = null;
        $plural = null;
        $strs = [];
    };

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ('' === $line) {
            $flush();
            $mode = null;
            continue;
        }
        if (0 === strpos($line, '#')) {
            continue;
        }
        if (preg_match('/^msgctxt\s+"(.*)"$/', $line, $m)) {
            $flush();
            $ctx = $unescape($m[1]);
            $mode = 'ctx';
        } elseif (preg_match('/^msgid\s+"(.*)"$/', $line, $m)) {
            if (null !== $id) {
                $flush();
            }
            $id = $unescape($m[1]);
            $mode = 'id';
        } elseif (preg_match('/^msgid_plural\s+"(.*)"$/', $line, $m)) {
            $plural = $unescape($m[1]);
            $mode = 'plural';
        } elseif (preg_match('/^msgstr(?:\[(\d+)\])?\s+"(.*)"$/', $line, $m)) {
            $idx = '' === ($m[1] ?? '') ? 0 : (int) $m[1];
            $strs[$idx] = $unescape($m[2]);
            $mode = 'str' . $idx;
        } elseif (preg_match('/^"(.*)"$/', $line, $m)) {
            $chunk = $unescape($m[1]);
            if ('id' === $mode) {
                $id .= $chunk;
            } elseif ('plural' === $mode) {
                $plural .= $chunk;
            } elseif ('ctx' === $mode) {
                $ctx .= $chunk;
            } elseif (null !== $mode && 0 === strpos((string) $mode, 'str')) {
                $i = (int) substr((string) $mode, 3);
                $strs[$i] = ($strs[$i] ?? '') . $chunk;
            }
        }
    }
    $flush();

    return [$entries, $header];
}

function pf_mo_compile(array $entries, string $header): string
{
    // The empty msgid carries the catalog header and must be present.
    $entries[''] = $header;
    ksort($entries, SORT_STRING);

    $count = count($entries);
    $originals = array_keys($entries);
    $translations = array_values($entries);

    $orig_table = '';
    $trans_table = '';
    // 7 * 4 bytes of header, then two tables of 2 * 4 bytes per entry.
    $offset = 28 + ($count * 16);

    $orig_data = '';
    foreach ($originals as $o) {
        $orig_table .= pack('VV', strlen($o), $offset + strlen($orig_data));
        $orig_data .= $o . "\0";
    }
    $trans_data = '';
    $trans_base = $offset + strlen($orig_data);
    foreach ($translations as $t) {
        $trans_table .= pack('VV', strlen($t), $trans_base + strlen($trans_data));
        $trans_data .= $t . "\0";
    }

    return pack(
        'VVVVVVV',
        0x950412de,          // magic, little-endian
        0,                   // revision
        $count,              // string count
        28,                  // originals table offset
        28 + ($count * 8),   // translations table offset
        0,                   // hash table size (none)
        0                    // hash table offset
    ) . $orig_table . $trans_table . $orig_data . $trans_data;
}

$po_files = glob($dir . '/*.po') ?: [];
if (empty($po_files)) {
    fwrite(STDERR, "No PO files in {$dir}\n");
    exit(2);
}

foreach ($po_files as $po) {
    [$entries, $header] = pf_mo_parse_po($po);
    $mo = substr($po, 0, -3) . '.mo';
    file_put_contents($mo, pf_mo_compile($entries, $header));
    printf("  %-28s -> %-28s %5d translated, %6d bytes\n", basename($po), basename($mo), count($entries), filesize($mo));
}

exit(0);
