# Предложения по рефакторингу проекта (PHP 8.4)

## Анализ текущего кода

### Текущие проблемы:
1. Поддерживаются только изображения и видео (остальные файлы игнорируются или вызывают ошибки)
2. Устаревшее определение типа файла через `exif_imagetype()` и fallback на Video
3. Нет поддержки аудиофайлов
4. Метаданные могут теряться при копировании (используется `copy()` без сохранения метаданных)
5. Структура директорий: `{type}/{year}/{year-month}` - не соответствует новым требованиям
6. Нет разделения ответственности (Sorter делает слишком много)
7. Хардкод типов файлов в классах
8. PHP 8.0+ (можно использовать PHP 8.4 возможности)

### Новые требования:
- Поддержка **всех типов файлов** (изображения, видео, аудио, документы и т.д.)
- Новая структура: `{year}/{month}/{images|audio|video|other}/`
- Сохранение **всех метаданных** (права доступа, временные метки, расширенные атрибуты)
- Использование современных паттернов PHP 8.4
- Надежное определение MIME типов
- Верификация целостности файлов (хеширование)
- Идемпотентность операций
- Масштабируемость и расширяемость

---

## Вариант рефакторинга: Hexagonal Architecture + Pipeline Processing

### Концепция:
Комбинированный подход, объединяющий:
- **Hexagonal Architecture** для изоляции доменной логики и легкости замены адаптеров
- **Pipeline Processing** через Symfony Messenger для параллельной обработки и масштабирования
- **Policy Pattern** для гибких правил организации файлов
- **CQRS** для четкого разделения команд и запросов

### Архитектурные принципы:

**Одна Symfony Console-команда** проходит по исходной директории и публикует события `FileDiscovered`.

**Воркеры по очередям** (local/Redis/RabbitMQ) параллельно:
- Классифицируют файлы через адаптеры (MIME/EXIF/ID3)
- Извлекают метаданные через порты
- Применяют политики организации
- Перемещают файлы с сохранением метаданных

**Доменная модель** (`MediaAsset`, `MediaMeta`, `OrganizerPolicy`) изолирована через порты:
- Адаптеры для сканирования (Finder)
- Адаптеры для детекции (MIME/EXIF/ID3)
- Адаптеры для хранения (Flysystem - локально/облако)
- Адаптеры для логирования (Monolog)

**Правила организации** (по дате/типу/проекту) — как политики (Policy Pattern)

### Паттерны:
- **Hexagonal Architecture** (Ports/Adapters) — изоляция домена
- **Pipeline** (конвейер обработки через Messenger)
- **CQRS** (команды: `IngestFile`, `OrganizeFile`; события: `FileOrganized`)
- **Policy/Strategy** для раскладки по каталогам
- **Retry/Dead Letter Queue** для устойчивости
- **Queue-based processing** для масштабирования
- **Builder** для DTO метаданных
- **Plugin System** для расширяемости

---

## Технологический стек

### Зависимости:

```json
{
  "require": {
    "php": "^8.4",
    "symfony/console": "^7.0",
    "symfony/messenger": "^7.0",
    "symfony/finder": "^7.0",
    "league/flysystem": "^3.0",
    "league/flysystem-bundle": "^3.0",
    "league/mime-type-detection": "^2.0",
    "james-heinrich/getid3": "^2.0",
    "monolog/monolog": "^3.0",
    "nesbot/carbon": "^3.0",
    "doctrine/dbal": "^3.0"
  }
}
```

### Инструменты:

**Core:**
- `symfony/console` — консольные команды
- `symfony/messenger` — асинхронная обработка и ретраи
- `symfony/finder` — сканирование дерева файлов

**Детекция и метаданные:**
- `league/mime-type-detection` — надёжное определение MIME (finfo + карта расширений)
- `james-heinrich/getid3` — извлечение метаданных аудио/видео
- `exif` (встроенный) — для изображений

**Хранилище:**
- `league/flysystem` — абстракция файловой системы (локально/облако)
- `league/flysystem-bundle` — декларативная конфигурация Flysystem
- `doctrine/dbal` — хранилище метаданных (SQLite/PostgreSQL) для идемпотентности

