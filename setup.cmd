@echo off
setlocal
cd /d "%~dp0"
where php >nul 2>&1
if errorlevel 1 (
    echo Install PHP 8.4+ and add it to PATH first.
    exit /b 1
)
where composer >nul 2>&1
if errorlevel 1 (
    echo Install Composer and add it to PATH first.
    exit /b 1
)
php scripts/setup.php prepare
if errorlevel 1 exit /b 1
call composer install --prefer-dist --no-interaction
if errorlevel 1 exit /b 1
php scripts/setup.php install
if errorlevel 1 exit /b 1
exit /b 0
