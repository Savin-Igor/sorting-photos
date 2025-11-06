FROM php:8.4-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    zip \
    unzip \
    sqlite \
    sqlite-dev \
    bash \
    shadow \
    linux-headers \
    && rm -rf /var/cache/apk/*

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    zip \
    pdo \
    pdo_sqlite \
    pcntl \
    exif \
    sockets

# Install Redis extension
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

# Install AMQP extension for RabbitMQ (optional)
# Note: This may fail if dependencies are not available, but that's OK
# We install runtime library to avoid loading errors
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        rabbitmq-c-dev \
        $PHPIZE_DEPS; \
    (pecl install amqp && docker-php-ext-enable amqp && apk add --no-cache rabbitmq-c) || \
    (echo "AMQP extension installation failed, disabling..." && \
     rm -f /usr/local/etc/php/conf.d/docker-php-ext-amqp.ini 2>/dev/null || true); \
    apk del .build-deps || true

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json composer.lock ./

# Install dependencies (including dev dependencies for testing)
RUN composer install --optimize-autoloader --no-interaction --prefer-dist

# Copy application files
COPY . .

# Create necessary directories
RUN mkdir -p var/log var/cache var/database var/coverage \
    && chown -R www-data:www-data var \
    && chmod -R 777 var/log \
    && chmod -R 755 var

# Create data directories
RUN mkdir -p /var/data/source /var/data/destination \
    && chown -R www-data:www-data /var/data \
    && chmod -R 755 /var/data

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Switch to www-data user
USER www-data

# Default command - keep container running for exec commands
# Use: docker compose exec app php index.php to run the application
CMD ["tail", "-f", "/dev/null"]

