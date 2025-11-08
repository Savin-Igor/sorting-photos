# Архитектура проекта и план рефакторинга

## 📋 Текущее состояние проекта

### Описание проекта

Проект представляет собой консольное приложение для сортировки и организации медиафайлов (фото и видео) с двумя основными функционалами:

1. **Локальная сортировка** - рекурсивное сканирование исходной директории, извлечение метаданных, сортировка по датам и перемещение в целевую директорию с опциональным сжатием
2. **Загрузка в Google Photos** - рекурсивное сканирование исходной директории, извлечение метаданных, загрузка в Google Photos с опциональным сжатием

### Ключевые требования

- ✅ **Всегда рекурсивное сканирование** - все файлы из всех поддиректорий обрабатываются
- ✅ **Всегда сортировка по датам** - файлы организуются по дате создания (приоритет: имя файла → EXIF → mtime)
- ✅ **Опциональное сжатие** - можно включить через переменную окружения `COMPRESSION_ENABLED`
- ✅ **Единые переменные окружения** - все команды используют `SOURCE_DIRECTORY` и `DESTINATION_DIRECTORY` (пути контейнера)
- ✅ **Дедупликация** - файлы с одинаковым хешем не копируются повторно
- ✅ **Сохранение метаданных** - при копировании сохраняются все метаданные файлов

---

## 🏗️ Текущая архитектура

### Структура проекта

```
src/
├── Application/
│   ├── Command/          # Команды (IngestFileCommand, OrganizeFileCommand)
│   ├── Handler/          # Обработчики команд (IngestFileHandler, OrganizeFileHandler)
│   └── Service/
│       └── GooglePhotos/ # Сервисы для Google Photos
├── Domain/
│   ├── Event/            # Доменные события
│   ├── Policies/         # Политики организации (OrganizerPolicy)
│   ├── Storage/          # Агрегаты для Google Photos
│   └── ValueObjects/     # Value Objects
├── Infrastructure/
│   ├── Config/           # Конфигурация DI контейнера
│   ├── Filesystem/       # Адаптеры файловой системы
│   ├── Metadata/         # Извлечение метаданных
│   ├── Messenger/        # Обработчики событий
│   ├── Statistics/       # Статистика через Redis
│   └── Storage/          # Репозитории и адаптеры хранилищ
├── Ports/                # Интерфейсы (Ports в Hexagonal Architecture)
└── Command/              # Symfony консольные команды
```

### Точки входа

#### 1. `index.php` - Точка входа для локальной сортировки

**Проблемы:**
- ❌ Содержит много бизнес-логики (подсчет размеров, статистика, проверки целостности)
- ❌ Вызывает команду `ScanFilesCommand` напрямую, минуя консольное приложение
- ❌ Дублирует логику, которая должна быть в команде
- ❌ Не соответствует принципам Hexagonal Architecture

**Что делает:**
1. Загружает переменные окружения
2. Создает DI контейнер
3. Подсчитывает размеры директорий
4. Вызывает `ScanFilesCommand` напрямую
5. Выводит статистику и проверяет целостность

#### 2. `bin/console` - Консольное приложение

**Что делает:**
1. Создает DI контейнер
2. Регистрирует все команды из контейнера
3. Запускает Symfony Console Application

**Команды:**
- `scan:files` - сканирование файлов и отправка событий
- `google-photos:upload` - загрузка в Google Photos
- `google-photos:authorize` - OAuth авторизация
- `google-photos:test` - тестирование API
- `test:compression` - тестирование сжатия
- `show:progress` - отображение прогресса
- `metadata:clear` - очистка метаданных
- `messenger:consume` - обработка сообщений из очереди

---

## 🔄 Текущие потоки обработки

### Поток 1: Локальная сортировка

```
index.php
  ↓
ScanFilesCommand (вызывается напрямую)
  ↓
ScannerPort.scan() (рекурсивно)
  ↓
FileDiscovered (событие)
  ↓
Messenger Queue
  ↓
IngestFileHandler
  ├─→ Извлечение метаданных
  ├─→ Определение типа файла
  ├─→ Вычисление хеша
  └─→ Сохранение в репозиторий
  ↓
FileProcessed (событие)
  ↓
OrganizeFileHandler
  ├─→ Проверка дубликатов
  ├─→ Применение OrganizerPolicy (сортировка по датам)
  ├─→ Копирование файла с метаданными
  ├─→ Проверка хеша
  └─→ Удаление исходного файла
  ↓
FileOrganized (событие)
```

