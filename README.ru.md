# Sorting Photos By Date

Современное PHP 8.4 приложение для организации фотографий, видео, аудиофайлов и документов по дате и типу. Построено на Hexagonal Architecture, Symfony Messenger и с поддержкой Docker.

## Возможности

- 📸 **Поддержка множества форматов**: Изображения, видео, аудиофайлы и документы
- 📅 **Умная организация**: Организует файлы по дате и типу (`{год}/{месяц}/{категория}/`)
- 🔒 **Сохранение метаданных**: Сохраняет права доступа, временные метки и расширенные атрибуты
- 🚀 **Масштабируемая архитектура**: Hexagonal Architecture с асинхронной обработкой сообщений
- 🐳 **Готово к Docker**: Полная настройка Docker Compose с поддержкой Redis/RabbitMQ
- ✅ **Контроль качества**: Комплексные тесты и инструменты статического анализа

## Требования

- PHP 8.4+
- Composer
- Docker & Docker Compose (для контейнеризованного развертывания)
- Расширение SQLite (для хранения метаданных)

## Быстрый старт

### Использование Docker (Рекомендуется)

1. **Клонируйте репозиторий:**
   ```bash
   git clone <repository-url>
   cd sorting-photos
   ```

2. **Инициализируйте директории данных:**
   ```bash
   make data-init
   ```

3. **Создайте файл окружения:**
   ```bash
   cp .env.docker.example .env
   ```

4. **Запустите приложение:**
   ```bash
   make up
   ```

5. **Поместите файлы для сортировки:**
   ```bash
   cp -r /path/to/your/photos/* var/data/source/
   ```

6. **Запустите приложение:**
   ```bash
   make run
   ```

### Локальная установка

1. **Установите зависимости:**
   ```bash
   composer install
   ```

2. **Настройте окружение:**
   ```bash
   cp .env.docker.example .env
   # Отредактируйте .env с вашими путями
   ```

3. **Запустите приложение:**
   ```bash
   php index.php
   ```

## Структура проекта

```
sorting-photos/
├── src/
│   ├── Domain/              # Доменный слой (Value Objects, Entities, Policies)
│   ├── Application/         # Слой приложения (Commands, Handlers)
│   ├── Ports/               # Порты (интерфейсы)
│   ├── Adapters/            # Адаптеры (реализации)
│   ├── Infrastructure/      # Инфраструктура (Messenger, Filesystem)
│   └── Command/             # Консольные команды
├── config/                  # Файлы конфигурации
├── tests/                   # Набор тестов
├── var/
│   ├── data/                # Директории данных
│   │   ├── source/          # Исходные файлы (только чтение)
│   │   └── destination/     # Организованные файлы
│   ├── log/                 # Логи приложения
│   ├── cache/               # Файлы кэша
│   └── database.sqlite      # База данных SQLite
├── bin/                     # Исполняемые скрипты
├── docker-compose.yml       # Конфигурация Docker Compose
├── Dockerfile               # Определение Docker образа
└── Makefile                 # Make команды
```

## Docker команды

### Основные команды

- `make up` - Запустить приложение с Redis (по умолчанию)
- `make up-rabbitmq` - Запустить с RabbitMQ вместо Redis
- `make up-worker` - Запустить с асинхронным worker для обработки сообщений
- `make down` - Остановить все контейнеры
- `make build` - Пересобрать Docker образы
- `make logs` - Показать логи всех контейнеров
- `make logs-app` - Показать логи приложения
- `make shell` - Открыть shell в контейнере приложения

### Команды приложения

- `make run` - Запустить сортировку файлов
- `make run-dry-run` - Запустить в режиме dry-run (без перемещения файлов)
- `make scan` - Сканировать файлы и отправить события
- `make consume` - Запустить consumer сообщений

### Тестирование и качество

- `make test` - Запустить PHPUnit тесты
- `make test-coverage` - Запустить тесты с отчетом о покрытии
- `make cs-fix` - Исправить стиль кода (PHP-CS-Fixer)
- `make phpstan` - Запустить PHPStan статический анализ
- `make psalm` - Запустить Psalm статический анализ
- `make grumphp` - Запустить все проверки качества кода
- `make qa` - Запустить все проверки качества и тесты

### Управление данными

- `make data-init` - Инициализировать директории данных
- `make data-clean` - Очистить директорию назначения (с подтверждением)

### Redis/RabbitMQ

