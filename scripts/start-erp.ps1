param(
    [switch]$NoBrowser
)

$ErrorActionPreference = 'Stop'
$baseDir = Split-Path -Parent $PSScriptRoot
Set-Location $baseDir

$cacheDir = Join-Path $baseDir '.cache'
$mysqlBinDir = Join-Path $cacheDir 'mysql-8.4.13-winx64\bin'
$mysqldExe = Join-Path $mysqlBinDir 'mysqld.exe'
$dataDir = Join-Path $cacheDir 'mysql-daily-data'
$iniFile = Join-Path $cacheDir 'my-daily.ini'
$phpScript = Join-Path $baseDir 'scripts\php.ps1'
$prepareDbScript = Join-Path $baseDir 'scripts\prepare_daily_db.php'

$dbHost = '127.0.0.1'
$dbPort = 3306
$appUrl = 'http://127.0.0.1:8000'
$loginUrl = "$appUrl/login"

function Test-PortListening([string]$serverHost, [int]$port, [int]$timeoutMs = 1000) {
    try {
        $client = New-Object System.Net.Sockets.TcpClient
        $iar = $client.BeginConnect($serverHost, $port, $null, $null)
        $wait = $iar.AsyncWaitHandle.WaitOne($timeoutMs, $false)
        if (-not $wait) {
            $client.Close()
            return $false
        }
        $client.EndConnect($iar)
        $client.Close()
        return $true
    } catch {
        return $false
    }
}

function Start-HiddenProcess([string]$commandLine, [string]$workingDirectory) {
    $res = Invoke-CimMethod -ClassName Win32_Process -MethodName Create -Arguments @{
        CommandLine = $commandLine
        CurrentDirectory = $workingDirectory
    }
    if ($res.ReturnValue -ne 0) {
        throw "Failed to start hidden background process (ReturnValue: $($res.ReturnValue)). Command: $commandLine"
    }
    return $res.ProcessId
}

try {
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host " Starting Smart Mini ERP" -ForegroundColor Cyan
    Write-Host "========================================" -ForegroundColor Cyan

    # 1. Check or start MySQL on port 3306
    if (Test-PortListening $dbHost $dbPort) {
        Write-Host "[1/4] [OK] MySQL is already running on port $dbPort. Reusing existing service." -ForegroundColor Green
    } else {
        Write-Host "[1/4] Starting local MySQL on port $dbPort..." -ForegroundColor Yellow
        if (-not (Test-Path $mysqldExe)) {
            throw "MySQL binary not found at '$mysqldExe'."
        }

        # Ensure my-daily.ini exists
        if (-not (Test-Path $iniFile)) {
            $iniContent = @"
[mysqld]
bind-address=127.0.0.1
port=$dbPort
datadir=$($dataDir.Replace('\', '/'))
mysqlx=0
"@
            [System.IO.File]::WriteAllText($iniFile, $iniContent)
        }

        # Initialize data directory if not yet initialized
        $systemDbDir = Join-Path $dataDir 'mysql'
        if (-not (Test-Path $systemDbDir)) {
            Write-Host "      Initializing MySQL data directory for daily operation..." -ForegroundColor Yellow
            New-Item -ItemType Directory -Path $dataDir -Force | Out-Null
            $initProcess = Start-Process -FilePath $mysqldExe -ArgumentList "--defaults-file=$iniFile", "--initialize-insecure", "--console" -NoNewWindow -PassThru -Wait
            if ($initProcess.ExitCode -ne 0) {
                throw "MySQL initialization failed with exit code $($initProcess.ExitCode)."
            }
        }

        # Start mysqld in hidden background process
        $mysqlCmd = "`"$mysqldExe`" --defaults-file=`"$iniFile`" --console"
        $pidStarted = Start-HiddenProcess $mysqlCmd $baseDir
        Write-Host "      Spawned mysqld background process (PID: $pidStarted)." -ForegroundColor Gray

        # Wait for port 3306 readiness
        $mysqlReady = $false
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Seconds 1
            if (Test-PortListening $dbHost $dbPort) {
                $mysqlReady = $true
                break
            }
        }
        if (-not $mysqlReady) {
            throw "MySQL did not begin listening on port $dbPort within 30 seconds."
        }
        Write-Host "      [OK] MySQL is listening on port $dbPort." -ForegroundColor Green
    }

    # 2. Prepare daily connection and verify database
    Write-Host "[2/4] Verifying daily database connection and schema..." -ForegroundColor Yellow
    $dbPrepOutput = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $phpScript $prepareDbScript 2>&1
    $dbPrepExit = $LASTEXITCODE
    if ($dbPrepExit -ne 0) {
        throw "Database preparation failed:`n$($dbPrepOutput -join "`n")"
    }
    foreach ($line in $dbPrepOutput) {
        if ($line.Trim()) {
            Write-Host "      $line" -ForegroundColor Gray
        }
    }
    Write-Host "      [OK] Database 'smart_mini_erp' is ready." -ForegroundColor Green

    # 3. Check or start Laravel server on port 8000
    if (Test-PortListening "127.0.0.1" 8000) {
        Write-Host "[3/4] [OK] Laravel server is already running on port 8000. Reusing existing service." -ForegroundColor Green
    } else {
        Write-Host "[3/4] Starting Laravel development server on port 8000..." -ForegroundColor Yellow
        $laravelCmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -Command `"Set-Location '$baseDir'; php scripts/setup.php serve`""
        $larPid = Start-HiddenProcess $laravelCmd $baseDir
        Write-Host "      Spawned Laravel server background process (PID: $larPid)." -ForegroundColor Gray

        $larReady = $false
        for ($i = 0; $i -lt 30; $i++) {
            Start-Sleep -Seconds 1
            if (Test-PortListening "127.0.0.1" 8000) {
                $larReady = $true
                break
            }
        }
        if (-not $larReady) {
            throw "Laravel server did not begin listening on port 8000 within 30 seconds."
        }
        Write-Host "      [OK] Laravel server is listening on port 8000." -ForegroundColor Green
    }

    # 4. Wait for application readiness and open browser
    Write-Host "[4/4] Verifying application readiness on $loginUrl..." -ForegroundColor Yellow
    $appReady = $false
    $lastErr = ""
    for ($i = 0; $i -lt 20; $i++) {
        try {
            $resp = Invoke-WebRequest -Uri $loginUrl -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
            if ($resp.StatusCode -eq 200) {
                $appReady = $true
                break
            }
        } catch {
            $lastErr = $_.Exception.Message
        }
        Start-Sleep -Seconds 1
    }
    if (-not $appReady) {
        throw "Application failed to serve $loginUrl with HTTP 200 within timeout: $lastErr"
    }
    Write-Host "      [OK] Application is healthy and responding (HTTP 200)." -ForegroundColor Green

    if ($NoBrowser) {
        Write-Host "      (Browser launch skipped via -NoBrowser switch)" -ForegroundColor Gray
    } else {
        Write-Host "      Opening $loginUrl in default browser..." -ForegroundColor Cyan
        Start-Process $loginUrl
    }

    Write-Host "========================================" -ForegroundColor Green
    Write-Host " Smart Mini ERP is running successfully!" -ForegroundColor Green
    Write-Host " URL: $loginUrl" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Green
    exit 0
} catch {
    Write-Host ""
    Write-Host "[ERROR] $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "ERP startup aborted. The browser was not opened." -ForegroundColor Red
    exit 1
}
