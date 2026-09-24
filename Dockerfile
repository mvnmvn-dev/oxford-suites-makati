FROM php:8.2-apache

# 1. Install required MySQL extensions for your database connection
RUN docker-php-ext-install mysqli pdo pdo_mysql

# 2. Enable apache rewrite rules if your application uses clean routing paths
RUN a2enmod rewrite

# 3. Copy all your system files straight into the default Apache root folder
COPY . /var/www/html/

EXPOSE 80