**Логирование:**
- `monolog/monolog` — структурированное логирование

**Очереди (опционально):**
- Redis/RabbitMQ через Symfony Messenger транспорты

---

## Архитектура проекта

```
├── src/
│   ├── Command/
│   │   ├── ScanFilesCommand.php          # Сканирование и публикация в очередь
│   │   └── ConsumeFilesCommand.php        # Запуск воркеров
│   │
│   ├── Domain/                            # Изолированная доменная модель
│   │   ├── MediaAsset.php                 # Aggregate Root
│   │   ├── MediaMeta.php                  # Value Object
│   │   ├── FileType.php                   # Enum (image, video, audio, other)
│   │   ├── FileCategory.php               # Enum (images, audio, video, other)
│   │   ├── FilePath.php                   # Value Object
│   │   ├── FileHash.php                   # Value Object
│   │   ├── MediaDate.php                  # Value Object
│   │   │
│   │   └── Policies/                      # Policy Pattern
│   │       ├── OrganizerPolicy.php        # Interface
│   │       ├── DateTypePolicy.php         # {year}/{month}/{category}/
│   │       ├── DatePolicy.php             # {year}/{month}/
│   │       └── TypePolicy.php             # {category}/{year}/{month}/
│   │
│   ├── Application/                       # Use Cases и CQRS
│   │   ├── Command/
│   │   │   ├── IngestFileCommand.php      # Команда: обработать файл
│   │   │   └── OrganizeFileCommand.php    # Команда: организовать файл
│   │   │
│   │   ├── Query/
│   │   │   └── GetFileMetadataQuery.php   # Запрос: получить метаданные
│   │   │
│   │   └── Handler/
│   │       ├── IngestFileHandler.php      # Обработчик команды
│   │       └── OrganizeFileHandler.php    # Обработчик команды
│   │
│   ├── Ports/                              # Интерфейсы (Hexagonal)
│   │   ├── ScannerPort.php                # Порт для сканирования файлов
│   │   ├── MetadataExtractorPort.php      # Порт для извлечения метаданных
│   │   ├── FilesystemPort.php             # Порт для файловой системы
│   │   ├── LoggerPort.php                 # Порт для логирования
│   │   └── RepositoryPort.php             # Порт для хранения метаданных
│   │
│   ├── Adapters/                           # Реализации портов
│   │   ├── Scanner/
│   │   │   └── SymfonyFinderAdapter.php   # Адаптер: Symfony Finder
│   │   │
│   │   ├── Metadata/
│   │   │   ├── ExifAdapter.php            # Адаптер: EXIF для изображений
│   │   │   ├── GetId3Adapter.php          # Адаптер: getID3 для аудио/видео
│   │   │   └── GenericAdapter.php         # Адаптер: mtime для остальных
│   │   │
│   │   ├── Filesystem/
│   │   │   ├── LocalFilesystemAdapter.php  # Адаптер: локальная ФС
│   │   │   ├── S3FilesystemAdapter.php    # Адаптер: AWS S3 (опционально)
│   │   │   └── FtpFilesystemAdapter.php   # Адаптер: FTP (опционально)
│   │   │
│   │   ├── Logger/
│   │   │   └── MonologAdapter.php         # Адаптер: Monolog
│   │   │
│   │   └── Repository/
│   │       └── DatabaseMetadataRepository.php  # SQLite/PostgreSQL
│   │
│   ├── Event/                              # Доменные события
│   │   ├── FileDiscovered.php             # Событие: файл обнаружен
│   │   ├── FileProcessed.php              # Событие: файл обработан
│   │   ├── FileOrganized.php              # Событие: файл организован
│   │   └── FileError.php                  # Событие: ошибка обработки
│   │
│   ├── Infrastructure/
│   │   ├── Messenger/
│   │   │   ├── FileDiscoveredHandler.php  # Обработчик события
│   │   │   └── RetryPolicy.php            # Политика повторов
│   │   │
│   │   ├── Filesystem/
│   │   │   └── MetadataPreservingCopier.php  # Копирование с метаданными
│   │   │
│   │   └── Config/
│   │       └── FilesystemConfig.php       # Конфигурация Flysystem
│   │
│   └── Builder/
│       └── MediaMetaBuilder.php           # Builder для метаданных
│
├── config/
│   ├── packages/
│   │   ├── messenger.yaml                 # Конфигурация очередей
│   │   └── flysystem.yaml                 # Конфигурация хранилищ
│   └── services.yaml                      # DI контейнер
│
└── tests/
    ├── Unit/
    ├── Integration/
    └── E2E/
```