**Проблемы:**
- ❌ Сжатие НЕ интегрировано в этот поток (только в Google Photos)
- ❌ `index.php` содержит бизнес-логику, которая должна быть в команде
- ❌ Нет единой команды для запуска всего процесса

### Поток 2: Загрузка в Google Photos

```
google-photos:upload
  ↓
FileScanner.scanAndCreateJobs()
  ├─→ ScannerPort.scan() (рекурсивно)
  ├─→ Извлечение метаданных
  ├─→ Определение типа файла
  ├─→ Вычисление хеша
  └─→ Создание UploadJob
  ↓
UploadOrchestrator.run()
  ├─→ Получение блокировки
  ├─→ Проверка квот
  ├─→ Обработка незавершенных батчей
  ├─→ Сбор новых батчей
  └─→ Загрузка файлов
      ↓
ResumableUploadService.uploadFile()
  ├─→ Сжатие (если включено)
  ├─→ Инициализация resumable сессии
  ├─→ Загрузка чанками
  └─→ Получение uploadToken
      ↓
BatchProcessor.processBatch()
  ├─→ Сбор до 50 uploadToken
  └─→ batchCreateMediaItems
```

**Проблемы:**
- ✅ Сжатие интегрировано
- ✅ Рекурсивное сканирование работает
- ✅ Сортировка по датам работает (через creationTime)

---

## ⚠️ Выявленные проблемы

### 1. Архитектурные проблемы

#### Проблема: Две точки входа
- `index.php` - для локальной сортировки
- `bin/console` - для всех команд

**Последствия:**
- Дублирование логики инициализации
- Разные подходы к обработке ошибок
- Сложность поддержки

#### Проблема: Бизнес-логика в `index.php`
- Подсчет размеров директорий
- Статистика и проверки целостности
- Вывод результатов

**Последствия:**
- Нарушение принципов Hexagonal Architecture
- Невозможность переиспользования логики
- Сложность тестирования

#### Проблема: Отсутствие команды для локальной сортировки
- Нет команды `organize:files` или `sort:files`
- Вся логика в `index.php`

**Последствия:**
- Невозможность запуска через `bin/console`
- Несоответствие архитектуре консольного приложения

### 2. Функциональные проблемы

#### Проблема: Сжатие не интегрировано в локальную сортировку
- `ImageCompressor` и `VideoCompressor` используются только в `ResumableUploadService`
- В `OrganizeFileHandler` сжатие отсутствует

**Последствия:**
- Невозможность сжать файлы при локальной сортировке
- Дублирование логики сжатия (если будет добавлено)

#### Проблема: Разные подходы к сканированию
- `ScanFilesCommand` использует `ScannerPort` и отправляет события
- `FileScanner` (Google Photos) использует `ScannerPort` и создает `UploadJob`

**Хорошо:**
- ✅ Оба используют один `ScannerPort` (единый интерфейс)
- ✅ Оба сканируют рекурсивно (через SymfonyFinderAdapter)

**Проблема:**
- Разные подходы к обработке результатов сканирования
- Нет единого сервиса для сканирования и создания задач

### 3. Проблемы конфигурации

#### Проблема: Разные способы получения путей
- `index.php` получает пути из контейнера
- Команды получают пути из аргументов или переменных окружения

**Хорошо:**
- ✅ Все используют `SOURCE_DIRECTORY` и `DESTINATION_DIRECTORY` (пути контейнера)
- ✅ Переменные окружения единообразны

---

## ✅ Что работает хорошо

1. **Единый ScannerPort** - оба функционала используют один интерфейс для сканирования
2. **Рекурсивное сканирование** - реализовано через `SymfonyFinderAdapter`
3. **Сортировка по датам** - реализована через `OrganizerPolicy`
4. **Hexagonal Architecture** - четкое разделение на слои (Domain, Application, Infrastructure)
5. **Единые переменные окружения** - все команды используют одинаковые переменные
6. **Дедупликация** - работает в обоих потоках
7. **Сохранение метаданных** - реализовано через `MetadataPreservingCopier`

---

## 🎯 Предложения по рефакторингу

### Цель рефакторинга

1. **Единая точка входа** - только `bin/console`
2. **Единая архитектура** - все команды работают одинаково
3. **Единая логика** - сканирование, сортировка, сжатие работают везде одинаково
4. **Соответствие Hexagonal Architecture** - бизнес-логика в Application/Domain слоях

