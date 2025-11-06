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
│   ├── Domain/                      # Доменный слой (изолирован)
│   │   ├── MediaAsset.php          # Aggregate Root
│   │   ├── ValueObjects/           # Value Objects
│   │   │   ├── FileType.php        # Enum (image, video, audio, other)
│   │   │   ├── FileCategory.php    # Enum (images, audio, video, other)
│   │   │   ├── FilePath.php        # Value Object
│   │   │   ├── FileHash.php        # Value Object
│   │   │   ├── MediaDate.php       # Value Object
│   │   │   └── MediaMeta.php       # Value Object
│   │   ├── Event/                   # Доменные события
│   │   │   ├── FileDiscovered.php
│   │   │   ├── FileProcessed.php
│   │   │   ├── FileOrganized.php
│   │   │   └── FileError.php
│   │   └── Policies/                # Policy Pattern
│   │       ├── OrganizerPolicy.php  # Интерфейс
│   │       ├── DateTypePolicy.php   # {год}/{месяц}/{категория}/
│   │       ├── DatePolicy.php       # {год}/{месяц}/
│   │       └── TypeDatePolicy.php   # {категория}/{год}/{месяц}/
│   │
│   ├── Application/                 # Use Cases & CQRS
│   │   ├── Command/
│   │   │   ├── IngestFileCommand.php
│   │   │   └── OrganizeFileCommand.php
│   │   └── Handler/
│   │       ├── IngestFileHandler.php
│   │       └── OrganizeFileHandler.php
│   │
│   ├── Ports/                       # Интерфейсы (Hexagonal)
│   │   ├── ScannerPort.php
│   │   ├── MetadataExtractorPort.php
│   │   ├── FilesystemPort.php
│   │   ├── LoggerPort.php
│   │   ├── MetadataRepositoryPort.php
│   │   ├── MimeTypeDetectorInterface.php
│   │   └── AudioVideoMetadataAnalyzerInterface.php
│   │
│   ├── Adapters/                    # Реализации портов
│   │   ├── Scanner/
│   │   │   └── SymfonyFinderAdapter.php
│   │   ├── Metadata/
│   │   │   ├── ExifAdapter.php
│   │   │   ├── GetId3Adapter.php
│   │   │   └── GenericAdapter.php
│   │   ├── Filesystem/
│   │   │   └── LocalFilesystemAdapter.php
│   │   ├── Logger/
│   │   │   └── MonologAdapter.php
│   │   └── Repository/
│   │       └── DatabaseMetadataRepository.php
│   │
│   ├── Infrastructure/
│   │   ├── Messenger/
│   │   │   ├── FileDiscoveredHandler.php
│   │   │   ├── FileProcessedHandler.php
│   │   │   ├── FileOrganizedHandler.php
│   │   │   ├── FileErrorHandler.php
│   │   │   ├── RetryPolicy.php
│   │   │   └── SynchronousMessageBus.php
│   │   ├── Filesystem/
│   │   │   ├── MetadataPreservingCopier.php
│   │   │   └── MimeTypeDetectorAdapter.php
│   │   └── Metadata/
│   │       ├── MetadataExtractorChain.php
│   │       └── GetId3Adapter.php
│   │
│   └── Command/                     # Консольные команды
│       └── ScanFilesCommand.php
│
├── config/
│   ├── packages/
│   │   ├── messenger.yaml          # Конфигурация очередей
│   │   └── flysystem.yaml          # Конфигурация файловой системы
│   └── services.yaml                # DI контейнер
│
├── tests/                            # Набор тестов
│   └── src/
│       └── Unit/
│
├── var/
│   ├── data/                        # Директории данных
│   │   ├── source/                  # Исходные файлы (только чтение)
│   │   └── destination/             # Организованные файлы
│   ├── log/                         # Логи приложения
│   ├── cache/                       # Файлы кэша
│   └── database.sqlite              # База данных SQLite
│
├── bin/                             # Исполняемые скрипты
│   └── console                      # Точка входа Symfony Console
│
├── docker-compose.yml               # Конфигурация Docker Compose
├── Dockerfile                       # Определение Docker образа
└── Makefile                         # Make команды
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

