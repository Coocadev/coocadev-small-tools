param(
    [Parameter(Mandatory=$true)][string]$Site,
    [string]$PhpBinary='php',
    [string]$Tag='current'
)
$ErrorActionPreference='Stop'
$productRoot=Split-Path -Parent $PSScriptRoot
if ($Tag -notmatch '^[a-z0-9-]+$') { throw 'Invalid evidence tag' }
$cases=@(
    @{Name='domain'; Marker='PASS: 36 domain assertions'; NeedsSite=$false},
    @{Name='integration'; Marker='PASS: 32 integration assertions'; NeedsSite=$true},
    @{Name='boundaries'; Marker='PASS total 19'; NeedsSite=$true},
    @{Name='classic-checkout'; Marker='PASS real classic guest checkout freezes before offline COD, then processing'; NeedsSite=$true},
    @{Name='store-checkout'; Marker='PASS real Blocks checkout saved snapshot before offline payment and reached processing'; NeedsSite=$true},
    @{Name='lifecycle'; Marker='PASS reinstall is inactive and preserves historical ledger'; NeedsSite=$true}
)
$results=@()
foreach ($case in $cases) {
    $file=Join-Path $PSScriptRoot ($case.Name+'.php')
    $log=Join-Path $productRoot ('evidence/'+$Tag+'-'+$case.Name+'.txt')
    $argsForTest=@($file)
    if ($case.NeedsSite) { $argsForTest+=@($Site) }
    $output=& $PhpBinary @argsForTest 2>&1
    $testExit=$LASTEXITCODE
    $output | Set-Content -LiteralPath $log -Encoding utf8
    $passed=($testExit -eq 0 -and (($output -join "`n").Contains($case.Marker)))
    $results+=@{Test=$case.Name; Passed=$passed; ExitCode=$testExit; Evidence=$log}
    if (!$passed) { $output | Write-Output; throw ('Test failed: '+$case.Name) }
    Write-Output ('PASS '+$Tag+' '+$case.Name)
}
$results | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $productRoot ('evidence/'+$Tag+'-run.json')) -Encoding utf8
