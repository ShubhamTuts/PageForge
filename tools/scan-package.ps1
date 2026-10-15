# Independent scan of the built archives: confirms no dev/VCS/secret files were
# packaged, and that the assets the UI references are actually inside the ZIP.
Add-Type -AssemblyName System.IO.Compression.FileSystem

$report = @()
$zips = @(
    (Join-Path $PSScriptRoot '.worktrees\free-1.2.0\dist\pageforge-1.2.0.zip'),
    (Join-Path $PSScriptRoot '.worktrees\pro-1.1.0\dist\pageforge-premium-1.1.0.zip')
)

# Anything matching these must never ship.
$forbidden = @(
    '(^|/)\.git($|/)', '(^|/)\.git$', '(^|/)\.svn($|/)', '(^|/)\.worktrees/', '(^|/)worktrees/',
    '(^|/)tests/', '(^|/)tools/', '(^|/)docs/', '(^|/)dist/', '(^|/)node_modules/',
    '(^|/)\.superpowers/', '(^|/)\.github/', '(^|/)\.vscode/', '(^|/)\.idea/',
    '\.env$', '\.env\.', '\.log$', '\.patch$', '\.diff$', '\.bak$', '\.orig$', '\.map$',
    '\.pem$', '\.key$', '\.p12$', '\.sql$', '\.zip$',
    '(^|/)AGENTS\.md$', '(^|/)README\.md$', '(^|/)composer\.(json|lock)$',
    '(^|/)package(-lock)?\.json$', '(^|/)phpunit\.xml', '(^|/)phpcs\.xml',
    # Anchored to a whole path segment: an unanchored "MISSION" also matches
    # "permission", which appears legitimately in Freemius vendor filenames.
    '(^|/)[^/]*MISSION[^/]*\.md$', '(^|/)instructions\.md$',
    '\.DS_Store$', 'Thumbs\.db$'
)

# Files the Connections screen references by exact name.
$required = @('assets/mcp/icon-chatgpt.png', 'assets/mcp/icon-claude.png', 'assets/mcp/mcp.svg')

# Secret-shaped content in text files.
$secretPatterns = @(
    'sk-[A-Za-z0-9]{20,}', 'sk-ant-[A-Za-z0-9\-]{20,}', 'AIza[A-Za-z0-9_\-]{30,}',
    'ghp_[A-Za-z0-9]{30,}', 'xoxb-[A-Za-z0-9\-]{20,}',
    'pfmcp_[A-Za-z0-9_]{20,}', 'pfat_[A-Za-z0-9]{40,}', 'pfrt_[A-Za-z0-9]{40,}',
    'pfs_[A-Za-z0-9]{40,}',
    'BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY'
)

foreach ($zipPath in $zips) {
    $report += "=================== " + (Split-Path $zipPath -Leaf) + " ==================="
    if (-not (Test-Path $zipPath)) { $report += "  MISSING"; continue }

    $hash = (Get-FileHash $zipPath -Algorithm SHA256).Hash.ToLower()
    $report += "  sha256 : $hash"
    $report += "  bytes  : " + (Get-Item $zipPath).Length

    $zip = [System.IO.Compression.ZipFile]::OpenRead($zipPath)
    try {
        $names = $zip.Entries | ForEach-Object { $_.FullName }
        # Strip the single top-level plugin folder for pattern matching.
        $rel = $names | ForEach-Object { ($_ -replace '^[^/]+/', '') }

        $report += "  entries: " + $names.Count

        $bad = @()
        foreach ($pattern in $forbidden) {
            $hits = $rel | Where-Object { $_ -match $pattern }
            if ($hits) { $bad += ($pattern + ' -> ' + ($hits -join ', ')) }
        }
        $report += "  dev/VCS/junk files: " + $(if ($bad.Count -eq 0) { 'NONE' } else { 'FOUND' })
        $bad | ForEach-Object { $report += "     $_" }

        $missing = $required | Where-Object { $rel -notcontains $_ }
        $report += "  required assets   : " + $(if ($missing.Count -eq 0) { 'ALL PRESENT' } else { 'MISSING: ' + ($missing -join ', ') })

        # Content scan of text entries.
        $leaks = @()
        foreach ($entry in $zip.Entries) {
            if ($entry.Length -eq 0 -or $entry.Length -gt 2000000) { continue }
            if ($entry.FullName -notmatch '\.(php|js|css|txt|pot|po|md|json|html)$') { continue }
            $sr = New-Object System.IO.StreamReader($entry.Open())
            $text = $sr.ReadToEnd()
            $sr.Close()
            foreach ($sp in $secretPatterns) {
                if ($text -match $sp) { $leaks += ($entry.FullName + ' :: ' + $sp) }
            }
        }
        $report += "  secret-shaped content: " + $(if ($leaks.Count -eq 0) { 'NONE' } else { 'FOUND' })
        $leaks | Select-Object -Unique | ForEach-Object { $report += "     $_" }

        $report += "  languages packaged: " + (@($rel | Where-Object { $_ -match '^languages/' }).Count) + " files"
        $report += "  top-level folder  : " + (($names | ForEach-Object { ($_ -split '/')[0] } | Select-Object -Unique) -join ', ')
    } finally {
        $zip.Dispose()
    }
}

$report | Out-File -FilePath (Join-Path $PSScriptRoot 'zip-scan.txt') -Encoding ascii
