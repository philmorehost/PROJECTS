@echo off
cd /d "%~dp0"
start "" /min cmd /c "cd /d ""%~dp0"" && node server.js"
timeout /t 2 /nobreak >nul
start "" http://127.0.0.1:4173/