- **Hexagonal Architecture** (Ports/Adapters) — Изоляция домена
- **Pipeline** — Конвейер обработки через Messenger
- **CQRS** — Команды (`IngestFile`, `OrganizeFile`) и События (`FileOrganized`)
- **Policy/Strategy** — Гибкие правила организации файлов
- **Retry/Dead Letter Queue** — Устойчивость
- **Queue-based processing** — Масштабируемость
- **Chain of Responsibility** — Цепочка извлечения метаданных
- **Dependency Injection** — Контейнер Symfony DI

### Конвейер обработки

```
ScanFilesCommand
    ↓
FileDiscovered (событие)
    ↓
Messenger Queue (асинхронно)
    ↓
IngestFileHandler
    ├─→ Определение MIME типа
    ├─→ Извлечение метаданных
    └─→ Вычисление хеша
    ↓
FileProcessed (событие)
    ↓
OrganizeFileHandler
    ├─→ Применение OrganizerPolicy
    ├─→ Копирование с сохранением метаданных
    ├─→ Проверка хеша
    └─→ Удаление источника
    ↓
FileOrganized (событие)
```

### Технологический стек

**Основные:**
- `symfony/console` — Консольные команды
- `symfony/messenger` — Асинхронная обработка и повторы
- `symfony/finder` — Сканирование дерева файлов

**Детекция и метаданные:**
- `league/mime-type-detection` — Надежное определение MIME (finfo + карта расширений)
- `james-heinrich/getid3` — Извлечение метаданных аудио/видео
- `exif` (встроенный) — Метаданные изображений

**Хранилище:**
- `league/flysystem` — Абстракция файловой системы (локально/облако)
- `doctrine/dbal` — Хранилище метаданных (SQLite/PostgreSQL) для идемпотентности

**Логирование:**
- `monolog/monolog` — Структурированное логирование

**Очереди (опционально):**
- Redis/RabbitMQ через транспорты Symfony Messenger

### Детали реализации

#### Детекция даты (приоритеты)

**Изображения:**
1. `EXIF DateTimeOriginal`
2. `EXIF DateTime`
3. `mtime` (время модификации)

**Аудио/Видео (getID3):**
1. `ID3 TDRC` (год записи)
2. `ID3 TYER` (год)
3. `mtime`

**Остальные файлы:**
1. `mtime`
2. `ctime` (время создания)

#### Процесс сохранения метаданных

1. Вычисление SHA-256 хеша исходного файла
2. Бинарное копирование файла
3. Верификация хеша копии (сравнение с исходным)
4. Восстановление метаданных:
   - `chmod()` — Права доступа
   - `touch()` — Временные метки (mtime, atime)
   - `xattr` (если поддерживается) — Расширенные атрибуты
5. Удаление источника только после успешной верификации

#### Идемпотентность

- Ключ в БД: `(absolute_path, size, hash)`
- Проверка перед обработкой: если файл уже обработан — пропуск
- Дедупликация по хешу содержимого

#### Разрешение коллизий

- Если файл существует: `{original-name}-{hash-prefix}.{ext}`
- Хеш используется для дедупликации и проверки целостности

#### Механизм повторов

- Автоматические повторы при ошибках (настраивается)
- Dead Letter Queue для проблемных файлов
- Уведомления о критических ошибках
- Логирование всех попыток

#### Горизонтальное масштабирование

- Несколько воркеров обрабатывают очередь параллельно
- Настройка количества воркеров через конфигурацию
- Балансировка нагрузки через очередь

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

## Преимущества архитектуры

- ✅ **Масштабируемость** — Параллельная обработка через Messenger
- ✅ **Расширяемость** — Hexagonal Architecture позволяет легко менять адаптеры
- ✅ **Устойчивость** — Retry, DLQ, идемпотентность
- ✅ **Гибкость** — Policy Pattern для разных правил организации
- ✅ **Тестируемость** — Легко мокировать порты
- ✅ **Изоляция** — Домен не знает о конкретных реализациях
- ✅ **Облачная готовность** — Легко добавить S3/FTP через Flysystem
- ✅ **Подходит для миллионов файлов** — Горизонтальное масштабирование

