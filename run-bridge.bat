@echo off
cd /d %~dp0
start "" "%~dp0php\php-win.exe" "%~dp0bridge.php"
exit
