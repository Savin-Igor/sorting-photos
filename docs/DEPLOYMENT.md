# 🚀 Развертывание и мониторинг

Руководство по развертыванию приложения Sorting Photos в различных окружениях и настройке мониторинга.

## 📋 Содержание

- [🐳 Docker развертывание](#-docker-развертывание)
- [🏠 Локальная установка](#-локальная-установка)
- [☁️ Облачное развертывание](#️-облачное-развертывание)
- [⚙️ Конфигурация production](#️-конфигурация-production)
- [📊 Мониторинг и метрики](#-мониторинг-и-метрики)
- [🔄 Логирование](#-логирование)
- [📈 Масштабирование](#-масштабирование)
- [🔒 Безопасность](#-безопасность)
- [🛠️ Резервное копирование](#️-резервное-копирование)
- [🚨 Обработка инцидентов](#-обработка-инцидентов)

## 🐳 Docker развертывание

### 🏗️ Production-ready Docker Compose

```yaml
# docker-compose.prod.yml
version: '3.8'

services:
  app:
    build:
      context: .
      dockerfile: Dockerfile.prod
      target: production
    container_name: sorting-photos-prod
    environment:
      - APP_ENV=prod
      - APP_DEBUG=0
      - SOURCE_DIRECTORY=/var/data/source
      - DESTINATION_DIRECTORY=/var/data/destination
      - MESSENGER_TRANSPORT_DSN=amqp://rabbitmq:5672/%2f/messages
      - DATABASE_URL=sqlite:///%kernel.project_dir%/var/database.sqlite
    volumes:
      - ./var:/var/www/html/var
      - source-data:/var/data/source:ro
      - destination-data:/var/data/destination
      - credentials:/var/www/html/config/credentials:ro
    depends_on:
      rabbitmq:
        condition: service_healthy
      redis:
        condition: service_healthy
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "php", "bin/console", "--version"]
      interval: 30s
      timeout: 10s
      retries: 3

  worker:
    build:
      context: .
      dockerfile: Dockerfile.prod
      target: worker
    container_name: sorting-photos-worker
    environment:
      - APP_ENV=prod
      - APP_DEBUG=0
      - SOURCE_DIRECTORY=/var/data/source
      - DESTINATION_DIRECTORY=/var/data/destination
      - MESSENGER_TRANSPORT_DSN=amqp://rabbitmq:5672/%2f/messages
    volumes:
      - ./var:/var/www/html/var
      - source-data:/var/data/source:ro
      - destination-data:/var/data/destination
      - credentials:/var/www/html/config/credentials:ro
    depends_on:
      - app
      - rabbitmq
    restart: unless-stopped
    command: ["php", "bin/console", "messenger:consume", "-vv", "--time-limit=3600"]
    deploy:
      replicas: 4
      restart_policy:
        condition: on-failure

  redis:
    image: redis:7-alpine
    container_name: sorting-photos-redis
    volumes:
      - redis-data:/data
    restart: unless-stopped
    command: redis-server --appendonly yes
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 3s
      retries: 3

  rabbitmq:
    image: rabbitmq:3-management-alpine
    container_name: sorting-photos-rabbitmq
    environment:
      - RABBITMQ_DEFAULT_USER=sorting_photos
      - RABBITMQ_DEFAULT_PASS=${RABBITMQ_PASSWORD}
    volumes:
      - rabbitmq-data:/var/lib/rabbitmq
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "rabbitmq-diagnostics", "ping"]
      interval: 30s
      timeout: 10s
      retries: 3

volumes:
  source-data:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: /host/path/to/source
  destination-data:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: /host/path/to/destination
  credentials:
    driver: local
    driver_opts:
      type: none
      o: bind
      device: /host/path/to/credentials
  redis-data:
  rabbitmq-data:

networks:
  default:
    name: sorting-photos-network
    driver: bridge
```

### 🏗️ Multi-stage Dockerfile

```dockerfile
# Dockerfile.prod
FROM php:8.4-cli-alpine AS base

# Системные зависимости
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
    rabbitmq-c-dev \
    $PHPIZE_DEPS

# PHP расширения
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    gd \
    zip \
    pdo \
    pdo_sqlite \
    pcntl \
    exif \
    sockets

# Redis расширение
RUN apk add --no-cache pcre-dev $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del pcre-dev $PHPIZE_DEPS

# AMQP расширение
RUN apk add --no-cache --virtual .build-deps \
    rabbitmq-c-dev \
    $PHPIZE_DEPS \
    && (pecl install amqp && docker-php-ext-enable amqp && apk add --no-cache rabbitmq-c) \
    || (echo "AMQP extension failed" && rm -f /usr/local/etc/php/conf.d/docker-php-ext-amqp.ini) \
    && apk del .build-deps

# Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

FROM base AS builder

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --optimize-autoloader --no-dev --prefer-dist

FROM base AS production

# Создание пользователя
ARG USER_ID=1000
ARG GROUP_ID=1000
RUN if [ "$USER_ID" != "0" ] && [ "$GROUP_ID" != "0" ]; then \
    addgroup -g $GROUP_ID appuser && \
    adduser -u $USER_ID -G appuser -D -s /bin/bash appuser; \
    fi

WORKDIR /var/www/html

# Копирование зависимостей
COPY --from=builder --chown=appuser:appuser /app/vendor ./vendor

# Копирование кода
COPY --chown=appuser:appuser . .

# Директории и права
RUN mkdir -p var/log var/cache var/database var/messenger \
    && chmod -R 755 var

# Оптимизация
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative
RUN php bin/console cache:warmup --env=prod

USER ${USER_ID:-33}

FROM production AS worker

# Supervisor для воркеров
RUN mkdir -p /etc/supervisor/conf.d /var/log/supervisor
COPY docker/supervisor/workers.conf /etc/supervisor/conf.d/

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/supervisord.conf"]
```

## 🏠 Локальная установка

### 📋 Системные требования

```bash
# PHP 8.4+
php --version | grep "PHP 8.4"

# Расширения PHP
php -m | grep -E "(pdo_sqlite|exif|zip)"

# Composer
composer --version

# SQLite3
sqlite3 --version
```

### 🚀 Установка

```bash
# 1. Клонирование
git clone <repository-url>
cd sorting-photos

# 2. Установка зависимостей
composer install --optimize-autoloader --no-dev

# 3. Настройка окружения
cp .env.example .env

# 4. Настройка путей
nano .env
```

```bash
# .env для production
APP_ENV=prod
APP_DEBUG=0

# Пути к директориям
SOURCE_DIRECTORY=/var/data/photos
DESTINATION_DIRECTORY=/var/data/sorted

# База данных
DATABASE_URL=sqlite:///%kernel.project_dir%/var/database.sqlite

# Очереди (RabbitMQ)
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@localhost:5672/%2f/messages

# Google Photos
GOOGLE_PHOTOS_CREDENTIALS_PATH=/etc/sorting-photos/credentials.json

# Мониторинг
LOG_LEVEL=warning
SENTRY_DSN=https://your-sentry-dsn@sentry.io/project
```

### 🛠️ Systemd сервисы

```bash
# /etc/systemd/system/sorting-photos.service
[Unit]
Description=Sorting Photos Application
After=network.target rabbitmq-server.service

[Service]
Type=simple
User=sorting-photos
Group=sorting-photos
WorkingDirectory=/opt/sorting-photos
ExecStart=/usr/bin/php bin/console messenger:consume -vv
Restart=always
RestartSec=10
Environment=APP_ENV=prod
Environment=APP_DEBUG=0

[Install]
WantedBy=multi-user.target
```

```bash
# /etc/systemd/system/sorting-photos-worker@.service
[Unit]
Description=Sorting Photos Worker %i
After=network.target rabbitmq-server.service

[Service]
Type=simple
User=sorting-photos
Group=sorting-photos
WorkingDirectory=/opt/sorting-photos
ExecStart=/usr/bin/php bin/console messenger:consume -vv --time-limit=3600
Restart=always
RestartSec=30
Environment=APP_ENV=prod
Environment=APP_DEBUG=0

[Install]
WantedBy=multi-user.target
```

```bash
# Управление сервисами
sudo systemctl daemon-reload
sudo systemctl enable sorting-photos-worker@{1..4}
sudo systemctl start sorting-photos-worker@{1..4}
```

## ☁️ Облачное развертывание

### 🐳 Docker Swarm

```yaml
# docker-compose.swarm.yml
version: '3.8'

services:
  app:
    image: sorting-photos:latest
    deploy:
      replicas: 2
      restart_policy:
        condition: on-failure
      resources:
        limits:
          memory: 512M
        reservations:
          memory: 256M

  worker:
    image: sorting-photos:worker
    deploy:
      replicas: 8
      restart_policy:
        condition: on-failure
      resources:
        limits:
          memory: 1G
        reservations:
          memory: 512M

  redis:
    image: redis:7-alpine
    deploy:
      replicas: 1
      restart_policy:
        condition: on-failure

  rabbitmq:
    image: rabbitmq:3-management-alpine
    deploy:
      replicas: 1
      restart_policy:
        condition: on-failure
      environment:
        - RABBITMQ_DEFAULT_USER=${RABBITMQ_USER}
        - RABBITMQ_DEFAULT_PASS=${RABBITMQ_PASS}
```

```bash
# Развертывание
docker stack deploy -c docker-compose.swarm.yml sorting-photos

# Масштабирование
docker service scale sorting-photos_worker=12
```

### ☸️ Kubernetes

```yaml
# k8s/deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: sorting-photos-app
spec:
  replicas: 2
  selector:
    matchLabels:
      app: sorting-photos
      component: app
  template:
    metadata:
      labels:
        app: sorting-photos
        component: app
    spec:
      containers:
      - name: app
        image: sorting-photos:latest
        env:
        - name: APP_ENV
          value: "prod"
        - name: MESSENGER_TRANSPORT_DSN
          value: "amqp://rabbitmq:5672/%2f/messages"
        resources:
          limits:
            memory: "512Mi"
            cpu: "500m"
          requests:
            memory: "256Mi"
            cpu: "250m"
        livenessProbe:
          exec:
            command:
            - php
            - bin/console
            - --version
          initialDelaySeconds: 30
          periodSeconds: 10
        readinessProbe:
          exec:
            command:
            - php
            - bin/console
            - cache:warmup
          initialDelaySeconds: 5
          periodSeconds: 5
```

```yaml
# k8s/worker-deployment.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: sorting-photos-worker
spec:
  replicas: 8
  selector:
    matchLabels:
      app: sorting-photos
      component: worker
  template:
    metadata:
      labels:
        app: sorting-photos
        component: worker
    spec:
      containers:
      - name: worker
        image: sorting-photos:worker
        command: ["php", "bin/console", "messenger:consume", "-vv"]
        env:
        - name: APP_ENV
          value: "prod"
        resources:
          limits:
            memory: "1Gi"
            cpu: "1000m"
          requests:
            memory: "512Mi"
            cpu: "500m"
```

### 🚀 AWS ECS/Fargate

```hcl
# Terraform для ECS
resource "aws_ecs_cluster" "sorting_photos" {
  name = "sorting-photos"
}

resource "aws_ecs_task_definition" "app" {
  family                   = "sorting-photos-app"
  requires_compatibilities = ["FARGATE"]
  network_mode            = "awsvpc"
  cpu                     = 512
  memory                  = 1024

  container_definitions = jsonencode([{
    name  = "app"
    image = "sorting-photos:latest"

    environment = [
      { name = "APP_ENV", value = "prod" },
      { name = "SOURCE_DIRECTORY", value = "/var/data/source" },
      { name = "DESTINATION_DIRECTORY", value = "/var/data/destination" }
    ]

    logConfiguration = {
      logDriver = "awslogs"
      options = {
        awslogs-group         = "/ecs/sorting-photos"
        awslogs-region        = var.aws_region
        awslogs-stream-prefix = "ecs"
      }
    }
  }])
}

resource "aws_ecs_service" "app" {
  name            = "sorting-photos-app"
  cluster         = aws_ecs_cluster.sorting_photos.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = 2

  load_balancer {
    target_group_arn = aws_lb_target_group.app.arn
    container_name   = "app"
    container_port   = 80
  }
}
```

## ⚙️ Конфигурация production

### 🔧 Environment переменные

```bash
# Приложение
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=your-secret-key

# База данных
DATABASE_URL=postgresql://user:pass@host:5432/db

# Кеширование
REDIS_URL=redis://redis:6379
CACHE_ADAPTER=redis

# Очереди
MESSENGER_TRANSPORT_DSN=amqp://user:pass@rabbitmq:5672/%2f/messages

# Файловые системы
SOURCE_DIRECTORY=/mnt/photos
DESTINATION_DIRECTORY=/mnt/sorted

# Google Photos
GOOGLE_PHOTOS_CREDENTIALS_PATH=/secrets/credentials.json
GOOGLE_PHOTOS_ALBUM_ID=album_123

# Мониторинг
SENTRY_DSN=https://dsn@sentry.io/project
LOG_LEVEL=warning

# Производительность
PHP_MEMORY_LIMIT=512M
OPCACHE_ENABLE=1
```

### 📊 Оптимизация PHP

```ini
# php.ini для production
[PHP]
memory_limit = 512M
max_execution_time = 300
max_input_time = 60

[opcache]
opcache.enable = 1
opcache.memory_consumption = 256
opcache.max_accelerated_files = 7963
opcache.revalidate_freq = 0
opcache.validate_timestamps = 0

[apcu]
apcu.enabled = 1
apcu.shm_size = 64M
```

### 🔄 Оптимизация Symfony

```yaml
# config/packages/prod/framework.yaml
framework:
  cache:
    app: cache.adapter.redis
    default_redis_provider: '%env(REDIS_URL)%'

  session:
    handler_id: Symfony\Component\HttpFoundation\Session\Storage\Handler\RedisSessionHandler
    save_path: '%env(REDIS_URL)%/sessions'

  messenger:
    transports:
      async: '%env(MESSENGER_TRANSPORT_DSN)%'
      failed: 'doctrine://default?queue_name=failed'
    retry_strategy:
      max_retries: 5
      delay: 1000
      multiplier: 2
      max_delay: 10000
```

## 📊 Мониторинг и метрики

### 📈 Prometheus метрики

```php
// src/Infrastructure/Monitoring/PrometheusMetrics.php
final readonly class PrometheusMetrics
{
    public function __construct(
        private PrometheusRegistry $registry,
        private string $namespace = 'sorting_photos',
    ) {}

    public function incrementFilesProcessed(): void
    {
        $this->registry
            ->getOrRegisterCounter(
                $this->namespace,
                'files_processed_total',
                'Total number of files processed'
            )
            ->inc();
    }

    public function recordProcessingTime(float $seconds): void
    {
        $this->registry
            ->getOrRegisterHistogram(
                $this->namespace,
                'file_processing_duration_seconds',
                'Time spent processing files',
                ['operation'],
                [0.1, 0.5, 1, 2.5, 5, 10]
            )
            ->observe($seconds, ['organize']);
    }

    public function setQueueSize(int $size): void
    {
        $this->registry
            ->getOrRegisterGauge(
                $this->namespace,
                'queue_size',
                'Current queue size'
            )
            ->set($size);
    }
}
```

### 📊 Метрики endpoints

```php
// src/Infrastructure/Http/MetricsController.php
final readonly class MetricsController
{
    public function __construct(
        private ProcessingStatistics $statistics,
        private PrometheusRenderer $renderer,
    ) {}

    #[Route('/metrics', methods: ['GET'])]
    public function metrics(): Response
    {
        $stats = $this->statistics->getCurrentStats();

        return new Response(
            $this->renderer->render($stats),
            200,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']
        );
    }

    #[Route('/health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $health = $this->checkSystemHealth();

        return new JsonResponse($health, $health['status'] === 'healthy' ? 200 : 503);
    }

    private function checkSystemHealth(): array
    {
        return [
            'status' => 'healthy',
            'timestamp' => time(),
            'checks' => [
                'database' => $this->checkDatabase(),
                'queue' => $this->checkQueue(),
                'storage' => $this->checkStorage(),
            ]
        ];
    }
}
```

### 📊 Grafana dashboards

```json
// Grafana dashboard JSON
{
  "dashboard": {
    "title": "Sorting Photos Monitoring",
    "panels": [
      {
        "title": "Files Processed Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(sorting_photos_files_processed_total[5m])",
            "legendFormat": "Files/sec"
          }
        ]
      },
      {
        "title": "Queue Size",
        "type": "singlestat",
        "targets": [
          {
            "expr": "sorting_photos_queue_size",
            "legendFormat": "Queue Size"
          }
        ]
      },
      {
        "title": "Processing Time",
        "type": "heatmap",
        "targets": [
          {
            "expr": "sorting_photos_file_processing_duration_seconds_bucket",
            "legendFormat": "Processing Time"
          }
        ]
      }
    ]
  }
}
```

### 🚨 Alerting правила

```yaml
# Prometheus alerting rules
groups:
  - name: sorting_photos
    rules:
      - alert: HighQueueSize
        expr: sorting_photos_queue_size > 1000
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "High queue size detected"
          description: "Queue size is {{ $value }} messages"

      - alert: ProcessingRateLow
        expr: rate(sorting_photos_files_processed_total[10m]) < 1
        for: 15m
        labels:
          severity: warning
        annotations:
          summary: "Low processing rate"
          description: "Processing rate dropped below 1 file/min"

      - alert: WorkerDown
        expr: up{job="sorting-photos-worker"} == 0
        for: 5m
        labels:
          severity: critical
        annotations:
          summary: "Worker is down"
          description: "Sorting Photos worker has stopped"
```

## 🔄 Логирование

### 📋 Структура логов

```json
{
  "timestamp": "2024-01-15T10:30:45.123Z",
  "level": "INFO",
  "channel": "app",
  "message": "File processed successfully",
  "context": {
    "file_path": "/var/data/source/photo.jpg",
    "destination_path": "/var/data/destination/2024/01/images/photo.jpg",
    "file_size": 2457600,
    "processing_time": 0.234,
    "hash": "a1b2c3d4e5f6...",
    "mime_type": "image/jpeg"
  },
  "extra": {
    "user": "system",
    "request_id": "req-123456",
    "hostname": "sorting-photos-prod-01"
  }
}
```

### 🔧 Конфигурация Monolog

```yaml
# config/packages/prod/monolog.yaml
monolog:
  channels: ['app', 'google_photos', 'messenger', 'security']

  handlers:
    main:
      type: fingers_crossed
      action_level: error
      handler: nested
      channels: ['!security']

    nested:
      type: stream
      path: '%kernel.logs_dir%/%kernel.environment%.log'
      level: debug
      formatter: monolog.formatter.json

    google_photos:
      type: stream
      path: '%kernel.logs_dir%/google_photos.log'
      level: info
      channels: ['google_photos']
      formatter: monolog.formatter.json

    messenger:
      type: stream
      path: '%kernel.logs_dir%/messenger.log'
      level: info
      channels: ['messenger']

    # Логи в Elasticsearch
    elasticsearch:
      type: elasticsearch
      elasticsearch:
        host: '%env(ELASTICSEARCH_HOST)%'
        port: '%env(int:ELASTICSEARCH_PORT)%'
        transport: Http
      index: 'sorting-photos-%date%'
      level: info
```

### 📊 Централизованное логирование

```yaml
# Filebeat конфигурация
filebeat.inputs:
- type: log
  paths:
    - /var/log/sorting-photos/*.log
  json.keys_under_root: true
  json.add_error_key: true

output.elasticsearch:
  hosts: ["elasticsearch:9200"]
  index: "sorting-photos-%{+yyyy.MM.dd}"

# Или Logstash
output.logstash:
  hosts: ["logstash:5044"]
```

## 📈 Масштабирование

### 🔄 Горизонтальное масштабирование

```yaml
# Kubernetes HPA
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: sorting-photos-worker-hpa
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: sorting-photos-worker
  minReplicas: 4
  maxReplicas: 20
  metrics:
  - type: External
    external:
      metric:
        name: sorting_photos_queue_size
        selector:
          matchLabels:
            app: sorting-photos
      target:
        type: AverageValue
        averageValue: "100"
```

### 📊 Автоматическое масштабирование

```python
# AWS Lambda для масштабирования
import boto3

def lambda_handler(event, context):
    ecs = boto3.client('ecs')
    cloudwatch = boto3.client('cloudwatch')

    # Получить размер очереди
    queue_size = cloudwatch.get_metric_statistics(
        Namespace='AWS/SQS',
        MetricName='ApproximateNumberOfMessagesVisible',
        Dimensions=[{'Name': 'QueueName', 'Value': 'sorting-photos-queue'}],
        StartTime=datetime.utcnow() - timedelta(minutes=5),
        EndTime=datetime.utcnow(),
        Period=300,
        Statistics=['Average']
    )

    # Рассчитать нужное количество воркеров
    desired_count = min(max(int(queue_size / 10), 2), 20)

    # Масштабировать сервис
    ecs.update_service(
        cluster='sorting-photos',
        service='sorting-photos-worker',
        desiredCount=desired_count
    )
```

### 💾 Оптимизация производительности

```yaml
# config/packages/prod/doctrine.yaml
doctrine:
  dbal:
    connections:
      default:
        # Connection pooling
        driver: pdo_pgsql
        host: '%env(DATABASE_HOST)%'
        port: '%env(DATABASE_PORT)%'
        dbname: '%env(DATABASE_NAME)%'
        user: '%env(DATABASE_USER)%'
        password: '%env(DATABASE_PASSWORD)%'
        charset: UTF8

        # Performance settings
        options:
          PDO::ATTR_PERSISTENT: true
          PDO::MYSQL_ATTR_USE_BUFFERED_QUERY: true

  orm:
    auto_generate_proxy_classes: false
    metadata_cache_driver:
      type: redis
      host: '%env(REDIS_HOST)%'
    query_cache_driver:
      type: redis
      host: '%env(REDIS_HOST)%'
    result_cache_driver:
      type: redis
      host: '%env(REDIS_HOST)%'
```

## 🔒 Безопасность

### 🔐 Секреты и credentials

```yaml
# Kubernetes secrets
apiVersion: v1
kind: Secret
metadata:
  name: sorting-photos-secrets
type: Opaque
data:
  google-credentials: <base64-encoded-json>
  database-password: <base64-encoded-password>
  rabbitmq-password: <base64-encoded-password>
  sentry-dsn: <base64-encoded-dsn>
```

### 🛡️ Безопасность контейнеров

```dockerfile
# Dockerfile.security
FROM php:8.4-cli-alpine

# Не использовать root
RUN addgroup -g 1001 appgroup && \
    adduser -u 1001 -G appgroup -D -s /bin/bash appuser

# Минимальный набор пакетов
RUN apk add --no-cache \
    libpng \
    libjpeg-turbo \
    freetype \
    libzip \
    sqlite \
    && rm -rf /var/cache/apk/*

# Установка только необходимых расширений
RUN docker-php-ext-install -j$(nproc) \
    pdo_sqlite \
    exif

USER appuser

# Read-only filesystem для runtime
RUN chmod -R 755 /var/www/html && \
    chmod -R 777 /var/www/html/var
```

### 🚫 Security headers

```php
// src/Infrastructure/Http/SecurityHeadersMiddleware.php
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('X-XSS-Protection', '1; mode=block')
            ->withHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains')
            ->withHeader('Content-Security-Policy', "default-src 'self'");
    }
}
```

### 📊 Аудит и compliance

```php
// src/Infrastructure/Security/AuditLogger.php
final readonly class AuditLogger
{
    public function __construct(
        private LoggerInterface $logger,
        private string $serviceName = 'sorting-photos',
    ) {}

    public function logAccess(string $user, string $action, array $context = []): void
    {
        $this->logger->info('Security event', [
            'event_type' => 'access',
            'user' => $user,
            'action' => $action,
            'timestamp' => time(),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'service' => $this->serviceName,
            'context' => $context,
        ]);
    }

    public function logFileOperation(string $operation, FilePath $filePath, array $context = []): void
    {
        $this->logger->info('File operation', [
            'event_type' => 'file_operation',
            'operation' => $operation,
            'file_path' => $filePath->toString(),
            'file_size' => filesize($filePath->toString()),
            'timestamp' => time(),
            'service' => $this->serviceName,
            'context' => $context,
        ]);
    }
}
```

## 🛠️ Резервное копирование

### 💾 Стратегия резервного копирования

```bash
# /etc/cron.daily/sorting-photos-backup
#!/bin/bash

BACKUP_DIR="/var/backups/sorting-photos"
DATE=$(date +%Y%m%d_%H%M%S)

# База данных
sqlite3 /opt/sorting-photos/var/database.sqlite ".backup '${BACKUP_DIR}/db_${DATE}.sqlite'"

# Конфигурация
tar -czf "${BACKUP_DIR}/config_${DATE}.tar.gz" /opt/sorting-photos/config/

# Метаданные
cp -r /opt/sorting-photos/var "${BACKUP_DIR}/var_${DATE}"

# Очистка старых бэкапов (старше 30 дней)
find "$BACKUP_DIR" -name "*.sqlite" -mtime +30 -delete
find "$BACKUP_DIR" -name "*.tar.gz" -mtime +30 -delete
find "$BACKUP_DIR" -name "var_*" -mtime +30 -type d -exec rm -rf {} +

# Синхронизация с облаком
rclone sync "$BACKUP_DIR" "s3:sorting-photos-backups/"
```

### 🔄 Восстановление из бэкапа

```bash
# Остановка сервисов
sudo systemctl stop sorting-photos-worker@*
sudo systemctl stop sorting-photos

# Восстановление базы данных
cp /var/backups/sorting-photos/db_20240115_120000.sqlite /opt/sorting-photos/var/database.sqlite

# Восстановление конфигурации
tar -xzf /var/backups/sorting-photos/config_20240115_120000.tar.gz -C /

# Запуск сервисов
sudo systemctl start sorting-photos
sudo systemctl start sorting-photos-worker@{1..4}
```

### 📊 Мониторинг бэкапов

```bash
# Проверка успешности бэкапа
#!/bin/bash

BACKUP_DIR="/var/backups/sorting-photos"
LATEST_BACKUP=$(find "$BACKUP_DIR" -name "db_*.sqlite" -mtime -1 | head -1)

if [ -z "$LATEST_BACKUP" ]; then
    echo "CRITICAL: No recent database backup found"
    exit 2
fi

BACKUP_SIZE=$(stat -f%z "$LATEST_BACKUP")
if [ "$BACKUP_SIZE" -lt 1024 ]; then
    echo "WARNING: Database backup is suspiciously small: $BACKUP_SIZE bytes"
    exit 1
fi

echo "OK: Database backup is healthy (size: $BACKUP_SIZE bytes)"
exit 0
```

## 🚨 Обработка инцидентов

### 📋 Runbook для инцидентов

#### 🚨 Критические инциденты

**Симптомы:** Приложение не отвечает, высокая загрузка CPU/памяти

**Действия:**
1. **Оценка ситуации**
   ```bash
   # Проверка статуса
   docker compose ps
   docker stats

   # Логи ошибок
   docker compose logs --tail=100 app
   ```

2. **Изоляция проблемы**
   ```bash
   # Остановка воркеров
   docker compose exec app pkill -f "messenger:consume"

   # Проверка базы данных
   docker compose exec app php bin/console doctrine:query:sql "SELECT COUNT(*) FROM media_assets"
   ```

3. **Восстановление**
   ```bash
   # Перезапуск сервисов
   docker compose restart

   # Очистка очередей при необходимости
   docker compose exec redis redis-cli FLUSHALL
   ```

4. **Пост-мортем**
   - Анализ логов
   - Проверка метрик
   - Улучшение мониторинга

#### ⚠️ Предупреждения

**Высокий размер очереди:**
```bash
# Проверка размера очереди
docker compose exec redis redis-cli LLEN messages

# Добавление воркеров
docker compose up -d --scale worker=8

# Мониторинг
watch 'docker compose exec redis redis-cli LLEN messages'
```

**Недоступность Google Photos API:**
```bash
# Проверка квот
curl -H "Authorization: Bearer $TOKEN" \
     "https://photoslibrary.googleapis.com/v1/quotas"

# Пауза в загрузке
docker compose exec app php bin/console google-photos:upload --pause
```

### 📞 Эскалация

```yaml
# Incident response plan
escalation:
  - level: 1
    condition: "Response time > 30s for 5 minutes"
    notify: "developer@company.com"
    timeout: "15 minutes"

  - level: 2
    condition: "Service down for 10 minutes"
    notify: "devops@company.com, manager@company.com"
    timeout: "30 minutes"

  - level: 3
    condition: "Data loss or corruption"
    notify: "ceo@company.com, all@company.com"
    timeout: "1 hour"
```

---

## 📚 Ссылки

- [🏠 На главную](../README.md)
- [📖 Руководство пользователя](USER_GUIDE.md)
- [🏗️ Архитектура](ARCHITECTURE.md)
- [🔧 Разработка](DEVELOPMENT.md)
- [🔄 Асинхронная обработка](ASYNC_PROCESSING.md)
- [📊 Мониторинг](MONITORING.md)
