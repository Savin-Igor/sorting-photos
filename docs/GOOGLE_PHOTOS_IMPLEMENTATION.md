# Реализация модуля массовой загрузки в Google Photos

## Статус реализации

✅ **Полностью реализовано**

## Созданные компоненты

### Domain Layer (Доменный слой)

#### Value Objects
- `UploadJobId` - идентификатор задачи загрузки
- `UploadBatchId` - идентификатор батча
- `ResumableSession` - сессия возобновляемой загрузки
- `BatchItem` - элемент батча

#### Enums
- `UploadState` - состояния загрузки файла (PENDING, UPLOADING, UPLOADED, IN_BATCH, COMPLETED, PAUSED, FAILED)
- `BatchState` - состояния батча (COLLECTING, READY, PROCESSING, COMPLETED, PAUSED, FAILED)

#### Aggregate Roots
- `UploadJob` - агрегат для файла с бизнес-логикой переходов состояний
- `UploadBatch` - агрегат для батча с логикой обработки

#### Domain Events
- `UploadInitiated` - начало загрузки
- `UploadProgressed` - прогресс загрузки
- `UploadCompleted` - завершение загрузки
- `UploadPausedByQuota` - пауза из-за квоты
- `BatchCreated` - создание батча
- `BatchCompleted` - завершение батча

### Application Layer (Слой приложения)

#### Сервисы
- `UploadOrchestrator` - главный координатор (синхронный цикл)
- `FileScanner` - сканирование файлов и создание Jobs
- `ResumableUploadService` - загрузка файлов чанками
- `BatchCollector` - сбор батчей до 50 файлов
- `BatchProcessor` - обработка батчей с детальной обработкой ошибок
- `QuotaManager` - управление квотами API
- `DistributedLockManager` - блокировки для предотвращения параллельного запуска
- `SessionExpirationChecker` - проверка и переинициализация истекших сессий

#### Исключения
- `QuotaExceededException` - исчерпание квот
- `SessionExpiredException` - истечение resumable сессии

### Infrastructure Layer (Инфраструктурный слой)

#### Repositories
- `DatabaseUploadJobRepository` - репозиторий для UploadJob
- `DatabaseUploadBatchRepository` - репозиторий для UploadBatch

#### API Client
- `GooglePhotosApiClient` - HTTP клиент для Google Photos API
  - `initiateResumableUpload()` - инициализация сессии
  - `uploadChunk()` - загрузка чанка
  - `completeUpload()` - завершение загрузки и получение токена
  - `queryUploadStatus()` - запрос статуса загрузки
  - `batchCreateMediaItems()` - пакетное создание медиа-элементов

#### Compressors
- `ImageCompressor` - сжатие изображений (JPEG до 75 МП, PNG до 200 МП)
- `VideoCompressor` - сжатие видео (до 10 ГБ, через ffmpeg)

#### Tracking
- `QuotaTracker` - отслеживание квот в БД
- `QuotaStatus` - статус квот

#### Database
- `DatabaseSchemaInitializer` - инициализация схемы БД

### Ports (Интерфейсы)

- `UploadJobRepositoryPort` - интерфейс репозитория Jobs
- `UploadBatchRepositoryPort` - интерфейс репозитория Batches
- `GooglePhotosApiClientPort` - интерфейс API клиента

### Commands (Команды)

- `UploadToGooglePhotosCommand` - консольная команда для запуска загрузки
  - `--source` - директория для сканирования
  - `--scan-only` - только сканирование без загрузки
  - `--init-schema` - инициализация схемы БД

## Структура базы данных

### Таблица: `google_photos_upload_jobs`
- `id` - идентификатор задачи
- `file_path` - путь к файлу
- `file_size` - размер файла
- `file_hash` - SHA-256 hash (для дедупликации)
- `mime_type` - MIME тип
- `is_video` - флаг видео
- `state` - состояние загрузки
- `resumable_session_uri` - URI сессии
- `uploaded_bytes` - загружено байт
- `upload_token` - токен загрузки
- `batch_id` - ID батча
- `creation_time` - дата съемки
- `retry_count` - счетчик повторов
- `last_error` - последняя ошибка
- `last_known_uploaded_bytes` - последний известный прогресс
- `session_expiration_count` - счетчик истечений сессий

