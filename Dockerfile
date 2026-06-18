# Imagem PHP + Apache para o Render (o Render não tem PHP nativo → usamos Docker)
FROM php:8.2-apache

# Extensões PDO: sqlite, mysql e pgsql (Supabase/Postgres)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libsqlite3-dev libpq-dev \
 && docker-php-ext-install pdo_mysql pdo_sqlite pdo_pgsql \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Honrar os .htaccess (protegem data/ e includes/ com "Require all denied")
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copiar a aplicação
COPY . /var/www/html/

# Pasta de dados gravável (SQLite)
RUN mkdir -p /var/www/html/data && chown -R www-data:www-data /var/www/html/data

# Arranque: o Render injeta a porta em $PORT
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
CMD ["docker-entrypoint.sh"]