### План рефакторинга

#### Этап 1: Создание команды для локальной сортировки

**Создать команду `organize:files`:**

```php
#[AsCommand(
    name: 'organize:files',
    description: 'Organize files from source to destination directory with date-based sorting'
)]
final class OrganizeFilesCommand extends Command
{
    // Использует те же сервисы, что и сейчас:
    // - ScannerPort для сканирования
    // - MessageBus для обработки событий
    // - StatisticsService для статистики
    // - DirectorySizeCalculator для подсчета размеров
}
```

**Что должна делать команда:**
1. Получать `SOURCE_DIRECTORY` и `DESTINATION_DIRECTORY` из переменных окружения
2. Подсчитывать размеры директорий (до и после)
3. Вызывать `ScanFilesCommand` или напрямую использовать `ScannerPort`
4. Выводить статистику и проверять целостность
5. Поддерживать опции `--dry-run`, `--recursive` (всегда true)

#### Этап 2: Интеграция сжатия в локальную сортировку

**Модифицировать `OrganizeFileHandler`:**

```php
public function handle(OrganizeFileCommand $command): FilePath
{
    $asset = $command->getAsset();
    $sourcePath = $asset->getSourcePath();
    
    // 1. Сжать файл если нужно (НОВОЕ)
    $compressedPath = $this->compressIfNeeded($sourcePath, $asset);
    
    // 2. Применить политику организации
    $targetPath = $this->policy->organize($asset);
    
    // 3. Копировать сжатый файл (если был сжат) или оригинал
    $this->filesystem->copyWithMetadata($compressedPath, $finalTargetPath);
    
    // 4. Удалить временный файл если был создан
    if ($compressedPath !== $sourcePath && str_starts_with($compressedPath, sys_get_temp_dir())) {
        unlink($compressedPath);
    }
    
    // 5. Удалить исходный файл
    $this->filesystem->delete($sourcePath);
}
```

**Создать сервис `FileCompressionService`:**

```php
final class FileCompressionService
{
    public function compressIfNeeded(
        FilePath $filePath,
        MediaAsset $asset
    ): FilePath {
        // Использует ImageCompressor и VideoCompressor
        // Возвращает путь к сжатому файлу или оригиналу
    }
}
```

#### Этап 3: Удаление `index.php`

**После создания команды `organize:files`:**
- Удалить `index.php`
- Обновить `Makefile` - заменить `make run` на `make organize-files`
- Обновить документацию

#### Этап 4: Унификация логики сканирования

**Создать единый сервис `FileScanningService`:**

```php
final class FileScanningService
{
    /**
     * Сканирует директорию и создает задачи для обработки
     * 
     * @param string $sourcePath Путь к исходной директории
     * @param string $mode 'local' или 'google-photos'
     * @return int Количество созданных задач
     */
    public function scanAndCreateTasks(string $sourcePath, string $mode): int
    {
        // Единая логика сканирования
        // Разные обработчики в зависимости от режима
    }
}
```

**Или оставить как есть:**
- `ScanFilesCommand` для локальной сортировки (отправляет события)
- `FileScanner` для Google Photos (создает UploadJob)

**Рекомендация:** Оставить как есть, так как логика уже разделена правильно через события и команды.

#### Этап 5: Улучшение архитектуры команд

**Принципы:**
1. Команды должны быть тонкими (thin) - только координация
2. Вся бизнес-логика в Application слое
3. Команды используют сервисы из контейнера
4. Единые переменные окружения для всех команд

**Структура команды:**

```php
final class OrganizeFilesCommand extends Command
{
    public function __construct(
        private readonly FileOrganizingService $organizingService,
        private readonly DirectorySizeCalculator $sizeCalculator,
        private readonly StatisticsService $statistics,
        private readonly LoggerPort $logger,
    ) {}
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // 1. Получить параметры из окружения или аргументов
        // 2. Вызвать сервис для выполнения работы
        // 3. Вывести результаты
    }
}
```

---

## 📐 Предлагаемая архитектура после рефакторинга

### Единая точка входа

```
bin/console
  ├── organize:files          # Локальная сортировка (замена index.php)
  ├── google-photos:upload    # Загрузка в Google Photos
  ├── test:compression        # Тестирование сжатия
  └── ... (остальные команды)
```

### Единый поток обработки (локальная сортировка)

