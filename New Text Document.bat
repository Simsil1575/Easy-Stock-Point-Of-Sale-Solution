@echo off
setlocal enabledelayedexpansion

:: Existing database file
set DB=pos.db

:: Check if database exists
if not exist %DB% (
    echo Database %DB% not found!
    pause
    exit /b
)

:: Create image folder if not exists
if not exist newfolder (
    mkdir newfolder
)

echo.
set /p PRODUCT_NAME=Enter product name: 
set /p IMAGE_PATH=Enter full image path: 

:: Check if image exists
if not exist "%IMAGE_PATH%" (
    echo Image file not found!
    pause
    exit /b
)

:: Get filename
for %%F in ("%IMAGE_PATH%") do set FILENAME=%%~nxF

:: Create unique filename
set NEWNAME=%RANDOM%_%FILENAME%

:: Copy image into newfolder
copy "%IMAGE_PATH%" "newfolder\%NEWNAME%" >nul

:: Ensure table exists (will not delete anything)
sqlite3 %DB% "CREATE TABLE IF NOT EXISTS products (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    product_name TEXT NOT NULL,
    image_path TEXT NOT NULL
);"

:: Insert new product
sqlite3 %DB% "INSERT INTO products (product_name, image_path) VALUES ('%PRODUCT_NAME%', 'newfolder/%NEWNAME%');"

echo.
echo Product saved successfully!
echo.
pause