- `make redis-cli` - Открыть Redis CLI
- `make redis-flush` - Очистить данные Redis
- `make rabbitmq-management` - Открыть веб-интерфейс RabbitMQ

## Конфигурация

### Переменные окружения

Создайте файл `.env` из `.env.docker.example`:

```bash
cp .env.docker.example .env
```

Ключевые переменные:

- `SOURCE_DIRECTORY` - Исходная директория (по умолчанию: `/var/data/source`)
- `DESTINATION_DIRECTORY` - Директория назначения (по умолчанию: `/var/data/destination`)
- `ORGANIZER_POLICY` - Политика организации (`date-type`, `date`, `type-date`)
- `DRY_RUN` - Режим dry-run (`true`/`false`)
- `MESSENGER_TRANSPORT_DSN` - Транспорт Messenger:
  - Redis: `redis://redis:6379/messages`
  - RabbitMQ: `amqp://guest:guest@rabbitmq:5672/%2f/messages`

### Политики организации

- **date-type** (по умолчанию): `{год}/{месяц}/{категория}/`
- **date**: `{год}/{месяц}/`
- **type-date**: `{категория}/{год}/{месяц}/`

## Архитектура

Приложение следует **Hexagonal Architecture** (Ports & Adapters) в сочетании с **Pipeline Processing**:

- **Domain Layer**: Value Objects, Entities, Domain Events, Policies
- **Application Layer**: Commands, Handlers, Queries
- **Infrastructure Layer**: Адаптеры для файловой системы, извлечения метаданных, обмена сообщениями
- **Ports**: Интерфейсы, определяющие контракты

### Ключевые паттерны

- **CQRS**: Разделение команд и запросов
- **Event-Driven**: Доменные события через Symfony Messenger
- **Policy Pattern**: Гибкие правила организации файлов
- **Chain of Responsibility**: Цепочка извлечения метаданных
- **Dependency Injection**: Контейнер Symfony DI

## Разработка

### Запуск тестов

```bash
# Все тесты
make test

# С покрытием
make test-coverage

# Конкретный тест
make test-filter TEST=TestClassName
```

### Инструменты качества кода

```bash
# Исправить стиль кода
make cs-fix

# Статический анализ
make phpstan
make psalm

# Все проверки
make grumphp
make qa
```

### Ручной запуск инструментов

```bash
# PHP-CS-Fixer
./vendor/bin/php-cs-fixer fix --allow-risky=yes

# PHPStan
./vendor/bin/phpstan analyse

# Psalm (с совместимостью PHP 8.4)
php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_STRICT" ./vendor/bin/psalm --no-cache

# Rector
./vendor/bin/rector process src --dry-run
```

### Конфигурация Psalm

Psalm настроен для совместимости с PHP 8.4. Некоторые зависимости vendor могут показывать предупреждения об устаревании, но это не влияет на функциональность приложения.

## Асинхронная обработка

Для асинхронной обработки файлов с worker'ами:

1. **Запустите приложение с worker:**
   ```bash
   make up-worker
   ```

2. **В другом терминале сканируйте файлы:**
   ```bash
   make scan
   ```

3. **Worker автоматически обработает сообщения из очереди**

## Решение проблем

### Проблемы с правами доступа

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

### Очистка очереди Redis

```bash
make redis-flush
```

### Пересборка образов

```bash
make build
```

## Категории файлов

Файлы автоматически категоризируются:

- **images**: JPEG, PNG, GIF, WebP и т.д.
- **audio**: MP3, FLAC, WAV, OGG и т.д.
- **video**: MP4, AVI, MOV, MKV и т.д.
- **other**: Документы и другие типы файлов

## Сохранение метаданных

Приложение сохраняет:

- Права доступа к файлам (mode)
- Временные метки (mtime, atime)
- Расширенные атрибуты (xattr)
- Владельца файла (когда возможно)

## Лицензия

MIT License

## Автор

Igors Savins - igor.savin@inbox.lv

## Участие в разработке

Вклад приветствуется! Пожалуйста, убедитесь:

1. Все тесты проходят (`make test`)
2. Проверки качества кода проходят (`make qa`)
3. Следуете стандартам кодирования PSR-12
4. Добавляете тесты для новых функций

## См. также

- [REFACTORING_PROPOSALS.md](REFACTORING_PROPOSALS.md) - Техническая спецификация и детали архитектуры

