$ErrorActionPreference = 'SilentlyContinue'
$baseDir = Split-Path -Parent $PSScriptRoot

Write-Host "========================================" -ForegroundColor Cyan
Write-Host " Stopping Smart Mini ERP Services" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan

# 1. Target only this project's MySQL daily processes
$myProcesses = Get-CimInstance Win32_Process -Filter "Name = 'mysqld.exe'" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*my-daily.ini*" -or 
    $_.CommandLine -like "*mysql-daily-data*" -or 
    ($_.CommandLine -like "*$baseDir*" -and $_.CommandLine -like "*3306*")
}

foreach ($p in $myProcesses) {
    Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    Write-Host "Stopped project MySQL process (PID: $($p.ProcessId))." -ForegroundColor Gray
}

# 2. Target only this project's Laravel server process (php.exe)
$phpProcesses = Get-CimInstance Win32_Process -Filter "Name = 'php.exe'" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*artisan*serve*" -and $_.CommandLine -like "*$baseDir*"
}

foreach ($p in $phpProcesses) {
    Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    Write-Host "Stopped project Laravel PHP process (PID: $($p.ProcessId))." -ForegroundColor Gray
}

# 3. Stop powershell launcher process spawned for setup.php serve
$psProcesses = Get-CimInstance Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue | Where-Object {
    $_.CommandLine -like "*setup.php serve*" -and $_.CommandLine -like "*$baseDir*"
}

foreach ($p in $psProcesses) {
    Stop-Process -Id $p.ProcessId -Force -ErrorAction SilentlyContinue
    Write-Host "Stopped project Laravel launcher process (PID: $($p.ProcessId))." -ForegroundColor Gray
}

# 4. Double-check port 8000 only if bound by a process belonging to this project
$conn8000 = Get-NetTCPConnection -LocalPort 8000 -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique
foreach ($pidToStop in $conn8000) {
    if ($pidToStop -and $pidToStop -gt 0) {
        $p = Get-CimInstance Win32_Process -Filter "ProcessId = $pidToStop" -ErrorAction SilentlyContinue
        if ($p -and ($p.CommandLine -like "*$baseDir*" -or $p.CommandLine -like "*artisan*")) {
            Stop-Process -Id $pidToStop -Force -ErrorAction SilentlyContinue
            Write-Host "Stopped lingering port 8000 project process (PID: $pidToStop)." -ForegroundColor Gray
        }
    }
}

# 5. Double-check port 3306 only if bound by this project's mysqld
$conn3306 = Get-NetTCPConnection -LocalPort 3306 -ErrorAction SilentlyContinue | Select-Object -ExpandProperty OwningProcess -Unique
foreach ($pidToStop in $conn3306) {
    if ($pidToStop -and $pidToStop -gt 0) {
        $p = Get-CimInstance Win32_Process -Filter "ProcessId = $pidToStop" -ErrorAction SilentlyContinue
        if ($p -and ($p.CommandLine -like "*my-daily.ini*" -or $p.CommandLine -like "*$baseDir*")) {
            Stop-Process -Id $pidToStop -Force -ErrorAction SilentlyContinue
            Write-Host "Stopped lingering port 3306 project process (PID: $pidToStop)." -ForegroundColor Gray
        }
    }
}

Write-Host "========================================" -ForegroundColor Green
Write-Host " [OK] Smart Mini ERP services stopped." -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Green