```
organize:files
  ↓
FileOrganizingService.organize()
  ├─→ Подсчет размеров (до)
  ├─→ ScannerPort.scan() (рекурсивно)
  ├─→ FileDiscovered → IngestFileHandler
  ├─→ FileProcessed → OrganizeFileHandler
  │   ├─→ FileCompressionService.compressIfNeeded() (НОВОЕ)
  │   ├─→ OrganizerPolicy.organize() (сортировка по датам)
  │   └─→ FilesystemPort.copyWithMetadata()
  ├─→ Подсчет размеров (после)
  └─→ Проверка целостности
```

### Единый поток обработки (Google Photos)

```
google-photos:upload
  ↓
FileScanner.scanAndCreateJobs()
  ├─→ ScannerPort.scan() (рекурсивно)
  └─→ Создание UploadJob
  ↓
UploadOrchestrator.run()
  ├─→ ResumableUploadService.uploadFile()
  │   └─→ ImageCompressor/VideoCompressor (сжатие)
  └─→ BatchProcessor.processBatch()
```

### Единые компоненты

1. **ScannerPort** - единый интерфейс для сканирования (уже есть ✅)
2. **MetadataExtractorPort** - единый интерфейс для метаданных (уже есть ✅)
3. **FileCompressionService** - единый сервис для сжатия (НОВОЕ)
4. **OrganizerPolicy** - единая политика сортировки (уже есть ✅)

---

## 🔧 Детальный план рефакторинга

### Шаг 1: Создание `FileCompressionService`

**Цель:** Единый сервис для сжатия файлов

**Расположение:** `src/Application/Service/FileCompressionService.php`

**Зависимости:**
- `ImageCompressor`
- `VideoCompressor`
- `MetadataExtractorPort` (для определения типа файла)

**Методы:**
```php
public function compressIfNeeded(FilePath $filePath, MediaAsset $asset): FilePath
{
    // Определить тип файла из MediaAsset
    // Вызвать соответствующий компрессор
    // Вернуть путь к сжатому файлу или оригиналу
}
```

### Шаг 2: Модификация `OrganizeFileHandler`

**Изменения:**
1. Добавить `FileCompressionService` в конструктор
2. Вызывать сжатие перед копированием
3. Обрабатывать временные файлы (удалять после копирования)

**Важно:** Сжатие должно происходить ПЕРЕД применением политики организации, чтобы хеш вычислялся от сжатого файла.

### Шаг 3: Создание команды `organize:files`

**Расположение:** `src/Command/OrganizeFilesCommand.php`

**Зависимости:**
- `ScannerPort`
- `MessageBusInterface`
- `FileOrganizingService` (НОВЫЙ сервис-оркестратор)
- `DirectorySizeCalculator`
- `StatisticsService`
- `LoggerPort`

**Опции:**
- `--source` - исходная директория (опционально, из окружения)
- `--destination` - целевая директория (опционально, из окружения)
- `--dry-run` - режим без изменений
- `--no-compression` - отключить сжатие (даже если включено в .env)

**Логика:**
1. Получить пути из аргументов или переменных окружения
2. Подсчитать размеры директорий (до)
3. Вызвать `FileOrganizingService.organize()`
4. Подсчитать размеры директорий (после)
5. Вывести статистику и проверить целостность

### Шаг 4: Создание `FileOrganizingService`

**Цель:** Оркестратор для процесса организации файлов

**Расположение:** `src/Application/Service/FileOrganizingService.php`

**Зависимости:**
- `ScannerPort`
- `MessageBusInterface`
- `StatisticsService`
- `LoggerPort`

**Методы:**
```php
public function organize(
    string $sourceDirectory,
    string $destinationDirectory,
    bool $dryRun = false
): OrganizingResult
{
    // 1. Сканировать файлы
    // 2. Отправить события FileDiscovered
    // 3. Дождаться обработки (синхронно или асинхронно)
    // 4. Вернуть результат
}
```

**Альтернатива:** Использовать существующий `ScanFilesCommand` через `CommandRunner` или оставить как есть (события обрабатываются автоматически).

### Шаг 5: Обновление `Makefile`

**Изменения:**
- Заменить `make run` на `make organize-files`
- Обновить документацию команд

### Шаг 6: Удаление `index.php`

**После проверки:**
- Убедиться, что команда `organize:files` работает идентично
- Удалить `index.php`
- Обновить документацию

---

