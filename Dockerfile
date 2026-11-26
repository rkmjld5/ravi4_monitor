FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && docker-php-ext-install mysqli \
    && rm -rf /var/lib/apt/lists/*

# Create or locate Render Secret Files group (GID 1000).
# Add Apache/PHP user www-data to that group.
RUN if ! getent group 1000 >/dev/null; then \
        groupadd --gid 1000 render-secrets; \
    fi \
    && SECRET_GROUP=$(getent group 1000 | cut -d: -f1) \
    && usermod -aG "$SECRET_GROUP" www-data

# Render web service port.
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:10000>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html

COPY . /var/www/html/

# Never copy local secret files into the container image.
RUN rm -f /var/www/html/.env \
    && chown -R www-data:www-data /var/www/html

EXPOSE 10000
