#!/usr/bin/env php
<?php
/**
 * PageForge POT generator.
 *
 * Extracts gettext calls from the plugin's PHP using PHP's own tokenizer rather
 * than regular expressions, so escaped quotes, concatenation and nested
 * parentheses cannot corrupt a string. Existing PO files are then resynced
 * against the new POT: previously translated entries keep their translation,
 * entries that no longer exist in source are dropped, and new entries appear
 * untranslated.
 *
 * Handles: __(), _e(), esc_html__(), esc_html_e(), esc_attr__(), esc_attr_e(),
 * _x(), esc_html_x(), _n(), _nx(), and the translator comments that precede them.
 *
 * Usage: php tools-pot.php <plugin-root> [--domain=pageforge]
 */

declare(strict_types=1);

$root = null;
$domain = 'pageforge';
foreach (array_slice($argv, 1) as $arg) {
    if (0 === strpos($arg, '--domain=')) {
        $domain = substr($arg, 9);
    } elseif (null === $root) {
        $root = $arg;
    }
}
$root = rtrim((string) $root, "/\\");
if (!is_dir($root)) {
    fwrite(STDERR, "Not a directory: {$root}\n");
    exit(2);
}

/** Never scanned: vendor code, tests, build output, other editions. */
const SKIP_DIRS = ['.git', '.svn', '.svn-release', '.worktrees', 'worktrees', 'node_modules', 'vendor', 'dist', 'build', 'tests', 'tools', 'docs', '.superpowers'];

/** fn => [singular index, plural index|null, context index|null] */
const GETTEXT = [
    '__' => [0, null, null],
    '_e' => [0, null, null],
    'esc_html__' => [0, null, null],
    'esc_html_e' => [0, null, null],
    'esc_attr__' => [0, null, null],
    'esc_attr_e' => [0, null, null],
    '_x' => [0, null, 1],
    '_ex' => [0, null, 1],
    'esc_html_x' => [0, null, 1],
    'esc_attr_x' => [0, null, 1],
    '_n' => [0, 1, null],
    '_nx' => [0, 1, 2],
];

function pf_pot_files(string $root): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f): bool {
                return $f->isDir() ? !in_array($f->getFilename(), SKIP_DIRS, true) : 'php' === strtolower($f->getExtension());
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = $f->getPathname();
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

/**
 * @return array<string, array> keyed by "context\4singular\5plural"
 */
function pf_pot_extract(array $files, string $root, string $domain): array
{
    $entries = [];

    foreach ($files as $file) {
        $tokens = @token_get_all((string) file_get_contents($file));
        if (!is_array($tokens)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file, strlen($root) + 1));
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) || T_STRING !== $token[0]) {
                continue;
            }
            $fn = $token[1];
            if (!isset(GETTEXT[$fn])) {
                continue;
            }
            // Skip a method/property access or a declaration: only bare calls.
            $prev = pf_pot_prev_significant($tokens, $i);
            if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }
            $next = pf_pot_next_significant($tokens, $i);
            if ('(' !== $next) {
                continue;
            }

            $args = pf_pot_read_args($tokens, $i, $count);
            if (null === $args) {
                continue;
            }

            [$singular_at, $plural_at, $context_at] = GETTEXT[$fn];
            $singular = $args[$singular_at] ?? null;
            if (!is_string($singular) || '' === $singular) {
                continue; // Variable or concatenated: nothing static to extract.
            }
            $plural = (null !== $plural_at && isset($args[$plural_at]) && is_string($args[$plural_at])) ? $args[$plural_at] : null;
            $context = (null !== $context_at && isset($args[$context_at]) && is_string($args[$context_at])) ? $args[$context_at] : null;

            // Only harvest strings belonging to this text domain. The domain is
            // the argument after the last message argument.
            $domain_at = 1 + (int) max($singular_at, (int) $plural_at, (int) $context_at);
            $found_domain = $args[$domain_at] ?? null;
            if (is_string($found_domain) && $found_domain !== $domain) {
                continue;
            }

            $key = ($context ?? '') . "\4" . $singular . "\5" . ($plural ?? '');
            if (!isset($entries[$key])) {
                $entries[$key] = [
                    'context' => $context,
                    'singular' => $singular,
                    'plural' => $plural,
                    'refs' => [],
                    'comments' => [],
                ];
            }
            $entries[$key]['refs'][] = $relative . ':' . (int) $token[2];

            $comment = pf_pot_translator_comment($tokens, $i);
            if (null !== $comment && !in_array($comment, $entries[$key]['comments'], true)) {
                $entries[$key]['comments'][] = $comment;
            }
        }
    }

    ksort($entries, SORT_STRING);
    return $entries;
}