## 📝 Рекомендации по реализации

### Приоритет 1: Критично

1. ✅ **Создать команду `organize:files`** - единая точка входа для локальной сортировки
2. ✅ **Интегрировать сжатие в `OrganizeFileHandler`** - единая логика сжатия
3. ✅ **Удалить `index.php`** - единая точка входа через `bin/console`

### Приоритет 2: Важно

4. ✅ **Создать `FileCompressionService`** - единый сервис для сжатия
5. ✅ **Унифицировать получение путей** - все команды используют одинаковый способ

### Приоритет 3: Улучшения

6. ✅ **Создать `FileOrganizingService`** - оркестратор для организации файлов
7. ✅ **Улучшить обработку ошибок** - единый подход во всех командах
8. ✅ **Добавить больше тестов** - покрытие тестами новой архитектуры

---

## 🎨 Архитектурные принципы

### Hexagonal Architecture (Ports & Adapters)

**Domain Layer (Ядро):**
- Value Objects: `FilePath`, `FileHash`, `MediaDate`, `MediaMeta`
- Entities: `MediaAsset`
- Policies: `OrganizerPolicy`
- Events: `FileDiscovered`, `FileProcessed`, `FileOrganized`

**Application Layer (Use Cases):**
- Commands: `IngestFileCommand`, `OrganizeFileCommand`
- Handlers: `IngestFileHandler`, `OrganizeFileHandler`
- Services: `FileOrganizingService`, `FileCompressionService`, `UploadOrchestrator`

**Infrastructure Layer (Адаптеры):**
- Adapters: `SymfonyFinderAdapter`, `LocalStorageAdapter`, `GooglePhotosApiClient`
- Repositories: `DatabaseMetadataRepository`, `DatabaseUploadJobRepository`
- Compressors: `ImageCompressor`, `VideoCompressor`

**Ports (Интерфейсы):**
- `ScannerPort`, `FilesystemPort`, `MetadataExtractorPort`, `LoggerPort`

### Принципы проектирования

1. **Single Responsibility** - каждый класс отвечает за одну задачу
2. **Dependency Inversion** - зависимости через интерфейсы (Ports)
3. **Open/Closed** - открыт для расширения, закрыт для модификации
4. **Immutability** - Value Objects и Events неизменяемы
5. **Idempotency** - операции можно повторять безопасно

---

## 📊 Сравнение: До и После

### До рефакторинга

```
index.php (бизнес-логика)
  ↓
ScanFilesCommand (вызывается напрямую)
  ↓
События → Handlers
  ↓
OrganizeFileHandler (БЕЗ сжатия)
```

**Проблемы:**
- Две точки входа
- Бизнес-логика в index.php
- Нет сжатия в локальной сортировке
- Разные подходы к обработке

### После рефакторинга

```
bin/console organize:files
  ↓
OrganizeFilesCommand (тонкая команда)
  ↓
FileOrganizingService (оркестратор)
  ↓
ScanFilesCommand или ScannerPort
  ↓
События → Handlers
  ↓
OrganizeFileHandler (С сжатием через FileCompressionService)
```

**Преимущества:**
- ✅ Единая точка входа
- ✅ Бизнес-логика в Application слое
- ✅ Сжатие работает везде
- ✅ Единый подход к обработке

---

## 🔍 Детальный анализ компонентов

### ScannerPort (✅ Хорошо)

**Реализация:** `SymfonyFinderAdapter`

**Особенности:**
- ✅ Рекурсивное сканирование по умолчанию
- ✅ Игнорирует скрытые файлы и VCS
- ✅ Возвращает `FilePath` Value Objects
- ✅ Используется в обоих функционалах

**Рекомендация:** Оставить как есть, работает отлично.

### OrganizerPolicy (✅ Хорошо)

**Реализация:** `DateTypePolicy`, `DatePolicy`, `TypeDatePolicy`

**Особенности:**
- ✅ Всегда сортирует по датам
- ✅ Гибкая политика через переменную `ORGANIZER_POLICY`
- ✅ Используется только в локальной сортировке

**Рекомендация:** Оставить как есть, работает отлично.

### Compression (⚠️ Требует доработки)

**Текущее состояние:**
- ✅ `ImageCompressor` и `VideoCompressor` реализованы
- ✅ Настраиваются через переменные окружения
- ✅ Используются в `ResumableUploadService`
- ❌ НЕ используются в `OrganizeFileHandler`

