FROM php:8.2-apache

# 1. Install required MySQL extensions for your database connection
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 2. Enable apache rewrite rules for framework routing paths
RUN a2enmod rewrite

# 3. Explicitly tell Apache to allow .htaccess overrides on your root directory
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# 4. Copy all your system code assets into the container workspace
COPY . /var/www/html/

# 5. Fix permissions so Apache can read your files cleanly
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
