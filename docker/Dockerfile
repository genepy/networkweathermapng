FROM php:8.4-fpm-alpine

LABEL maintainer="Zabbix Weathermap Team"
LABEL description="Zabbix Weathermap - Network visualization tool"

# Install system dependencies
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    git \
    zip \
    unzip \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    sqlite-dev \
    ttf-dejavu \
    fontconfig \
    rrdtool \
    rsvg-convert \
    net-snmp-dev \
    net-snmp-libs \
    fping \
    openssh-client 

# Configure and install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        gd \
        pdo \
        pdo_mysql \
        pdo_sqlite \
        zip \
        intl \
        mbstring \
        opcache \
        snmp

RUN cat /etc/resolv.conf \
    && php -r '$h="api.github.com"; $ip=gethostbyname($h); echo "$h => $ip\n"; exit($ip === $h ? 1 : 0);' \
    && curl -sS -o /dev/null -w 'GitHub HTTP %{http_code}\n' --max-time 15 https://api.github.com/

# Install Composer
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

# Create app directory structure
RUN mkdir -p /app/public \
    /app/app \
    /app/lib \
    /app/data/configs \
    /app/data/output \
    /app/data/cache \
    /app/logs \
    && chown -R www-data:www-data /app

# Copy nginx configuration
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf

# Copy PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY docker/php/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

COPY docker/nginx-start.sh /usr/local/bin/nginx-start

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Defaults so a plain "docker run" works without docker-compose
ENV APP_ROOT=/app \
    DATA_ROOT=/app/data \
    DB_PATH=/app/data/weathermap.db

# Set working directory
WORKDIR /app

# Copy application source
COPY src/ /app/

# Icons and backgrounds are kept in the data volume (see docker/init-data.sh)
COPY docker/init-data.sh /usr/local/bin/weathermap-init
RUN for dir in objects backgrounds; do \
        mv /app/public/$dir /app/public/$dir.dist && ln -s /app/data/$dir /app/public/$dir; \
    done

# Copy composer files and install dependencies
COPY docker/composer.docker.json /app/composer.json
COPY composer.lock* /app/
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Set permissions
RUN chown -R www-data:www-data /app \
    && chmod -R 755 /app/public \
    && chmod -R 777 /app/data

# Expose port
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/health || exit 1

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