**Рекомендация:** 
1. Создать `FileCompressionService`
2. Интегрировать в `OrganizeFileHandler`
3. Использовать в обоих потоках

### Metadata Extraction (✅ Хорошо)

**Реализация:** `MetadataExtractorChain`

**Особенности:**
- ✅ Единый интерфейс `MetadataExtractorPort`
- ✅ Цепочка адаптеров (EXIF, GetID3, Filename)
- ✅ Приоритет: имя файла → EXIF → mtime
- ✅ Используется в обоих потоках

**Рекомендация:** Оставить как есть, работает отлично.

---

## 📋 Чеклист рефакторинга

### Фаза 1: Подготовка

- [ ] Создать `FileCompressionService`
- [ ] Добавить тесты для `FileCompressionService`
- [ ] Создать `FileOrganizingService` (опционально)

### Фаза 2: Интеграция сжатия

- [ ] Модифицировать `OrganizeFileHandler` для использования сжатия
- [ ] Добавить `FileCompressionService` в DI контейнер
- [ ] Протестировать сжатие в локальной сортировке

### Фаза 3: Создание команды

- [ ] Создать команду `organize:files`
- [ ] Перенести логику из `index.php` в команду
- [ ] Добавить опции команды
- [ ] Протестировать команду

### Фаза 4: Миграция

- [ ] Обновить `Makefile` (заменить `make run` на `make organize-files`)
- [ ] Обновить документацию
- [ ] Протестировать полный цикл
- [ ] Удалить `index.php`

### Фаза 5: Улучшения

- [ ] Унифицировать обработку ошибок
- [ ] Добавить больше тестов
- [ ] Обновить документацию архитектуры

---

## 🎯 Итоговые рекомендации

### Что сделать обязательно:

1. **Создать команду `organize:files`** - критично для единой архитектуры
2. **Интегрировать сжатие в локальную сортировку** - для единой функциональности
3. **Удалить `index.php`** - для единой точки входа

### Что можно улучшить:

4. **Создать `FileCompressionService`** - для переиспользования логики
5. **Создать `FileOrganizingService`** - для лучшей организации кода
6. **Улучшить обработку ошибок** - для лучшей надежности

### Что оставить как есть:

- ✅ `ScannerPort` - работает отлично
- ✅ `OrganizerPolicy` - работает отлично
- ✅ `MetadataExtractorPort` - работает отлично
- ✅ Архитектура Google Photos - работает отлично

---

## 📚 Документация

После рефакторинга необходимо обновить:

1. `README.md` - описание команд
2. `docs/ARCHITECTURE.md` - архитектура проекта (НОВЫЙ файл)
3. `docs/USAGE.md` - руководство пользователя (НОВЫЙ файл)
4. `Makefile` - обновить команды

---

## ⚠️ Важные замечания

1. **Рекурсивное сканирование** - уже реализовано через `SymfonyFinderAdapter`, всегда рекурсивно ✅
2. **Сортировка по датам** - уже реализована через `OrganizerPolicy`, всегда по датам ✅
3. **Переменные окружения** - уже единообразны, все используют `SOURCE_DIRECTORY` и `DESTINATION_DIRECTORY` ✅
4. **Сжатие** - нужно интегрировать в локальную сортировку ⚠️
5. **Единая точка входа** - нужно создать команду `organize:files` и удалить `index.php` ⚠️

---

## 🚀 План действий

### Неделя 1: Подготовка

1. Создать `FileCompressionService`
2. Добавить тесты
3. Модифицировать `OrganizeFileHandler`

### Неделя 2: Команда

1. Создать команду `organize:files`
2. Перенести логику из `index.php`
3. Протестировать

### Неделя 3: Миграция

1. Обновить `Makefile`
2. Обновить документацию
3. Удалить `index.php`

### Неделя 4: Улучшения

1. Рефакторинг и оптимизация
2. Дополнительные тесты
3. Финальная документация

---

## 📝 Заключение

Проект имеет хорошую архитектурную основу (Hexagonal Architecture), но требует рефакторинга для:

1. **Единой точки входа** - все через `bin/console`
2. **Единой логики сжатия** - работает везде одинаково
3. **Единой архитектуры команд** - все команды работают одинаково

Основные изменения:
- Создать команду `organize:files`
- Интегрировать сжатие в `OrganizeFileHandler`
- Удалить `index.php`

Остальное уже работает хорошо и требует только небольших улучшений.

