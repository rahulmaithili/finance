@echo off
title IEMS ERP Local Server Launcher
echo ===================================================
echo   IEMS ERP - Local Services Launcher (PHP / MySQL)
echo ===================================================
echo.

:: 1. Start MySQL Daemon with specific directory configuration
echo [1/3] Starting MariaDB/MySQL Database Server...
start "IEMS MySQL Database" /min "C:\xamppp\mysql\bin\mysqld.exe" --basedir=C:\xamppp\mysql --datadir=C:\xamppp\mysql\data --console
timeout /t 3 /nobreak >nul

:: 2. Open login page in the default web browser
echo [2/3] Opening Web Browser to http://localhost:8000/login.php...
start http://localhost:8000/login.php
timeout /t 1 /nobreak >nul

:: 3. Start PHP Built-in Web Server
echo [3/3] Starting PHP Built-in Server on http://localhost:8000/...
echo.
echo Press [Ctrl + C] in this window to stop the PHP server.
echo.
"C:\xamppp\php\php.exe" -S localhost:8000

echo.
echo PHP Server stopped. Closing database instance...
taskkill /IM mysqld.exe /F >nul 2>&1
echo Local services closed cleanly.
timeout /t 2 /nobreak >nul
