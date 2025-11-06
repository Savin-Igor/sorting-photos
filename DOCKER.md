# Docker Setup для Sorting Photos

Этот проект использует Docker Compose для запуска приложения с поддержкой Redis или RabbitMQ для обработки сообщений через Symfony Messenger.

## Быстрый старт

1. **Инициализация данных:**
   ```bash
   make data-init
   ```

2. **Запуск с Redis (по умолчанию):**
   ```bash
   make up
   ```

3. **Запуск с RabbitMQ:**
   ```bash
   make up-rabbitmq
   ```

4. **Запуск с Worker для асинхронной обработки:**
   ```bash
   make up-worker
   ```

5. **Запуск приложения:**
   ```bash
   make run
   ```

## Структура директорий

- `var/data/source/` - исходные файлы для сортировки (только чтение)
- `var/data/destination/` - отсортированные файлы
- `var/log/` - логи приложения
- `var/cache/` - кэш
- `var/database.sqlite` - база данных SQLite

## Основные команды Make

### Docker Compose
- `make up` - запустить с Redis
- `make up-rabbitmq` - запустить с RabbitMQ
- `make up-worker` - запустить с worker для обработки сообщений
- `make down` - остановить все контейнеры
- `make build` - пересобрать образы
- `make logs` - показать логи всех контейнеров
- `make logs-app` - показать логи приложения
- `make logs-worker` - показать логи worker
- `make shell` - открыть shell в контейнере приложения

### Приложение
- `make run` - запустить сортировку файлов
- `make run-dry-run` - запустить в режиме dry-run (без перемещения файлов)
- `make scan` - только сканировать файлы и отправить события
- `make consume` - запустить consumer для обработки сообщений

### Тестирование
- `make test` - запустить все тесты
- `make test-coverage` - запустить тесты с отчетом о покрытии
- `make test-filter TEST=TestClassName` - запустить конкретный тест

### Качество кода
- `make cs-fix` - исправить стиль кода (PHP-CS-Fixer)
- `make phpstan` - статический анализ (PHPStan)
- `make psalm` - статический анализ (Psalm)
- `make grumphp` - запустить все проверки качества кода
- `make qa` - запустить все проверки качества кода и тесты

### Управление данными
- `make data-init` - создать директории для данных
- `make data-clean` - очистить директорию назначения (с подтверждением)

### Redis
- `make redis-cli` - открыть Redis CLI
- `make redis-flush` - очистить все данные Redis

### RabbitMQ
- `make rabbitmq-management` - открыть веб-интерфейс RabbitMQ (http://localhost:15672)

## Конфигурация

Создайте файл `.env` на основе `.env.docker.example`:

```bash
cp .env.docker.example .env
```

### Переменные окружения

- `SOURCE_DIRECTORY` - исходная директория (по умолчанию: `/var/data/source`)
- `DESTINATION_DIRECTORY` - директория назначения (по умолчанию: `/var/data/destination`)
- `ORGANIZER_POLICY` - политика организации файлов (`date-type`, `date`, `type-date`)
- `DRY_RUN` - режим dry-run (`true`/`false`)
- `MESSENGER_TRANSPORT_DSN` - DSN для транспорта Messenger:
  - Redis: `redis://redis:6379/messages`
  - RabbitMQ: `amqp://guest:guest@rabbitmq:5672/%2f/messages`

## Использование

1. **Поместите файлы для сортировки в `var/data/source/`:**
   ```bash
   cp -r /path/to/your/photos/* var/data/source/
   ```

2. **Запустите приложение:**
   ```bash
   make run
   ```

3. **Проверьте результаты в `var/data/destination/`:**
   ```bash
   ls -la var/data/destination/
   ```

## Асинхронная обработка с Worker

Для обработки файлов асинхронно через очередь:

1. **Запустите приложение с worker:**
   ```bash
   make up-worker
   ```

2. **В другом терминале запустите сканирование:**
   ```bash
   make scan
   ```

3. **Worker автоматически обработает все сообщения из очереди**

## Переключение между Redis и RabbitMQ

### Использование Redis (по умолчанию)
```bash
make up
```

### Использование RabbitMQ
1. Обновите `.env`:
   ```env
   MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages
   ```

2. Запустите с профилем RabbitMQ:
   ```bash
   make up-rabbitmq
   ```

3. Откройте веб-интерфейс RabbitMQ:
   ```bash
   make rabbitmq-management
   ```

## Troubleshooting

### Проблемы с правами доступа
Если возникают проблемы с правами доступа к файлам:
```bash
make shell
# В контейнере:
sudo chown -R www-data:www-data /var/data /var/www/html/var
```

### Просмотр логов
```bash
make logs-app
# или
make logs-worker
```

### Очистка Redis очереди
```bash
make redis-flush
```

### Пересборка образов
```bash
make build
```

## Структура проекта в Docker

```
/var/www/html/          - корень приложения
  ├── src/              - исходный код
  ├── config/           - конфигурация
  ├── bin/              - исполняемые файлы
  └── var/              - переменные данные
      ├── data/         - данные (volume)
      │   ├── source/   - исходные файлы
      │   └── destination/ - отсортированные файлы
      ├── log/          - логи приложения
      ├── cache/        - кэш
      └── database.sqlite - SQLite база данных
```

