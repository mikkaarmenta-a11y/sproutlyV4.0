FROM php:8.2-apache

# 1. Install PostgreSQL extensions for Supabase
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*

# 2. Enable Apache mod_rewrite
RUN a2enmod rewrite

# 3. Copy all project files into /var/www/html
COPY . /var/www/html/

# 4. Configure Apache to use router.php (or serve api/ and assets/ cleanly)
RUN sed -i 's|/var/www/html|/var/www/html/api|g' /etc/apache2/sites-available/000-default.conf \
    && echo '<Directory /var/www/html/api>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
Alias /assets /var/www/html/assets\n\
<Directory /var/www/html/assets>\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/sites-available/000-default.conf

# 5. Set proper permissions
RUN chown -R www-data:www-data /var/www/html