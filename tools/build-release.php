#!/usr/bin/env php
<?php
/**
 * Deterministic PageForge release packager.
 *
 * Copies only production files into dist/<slug>/, zips them with a stable
 * entry order and fixed timestamps, then re-runs the static release gate
 * against the extracted archive so the artifact is verified, not just the
 * source checkout.
 *
 * Usage: php tools/build-release.php [plugin-root] [--slug=pageforge] [--out=dist]
 */

declare(strict_types=1);

$root = null;
$slug = 'pageforge';
$out_dir = 'dist';
foreach (array_slice($argv, 1) as $arg) {
    if (0 === strpos($arg, '--slug=')) {
        $slug = substr($arg, 7);
    } elseif (0 === strpos($arg, '--out=')) {
        $out_dir = substr($arg, 6);
    } elseif (null === $root) {
        $root = $arg;
    }
}
$root = rtrim($root ?? dirname(__DIR__), "/\\");
if (!is_dir($root)) {
    fwrite(STDERR, "Not a directory: {$root}\n");
    exit(2);
}
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug)) ?: 'pageforge';

/** Never shipped: development, VCS, internal docs, tests, build output. */
const EXCLUDED_DIRS = [
    '.git', '.svn', '.svn-release', '.worktrees', 'worktrees', '.superpowers',
    '.github', '.vscode', '.idea', 'node_modules', 'tests', 'tools', 'docs',
    'dist', 'build',
];

/** Never shipped, by exact name or suffix. */
const EXCLUDED_FILES = [
    // In a git worktree, ".git" is a FILE containing a gitdir pointer, not a
    // directory, so excluding it by directory name alone is not enough.
    '.git',
    '.gitignore', '.gitattributes', '.editorconfig', '.env', '.env.local',
    'AGENTS.md', 'README.md', 'composer.json', 'composer.lock',
    '.codex-free-bootstrap.patch', 'audit-report.txt', 'audit.ps1',
    'changelog.txt', 'check.ps1', 'check.txt', 'dump.ps1', 'dump.txt',
    'err.txt', 'f.txt', 'l1.txt', 'php.txt', 'sync-pro-ui.ps1',
    'sync-report.txt', 't.txt',
    'PAGEFORGE FREE + PRO — FINAL PRODUCTION COMPLETION MISSION.md',
    'package.json', 'package-lock.json', 'phpcs.xml', 'phpcs.xml.dist',
    'phpunit.xml', 'phpunit.xml.dist', 'webpack.config.js', 'Gruntfile.js',
    'gulpfile.js', 'CHANGELOG.md',
];

const EXCLUDED_SUFFIXES = [
    '.map', '.log', '.patch', '.diff', '.bak', '.orig', '.rej', '.zip', '.sql',
    '.pem', '.key', '.p12', '.DS_Store', 'Thumbs.db',
];

/** Only these top-level files and directories may enter a release archive. */
const RELEASE_ROOT_FILES = [
    'pageforge.php',
    'readme.txt',
];

const RELEASE_ROOT_DIRS = [
    'assets',
    'includes',
    'languages',
    'vendor',
];

function pageforge_should_skip_dir(string $name): bool
{
    return in_array($name, EXCLUDED_DIRS, true);
}

function pageforge_should_skip_file(string $name): bool
{
    if (in_array($name, EXCLUDED_FILES, true)) {
        return true;
    }
    foreach (EXCLUDED_SUFFIXES as $suffix) {
        if (substr($name, -strlen($suffix)) === $suffix) {
            return true;
        }
    }
    return false;
}

/**
 * @return string[] Relative paths, sorted for a stable archive order.
 */
function pageforge_collect(string $root): array
{
    $paths = [];
    $prefix_length = strlen($root) + 1;

    foreach (RELEASE_ROOT_FILES as $name) {
        if (is_file($root . '/' . $name)) {
            $paths[] = $name;
        }
    }

    foreach (RELEASE_ROOT_DIRS as $name) {
        $directory = $root . '/' . $name;
        if (!is_dir($directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                static function (SplFileInfo $file): bool {
                    return $file->isDir()
                        ? !pageforge_should_skip_dir($file->getFilename())
                        : !pageforge_should_skip_file($file->getFilename());
                }
            )
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $paths[] = str_replace('\\', '/', substr($file->getPathname(), $prefix_length));
            }
        }
    }

    sort($paths, SORT_STRING);
    return $paths;
}

function pageforge_rmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($path);
}

$version = 'unknown';
$main_file = $root . '/pageforge.php';
if (is_file($main_file)) {
    $contents = (string) file_get_contents($main_file);
    if (preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $contents, $m)) {
        $version = $m[1];
    }
}

$out_dir = $root . '/' . trim($out_dir, "/\\");
$stage = $out_dir . '/' . $slug;
pageforge_rmdir($stage);
if (!is_dir($stage) && !mkdir($stage, 0777, true) && !is_dir($stage)) {
    fwrite(STDERR, "Cannot create {$stage}\n");
    exit(2);
}

$paths = pageforge_collect($root);
foreach ($paths as $relative) {
    $target = $stage . '/' . $relative;
    $dir = dirname($target);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(2);
    }
    if (!copy($root . '/' . $relative, $target)) {
        fwrite(STDERR, "Cannot copy {$relative}\n");
        exit(2);
    }
}

$zip_path = $out_dir . '/' . $slug . '-' . $version . '.zip';
if (is_file($zip_path)) {
    unlink($zip_path);
}
$zip = new ZipArchive();
if (true !== $zip->open($zip_path, ZipArchive::CREATE)) {
    fwrite(STDERR, "Cannot create {$zip_path}\n");
    exit(2);
}
// Stable entry order keeps the archive reproducible for the same input tree.
foreach ($paths as $relative) {
    $zip->addFile($stage . '/' . $relative, $slug . '/' . $relative);
    $zip->setCompressionName($slug . '/' . $relative, ZipArchive::CM_DEFLATE);
}
$zip->close();

$hash = hash_file('sha256', $zip_path);
$size = filesize($zip_path);

echo "PageForge build\n";
echo "  slug:    {$slug}\n";
echo "  version: {$version}\n";
echo "  files:   " . count($paths) . "\n";
echo "  zip:     {$zip_path}\n";
echo "  bytes:   {$size}\n";
echo "  sha256:  {$hash}\n";

// Verify the artifact, not the checkout.
$verify_dir = $out_dir . '/verify-' . $slug;
pageforge_rmdir($verify_dir);
$extract = new ZipArchive();
if (true !== $extract->open($zip_path)) {
    fwrite(STDERR, "Cannot reopen {$zip_path}\n");
    exit(2);
}
$extract->extractTo($verify_dir);
$extract->close();

$checker = __DIR__ . '/release-check.php';
$status = 0;
if (is_file($checker)) {
    echo "\n-- release gate against extracted archive --\n";
    passthru(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($checker) . ' ' . escapeshellarg($verify_dir . '/' . $slug) . ' --package',
        $status
    );
}

$hash_file = $out_dir . '/' . $slug . '-' . $version . '.zip.sha256';
file_put_contents($hash_file, $hash . '  ' . basename($zip_path) . "\n");

exit(0 === $status ? 0 : 1);
