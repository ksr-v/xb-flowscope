param([string]$XboardPath = $env:XBOARD_PATH)
$ErrorActionPreference = 'Stop'
if ($XboardPath) { $env:XBOARD_PATH = $XboardPath }
$taskPhpDir = Split-Path -Parent (Get-Command php).Source
$taskPhpArgs = @()
if (Test-Path -LiteralPath (Join-Path $taskPhpDir 'ext/php_pdo_sqlite.dll')) {
    $taskPhpArgs += @('-d', "extension_dir=$taskPhpDir/ext", '-d', 'extension=pdo_sqlite', '-d', 'extension=sqlite3', '-d', 'extension=mbstring')
}
Get-ChildItem -LiteralPath (Join-Path $PSScriptRoot '../FlowScope') -Filter '*.php' -Recurse | ForEach-Object {
    & php @taskPhpArgs -l $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($_.FullName)" }
}
foreach ($taskTest in @('backend.php', 'integration.php', 'install.php', 'upgrade-install.php', 'ipv4.php')) {
    & php @taskPhpArgs (Join-Path $PSScriptRoot "../tests/$taskTest")
    if ($LASTEXITCODE -ne 0) { throw "Test failed: $taskTest" }
}
& php @taskPhpArgs (Join-Path $PSScriptRoot '../tests/upgrade-install.php') '2.1.1'
if ($LASTEXITCODE -ne 0) { throw '2.1.1 upgrade test failed' }
& php @taskPhpArgs (Join-Path $PSScriptRoot '../tests/upgrade-install.php') '2.3.0'
if ($LASTEXITCODE -ne 0) { throw '2.3.0 upgrade test failed' }
& node --check (Join-Path $PSScriptRoot '../FlowScope/resources/assets/flowscope.js')
if ($LASTEXITCODE -ne 0) { throw 'JavaScript syntax failed' }
& python (Join-Path $PSScriptRoot '../tests/collector_test.py')
if ($LASTEXITCODE -ne 0) { throw 'Collector tests failed' }
& python (Join-Path $PSScriptRoot '../tests/protocol-e2e.py')
if ($LASTEXITCODE -ne 0) { throw 'Collector/PHP protocol tests failed' }
& python (Join-Path $PSScriptRoot '../tests/bootstrap_test.py')
if ($LASTEXITCODE -ne 0) { throw 'One-click bootstrap tests failed' }