### Таблица: `google_photos_upload_batches`
- `id` - идентификатор батча
- `state` - состояние батча
- `current_index` - текущий индекс (где остановились)
- `total_items` - всего элементов
- `total_size` - суммарный размер
- `items_json` - JSON массив элементов
- `error_message` - сообщение об ошибке
- `quota_reset_time` - время сброса квоты

### Таблица: `google_photos_quota_status`
- `date` - дата (PRIMARY KEY)
- `requests_used` - использовано запросов
- `requests_limit` - лимит запросов (10,000)
- `bytes_used` - использовано байт
- `bytes_limit` - лимит байт (75 ГБ)

### Таблица: `distributed_locks`
- `lock_name` - имя блокировки (PRIMARY KEY)
- `process_id` - ID процесса
- `acquired_at` - время получения
- `expires_at` - время истечения

## Конфигурация

### Переменные окружения

```bash
GOOGLE_PHOTOS_ACCESS_TOKEN=your_access_token
GOOGLE_PHOTOS_ALBUM_ID=optional_album_id
```

### Services.yaml

Все сервисы автоматически регистрируются через autowiring. Добавлены явные конфигурации для:
- Репозиториев
- API клиента
- HTTP клиента (Guzzle)
- Compressors
- QuotaTracker

## Алгоритм работы

1. **Сканирование**: `FileScanner` сканирует директорию, создает `UploadJob` для каждого файла
2. **Загрузка**: `ResumableUploadService` загружает файл чанками через Resumable Upload
3. **Сбор батча**: `BatchCollector` собирает до 50 готовых токенов в `UploadBatch`
4. **Обработка батча**: `BatchProcessor` отправляет батч через `batchCreateMediaItems`
5. **Обработка ошибок**: Детальная обработка каждого элемента батча, повтор для неудачных

## Особенности реализации

### Синхронность
- Строго последовательная обработка (один файл/батч за раз)
- Блокировка через `DistributedLockManager` предотвращает параллельный запуск

### Возобновляемость
- Checkpoint каждые 10 МБ при загрузке
- Сохранение состояния после каждого шага
- Автоматическое возобновление с места остановки

### Обработка квот
- Автоматическая пауза при ошибке 429
- Запись времени сброса квоты
- Автоматическое возобновление после сброса

### Истечение сессий
- Отслеживание срока действия resumable URI (7 дней)
- Автоматическая проверка перед использованием
- Переинициализация при истечении

### Частичные ошибки
- Детальный парсинг ответа `batchCreateMediaItems`
- Индивидуальная обработка каждого элемента
- Повтор для неудачных элементов

## Интеграция

Модуль полностью интегрирован с существующей системой:
- Использует существующие `MetadataExtractorPort` для извлечения дат
- Использует существующий `ScannerPort` для сканирования
- Использует существующий `LoggerPort` для логирования
- Использует существующий `MessageBusInterface` для событий
- Использует существующую БД через Doctrine DBAL

## Зависимости

Добавлены в `composer.json`:
- `guzzlehttp/guzzle` - HTTP клиент
- `guzzlehttp/psr7` - PSR-7 интерфейсы

## Тестирование

Для тестирования необходимо:
1. Установить зависимости: `composer install`
2. Настроить переменные окружения
3. Инициализировать БД: `php bin/console google-photos:upload --init-schema`
4. Запустить сканирование: `php bin/console google-photos:upload --source=/path --scan-only`
5. Запустить загрузку: `php bin/console google-photos:upload`

## Следующие шаги

1. Получить OAuth 2.0 токен для Google Photos API
2. Настроить переменные окружения
3. Протестировать на небольшом наборе файлов
4. Запустить массовую загрузку

