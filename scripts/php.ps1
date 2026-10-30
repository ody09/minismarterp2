$ErrorActionPreference = 'Stop'
$phpBinary = (Get-Command php -ErrorAction Stop).Source
$loadedModules = & $phpBinary -m
$extensionLines = @()
foreach ($extensionName in @('pdo_mysql', 'pdo_sqlite')) {
    if ($loadedModules -notcontains $extensionName) {
        $extensionLines += "extension=$extensionName"
    }
}
$scanDirectory = Join-Path $PSScriptRoot '..\.cache\php'
New-Item -ItemType Directory -Path $scanDirectory -Force | Out-Null
[System.IO.File]::WriteAllText((Join-Path $scanDirectory 'extensions.ini'), ($extensionLines -join "`n"))
$originalScanDirectory = $env:PHP_INI_SCAN_DIR
try {
    $env:PHP_INI_SCAN_DIR = "$originalScanDirectory$([System.IO.Path]::PathSeparator)$scanDirectory"
    & $phpBinary @args
    $phpExitCode = $LASTEXITCODE
} finally {
    $env:PHP_INI_SCAN_DIR = $originalScanDirectory
}
exit $phpExitCode
