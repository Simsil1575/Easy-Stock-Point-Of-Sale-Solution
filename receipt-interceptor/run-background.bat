@echo off
cd /d "%~dp0"
echo Starting receipt interceptor on port 3847...
echo RECEIPT_PHP_URL=%RECEIPT_PHP_URL%
echo Press Ctrl+C to stop.
node server.js
pause
