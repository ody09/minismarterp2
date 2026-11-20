@echo off
setlocal
cd /d "%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\stop-erp.ps1" %*
if "%~1"=="" timeout /t 2 >nul
exit /b 0