function pf_pot_prev_significant(array $tokens, int $i)
{
    for ($j = $i - 1; $j >= 0; $j--) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return $tokens[$j];
    }
    return null;
}

function pf_pot_next_significant(array $tokens, int $i)
{
    $count = count($tokens);
    for ($j = $i + 1; $j < $count; $j++) {
        if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        return is_array($tokens[$j]) ? $tokens[$j] : $tokens[$j];
    }
    return null;
}

/**
 * Reads the call's top-level arguments. A non-literal argument becomes null so
 * the caller can tell "no static string" from "empty string".
 *
 * @return array<int, string|null>|null
 */
function pf_pot_read_args(array $tokens, int $name_index, int $count): ?array
{
    // Advance to the opening parenthesis.
    $i = $name_index + 1;
    while ($i < $count && !('(' === $tokens[$i])) {
        if (is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            $i++;
            continue;
        }
        if ('(' !== $tokens[$i]) {
            return null;
        }
    }
    if ($i >= $count) {
        return null;
    }

    $depth = 0;
    $args = [];
    $current = [];
    for (; $i < $count; $i++) {
        $tok = $tokens[$i];
        if (!is_array($tok)) {
            if ('(' === $tok || '[' === $tok) {
                $depth++;
                if (1 === $depth) {
                    continue; // Opening paren of this call.
                }
            } elseif (')' === $tok || ']' === $tok) {
                $depth--;
                if (0 === $depth) {
                    $args[] = pf_pot_literal($current);
                    return $args;
                }
            } elseif (',' === $tok && 1 === $depth) {
                $args[] = pf_pot_literal($current);
                $current = [];
                continue;
            }
        }
        if ($depth >= 1) {
            $current[] = $tok;
        }
    }
    return null;
}

/**
 * Resolves a token run to a literal string, including simple concatenation of
 * adjacent literals. Anything else (a variable, a function call) yields null.
 */
function pf_pot_literal(array $run): ?string
{
    $parts = [];
    foreach ($run as $tok) {
        if (is_array($tok)) {
            if (in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (T_CONSTANT_ENCAPSED_STRING === $tok[0]) {
                $parts[] = pf_pot_unquote($tok[1]);
                continue;
            }
            return null;
        }
        if ('.' === $tok) {
            continue;
        }
        return null;
    }
    return empty($parts) ? null : implode('', $parts);
}

function pf_pot_unquote(string $raw): string
{
    $quote = $raw[0] ?? "'";
    $inner = substr($raw, 1, -1);
    if ("'" === $quote) {
        return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    }
    // Double-quoted: resolve the escapes gettext cares about.
    return str_replace(
        ['\\\\', '\\"', '\\n', '\\r', '\\t', '\\$'],
        ['\\', '"', "\n", "\r", "\t", '$'],
        $inner
    );
}

/** Finds a `translators:` comment immediately preceding the call. */
function pf_pot_translator_comment(array $tokens, int $i): ?string
{
    for ($j = $i - 1; $j >= 0 && $j >= $i - 8; $j--) {
        $tok = $tokens[$j];
        if (!is_array($tok)) {
            continue;
        }
        if (T_WHITESPACE === $tok[0]) {
            continue;
        }
        if (in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            if (false !== stripos($tok[1], 'translators:')) {
                $text = preg_replace('#^\s*(/\*+|//|\*)\s*|\s*\*/\s*$#m', '', $tok[1]);
                $text = trim(preg_replace('/\s+/', ' ', (string) $text) ?? '');
                return '' === $text ? null : $text;
            }
            continue;
        }
        break;
    }
    return null;
}

function pf_pot_escape(string $value): string
{
    return str_replace(
        ['\\', '"', "\n", "\r", "\t"],
        ['\\\\', '\\"', '\\n', '\\r', '\\t'],
        $value
    );
}

// ------------------------------------------------------------------ build ----

$plugin_name = 'PageForge';
$plugin_version = '';
$main = $root . '/pageforge.php';
if (is_file($main)) {
    $head = (string) file_get_contents($main);
    if (preg_match('/^\s*\*\s*Plugin Name:\s*(.+)$/mi', $head, $m)) {
        $plugin_name = trim($m[1]);
    }
    if (preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $head, $m)) {
        $plugin_version = trim($m[1]);
    }
}

