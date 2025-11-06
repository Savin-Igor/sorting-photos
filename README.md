# Sorting Photos By Date

A modern PHP 8.4 application for organizing photos, videos, audio files, and documents by date and type. Built with Hexagonal Architecture, Symfony Messenger, and Docker support.

## Features

- 📸 **Multi-format support**: Images, videos, audio files, and documents
- 📅 **Smart organization**: Organizes files by date and type (`{year}/{month}/{category}/`)
- 🔒 **Metadata preservation**: Preserves file permissions, timestamps, and extended attributes
- 🚀 **Scalable architecture**: Hexagonal Architecture with async message processing
- 🐳 **Docker ready**: Full Docker Compose setup with Redis/RabbitMQ support
- ✅ **Quality assurance**: Comprehensive tests and static analysis tools

## Requirements

- PHP 8.4+
- Composer
- Docker & Docker Compose (for containerized deployment)
- SQLite extension (for metadata storage)

## Quick Start

### Using Docker (Recommended)

1. **Clone the repository:**
   ```bash
   git clone <repository-url>
   cd sorting-photos
   ```

2. **Initialize data directories:**
   ```bash
   make data-init
   ```

3. **Create environment file:**
   ```bash
   cp .env.docker.example .env
   ```

4. **Start the application:**
   ```bash
   make up
   ```

5. **Place files to sort:**
   ```bash
   cp -r /path/to/your/photos/* var/data/source/
   ```

6. **Run the application:**
   ```bash
   make run
   ```

### Local Installation

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Configure environment:**
   ```bash
   cp .env.docker.example .env
   # Edit .env with your paths
   ```

3. **Run the application:**
   ```bash
   php index.php
   ```

## Project Structure

```
sorting-photos/
├── src/
│   ├── Domain/                      # Domain layer (isolated)
│   │   ├── MediaAsset.php          # Aggregate Root
│   │   ├── ValueObjects/           # Value Objects
│   │   │   ├── FileType.php        # Enum (image, video, audio, other)
│   │   │   ├── FileCategory.php    # Enum (images, audio, video, other)
│   │   │   ├── FilePath.php        # Value Object
│   │   │   ├── FileHash.php        # Value Object
│   │   │   ├── MediaDate.php       # Value Object
│   │   │   └── MediaMeta.php       # Value Object
│   │   ├── Event/                   # Domain Events
│   │   │   ├── FileDiscovered.php
│   │   │   ├── FileProcessed.php
│   │   │   ├── FileOrganized.php
│   │   │   └── FileError.php
│   │   └── Policies/                # Policy Pattern
│   │       ├── OrganizerPolicy.php  # Interface
│   │       ├── DateTypePolicy.php   # {year}/{month}/{category}/
│   │       ├── DatePolicy.php       # {year}/{month}/
│   │       └── TypeDatePolicy.php   # {category}/{year}/{month}/
│   │
│   ├── Application/                 # Use Cases & CQRS
│   │   ├── Command/
│   │   │   ├── IngestFileCommand.php
│   │   │   └── OrganizeFileCommand.php
│   │   └── Handler/
│   │       ├── IngestFileHandler.php
│   │       └── OrganizeFileHandler.php
│   │
│   ├── Ports/                       # Interfaces (Hexagonal)
│   │   ├── ScannerPort.php
│   │   ├── MetadataExtractorPort.php
│   │   ├── FilesystemPort.php
│   │   ├── LoggerPort.php
│   │   ├── MetadataRepositoryPort.php
│   │   ├── MimeTypeDetectorInterface.php
│   │   └── AudioVideoMetadataAnalyzerInterface.php
│   │
│   ├── Adapters/                    # Port implementations
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
│   └── Command/                     # Console commands
│       └── ScanFilesCommand.php
│
├── config/
│   ├── packages/
│   │   ├── messenger.yaml          # Queue configuration
│   │   └── flysystem.yaml          # Filesystem configuration
│   └── services.yaml                # DI container
│
├── tests/                            # Test suite
│   └── src/
│       └── Unit/
│
├── var/
│   ├── data/                        # Data directories
│   │   ├── source/                  # Source files (read-only)
│   │   └── destination/            # Organized files
│   ├── log/                         # Application logs
│   ├── cache/                       # Cache files
│   └── database.sqlite              # SQLite database
│
├── bin/                             # Executable scripts
│   └── console                      # Symfony Console entry point
│
├── docker-compose.yml               # Docker Compose configuration
├── Dockerfile                       # Docker image definition
└── Makefile                         # Make commands
```

