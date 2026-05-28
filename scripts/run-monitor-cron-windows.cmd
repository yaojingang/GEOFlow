@echo off
set DAY=%1
set LIMIT=%2
if "%LIMIT%"=="" set LIMIT=5

wsl -d Ubuntu -- bash -lc "cd /home/qrrwi/dev/geoflow && scripts/run-monitor-cron.sh %DAY% %LIMIT%"
