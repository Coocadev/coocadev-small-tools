param([string]$InstalledPath = '', [string[]]$Slugs = @())
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$catalog = Get-Content -LiteralPath (Join-Path $root 'catalog.json') -Raw | ConvertFrom-Json
$version = $catalog.version
$artifacts = Join-Path $root "artifacts\$version"
if (-not $InstalledPath) { $InstalledPath = Join-Path $PSScriptRoot 'sites\combined' }
$site = (Resolve-Path -LiteralPath $InstalledPath).Path
if (-not $Slugs.Count) { $Slugs = @($catalog.products.slug) }
$php = Join-Path $PSScriptRoot 'runtime\php81\php.exe'
if (-not (Test-Path -LiteralPath $php)) { $php = (Get-Command php).Source }
Add-Type -AssemblyName System.IO.Compression
$results = @()
foreach ($slug in $Slugs) {
 if ($slug -notin @($catalog.products.slug)) { throw 'Unknown product.' }
 $manifest = Get-Content -LiteralPath "$artifacts\$slug-files.json" -Raw | ConvertFrom-Json
 $package = Get-Content -LiteralPath "$artifacts\$slug-package.json" -Raw | ConvertFrom-Json
 if ((Get-FileHash -LiteralPath $package.ZIP -Algorithm SHA256).Hash.ToLowerInvariant() -ne $package.SHA256) { throw "ZIP hash mismatch: $slug" }
 $installedRoot = Join-Path $site "wp-content\plugins\$slug"
 if ((Get-Item -LiteralPath $installedRoot).LinkType) { throw "Verify a real installation, not a source junction: $slug" }
 $stream = [IO.File]::OpenRead($package.ZIP)
 $archive = [IO.Compression.ZipArchive]::new($stream,[IO.Compression.ZipArchiveMode]::Read,$false)
 $phpCount = 0
 $jsCount = 0
 try {
  if ($archive.Entries.Count -ne @($manifest).Count) { throw "ZIP entry count mismatch: $slug" }
  if (@(Get-ChildItem -LiteralPath $installedRoot -Recurse -File).Count -ne @($manifest).Count) { throw "Installed file count mismatch: $slug" }
  foreach ($file in $manifest) {
   $entry = $archive.GetEntry($file.Entry)
   if ($null -eq $entry -or $entry.Length -ne $file.Bytes -or $file.Entry -notlike "$slug/*" -or $file.Entry.Contains('..')) { throw "ZIP entry mismatch: $($file.Entry)" }
   $input = $entry.Open()
   $hash = [Security.Cryptography.SHA256]::Create()
   try { $digest = ([BitConverter]::ToString($hash.ComputeHash($input))).Replace('-','').ToLowerInvariant() } finally { $input.Dispose(); $hash.Dispose() }
   if ($digest -ne $file.SHA256) { throw "ZIP content hash mismatch: $($file.Entry)" }
   $relative = $file.Entry.Substring($slug.Length+1).Replace('/','\')
   $source = Join-Path $root "projects\$slug\plugin\$relative"
   $installed = Join-Path $installedRoot $relative
   foreach ($path in @($source,$installed)) {
    if ((Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant() -ne $digest) { throw "Source/installed hash mismatch: $($file.Entry)" }
   }
   if ($relative.EndsWith('.php')) { $lint = & $php -l $installed 2>&1; if ($LASTEXITCODE -ne 0) { throw "PHP syntax failed: $lint" }; $phpCount++ }
   if ($relative.EndsWith('.js')) { $lint = & node --check $installed 2>&1; if ($LASTEXITCODE -ne 0) { throw "JS syntax failed: $lint" }; $jsCount++ }
  }
 } finally { $archive.Dispose(); $stream.Dispose() }
 $checkPath = Join-Path $root "reports\$version\package-checks\$slug.json"
 $metaPath = Join-Path $root "reports\$version\package-checks\$slug-meta.json"
 $check = [IO.File]::ReadAllText($checkPath)
 $meta = Get-Content -LiteralPath $metaPath -Raw | ConvertFrom-Json
 $errors = ([regex]::Matches($check,'"type"\s*:\s*"ERROR"')).Count
 $warnings = ([regex]::Matches($check,'"type"\s*:\s*"WARNING"')).Count
 if ($meta.exitCode -ne 0 -or $errors -gt 0 -or $check -match 'Fatal error|Error establishing|PHP Warning|Parse error') { throw "Plugin Check failed: $slug" }
 $results += [PSCustomObject]@{Plugin=$slug;Version=$version;Files=@($manifest).Count;Bytes=$package.Bytes;SHA256=$package.SHA256;InstalledIdentical=$true;PHPLint=$phpCount;JSLint=$jsCount;PluginCheckErrors=$errors;PluginCheckWarnings=$warnings}
}
$results | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath "$artifacts\verified-packages.json" -Encoding UTF8
$results | Format-Table Plugin,Files,Bytes,PHPLint,JSLint,PluginCheckErrors,PluginCheckWarnings