## Docker Commands

### Basic Commands

- `make up` - Start application with Redis (default)
- `make up-rabbitmq` - Start with RabbitMQ instead of Redis
- `make up-worker` - Start with async worker for message processing
- `make down` - Stop all containers
- `make build` - Rebuild Docker images
- `make logs` - Show logs from all containers
- `make logs-app` - Show application logs
- `make shell` - Open shell in application container

### Application Commands

- `make run` - Run file sorting
- `make run-dry-run` - Run in dry-run mode (no files moved)
- `make scan` - Scan files and dispatch events
- `make consume` - Start message consumer

### Testing & Quality

- `make test` - Run PHPUnit tests
- `make test-coverage` - Run tests with coverage report
- `make cs-fix` - Fix code style (PHP-CS-Fixer)
- `make phpstan` - Run PHPStan static analysis
- `make psalm` - Run Psalm static analysis
- `make grumphp` - Run all code quality checks
- `make qa` - Run all quality checks and tests

### Data Management

- `make data-init` - Initialize data directories
- `make data-clean` - Clean destination directory (with confirmation)

### Redis/RabbitMQ

- `make redis-cli` - Open Redis CLI
- `make redis-flush` - Flush Redis data
- `make rabbitmq-management` - Open RabbitMQ management UI

## Configuration

### Environment Variables

Create `.env` file from `.env.docker.example`:

```bash
cp .env.docker.example .env
```

Key variables:

- `SOURCE_DIRECTORY` - Source directory (default: `/var/data/source`)
- `DESTINATION_DIRECTORY` - Destination directory (default: `/var/data/destination`)
- `ORGANIZER_POLICY` - Organization policy (`date-type`, `date`, `type-date`)
- `DRY_RUN` - Dry-run mode (`true`/`false`)
- `MESSENGER_TRANSPORT_DSN` - Messenger transport:
  - Redis: `redis://redis:6379/messages`
  - RabbitMQ: `amqp://guest:guest@rabbitmq:5672/%2f/messages`

### Organization Policies

- **date-type** (default): `{year}/{month}/{category}/`
- **date**: `{year}/{month}/`
- **type-date**: `{category}/{year}/{month}/`

## Architecture

The application follows **Hexagonal Architecture** (Ports & Adapters) combined with **Pipeline Processing**:

- **Domain Layer**: Value Objects, Entities, Domain Events, Policies
- **Application Layer**: Commands, Handlers, Queries
- **Infrastructure Layer**: Adapters for filesystem, metadata extraction, messaging
- **Ports**: Interfaces defining contracts

### Key Patterns

- **Hexagonal Architecture** (Ports/Adapters) — Domain isolation
- **Pipeline** — Processing pipeline via Messenger
- **CQRS** — Commands (`IngestFile`, `OrganizeFile`) and Events (`FileOrganized`)
- **Policy/Strategy** — Flexible file organization rules
- **Retry/Dead Letter Queue** — Resilience
- **Queue-based processing** — Scalability
- **Chain of Responsibility** — Metadata extraction chain
- **Dependency Injection** — Symfony DI container

### Processing Pipeline

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

### Technology Stack

**Core:**
- `symfony/console` — Console commands
- `symfony/messenger` — Async processing and retries
- `symfony/finder` — File tree scanning

**Detection & Metadata:**
- `league/mime-type-detection` — Reliable MIME detection (finfo + extension map)
- `james-heinrich/getid3` — Audio/video metadata extraction
- `exif` (built-in) — Image metadata

**Storage:**
- `league/flysystem` — Filesystem abstraction (local/cloud)
- `doctrine/dbal` — Metadata storage (SQLite/PostgreSQL) for idempotency

