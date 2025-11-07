# Предложения по архитектуре абстракции хранилищ

## Текущее состояние

Текущая архитектура использует:
- `FilesystemPort` - интерфейс для работы с файлами (локальная ФС)
- `ScannerPort` - интерфейс для сканирования файлов
- Параметры `source_directory` и `destination_directory` задаются через env переменные
- Используется Flysystem для работы с локальной файловой системой

## Предложения по реализации

### 1. **Двухуровневая абстракция: StoragePort и StorageAdapter**

**Концепция:**
- Создать два интерфейса: `StoragePort` (для чтения) и `DestinationStoragePort` (для записи)
- Разделить ответственность: источник и назначение могут быть разными типами хранилищ
- Использовать атрибуты PHP 8+ для конфигурации и метаданных

**Структура:**
```php
// Порты
interface StoragePort {
    public function scan(string $location): iterable<StorageItem>;
    public function read(StorageItem $item): string;
    public function getMetadata(StorageItem $item): StorageMetadata;
}

interface DestinationStoragePort {
    public function write(StorageItem $item, string $content, StorageMetadata $metadata): bool;
    public function exists(StorageItem $item): bool;
    public function delete(StorageItem $item): bool;
}

// Value Objects
final class StorageItem {
    public function __construct(
        private readonly string $identifier,
        private readonly StorageType $type,
        private readonly string $path
    ) {}
}

enum StorageType {
    case LOCAL;
    case GOOGLE_PHOTOS;
    case DROPBOX;
    case S3;
}
```

**Преимущества:**
- Четкое разделение источника и назначения
- Типобезопасность через enum
- Легко расширяется новыми хранилищами
- Можно использовать один и тот же адаптер для источника и назначения

---

### 2. **Конфигурация через атрибуты и DI с автоматическим выбором адаптера**

**Концепция:**
- Использовать PHP 8 атрибуты для маркировки адаптеров
- Автоматический выбор адаптера на основе конфигурации через DI
- Конфигурация через DSN-подобные строки (как в Symfony)

**Структура:**
```php
#[StorageAdapter(type: StorageType::GOOGLE_PHOTOS)]
final class GooglePhotosAdapter implements StoragePort, DestinationStoragePort {
    public function __construct(
        private GooglePhotosClient $client,
        #[RateLimit(requests: 100, per: 60)] // Атрибут для rate limiting
        private RateLimiter $rateLimiter
    ) {}
}

// Конфигурация через DSN
// SOURCE_STORAGE_DSN=google-photos://album-id?client_id=xxx&client_secret=yyy
// DESTINATION_STORAGE_DSN=local:///var/data/destination
// или
// SOURCE_STORAGE_DSN=local:///var/data/source
// DESTINATION_STORAGE_DSN=google-photos://album-id?client_id=xxx&client_secret=yyy

final class StorageAdapterFactory {
    public function createFromDsn(string $dsn): StoragePort|DestinationStoragePort {
        $parsed = parse_url($dsn);
        $type = match($parsed['scheme']) {
            'local' => StorageType::LOCAL,
            'google-photos' => StorageType::GOOGLE_PHOTOS,
            'dropbox' => StorageType::DROPBOX,
            's3' => StorageType::S3,
        };
        
        return $this->container->get($this->getAdapterServiceId($type));
    }
}
```

**Преимущества:**
- Минимум хардкода - все через конфигурацию
- Использование современных возможностей PHP (атрибуты, enum, match)
- Гибкая конфигурация через DSN
- Автоматическая регистрация через DI

---

### 3. **Rate Limiting через декоратор и middleware паттерн**

**Концепция:**
- Создать декоратор для rate limiting, который оборачивает любой StoragePort
- Использовать middleware паттерн для цепочки обработки (rate limit → retry → logging)
- Конфигурация rate limits через атрибуты или конфиг

**Структура:**
```php
final class RateLimitedStoragePort implements StoragePort {
    public function __construct(
        private StoragePort $inner,
        private RateLimiter $rateLimiter
    ) {}
    
    public function read(StorageItem $item): string {
        $this->rateLimiter->waitIfNeeded();
        return $this->inner->read($item);
    }
}

// Конфигурация rate limits
final class GooglePhotosRateLimiter implements RateLimiter {
    public function __construct() {
        // Google Photos API: 100 requests per 100 seconds per user
        parent::__construct(100, 100);
    }
}

// В DI конфигурации
services:
    SortingPhotosByDate\Adapters\Storage\GooglePhotos\GooglePhotosAdapter:
        decorates: SortingPhotosByDate\Ports\StoragePort
        arguments:
            $inner: '@.inner'
            $rateLimiter: '@SortingPhotosByDate\Infrastructure\RateLimiting\GooglePhotosRateLimiter'
```

**Преимущества:**
- Разделение ответственности: адаптер не знает о rate limiting
- Легко тестировать (можно мокировать rate limiter)
- Можно применять к любому адаптеру
- Гибкая настройка для разных API

---

### 4. **Единый интерфейс StoragePort с опциональными возможностями**

**Концепция:**
- Один интерфейс `StoragePort` с методами для чтения и записи
- Опциональные интерфейсы для специфичных возможностей (например, `SupportsStreaming`, `SupportsMetadata`)
- Использование trait'ов для общей функциональности