$files = pf_pot_files($root);
$entries = pf_pot_extract($files, $root, $domain);

$now = gmdate('Y-m-d H:iO');
$out = [];
$out[] = '# Copyright (C) ' . gmdate('Y') . ' PageForge';
$out[] = '# This file is distributed under the same license as the ' . $plugin_name . ' plugin.';
$out[] = 'msgid ""';
$out[] = 'msgstr ""';
$out[] = '"Project-Id-Version: ' . $plugin_name . ' ' . $plugin_version . '\n"';
$out[] = '"Report-Msgid-Bugs-To: https://pageforge.pro/support\n"';
$out[] = '"POT-Creation-Date: ' . $now . '\n"';
$out[] = '"MIME-Version: 1.0\n"';
$out[] = '"Content-Type: text/plain; charset=UTF-8\n"';
$out[] = '"Content-Transfer-Encoding: 8bit\n"';
$out[] = '"PO-Revision-Date: ' . $now . '\n"';
$out[] = '"Language-Team: PageForge\n"';
$out[] = '"Language: en_US\n"';
$out[] = '"Plural-Forms: nplurals=2; plural=(n != 1);\n"';
$out[] = '"X-Generator: pageforge tools-pot.php\n"';
$out[] = '"X-Domain: ' . $domain . '\n"';
$out[] = '';

foreach ($entries as $entry) {
    foreach ($entry['comments'] as $comment) {
        $out[] = '#. ' . $comment;
    }
    $refs = array_values(array_unique($entry['refs']));
    sort($refs, SORT_STRING);
    // Wrap reference lines so the file stays readable.
    $line = '';
    foreach ($refs as $ref) {
        if ('' !== $line && strlen($line) + strlen($ref) + 1 > 70) {
            $out[] = '#:' . $line;
            $line = '';
        }
        $line .= ' ' . $ref;
    }
    if ('' !== $line) {
        $out[] = '#:' . $line;
    }

    if (null !== $entry['context']) {
        $out[] = 'msgctxt "' . pf_pot_escape($entry['context']) . '"';
    }
    $out[] = 'msgid "' . pf_pot_escape($entry['singular']) . '"';
    if (null !== $entry['plural']) {
        $out[] = 'msgid_plural "' . pf_pot_escape($entry['plural']) . '"';
        $out[] = 'msgstr[0] ""';
        $out[] = 'msgstr[1] ""';
    } else {
        $out[] = 'msgstr ""';
    }
    $out[] = '';
}

$pot_path = $root . '/languages/' . $domain . '.pot';
if (!is_dir(dirname($pot_path))) {
    mkdir(dirname($pot_path), 0777, true);
}
file_put_contents($pot_path, implode("\n", $out));

printf("POT written: %s\n", $pot_path);
printf("  source files scanned : %d\n", count($files));
printf("  unique strings        : %d\n", count($entries));

