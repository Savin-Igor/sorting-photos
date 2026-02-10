FROM php:8.4-cli-alpine

# Build arguments for user ID and group ID
# These should match the host user to avoid permission issues
ARG USER_ID=1000
ARG GROUP_ID=1000

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
    supervisor \
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

# Create user with same UID/GID as host user to avoid permission issues
# This ensures files created in container have same ownership as host user
RUN if [ "$USER_ID" != "0" ] && [ "$GROUP_ID" != "0" ]; then \
    deluser www-data 2>/dev/null || true; \
    addgroup -g $GROUP_ID appuser 2>/dev/null || true; \
    adduser -u $USER_ID -G appuser -D -s /bin/bash appuser 2>/dev/null || true; \
    fi

# Create necessary directories with proper permissions
# Use 777 for var directory to allow write access from host user
RUN mkdir -p var/log var/cache var/database var/coverage var/messenger \
    && chmod -R 777 var

# Create data directories
RUN mkdir -p /var/data/source /var/data/destination \
    && chmod -R 755 /var/data

# Set permissions - use appuser if created, otherwise www-data
RUN if id appuser >/dev/null 2>&1; then \
    chown -R appuser:appuser /var/www/html /var/data; \
    else \
    chown -R www-data:www-data /var/www/html /var/data; \
    fi

# Switch to appuser if created, otherwise www-data (UID 33 = www-data)
# Use numeric UID directly - Docker will resolve it to the correct user
RUN if id appuser >/dev/null 2>&1; then \
    echo "Using appuser (UID: $USER_ID, GID: $GROUP_ID)"; \
    else \
    echo "Using www-data (UID: 33)"; \
    fi

# Set umask to allow group and others write access (for volume mounts)
# This ensures files created in container are accessible from host
ENV UMASK=0002

# Use numeric UID - Docker will use the user with this UID
USER ${USER_ID:-33}

# Create supervisor directory
RUN mkdir -p /etc/supervisor/conf.d /var/log/supervisor

# Copy supervisor config
COPY docker/supervisor/supervisord.conf /etc/supervisor/supervisord.conf

# Default command - keep container running for exec commands
# Use: docker compose exec app php index.php to run the application
CMD ["tail", "-f", "/dev/null"]

