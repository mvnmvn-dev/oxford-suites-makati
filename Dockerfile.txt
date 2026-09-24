FROM php:8.2-apache

# Install required MySQL extensions for your database connection
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable apache rewrite rules if your framework uses clean routing URLs
RUN a2enmod rewrite

# Copy all your project code into the container web directory
COPY . /var/www/html/

EXPOSE 80
