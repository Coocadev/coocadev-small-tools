param([string[]]$Slugs = @())
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$catalog = Get-Content -LiteralPath (Join-Path $root 'catalog.json') -Raw | ConvertFrom-Json
$version = $catalog.version
if ($version -notmatch '^\d+\.\d+\.\d+$') { throw 'Invalid catalog release version.' }
$knownSlugs = @($catalog.products.slug)
if (-not $Slugs.Count) { $Slugs = $knownSlugs }
$output = Join-Path $root "artifacts\$version"
New-Item -ItemType Directory -Path $output -Force | Out-Null
Add-Type -AssemblyName System.IO.Compression
foreach ($slug in $Slugs) {
    if ($slug -notin $knownSlugs -or $slug -notmatch '^coocadev-[a-z0-9-]+$') { throw 'Unknown product.' }
    $source = (Resolve-Path -LiteralPath (Join-Path $root "projects\$slug\plugin")).Path
    $main = [IO.File]::ReadAllText((Join-Path $source "$slug.php"))
    $readme = [IO.File]::ReadAllText((Join-Path $source 'readme.txt'))
    $escapedVersion = [regex]::Escape($version)
    if ($main -notmatch "(?m)^\s*\*?\s*Version:\s*$escapedVersion\s*$" -or $readme -notmatch "(?m)^Stable tag:\s*$escapedVersion\s*$") { throw "Version mismatch: $slug" }
    if ($main -notmatch 'License:\s*GPL-2\.0-or-later' -or -not (Test-Path -LiteralPath (Join-Path $source 'LICENSE'))) { throw "GPL license missing: $slug" }
    $files = @(Get-ChildItem -LiteralPath $source -Recurse -File | Sort-Object FullName)
    $manifest = @()
    foreach ($file in $files) {
        $relative = $file.FullName.Substring($source.Length + 1).Replace('\','/')
        if ($relative -notmatch ('^(' + [regex]::Escape($slug) + '\.php|uninstall\.php|readme\.txt|LICENSE|(src|assets|blocks)/[a-zA-Z0-9_./-]+\.(php|js|css|json))$')) { throw "Unexpected runtime file: $relative" }
        if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Symlinks are not packaged: $relative" }
        $manifest += [PSCustomObject]@{ Entry="$slug/$relative"; Bytes=$file.Length; SHA256=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant() }
    }
    $zipPath = Join-Path $output "$slug-$version.zip"
    $stream = [IO.File]::Open($zipPath,[IO.FileMode]::Create,[IO.FileAccess]::ReadWrite)
    $archive = [IO.Compression.ZipArchive]::new($stream,[IO.Compression.ZipArchiveMode]::Create,$false)
    try {
        for ($i=0; $i -lt $files.Length; $i++) {
            $entry = $archive.CreateEntry($manifest[$i].Entry,[IO.Compression.CompressionLevel]::Optimal)
            $entry.LastWriteTime = [DateTimeOffset]::new(2026,10,15,0,0,0,[TimeSpan]::Zero)
            $destination = $entry.Open()
            $input = [IO.File]::OpenRead($files[$i].FullName)
            try { $input.CopyTo($destination) } finally { $input.Dispose(); $destination.Dispose() }
        }
    } finally { $archive.Dispose(); $stream.Dispose() }
    $manifest | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $output "$slug-files.json") -Encoding UTF8
    [PSCustomObject]@{ Plugin=$slug; Version=$version; Files=$files.Length; ZIP=$zipPath; Bytes=(Get-Item -LiteralPath $zipPath).Length; SHA256=(Get-FileHash -LiteralPath $zipPath -Algorithm SHA256).Hash.ToLowerInvariant() } |
      ConvertTo-Json | Set-Content -LiteralPath (Join-Path $output "$slug-package.json") -Encoding UTF8
    Write-Output "Built $slug $version ($($files.Length) runtime files)."
}
