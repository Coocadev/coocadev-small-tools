$ErrorActionPreference = 'Stop'
# All changes are confined to the two reserved local QA databases; never accept a remote URL.
$root = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '..\..\..')).Path
$wp = Join-Path $root 'qa\downloads\wp-cli.phar'
$products = @('stockdigest','invoiceroute','downloadrescue')
$currentPhp = (Get-Command php.exe).Source
$minimumPhp = Join-Path $root 'qa\runtime\php81\php.exe'
foreach($product in $products){
 $dir=Join-Path $root "projects\coocadev-$product"
 & $minimumPhp "$root\qa\dependencies\vendor\squizlabs\php_codesniffer\bin\phpcbf" "--standard=$dir\phpcs.xml.dist" "$dir\plugin" | Out-File -LiteralPath "$dir\reports\0.2.0\phpcbf.txt" -Encoding utf8
 & $minimumPhp "$root\qa\dependencies\vendor\squizlabs\php_codesniffer\bin\phpcs" "--standard=$dir\phpcs.xml.dist" --report=json "$dir\plugin" | Out-File -LiteralPath "$dir\reports\0.2.0\phpcs.json" -Encoding utf8
 $coding=Get-Content -Raw -LiteralPath "$dir\reports\0.2.0\phpcs.json"|ConvertFrom-Json
 if($coding.totals.errors -or $coding.totals.warnings){throw "$product WPCS failed"}
 & $minimumPhp "$root\qa\dependencies\vendor\squizlabs\php_codesniffer\bin\phpcs" --standard=PHPCompatibilityWP --runtime-set testVersion 8.1-8.5 --report=json "$dir\plugin" | Out-File -LiteralPath "$dir\reports\0.2.0\phpcompatibility.json" -Encoding utf8
 $compat=Get-Content -Raw -LiteralPath "$dir\reports\0.2.0\phpcompatibility.json"|ConvertFrom-Json
 if($compat.totals.errors -or $compat.totals.warnings){throw "$product PHP compatibility failed"}
 $lint=foreach($file in Get-ChildItem -LiteralPath "$dir\plugin" -Filter *.php -Recurse){ & $minimumPhp -l $file.FullName; if($LASTEXITCODE){throw "$product PHP syntax failed"} }
 $lint|Out-File -LiteralPath "$dir\reports\0.2.0\php81-lint.txt" -Encoding utf8
}
foreach($stack in @('sites','min-sites')){
 $site=Join-Path $root "qa\$stack\dispatchgo"
 $php=if($stack -eq 'sites'){$currentPhp}else{$minimumPhp}
 $log=Join-Path $site 'wp-content\debug.log'
 $offset=if(Test-Path -LiteralPath $log){(Get-Item -LiteralPath $log).Length}else{0}
 foreach($product in $products){
  $target=Join-Path $site "wp-content\plugins\coocadev-$product"
  if((Get-Item -LiteralPath $target).LinkType){throw 'Refusing to overwrite any source junction'}
  Copy-Item -Path "$root\projects\coocadev-$product\plugin\*" -Destination $target -Recurse -Force
 }
 # Read and restore options with PHP8.1: the current WP-CLI phar has PHP8.5 vendor bootstrap deprecations.
 $oldHpos=(& $minimumPhp $wp "--path=$site" option get woocommerce_custom_orders_table_enabled).Trim()
 $oldSync=(& $minimumPhp $wp "--path=$site" option get woocommerce_custom_orders_table_data_sync_enabled).Trim()
 try{
  & $minimumPhp $wp "--path=$site" option update woocommerce_custom_orders_table_data_sync_enabled no --quiet
  foreach($storage in @('hpos','legacy')){
   $enabled=if($storage -eq 'hpos'){'yes'}else{'no'}
   & $minimumPhp $wp "--path=$site" option update woocommerce_custom_orders_table_enabled $enabled --quiet
   if($LASTEXITCODE){throw 'Could not select local storage mode'}
   foreach($product in $products){
    $dir=Join-Path $root "projects\coocadev-$product"
    $names=@('integration')
    if($product -eq 'stockdigest'){$names=@('features')+$names}
    if($product -ne 'invoiceroute'){$names+=@('http-boundaries')}
    if($storage -eq 'hpos'){$names+=@('lifecycle')}
    foreach($name in $names){
     $testPhp=if($name -eq 'lifecycle'){$minimumPhp}else{$php}
     & $testPhp $wp "--path=$site" eval-file "$dir\tests\$name.php" 2>&1 | Out-File -LiteralPath "$dir\reports\0.2.0\$name-$stack-$storage.raw.log" -Encoding utf8
     if($LASTEXITCODE){throw "$product $name failed on $stack/$storage"}
     $raw=Get-Content -LiteralPath "$dir\reports\0.2.0\$name-$stack-$storage.raw.log"
     $vendor=$raw|Where-Object{$_ -match '^Deprecated: .*phar://.*/wp-cli\.phar/vendor/'}
     if($vendor){$vendor|Out-File -LiteralPath "$dir\reports\0.2.0\wp-cli-php85-vendor-warnings.txt" -Append -Encoding utf8}
     $clean=($raw|Where-Object{$_ -notmatch '^Deprecated: .*phar://.*/wp-cli\.phar/vendor/'}) -join "`n"
     $null=$clean|ConvertFrom-Json
     $clean|Out-File -LiteralPath "$dir\reports\0.2.0\$name-$stack-$storage.json" -Encoding utf8
    }
    if($product -eq 'invoiceroute'){
     foreach($mode in @('classic','blocks')){
      & $php $wp "--path=$site" eval-file "$dir\tests\checkout.php" $mode 2>&1 | Out-File -LiteralPath "$dir\reports\0.2.0\checkout-$mode-$stack-$storage.raw.log" -Encoding utf8
      if($LASTEXITCODE){throw "InvoiceRoute native $mode checkout failed on $stack/$storage"}
      $raw=Get-Content -LiteralPath "$dir\reports\0.2.0\checkout-$mode-$stack-$storage.raw.log"
      $clean=($raw|Where-Object{$_ -notmatch '^Deprecated: .*phar://.*/wp-cli\.phar/vendor/'}) -join "`n"
      $null=$clean|ConvertFrom-Json
      $clean|Out-File -LiteralPath "$dir\reports\0.2.0\checkout-$mode-$stack-$storage.json" -Encoding utf8
     }
    }
    Write-Output "$product $stack/$storage PASS"
   }
  }
  foreach($product in $products){
   $dir=Join-Path $root "projects\coocadev-$product"
   & $minimumPhp $wp "--path=$site" --skip-plugins=woocommerce eval-file "$dir\tests\dependency.php" 2>&1 | Out-File -LiteralPath "$dir\reports\0.2.0\dependency-$stack.txt" -Encoding utf8
   if($LASTEXITCODE){throw "$product dependency guard failed"}
   & $minimumPhp $wp "--path=$site" plugin check "coocadev-$product" --format=json 2>&1 | Out-File -LiteralPath "$dir\reports\0.2.0\plugin-check-$stack.json" -Encoding utf8
   if($LASTEXITCODE){throw "$product Plugin Check command failed"}
   $pcp=Get-Content -Raw -LiteralPath "$dir\reports\0.2.0\plugin-check-$stack.json"
   if($pcp -match '"type"\s*:\s*"(?:ERROR|WARNING)"'){throw "$product Plugin Check findings remain"}
  }
 }finally{
  & $minimumPhp $wp "--path=$site" option update woocommerce_custom_orders_table_enabled $oldHpos --quiet
  if($LASTEXITCODE){throw 'Could not restore local HPOS option'}
  & $minimumPhp $wp "--path=$site" option update woocommerce_custom_orders_table_data_sync_enabled $oldSync --quiet
  if($LASTEXITCODE){throw 'Could not restore local synchronization option'}
  $delta=''
  if(Test-Path -LiteralPath $log){$stream=[IO.File]::Open($log,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::ReadWrite);try{$null=$stream.Seek($offset,[IO.SeekOrigin]::Begin);$reader=[IO.StreamReader]::new($stream);$delta=$reader.ReadToEnd()}finally{$stream.Dispose()}}
  foreach($product in $products){$delta|Out-File -LiteralPath "$root\projects\coocadev-$product\reports\0.2.0\wp-debug-delta-$stack.log" -Encoding utf8}
 }
 if($delta.Trim()){throw "New WP_DEBUG output on $stack; inspect the saved delta"}
}
Write-Output 'PASS all three WooCommerce microtools: current/minimum, HPOS without sync, legacy, native checkout, lifecycle, static analysis, dependency guard and fresh WP_DEBUG delta.'
