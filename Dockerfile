FROM php:8.2-apache

# 1. Suppress the FQDN warning globally
RUN echo "ServerName oxfordsuitesmakati.io" >> /etc/apache2/apache2.conf

# 2. Install required MySQL extensions for your database connection
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 3. Enable apache rewrite rules for framework routing paths
RUN a2enmod rewrite

# 4. Explicitly tell Apache to allow .htaccess overrides on your root directory
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# 5. Copy all your system code assets into the container workspace
COPY . /var/www/html/

# 6. Fix permissions so Apache can read your files cleanly
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
