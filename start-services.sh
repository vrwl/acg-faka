#!/bin/bash
# acg-faka 服务启动脚本
set -e

echo "启动 MariaDB..."
mkdir -p /run/mysqld
chown mysql:mysql /run/mysqld
mariadbd --user=mysql --datadir=/var/lib/mysql --bind-address=127.0.0.1 --skip-name-resolve > /tmp/mariadb.log 2>&1 &
sleep 3

echo "启动 Redis..."
redis-server --daemonize yes --bind 127.0.0.1 --port 6379

echo "启动 PHP-FPM..."
php-fpm -D
sleep 1

echo "启动 Nginx..."
nginx

echo "所有服务已启动！"
echo "  - 首页: http://localhost:8080/"
echo "  - 后台: http://localhost:8080/admin"
