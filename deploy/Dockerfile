# Local smoke-test image: PHP 8.1 + Apache (HostGator-like). Not used in production.
FROM php:8.1-apache
RUN docker-php-ext-install pdo_mysql \
 && a2enmod rewrite headers \
 && printf '<Directory /var/www/html>\n  AllowOverride All\n  Require all granted\n</Directory>\n' > /etc/apache2/conf-available/kt.conf \
 && a2enconf kt