**Структура:**
```php
interface StoragePort {
    // Обязательные методы
    public function read(StorageItem $item): string;
    public function write(StorageItem $item, string $content): bool;
    public function exists(StorageItem $item): bool;
    public function scan(string $location): iterable<StorageItem>;
}

// Опциональные интерфейсы
interface SupportsStreaming {
    public function readStream(StorageItem $item): resource;
    public function writeStream(StorageItem $item, resource $stream): bool;
}

interface SupportsMetadata {
    public function getMetadata(StorageItem $item): StorageMetadata;
    public function setMetadata(StorageItem $item, StorageMetadata $metadata): bool;
}

// Адаптеры реализуют только нужные интерфейсы
final class GooglePhotosAdapter implements StoragePort, SupportsMetadata {
    // ...
}

final class LocalAdapter implements StoragePort, SupportsStreaming, SupportsMetadata {
    // ...
}
```

**Преимущества:**
- Гибкость: каждый адаптер реализует только то, что поддерживает
- Проверка возможностей через `instanceof`
- Единая точка входа для всех операций
- Легко добавлять новые возможности без изменения базового интерфейса

---

### 5. **Конфигурация через Value Objects и Factory с валидацией**

**Концепция:**
- Использовать Value Objects для конфигурации хранилищ
- Factory с валидацией конфигурации
- Конфигурация через структурированные объекты вместо строк

**Структура:**
```php
final readonly class StorageConfiguration {
    public function __construct(
        public readonly StorageType $type,
        public readonly array $credentials,
        public readonly ?RateLimitConfiguration $rateLimit = null,
        public readonly array $options = []
    ) {
        $this->validate();
    }
    
    private function validate(): void {
        match($this->type) {
            StorageType::GOOGLE_PHOTOS => $this->validateGooglePhotos(),
            StorageType::DROPBOX => $this->validateDropbox(),
            // ...
        };
    }
}

final readonly class RateLimitConfiguration {
    public function __construct(
        public readonly int $requests,
        public readonly int $perSeconds,
        public readonly ?int $burst = null
    ) {}
}

final class StorageAdapterFactory {
    public function createSource(StorageConfiguration $config): StoragePort {
        return match($config->type) {
            StorageType::LOCAL => new LocalAdapter($config->options['path']),
            StorageType::GOOGLE_PHOTOS => new GooglePhotosAdapter(
                $this->createGooglePhotosClient($config->credentials),
                $this->createRateLimiter($config->rateLimit)
            ),
            // ...
        };
    }
}

// Использование
$sourceConfig = new StorageConfiguration(
    type: StorageType::GOOGLE_PHOTOS,
    credentials: ['client_id' => '...', 'client_secret' => '...'],
    rateLimit: new RateLimitConfiguration(requests: 100, perSeconds: 100)
);
```

**Преимущества:**
- Типобезопасность на уровне компиляции
- Валидация конфигурации при создании
- Легко расширять новыми параметрами
- Явная структура конфигурации
- Можно использовать в DI через параметры

---

## Рекомендуемый подход (комбинация предложений)

**Рекомендую комбинировать предложения 1, 2 и 3:**

1. **Двухуровневая абстракция** (предложение 1) - для четкого разделения источника и назначения
2. **Конфигурация через DSN** (предложение 2) - для гибкости и минимизации хардкода
3. **Rate Limiting через декоратор** (предложение 3) - для разделения ответственности

**Дополнительно:**
- Использовать **атрибуты PHP 8+** для метаданных адаптеров
- Использовать **enum** для типов хранилищ
- Создать **StorageItem** как Value Object для унификации работы с файлами из разных источников
- Использовать **Factory паттерн** для создания адаптеров на основе конфигурации

**Пример структуры:**

```
src/
├── Domain/
│   ├── Storage/
│   │   ├── StorageItem.php (Value Object)
│   │   ├── StorageMetadata.php (Value Object)
│   │   └── StorageType.php (Enum)
│   └── ...
├── Ports/
│   ├── StoragePort.php (интерфейс для чтения)
│   ├── DestinationStoragePort.php (интерфейс для записи)
│   └── ...
├── Adapters/
│   ├── Storage/
│   │   ├── Local/
│   │   │   └── LocalStorageAdapter.php
│   │   ├── GooglePhotos/
│   │   │   ├── GooglePhotosAdapter.php
│   │   │   ├── GooglePhotosClient.php
│   │   │   └── GooglePhotosRateLimiter.php
│   │   └── ...
│   └── ...
└── Infrastructure/
    ├── Storage/
    │   ├── StorageAdapterFactory.php
    │   ├── StorageConfiguration.php
    │   └── RateLimiting/
    │       ├── RateLimiter.php
    │       └── RateLimitedStoragePort.php (декоратор)
    └── ...
```

**Конфигурация:**

```yaml
# config/storage.yaml
storage:
    source:
        dsn: "%env(SOURCE_STORAGE_DSN)%"
        # или явно:
        # type: google-photos
        # credentials:
        #     client_id: "%env(GOOGLE_PHOTOS_CLIENT_ID)%"
        #     client_secret: "%env(GOOGLE_PHOTOS_CLIENT_SECRET)%"
        # rate_limit:
        #     requests: 100
        #     per_seconds: 100
    
    destination:
        dsn: "%env(DESTINATION_STORAGE_DSN)%"
```

**Преимущества комбинированного подхода:**
- ✅ Максимальная абстракция от реализации
- ✅ Минимум хардкода (все через конфигурацию)
- ✅ Использование современных возможностей PHP
- ✅ Легко расширяется новыми хранилищами
- ✅ Разделение ответственности (rate limiting отдельно)
- ✅ Типобезопасность
- ✅ Легко тестировать