---

## Ключевые детали реализации

### 1. Детекция даты (приоритеты):

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

### 2. Перемещение без потери метаданных:

```php
// Псевдокод процесса копирования
1. Вычисление SHA-256 хеша исходного файла
2. Бинарное копирование файла
3. Верификация хеша копии (сравнение с исходным)
4. Восстановление метаданных:
   - chmod() - права доступа
   - touch() - временные метки (mtime, atime)
   - xattr (если поддерживается) - расширенные атрибуты
5. Удаление источника только после успешной верификации
```

### 3. Идемпотентность:

- Ключ в БД: `(absolute_path, size, hash)`
- Проверка перед обработкой: если файл уже обработан — пропуск
- Дедупликация по хешу содержимого

### 4. Разрешение коллизий:

- Если файл существует: `{original-name}-{hash-prefix}.{ext}`
- Хеш используется для дедупликации и проверки целостности

### 5. Политики организации:

```php
interface OrganizerPolicy {
    public function organize(MediaAsset $asset): FilePath;
}

// DateTypePolicy: 2025/11/images/file.jpg
// DatePolicy: 2025/11/file.jpg (альтернатива)
// TypeDatePolicy: images/2025/11/file.jpg (альтернатива)
```

### 6. Pipeline обработки:

```
ScanFilesCommand
    ↓
FileDiscovered (event)
    ↓
Messenger Queue (async)
    ↓
IngestFileHandler
    ├─→ Detect MIME type
    ├─→ Extract metadata
    └─→ Calculate hash
    ↓
FileProcessed (event)
    ↓
OrganizeFileHandler
    ├─→ Apply OrganizerPolicy
    ├─→ Copy with metadata preservation
    ├─→ Verify hash
    └─→ Delete source
    ↓
FileOrganized (event)
```

### 7. Retry механизм:

- Автоматические повторы при ошибках (configurable)
- Dead Letter Queue для проблемных файлов
- Уведомления о критических ошибках
- Логирование всех попыток

### 8. Горизонтальное масштабирование:

- Несколько воркеров обрабатывают очередь параллельно
- Настройка количества воркеров через конфигурацию
- Балансировка нагрузки через очередь

---

## Преимущества комбинированного подхода:

- ✅ **Масштабируемость** — параллельная обработка через Messenger
- ✅ **Расширяемость** — Hexagonal Architecture позволяет легко менять адаптеры
- ✅ **Устойчивость** — retry, DLQ, идемпотентность
- ✅ **Гибкость** — Policy Pattern для разных правил организации
- ✅ **Тестируемость** — легко мокировать порты
- ✅ **Изоляция** — домен не знает о конкретных реализациях
- ✅ **Облачная готовность** — легко добавить S3/FTP через Flysystem
- ✅ **Подходит для миллионов файлов** — горизонтальное масштабирование

---

## Недостатки:

- ❌ Больше инфраструктуры (очередь, БД)
- ❌ Сложнее отладка (асинхронная обработка)
- ❌ Требует настройки очередей
- ❌ Больше зависимостей
- ❌ Требует понимания Hexagonal Architecture и CQRS

---

## Пример использования:

### Сканирование и публикация в очередь:

```bash
# Сканирование директории и публикация событий
php bin/console scan:files /source/dir --queue=async

# Dry-run режим (без реальной обработки)
php bin/console scan:files /source/dir --dry-run
```

### Запуск воркеров:

```bash
# Локальная очередь (синхронная обработка)
php bin/console messenger:consume async -vv

# Параллельный запуск нескольких воркеров
php bin/console messenger:consume async -vv &
php bin/console messenger:consume async -vv &
```

### Конфигурация политики:

