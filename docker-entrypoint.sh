#!/usr/bin/env bash
set -e

# O Render fornece a porta em $PORT (por omissão 10000). Apache passa a escutar lá.
PORT="${PORT:-10000}"
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Garantir pasta de dados gravável (para SQLite)
mkdir -p /var/www/html/data
chown -R www-data:www-data /var/www/html/data || true

exec apache2-foreground
