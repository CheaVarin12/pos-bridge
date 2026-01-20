@echo off
TITLE POS Print Bridge (Portable)
cd /d "%~dp0"

echo ---------------------------------------
echo  POS PRINTER BRIDGE
echo ---------------------------------------
echo.

:: 1. CHECK FOR PORTABLE PHP
:: We look for php.exe inside the "php" folder right next to this script
IF EXIST "php\php.exe" (
    echo [INFO] Portable PHP found! Using it...
    set PHP_CMD="php\php.exe"
) ELSE (
    echo [WARNING] "php" folder not found!
    echo [INFO] Trying system PHP...
    set PHP_CMD=php
)

:: 2. RUN THE BRIDGE
:: This runs the command we found above
%PHP_CMD% bridge.php

:: 3. ERROR CATCH
echo.
echo [ERROR] The bridge script stopped.
pause