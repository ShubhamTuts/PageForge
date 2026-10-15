#!/usr/bin/env php
<?php
/**
 * PageForge static release gate.
 *
 * Runs the checks that do not need a WordPress runtime: PHP lint, encoding,
 * secret and debug scanning, version consistency, and a registration inventory.
 *
 * Usage: php tools/release-check.php [plugin-root]
 *
 * Exits non-zero when any blocking check fails.
 */

declare(strict_types=1);

$root = null;
$package_mode = false;
foreach (array_slice($argv, 1) as $arg) {
    if ('--package' === $arg) {
        $package_mode = true;
    } elseif (null === $root) {
        $root = $arg;
    }
}
$root = rtrim($root ?? dirname(__DIR__), "/\\");
if (!is_dir($root)) {
    fwrite(STDERR, "Not a directory: {$root}\n");
    exit(2);
}

/** Directories that never ship and are never scanned. */
const EXCLUDED_DIRS = [
    '.git', '.svn', '.svn-release', '.worktrees', 'worktrees', '.superpowers',
    'node_modules', 'tests', 'tools', 'docs', 'dist',
];

$failures = [];
$warnings = [];

/**
 * @return SplFileInfo[]
 */
function pageforge_release_files(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $file): bool {
                if (!$file->isDir()) {
                    return true;
                }
                return !in_array($file->getFilename(), EXCLUDED_DIRS, true);
            }
        )
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file;
        }
    }
    usort($files, static function (SplFileInfo $a, SplFileInfo $b): int {
        return strcmp($a->getPathname(), $b->getPathname());
    });
    return $files;
}

$files = pageforge_release_files($root);
$php_files = array_values(array_filter($files, static function (SplFileInfo $f): bool {
    return 'php' === strtolower($f->getExtension());
}));

// ---------------------------------------------------------------- PHP lint ---
foreach ($php_files as $file) {
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
    if (0 !== $status) {
        $failures[] = 'lint: ' . $file->getPathname() . ' :: ' . implode(' ', $output);
    }
}

// ------------------------------------------------------- encoding integrity ---
// Double-encoded UTF-8 shows up as these byte runs. A real en dash is E2 80 93;
// a mojibaked one is C3 A2 C2 80 C2 93.
$mojibake_markers = [
    "\xC3\xA2\xC2\x80",     // â€ sequences
    "\xC3\x83\xC2",         // Ã followed by a C2 continuation
    "\xC3\xAF\xC2\xBB\xC2\xBF", // mojibaked BOM
];
foreach ($files as $file) {
    $extension = strtolower($file->getExtension());
    if (!in_array($extension, ['php', 'js', 'css', 'txt', 'md', 'po', 'pot', 'json'], true)) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    if ('' === $contents) {
        continue;
    }
    $valid_utf8 = function_exists('mb_check_encoding') ? mb_check_encoding($contents, 'UTF-8') : (1 === preg_match('//u', $contents));
    if (!$valid_utf8) {
        $failures[] = 'encoding: not valid UTF-8: ' . $file->getPathname();
        continue;
    }
    foreach ($mojibake_markers as $marker) {
        if (false !== strpos($contents, $marker)) {
            $failures[] = 'encoding: double-encoded UTF-8 in ' . $file->getPathname();
            break;
        }
    }
    if (0 === strpos($contents, "\xEF\xBB\xBF")) {
        $failures[] = 'encoding: UTF-8 BOM in ' . $file->getPathname();
    }
}

// --------------------------------------------------------------- secrets ------
// Looks for assigned literal credentials, not the option names that hold them.
$secret_patterns = [
    '/\bsk-[A-Za-z0-9]{20,}/' => 'OpenAI-style API key',
    '/\bsk-ant-[A-Za-z0-9\-_]{20,}/' => 'Anthropic API key',
    '/\bAIza[0-9A-Za-z\-_]{30,}/' => 'Google API key',
    '/\bghp_[A-Za-z0-9]{30,}/' => 'GitHub token',
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/' => 'private key',
    '/\bDB_PASSWORD\b\s*,\s*[\'"][^\'"]{3,}/' => 'database password',
];
foreach ($files as $file) {
    if (in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'gif', 'mo', 'zip'], true)) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    foreach ($secret_patterns as $pattern => $label) {
        if (preg_match($pattern, $contents)) {
            $failures[] = 'secret: possible ' . $label . ' in ' . $file->getPathname();
        }
    }
}

