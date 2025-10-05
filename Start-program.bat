cd /d %~dp0
php artisan optimize:clear
start "" php artisan queue:work database --queue=videos --sleep=1 --tries=1 --timeout=0
start "" php artisan serve --port=8001