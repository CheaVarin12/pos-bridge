@echo off
TITLE POS Print Bridge

:: Navigate to the folder where this file is
cd /d "%~dp0"

echo ---------------------------------------
echo  POS PRINTER BRIDGE
echo ---------------------------------------
echo.

:: This uses the PHP already installed on your laptop
php bridge.php

:: If it crashes, keep the window open so you can see why
pause