// ------------------------------------------------------------ debug output ----
$debug_patterns = [
    '/\bvar_dump\s*\(/' => 'var_dump',
    '/\bprint_r\s*\([^)]*\)\s*;/' => 'print_r',
    '/\berror_log\s*\(/' => 'error_log',
    '/\bconsole\.log\s*\(/' => 'console.log',
    '/\bdebugger\b/' => 'debugger',
];
foreach ($files as $file) {
    if (!in_array(strtolower($file->getExtension()), ['php', 'js'], true)) {
        continue;
    }
    // Bundled minified vendor code is not ours to police.
    if (preg_match('#[\\\\/](vendor|freemius)[\\\\/]#i', $file->getPathname())
        || preg_match('#\.min\.js$#i', $file->getPathname())) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    foreach ($debug_patterns as $pattern => $label) {
        if (preg_match($pattern, $contents)) {
            $warnings[] = 'debug: ' . $label . ' in ' . $file->getPathname();
        }
    }
}

// --------------------------------------------------------- file policy --------
// Blocking: these must never appear inside a distributed package. In a git
// worktree ".git" is a file (a gitdir pointer), so a directory-name-only
// exclusion misses it.
// Only enforced with --package, because a source checkout legitimately
// contains .git/.gitignore; a built package never may.
if ($package_mode) {
    $forbidden_names = ['.git', '.gitignore', '.gitattributes', '.env', 'AGENTS.md'];
    $forbidden_fragments = ['/.git/', '/tests/', '/tools/', '/.worktrees/', '/.superpowers/', '/dist/'];
    foreach ($files as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
        if (in_array($file->getFilename(), $forbidden_names, true)) {
            $failures[] = 'file-policy: ' . $relative . ' must not ship in a release package';
            continue;
        }
        foreach ($forbidden_fragments as $fragment) {
            if (false !== strpos($relative, $fragment)) {
                $failures[] = 'file-policy: ' . $relative . ' must not ship in a release package';
                break;
            }
        }
    }
}

// ----------------------------------------------------- version consistency ----
$main_file = $root . '/pageforge.php';
$readme = $root . '/readme.txt';
$versions = [];
if (is_file($main_file)) {
    $contents = (string) file_get_contents($main_file);
    if (preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $contents, $m)) {
        $versions['plugin header'] = $m[1];
    }
    if (preg_match('/define\(\s*[\'"]PAGEFORGE_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/', $contents, $m)) {
        $versions['PAGEFORGE_VERSION'] = $m[1];
    }
} else {
    $failures[] = 'structure: pageforge.php is missing';
}
if (is_file($readme)) {
    $contents = (string) file_get_contents($readme);
    if (preg_match('/^\s*Stable tag:\s*(\S+)/mi', $contents, $m)) {
        $versions['readme stable tag'] = $m[1];
    }
}
if (count(array_unique($versions)) > 1) {
    $pairs = [];
    foreach ($versions as $label => $value) {
        $pairs[] = $label . '=' . $value;
    }
    $failures[] = 'version: mismatch (' . implode(', ', $pairs) . ')';
}

