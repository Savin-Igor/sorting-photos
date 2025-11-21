# 📸 Sorting Photos By Date

Современное PHP 8.4 приложение для организации фотографий, видео, аудиофайлов и документов по дате и типу. Построено на гексагональной архитектуре с поддержкой асинхронной обработки и интеграцией Google Photos.

[![PHP Version](https://img.shields.io/badge/PHP-8.4+-blue.svg)](https://php.net/)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Docker](https://img.shields.io/badge/Docker-Ready-blue.svg)](https://docker.com/)

## 📋 Содержание

- [🚀 Быстрый старт](#-быстрый-старт)
- [🎯 Возможности](#-возможности)
- [🏗️ Архитектура](#️-архитектура)
- [📖 Использование](#-использование)
- [⚙️ Конфигурация](#️-конфигурация)
- [🔧 Разработка](#-разработка)
- [📚 Документация](#-документация)
- [🙋 Поддержка](#-поддержка)

## 🚀 Быстрый старт

### 🐳 Docker (Рекомендуется)

```bash
# 1. Клонировать репозиторий
git clone <repository-url>
cd sorting-photos

# 2. Инициализировать директории данных
make data-init

# 3. Запустить приложение
make up

# 4. Поместить файлы для сортировки
cp -r /path/to/your/photos/* var/data/source/

# 5. Запустить сортировку
make run
```

### 📁 Результат работы

```
📁 var/data/destination/
├── 2023/
│   ├── 01/
│   │   ├── images/
│   │   │   ├── photo1.jpg
│   │   │   └── photo2.png
│   │   └── video/
│   │       └── video1.mp4
│   └── 12/
│       ├── images/
│       │   ├── christmas1.jpg
│       │   └── christmas2.jpg
│       └── audio/
│           └── music.mp3
└── 2024/
    ├── 06/
    │   └── images/
    │       ├── vacation1.jpg
    │       └── vacation2.jpg
    └── 11/
        └── video/
            └── birthday.mp4
```

### 🏠 Локальная установка

```bash
# Установка зависимостей
composer install

# Настройка окружения
cp .env.example .env

# Запуск
php bin/console organize:files /path/to/source /path/to/destination
```

## 🎯 Возможности

### 📸 Форматы файлов
- **Изображения**: JPEG, PNG, GIF, WebP, TIFF, BMP, HEIC
- **Видео**: MP4, AVI, MOV, MKV, WMV, FLV, WebM
- **Аудио**: MP3, FLAC, WAV, OGG, AAC, WMA
- **Документы**: PDF, DOC, DOCX, TXT, и другие

### 📅 Организация файлов
- **По умолчанию**: `{год}/{месяц}/{категория}/`
- **По дате**: `{год}/{месяц}/`
- **По типу**: `{категория}/{год}/{месяц}/`

### 🔒 Сохранение метаданных и безопасность
- Права доступа к файлам (chmod)
- Временные метки (mtime, atime)
- Расширенные атрибуты (xattr)
- Владелец файла
- **Исходные файлы НИКОГДА не удаляются** - только копируются в destination

### 🚀 Производительность
- **Синхронный режим**: ~10-50 файлов/сек
- **Асинхронный режим**: ~40-200 файлов/сек (4 воркера)
- **Масштабирование**: До 8+ параллельных воркеров

### ☁️ Google Photos интеграция
- Загрузка больших объемов (до 2 ТБ+)
- Resumable upload с возобновлением
- Batch processing (до 50 элементов)
- Управление квотами API
- Сжатие изображений без потери качества
- Сохранение EXIF данных и даты создания
- Быстрый поиск файлов (locate/find/hybrid)
- Гибкая фильтрация файлов (по типу, размеру, дате, regex)

### ✅ Качество и надежность
- Полное покрытие тестами
- Статический анализ (PHPStan, Psalm)
- Идемпотентность операций
- Retry и Dead Letter Queue
- Структурированное логирование

## 🏗️ Архитектура

### 🎯 Гексагональная архитектура (Ports & Adapters)

```mermaid
graph TB
    subgraph "🎭 Presentation Layer"
        CLI[Консольные команды]
        API[HTTP API]
    end

    subgraph "📱 Application Layer"
        CMD[Commands]
        HDL[Handlers]
        SVC[Services]
    end

    subgraph "🎯 Domain Layer"
        ENT[Entities & Value Objects]
        EVT[Domain Events]
        POL[Policies]
    end

    subgraph "🔌 Ports"
        SP[ScannerPort]
        MP[MetadataExtractorPort]
        FP[FilesystemPort]
        LP[LoggerPort]
        RP[RepositoryPort]
    end

    subgraph "🛠️ Adapters"
        SF[SymfonyFinder]
        EXIF[EXIF Adapter]
        GTID3[getID3 Adapter]
        LFS[LocalFilesystem]
        MONO[Monolog]
        DB[SQLite Repository]
    end

    subgraph "🏗️ Infrastructure"
        MSG[Messenger]
        CONF[Configuration]
        LOG[Logging]
    end

    CLI --> CMD
    CMD --> HDL
    HDL --> SVC
    SVC --> ENT
    ENT --> EVT
    EVT --> POL

    HDL --> SP
    HDL --> MP
    HDL --> FP
    HDL --> LP
    HDL --> RP

    SP --> SF
    MP --> EXIF
    MP --> GTID3
    FP --> LFS
    LP --> MONO
    RP --> DB

    MSG --> CONF
    LOG --> CONF
```

### 🔄 Pipeline обработки

```mermaid
flowchart TD
    A[ScanFilesCommand] --> B[FileDiscovered Event]
    B --> C{Async Mode?}

    C -->|Да| D[Messenger Queue]
    C -->|Нет| E[IngestFileHandler]

    D --> F[Worker Consumer]
    F --> E

    E --> G[Detect MIME Type]
    G --> H[Extract Metadata]
    H --> I[Calculate SHA-256 Hash]
    I --> J[FileProcessed Event]

    J --> K[OrganizeFileHandler]
    K --> L[Apply OrganizerPolicy]
    L --> M[Copy with Metadata Preservation]
    M --> N[Verify Hash]
    N --> O[Delete Source]
    O --> P[FileOrganized Event]

    P --> Q[Success: Continue]
    N -->|Hash Mismatch| R[Error: Retry/Dead Letter]
    M -->|Copy Failed| R
    O -->|Delete Failed| R
```

### 📦 Структура проекта

```
sorting-photos/
├── 📁 src/
│   ├── 🎯 Domain/           # Домен (бизнес-логика)
│   │   ├── MediaAsset.php   # Агрегатный корень
│   │   ├── ValueObjects/    # Value Objects
│   │   ├── Event/           # Доменные события
│   │   └── Policies/        # Политики организации
│   │
│   ├── 📱 Application/      # Прикладной слой
│   │   ├── Command/         # Команды CQRS
│   │   └── Handler/         # Обработчики команд
│   │
│   ├── 🔌 Ports/            # Интерфейсы (контракты)
│   │   ├── ScannerPort.php
│   │   ├── MetadataExtractorPort.php
│   │   └── FilesystemPort.php
│   │
│   ├── 🛠️ Adapters/         # Реализации портов
│   │   ├── Scanner/
│   │   ├── Metadata/
│   │   └── Filesystem/
│   │
│   └── 🏗️ Infrastructure/   # Инфраструктурный слой
│       ├── Config/
│       ├── Messenger/
│       └── Storage/
│
├── ⚙️ config/               # Конфигурация
├── 🧪 tests/                # Тесты
├── 🐳 docker-compose.yml    # Docker
└── 📖 docs/                 # Документация
```

## 📖 Использование

### 🐳 Docker команды

#### Основные команды
```bash
make up              # Запустить с Redis
make up-rabbitmq     # Запустить с RabbitMQ
make up-worker       # Запустить с воркером
make down            # Остановить все
make shell           # Открыть shell в контейнере
```

#### Приложение
```bash
make run                    # Синхронная сортировка
make run-dry-run           # Пробный запуск (без перемещения)
make scan                  # Сканирование без обработки
make consume               # Запустить воркер
make consume-workers WORKERS=4  # Несколько воркеров
```

#### Управление данными
```bash
make data-init             # Создать директории
make data-clean            # Очистить destination
make metadata-clear        # Очистить метаданные
```

#### Google Photos
```bash
make google-photos-authorize      # Авторизация
make google-photos-test           # Тестирование API
make google-photos-upload         # Загрузка файлов
```

### ⚙️ Переменные окружения

```bash
# Директории (пути на хосте)
SOURCE_DIRECTORY_HOST=/home/user/Pictures
DESTINATION_DIRECTORY_HOST=/mnt/external/sorted

# Политика организации
ORGANIZER_POLICY=date-type  # date-type, date, type-date

# Режим работы
DRY_RUN=false              # true для тестового запуска
ASYNC_MODE=false           # true для асинхронной обработки

# Messenger (очереди)
MESSENGER_TRANSPORT_DSN=redis://redis:6379/messages
# или
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@rabbitmq:5672/%2f/messages
```

## 🔧 Разработка

### 🧪 Тестирование

```bash
make test                  # Все тесты
make test-coverage         # С покрытием
make test-filter TEST=TestClassName  # Конкретный тест
```

### ✅ Качество кода

```bash
make cs-fix               # Исправить стиль
make phpstan             # PHPStan анализ
make psalm               # Psalm анализ
make grumphp             # Все проверки
make qa                  # Качество + тесты
```

### 🔨 Ручные инструменты

```bash
# PHP-CS-Fixer
./vendor/bin/php-cs-fixer fix --allow-risky=yes

# PHPStan
./vendor/bin/phpstan analyse

# Psalm
php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_STRICT" ./vendor/bin/psalm

# Rector
./vendor/bin/rector process src --dry-run
```

## 📚 Документация

- 📖 [Полное руководство пользователя](docs/USER_GUIDE.md)
- 🏗️ [Архитектура и дизайн](docs/ARCHITECTURE.md)
- ☁️ [Google Photos API](docs/GOOGLE_PHOTOS_API.md)
- ⚙️ [Конфигурация и развертывание](docs/DEPLOYMENT.md)
- 🔧 [Разработка и contribution](docs/DEVELOPMENT.md)
- 🔄 [Асинхронная обработка](docs/ASYNC_PROCESSING.md)
- 📊 [Мониторинг и метрики](docs/MONITORING.md)

## 🏛️ Требования

- **PHP**: 8.4+
- **Composer**: 2.0+
- **Docker**: 20.0+ (рекомендуется)
- **Docker Compose**: 2.0+
- **SQLite**: расширение (для метаданных)

## 📈 Производительность

### Синхронный режим
- **Производительность**: 10-50 файлов/сек
- **Использование памяти**: ~50-100 MB
- **Рекомендация**: До 10k файлов

### Асинхронный режим
- **Производительность**: 40-200 файлов/сек (4 воркера)
- **Использование памяти**: ~100-300 MB
- **Рекомендация**: От 10k файлов, до 2 ТБ+

### Масштабирование воркеров
```bash
# 4 воркера: ~40-200 файлов/сек
make consume-workers WORKERS=4

# 8 воркеров: ~80-400 файлов/сек
make consume-workers WORKERS=8
```

## 🐛 Решение проблем

### Проблемы с правами доступа
```bash
make shell
# В контейнере:
sudo chown -R www-data:www-data /var/data /var/www/html/var
```

### Просмотр логов
```bash
make logs-app           # Логи приложения
make logs-worker        # Логи воркера
make show-progress      # Прогресс обработки
```

### Очистка очередей
```bash
make redis-flush        # Очистить Redis
make metadata-clear     # Очистить метаданные
```

## 🙋 Поддержка

### 📞 Контакты
- **Автор**: Igors Savins
- **Email**: igor.savin@inbox.lv
- **GitHub**: [репозиторий проекта]

### 📋 Сообщение об ошибке
При создании issue укажите:
1. Версию PHP и ОС
2. Docker версии (если используется)
3. Полные логи ошибки
4. Шаги для воспроизведения
5. Конфигурацию (`.env` без секретов)

### 🤝 Вклад в проект
1. Fork репозиторий
2. Создать feature branch
3. Написать тесты
4. Запустить `make qa`
5. Создать Pull Request

## 📄 Лицензия

MIT License - см. [LICENSE](LICENSE) файл для деталей.

## 🙏 Благодарности

- Symfony Framework за компоненты
- Google Photos API за интеграцию
- Сообщество PHP за инструменты качества