**Logging:**
- `monolog/monolog` — Structured logging

**Queues (optional):**
- Redis/RabbitMQ via Symfony Messenger transports

### Implementation Details

#### Date Detection (Priority Order)

**Images:**
1. `EXIF DateTimeOriginal`
2. `EXIF DateTime`
3. `mtime` (modification time)

**Audio/Video (getID3):**
1. `ID3 TDRC` (recording year)
2. `ID3 TYER` (year)
3. `mtime`

**Other Files:**
1. `mtime`
2. `ctime` (creation time)

#### Metadata Preservation Process

1. Calculate SHA-256 hash of source file
2. Binary file copy
3. Verify hash of copy (compare with source)
4. Restore metadata:
   - `chmod()` — File permissions
   - `touch()` — Timestamps (mtime, atime)
   - `xattr` (if supported) — Extended attributes
5. Delete source only after successful verification

#### Idempotency

- Database key: `(absolute_path, size, hash)`
- Pre-processing check: if file already processed — skip
- Deduplication by content hash

#### Collision Resolution

- If file exists: `{original-name}-{hash-prefix}.{ext}`
- Hash used for deduplication and integrity verification

#### Retry Mechanism

- Automatic retries on errors (configurable)
- Dead Letter Queue for problematic files
- Notifications on critical errors
- Logging of all attempts

#### Horizontal Scaling

- Multiple workers process queue in parallel
- Worker count configurable
- Load balancing via queue

## Development

### Running Tests

```bash
# All tests
make test

# With coverage
make test-coverage

# Specific test
make test-filter TEST=TestClassName
```

### Code Quality Tools

```bash
# Fix code style
make cs-fix

# Static analysis
make phpstan
make psalm

# All checks
make grumphp
make qa
```

### Manual Tool Execution

```bash
# PHP-CS-Fixer
./vendor/bin/php-cs-fixer fix --allow-risky=yes

# PHPStan
./vendor/bin/phpstan analyse

# Psalm (with PHP 8.4 compatibility)
php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_STRICT" ./vendor/bin/psalm --no-cache

# Rector
./vendor/bin/rector process src --dry-run
```

### Psalm Configuration

Psalm is configured for PHP 8.4 compatibility. Some vendor dependencies may show deprecation warnings, but these don't affect application functionality.

## Async Processing

For async file processing with workers:

1. **Start application with worker:**
   ```bash
   make up-worker
   ```

2. **In another terminal, scan files:**
   ```bash
   make scan
   ```

3. **Worker automatically processes messages from queue**

## Troubleshooting

### Permission Issues

```bash
make shell
# In container:
sudo chown -R www-data:www-data /var/data /var/www/html/var
```

### View Logs

```bash
make logs-app
# or
make logs-worker
```

### Clear Redis Queue

```bash
make redis-flush
```

### Rebuild Images

```bash
make build
```

## File Categories

Files are automatically categorized:

- **images**: JPEG, PNG, GIF, WebP, etc.
- **audio**: MP3, FLAC, WAV, OGG, etc.
- **video**: MP4, AVI, MOV, MKV, etc.
- **other**: Documents and other file types

## Metadata Preservation

The application preserves:

- File permissions (mode)
- Timestamps (mtime, atime)
- Extended attributes (xattr)
- File ownership (when possible)

## License

MIT License

## Author

Igors Savins - igor.savin@inbox.lv

## Contributing

Contributions are welcome! Please ensure:

1. All tests pass (`make test`)
2. Code quality checks pass (`make qa`)
3. Follow PSR-12 coding standards
4. Add tests for new features

## Architecture Benefits

- ✅ **Scalability** — Parallel processing via Messenger
- ✅ **Extensibility** — Hexagonal Architecture allows easy adapter swapping
- ✅ **Resilience** — Retry, DLQ, idempotency
- ✅ **Flexibility** — Policy Pattern for different organization rules
- ✅ **Testability** — Easy to mock ports
- ✅ **Isolation** — Domain doesn't know about concrete implementations
- ✅ **Cloud-ready** — Easy to add S3/FTP via Flysystem
- ✅ **Suitable for millions of files** — Horizontal scaling