// ------------------------------------------------- registration inventory -----
$inventory = [
    'actions' => [], 'filters' => [], 'ajax' => [], 'rest' => [],
    'shortcodes' => [], 'cron' => [], 'post_types' => [], 'options' => [],
];
$patterns = [
    'ajax' => '/wp_ajax(?:_nopriv)?_([a-z0-9_]+)/',
    'shortcodes' => '/add_shortcode\(\s*[\'"]([^\'"]+)[\'"]/',
    'post_types' => '/register_post_type\(\s*[\'"]([^\'"]+)[\'"]/',
    // Matches a literal namespace or a class constant/variable, so a route
    // registered via self::ROUTE_NAMESPACE still shows up in the inventory.
    'rest' => '/register_rest_route\(\s*(?:[\'"]([^\'"]+)[\'"]|([A-Za-z_:$\\\\]+[A-Za-z0-9_:$]*))/',
    'cron' => '/wp_schedule_event|wp_schedule_single_event|wp_next_scheduled\(\s*[\'"]([a-z0-9_]+)[\'"]/',
];
foreach ($php_files as $file) {
    if (preg_match('#[\\\\/](vendor|freemius)[\\\\/]#i', $file->getPathname())) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    foreach ($patterns as $bucket => $pattern) {
        if (preg_match_all($pattern, $contents, $matches)) {
            // Some patterns have alternate capture groups; take whichever matched.
            $groups = array_slice($matches, 1);
            $count = count($matches[0]);
            for ($i = 0; $i < $count; $i++) {
                $name = '';
                foreach ($groups as $group) {
                    if (isset($group[$i]) && '' !== $group[$i]) {
                        $name = $group[$i];
                        break;
                    }
                }
                if ('' !== $name) {
                    $inventory[$bucket][$name] = true;
                }
            }
        }
    }
    if (preg_match_all('/add_action\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches)) {
        foreach ($matches[1] as $name) {
            $inventory['actions'][$name] = true;
        }
    }
    if (preg_match_all('/add_filter\(\s*[\'"]([^\'"]+)[\'"]/', $contents, $matches)) {
        foreach ($matches[1] as $name) {
            $inventory['filters'][$name] = true;
        }
    }
    if (preg_match_all('/(?:get|update|add|delete)_option\(\s*[\'"]([a-z0-9_]+)[\'"]/', $contents, $matches)) {
        foreach ($matches[1] as $name) {
            $inventory['options'][$name] = true;
        }
    }
}

// --------------------------------------- callbacks that do not exist ----------
// Catches the class of bug where a hook names a method that was never written.
$hooked_methods = [];
foreach ($php_files as $file) {
    if (preg_match('#[\\\\/](vendor|freemius)[\\\\/]#i', $file->getPathname())) {
        continue;
    }
    $contents = (string) file_get_contents($file->getPathname());
    if (preg_match_all('/add_(?:action|filter)\(\s*[\'"][^\'"]+[\'"]\s*,\s*\[\s*\$this\s*,\s*[\'"]([a-z0-9_]+)[\'"]/i', $contents, $matches)) {
        foreach ($matches[1] as $method) {
            $hooked_methods[$file->getPathname()][$method] = true;
        }
    }
}
foreach ($hooked_methods as $path => $methods) {
    $contents = (string) file_get_contents($path);
    foreach (array_keys($methods) as $method) {
        if (!preg_match('/function\s+' . preg_quote($method, '/') . '\s*\(/i', $contents)) {
            $failures[] = 'hook: ' . basename($path) . ' hooks $this->' . $method . '() which is not defined';
        }
    }
}

// ------------------------------------------------- referenced asset presence ---
// The Connections screen renders these by exact filename. A missing file used
// to be swallowed by an inline onerror handler, so a packaging mistake shipped
// silently and the connector cards simply had no icons. The gate now fails
// instead, and every asset referenced from PHP under assets/ must exist.
$required_assets = [
    'assets/mcp/icon-chatgpt.png',
    'assets/mcp/icon-claude.png',
    'assets/mcp/mcp.svg',
];
foreach ($required_assets as $relative) {
    $path = $root . '/' . $relative;
    if (!is_file($path)) {
        $failures[] = 'asset missing: ' . $relative;
        continue;
    }
    if (filesize($path) < 64) {
        $failures[] = 'asset looks truncated: ' . $relative . ' (' . filesize($path) . ' bytes)';
    }
}

$referenced_assets = [];
foreach ($php_files as $file) {
    if (!preg_match_all('#PAGEFORGE_URL\s*\.\s*[\'"](assets/[^\'"]+)[\'"]#', (string) file_get_contents($file->getPathname()), $matches)) {
        continue;
    }
    foreach ($matches[1] as $relative) {
        $referenced_assets[$relative] = true;
    }
}
foreach (array_keys($referenced_assets) as $relative) {
    if (!is_file($root . '/' . $relative)) {
        $failures[] = 'asset referenced from PHP but not packaged: ' . $relative;
    }
}

// Silently hiding a broken image hides a packaging failure, so the pattern is
// rejected outright rather than relied upon as a fallback.
foreach ($php_files as $file) {
    $contents = (string) file_get_contents($file->getPathname());
    if (preg_match('#<img[^>]*\bonerror\s*=#i', $contents)) {
        $failures[] = 'silent image onerror fallback in ' . $file->getFilename() . ' (hides packaging failures)';
    }
}

// ------------------------------------------------------------------ report ----
echo "PageForge release check: {$root}\n";
printf("  assets:      %d referenced, %d required\n", count($referenced_assets), count($required_assets));
printf("  files scanned: %d (%d PHP)\n", count($files), count($php_files));
foreach ($inventory as $bucket => $names) {
    printf("  %-11s %d %s\n", $bucket . ':', count($names), count($names) ? '(' . implode(', ', array_slice(array_keys($names), 0, 8)) . (count($names) > 8 ? ', ...' : '') . ')' : '');
}
if (!empty($versions)) {
    echo '  version:    ' . implode(', ', array_map(
        static function ($label, $value) { return $label . '=' . $value; },
        array_keys($versions),
        $versions
    )) . "\n";
}

foreach ($warnings as $warning) {
    echo "WARN  {$warning}\n";
}
foreach ($failures as $failure) {
    echo "FAIL  {$failure}\n";
}

printf("\n%s (%d failures, %d warnings)\n", empty($failures) ? 'PASS' : 'FAIL', count($failures), count($warnings));
exit(empty($failures) ? 0 : 1);
