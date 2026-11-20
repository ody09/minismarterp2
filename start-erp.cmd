@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\start-erp.ps1" %*
set EXIT_CODE=%ERRORLEVEL%
if not %EXIT_CODE%==0 (
    echo.
    echo [ERROR] Startup failed with exit code %EXIT_CODE%.
    if "%~1"=="" pause
    exit /b %EXIT_CODE%
)
exit /b 0