// -------------------------------------------------- resync the PO catalogs ----

/**
 * Reads existing translations out of a PO file so they survive regeneration.
 *
 * @return array<string, string[]> key => msgstr list
 */
function pf_po_read(string $path): array
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $out = [];
    $ctx = null;
    $id = null;
    $plural = null;
    $strs = [];
    $mode = null;

    $flush = static function () use (&$out, &$ctx, &$id, &$plural, &$strs): void {
        if (null !== $id && '' !== $id) {
            $key = ($ctx ?? '') . "\4" . $id . "\5" . ($plural ?? '');
            $has = false;
            foreach ($strs as $s) {
                if ('' !== $s) {
                    $has = true;
                    break;
                }
            }
            if ($has) {
                $out[$key] = $strs;
            }
        }
        $ctx = null;
        $id = null;
        $plural = null;
        $strs = [];
    };

    $unescape = static function (string $v): string {
        return str_replace(['\\n', '\\r', '\\t', '\\"', '\\\\'], ["\n", "\r", "\t", '"', '\\'], $v);
    };

    foreach ($lines as $line) {
        $line = rtrim($line);
        if ('' === $line || 0 === strpos($line, '#')) {
            if ('' === $line) {
                $flush();
                $mode = null;
            }
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
                $idx = (int) substr((string) $mode, 3);
                $strs[$idx] = ($strs[$idx] ?? '') . $chunk;
            }
        }
    }
    $flush();
    return $out;
}

$po_files = glob($root . '/languages/' . $domain . '-*.po') ?: [];
foreach ($po_files as $po_path) {
    $locale = (string) preg_replace('/^' . preg_quote($domain, '/') . '-|\.po$/', '', basename($po_path));
    $existing = pf_po_read($po_path);

    $lines = [];
    $lines[] = '# Translation of ' . $plugin_name . ' for ' . $locale . '.';
    $lines[] = 'msgid ""';
    $lines[] = 'msgstr ""';
    $lines[] = '"Project-Id-Version: ' . $plugin_name . ' ' . $plugin_version . '\n"';
    $lines[] = '"POT-Creation-Date: ' . $now . '\n"';
    $lines[] = '"PO-Revision-Date: ' . $now . '\n"';
    $lines[] = '"MIME-Version: 1.0\n"';
    $lines[] = '"Content-Type: text/plain; charset=UTF-8\n"';
    $lines[] = '"Content-Transfer-Encoding: 8bit\n"';
    $lines[] = '"Language: ' . $locale . '\n"';
    $lines[] = '"Plural-Forms: nplurals=2; plural=(n != 1);\n"';
    $lines[] = '"X-Domain: ' . $domain . '\n"';
    $lines[] = '';

    $kept = 0;
    foreach ($entries as $key => $entry) {
        foreach ($entry['comments'] as $comment) {
            $lines[] = '#. ' . $comment;
        }
        if (null !== $entry['context']) {
            $lines[] = 'msgctxt "' . pf_pot_escape($entry['context']) . '"';
        }
        $lines[] = 'msgid "' . pf_pot_escape($entry['singular']) . '"';
        $have = $existing[$key] ?? [];
        if (null !== $entry['plural']) {
            $lines[] = 'msgid_plural "' . pf_pot_escape($entry['plural']) . '"';
            $lines[] = 'msgstr[0] "' . pf_pot_escape((string) ($have[0] ?? '')) . '"';
            $lines[] = 'msgstr[1] "' . pf_pot_escape((string) ($have[1] ?? '')) . '"';
        } else {
            $lines[] = 'msgstr "' . pf_pot_escape((string) ($have[0] ?? '')) . '"';
        }
        if (!empty($have[0])) {
            $kept++;
        }
        $lines[] = '';
    }

    file_put_contents($po_path, implode("\n", $lines));
    printf("  PO resynced: %-28s translations kept: %d / %d\n", basename($po_path), $kept, count($entries));
}

exit(0);
