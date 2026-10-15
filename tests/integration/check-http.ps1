# Run against the locally served disposable WordPress site after activation/CLI tests.
$routeOutput = & php -d extension=pdo_sqlite -d extension=sqlite3 "$PSScriptRoot/http-routes.php"
$routes = ($routeOutput | Select-Object -Last 1) | ConvertFrom-Json
if ($LASTEXITCODE -ne 0) { throw 'Could not resolve generated test routes.' }
$failed = $false
foreach ($kind in @('page', 'cpt')) {
    $response = Invoke-WebRequest -Uri $routes.$kind
    $canonical = ([regex]::Match($response.Content, '<link rel="canonical" href="([^"]+)"')).Groups[1].Value
    $pass = $response.StatusCode -eq 200 -and $canonical -eq $routes.$kind
    [pscustomobject]@{Test=$kind;Pass=$pass;Url=$routes.$kind;Status=$response.StatusCode;Canonical=$canonical} | ConvertTo-Json -Compress
    if (-not $pass) { $failed = $true }
}
if ($failed) { exit 1 }