```yaml
# config/services.yaml
parameters:
    organizer.policy: 'date-type'  # date-type, date, type-date
```

---

## Общие лучшие практики

### Сохранение метаданных:

1. **Не переписывать теги** — только читать и перемещать
2. **Восстановление атрибутов:**
   ```php
   // Права доступа
   chmod($destination, fileperms($source));
   
   // Временные метки
   touch($destination, filemtime($source), fileatime($source));
   
   // Расширенные атрибуты (если поддерживается)
   if (function_exists('xattr_get')) {
       // Сохранение xattr
   }
   ```

### Верификация целостности:

- Хеширование содержимого (SHA-256) для идемпотентности
- Дедупликация по хешу
- Проверка после копирования

### Логирование и отладка:

- Dry-run режим (без реального перемещения)
- Rollback механизм (опционально)
- Детальное логирование операций
- Статистика: обработано, перемещено, ошибки, дубликаты

### Конфигурация:

- `.env` файл для параметров
- Валидируемые параметры (исходная/целевая директории, политика даты)
- Правила переименования при коллизиях

### Тестирование:

- **Unit тесты:** детекторы/политики/стратегии
- **Интеграционные тесты:** с фиктивными файлами
- **E2E тесты:** полный цикл обработки через очередь

---

## План миграции

### Этап 1: Подготовка (2-3 дня)
1. Обновить `composer.json` (PHP 8.4, новые зависимости)
2. Создать структуру директорий
3. Настроить Symfony Messenger и Flysystem Bundle
4. Настроить конфигурацию (.env, services.yaml)

### Этап 2: Domain Layer (3-4 дня)
1. Value Objects (FileType, FileCategory, FilePath, FileHash, MediaDate)
2. MediaAsset (Aggregate Root)
3. MediaMeta (Value Object)
4. OrganizerPolicy интерфейс и реализации

### Этап 3: Ports & Adapters (4-5 дней)
1. Определить все порты (интерфейсы)
2. Реализовать адаптеры:
   - SymfonyFinderAdapter (Scanner)
   - ExifAdapter, GetId3Adapter, GenericAdapter (Metadata)
   - LocalFilesystemAdapter (Filesystem)
   - MonologAdapter (Logger)
   - DatabaseMetadataRepository (Repository)

### Этап 4: Application Layer (3-4 дня)
1. Команды (IngestFile, OrganizeFile)
2. Обработчики команд
3. Запросы и обработчики запросов (опционально)

### Этап 5: Infrastructure (2-3 дня)
1. MetadataPreservingCopier (с сохранением всех атрибутов)
2. Конфигурация Messenger (очереди, retry, DLQ)
3. Конфигурация Flysystem

### Этап 6: Events & Handlers (2-3 дня)
1. Доменные события
2. Event handlers через Messenger
3. Retry политики

### Этап 7: Commands (1-2 дня)
1. ScanFilesCommand (сканирование и публикация)
2. ConsumeFilesCommand (запуск воркеров)

### Этап 8: Тестирование (3-4 дня)
1. Unit тесты для всех компонентов
2. Интеграционные тесты с реальными файлами
3. E2E тесты через очередь
4. Dry-run тестирование на реальных данных

### Этап 9: Документация и деплой (1-2 дня)
1. Обновление README
2. Документация API
3. Миграция на новую версию
4. Мониторинг и логирование

**Итого: ~21-28 дней разработки**

---

## Следующие шаги

Готов начать реализацию комбинированного подхода. Могу:

1. ✅ Обновить `composer.json` с нужными зависимостями
2. ✅ Создать полную структуру классов
3. ✅ Реализовать Domain Layer (Value Objects, MediaAsset, Policies)
4. ✅ Реализовать Ports & Adapters
5. ✅ Реализовать Application Layer (CQRS)
6. ✅ Настроить Symfony Messenger и Flysystem
7. ✅ Реализовать сохранение метаданных при копировании
8. ✅ Обновить структуру директорий на `{year}/{month}/{category}/`
9. ✅ Добавить верификацию хешей и дедупликацию
10. ✅ Написать тесты
11. ✅ Обновить документацию

**Начинаем?** 🚀
