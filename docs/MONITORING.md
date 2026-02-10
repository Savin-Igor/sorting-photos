# 📊 Мониторинг и метрики

> **📖 Основная документация**: См. [Развертывание](../docs/DEPLOYMENT.md#📊-мониторинг-и-метрики) для production мониторинга.

## 📋 Содержание

- [🎯 Обзор](#-обзор)
- [📈 Метрики приложения](#-метрики-приложения)
- [📊 Мониторинг очередей](#-мониторинг-очередей)
- [🔍 Логирование](#-логирование)
- [🚨 Алертинг](#-алертинг)
- [📊 Dashboards](#-dashboards)
- [🔧 Инструменты](#-инструменты)

## 🎯 Обзор

Мониторинг Sorting Photos включает:

- **Метрики производительности** - скорость обработки, время отклика
- **Мониторинг состояния** - health checks, статус сервисов
- **Метрики очередей** - размер очередей, скорость обработки
- **Логирование** - структурированные логи всех операций
- **Алертинг** - уведомления о проблемах

## 📈 Метрики приложения

### Основные метрики

```bash
# Текущая статистика обработки
make show-progress

# Детальная информация
make shell
sqlite3 var/database.sqlite << 'EOF'
SELECT
    status,
    COUNT(*) as files_count,
    SUM(file_size) as total_size_bytes,
    AVG(extract_time) as avg_extract_time,
    AVG(organize_time) as avg_organize_time
FROM media_assets
GROUP BY status;
EOF
```

### Метрики производительности

```bash
# Скорость обработки
make shell
sqlite3 var/database.sqlite << 'EOF'
SELECT
    strftime('%Y-%m-%d %H:00:00', created_at) as hour,
    COUNT(*) as files_processed,
    AVG(julianday(completed_at) - julianday(created_at)) * 86400 as avg_processing_time_sec
FROM media_assets
WHERE status = 'completed'
    AND created_at >= datetime('now', '-24 hours')
GROUP BY hour
ORDER BY hour DESC;
EOF
```

### Метрики ошибок

```bash
# Статистика ошибок по типам
make shell
sqlite3 var/database.sqlite << 'EOF'
SELECT
    error_type,
    COUNT(*) as error_count,
    MAX(created_at) as last_error
FROM processing_errors
WHERE created_at >= datetime('now', '-7 days')
GROUP BY error_type
ORDER BY error_count DESC;
EOF
```

## 📊 Мониторинг очередей

### Redis очередь

```bash
# Подключение к Redis
make redis-cli

# Проверка размера очереди
LLEN messages

# Просмотр сообщений (осторожно с большими очередями)
LRANGE messages 0 10

# Статистика очередей
INFO keyspace
```

### RabbitMQ (если используется)

```bash
# Открыть веб-интерфейс
make rabbitmq-management

# Или через CLI
docker compose exec rabbitmq rabbitmqctl list_queues name messages
```

### Метрики очередей

```bash
# Скрипт мониторинга очередей
#!/bin/bash
while true; do
    TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

    # Redis queue size
    REDIS_SIZE=$(docker compose exec -T redis redis-cli LLEN messages 2>/dev/null || echo "error")

    # Worker count
    WORKER_COUNT=$(docker compose exec -T app ps aux | grep "messenger:consume" | grep -v grep | wc -l 2>/dev/null || echo "0")

    echo "$TIMESTAMP | Queue: $REDIS_SIZE | Workers: $WORKER_COUNT"
    sleep 60
done
```

## 🔍 Логирование

### Структура логов

```json
{
  "timestamp": "2024-01-15T10:30:45.123Z",
  "level": "INFO",
  "channel": "app",
  "message": "File processed successfully",
  "context": {
    "operation": "file_processing",
    "file_path": "/var/data/source/photo.jpg",
    "file_size": 2457600,
    "processing_time": 0.234,
    "hash": "a1b2c3d4e5f6...",
    "mime_type": "image/jpeg",
    "destination_path": "/var/data/destination/2024/01/images/photo.jpg"
  },
  "extra": {
    "user": "system",
    "hostname": "sorting-photos-prod-01",
    "request_id": "req-123456",
    "memory_peak": "32MB",
    "cpu_usage": "15%"
  }
}
```

### Логи по компонентам

```bash
# Основные логи приложения
make logs-app

# Логи воркеров
make logs-worker

# Логи Google Photos
tail -f var/log/google_photos.log

# Messenger логи
tail -f var/log/messenger.log
```

### Анализ логов

```bash
# Поиск ошибок за последний час
make logs-app | grep "$(date '+%Y-%m-%dT%H')" | grep ERROR

# Статистика обработки по времени
make logs-app | grep "File processed" | awk '{print $1}' | cut -d'T' -f2 | cut -d':' -f1 | sort | uniq -c

# Поиск медленных операций (>1 сек)
make logs-app | jq 'select(.context.processing_time > 1)' | jq -r '.message + " - " + (.context.processing_time | tostring) + "s"'
```

## 🚨 Алертинг

### Правила алертинга

#### Критические алерты

```bash
# Приложение не отвечает
#!/bin/bash
if ! docker compose ps app | grep -q "Up"; then
    echo "CRITICAL: Sorting Photos app is down"
    # Отправить алерт в Slack/Discord/email
    exit 2
fi

# Очередь переполнена
QUEUE_SIZE=$(docker compose exec -T redis redis-cli LLEN messages 2>/dev/null || echo "0")
if [ "$QUEUE_SIZE" -gt 10000 ]; then
    echo "CRITICAL: Queue size is $QUEUE_SIZE messages"
    exit 2
fi
```

#### Предупреждения

```bash
# Низкая скорость обработки
RECENT_FILES=$(docker compose exec -T app php bin/console show:progress --once | grep "Files processed" | awk '{print $3}')
if [ "$RECENT_FILES" -lt 10 ]; then
    echo "WARNING: Processing rate is low: $RECENT_FILES files in last check"
    exit 1
fi

# Высокое использование памяти
MEMORY_USAGE=$(docker stats --no-stream --format "table {{.Container}}\t{{.MemUsage}}" | grep sorting-photos-app | awk '{print $2}' | sed 's/%//')
if [ "${MEMORY_USAGE%.*}" -gt 80 ]; then
    echo "WARNING: High memory usage: $MEMORY_USAGE%"
    exit 1
fi
```

### Интеграция с алертингом

#### Slack уведомления

```bash
# Отправка в Slack
curl -X POST -H 'Content-type: application/json' \
     --data '{"text":"Sorting Photos Alert: High queue size detected"}' \
     $SLACK_WEBHOOK_URL
```

#### Email уведомления

```bash
# Отправка email
echo "Sorting Photos Alert: Application is down" | \
mail -s "Sorting Photos Alert" admin@company.com
```

## 📊 Dashboards

### Grafana Dashboard

```json
{
  "dashboard": {
    "title": "Sorting Photos Monitoring",
    "panels": [
      {
        "title": "Files Processing Rate",
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
        "title": "Error Rate",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(sorting_photos_errors_total[5m])",
            "legendFormat": "Errors/sec"
          }
        ]
      },
      {
        "title": "Memory Usage",
        "type": "graph",
        "targets": [
          {
            "expr": "sorting_photos_memory_usage_bytes",
            "legendFormat": "Memory (bytes)"
          }
        ]
      }
    ]
  }
}
```

### Метрики для Grafana

```yaml
# Prometheus конфигурация
scrape_configs:
  - job_name: 'sorting-photos'
    static_configs:
      - targets: ['sorting-photos:9090']
    metrics_path: '/metrics'

  - job_name: 'sorting-photos-health'
    static_configs:
      - targets: ['sorting-photos:8080']
    metrics_path: '/health'
```

## 🔧 Инструменты

### Мониторинг инструменты

```bash
# Prometheus + Grafana
docker run -d -p 9090:9090 prom/prometheus
docker run -d -p 3000:3000 grafana/grafana

# ELK Stack для логов
docker run -d -p 5601:5601 -p 9200:9200 -p 5044:5044 sebp/elk

# Zabbix для комплексного мониторинга
# Конфигурация Zabbix агента в Dockerfile
```

### Отладочные инструменты

```bash
# PHP profiling
# Добавьте в код:
xhprof_enable(XHPROF_FLAGS_CPU | XHPROF_FLAGS_MEMORY);
// ... код ...
$data = xhprof_disable();
file_put_contents('/tmp/xhprof.prof', serialize($data));

# Memory profiling
php -r "
echo 'Memory usage: ' . (memory_get_peak_usage(true) / 1024 / 1024) . ' MB' . PHP_EOL;
echo 'Included files: ' . count(get_included_files()) . PHP_EOL;
"

# Database profiling
# В config/packages/doctrine.yaml добавить:
doctrine:
    dbal:
        connections:
            default:
                options:
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY: false
                logging: true
                profiling: true
```

### Автоматизированный мониторинг

```bash
# Cron job для регулярных проверок
*/5 * * * * /opt/sorting-photos/monitoring/check-health.sh
*/15 * * * * /opt/sorting-photos/monitoring/check-performance.sh
0 * * * * /opt/sorting-photos/monitoring/cleanup-old-logs.sh
```

---

## 📚 Ссылки

- [🏠 На главную](../README.md)
- [📖 Руководство пользователя](../docs/USER_GUIDE.md#📊-мониторинг-и-статистика)
- [🚀 Развертывание](../docs/DEPLOYMENT.md#📊-мониторинг-и-метрики)
- [🔧 Разработка](../docs/DEVELOPMENT.md#📊-профилирование-и-отладка)
