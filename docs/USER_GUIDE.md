# 📖 Руководство пользователя: Sorting Photos

Добро пожаловать в **Sorting Photos** - современное приложение для автоматической организации фотографий, видео и документов по дате и типу!

## 📋 Содержание

- [🚀 Быстрый старт](#-быстрый-старт)
- [🐳 Установка с Docker](#-установка-с-docker)
- [🏠 Локальная установка](#-локальная-установка)
- [⚙️ Базовая настройка](#️-базовая-настройка)
- [📸 Использование](#-использование)
- [📁 Форматы организации](#-форматы-организации)
- [🔄 Асинхронная обработка](#-асинхронная-обработка)
- [☁️ Google Photos интеграция](#️-google-photos-интеграция)
- [📊 Мониторинг и статистика](#-мониторинг-и-статистика)
- [🐛 Решение проблем](#-решение-проблем)
- [❓ Часто задаваемые вопросы](#-часто-задаваемые-вопросы)

## 🚀 Быстрый старт

### 🎯 Для чего это приложение?

**Sorting Photos** поможет вам:
- 📸 **Организовать тысячи фото/видео** автоматически
- 📅 **Сортировать по дате съемки** (не по дате изменения файла)
- 🔒 **Сохранить все метаданные** (права, временные метки)
- 🚀 **Обработать большие объемы** (2+ ТБ) с высокой скоростью
- ☁️ **Загрузить в Google Photos** с правильной хронологией

### ⚡ 5-минутный старт

```bash
# 1. Клонировать
git clone <repository-url>
cd sorting-photos

# 2. Инициализировать
make data-init

# 3. Запустить
make up

# 4. Поместить файлы
cp -r ~/Pictures/* var/data/source/

# 5. Запустить сортировку
make organize-files
```

**Результат:**
```
var/data/destination/
├── 2023/
│   ├── 01/
│   │   ├── images/  # Фото января 2023
│   │   └── video/   # Видео января 2023
│   └── 12/
│       ├── images/  # Фото декабря 2023
│       └── audio/   # Аудио декабря 2023
└── 2024/
    └── 06/
        └── images/  # Фото июня 2024
```

## 🐳 Установка с Docker

### 📋 Системные требования

- **ОС**: Linux, macOS, Windows (WSL2)
- **Docker**: 20.0+
- **Docker Compose**: 2.0+
- **RAM**: 512 MB минимум, 2 GB+ рекомендуется
- **Диск**: Зависит от объема файлов

### 🚀 Пошаговая установка

#### Шаг 1: Клонирование репозитория

```bash
git clone <repository-url>
cd sorting-photos
```

#### Шаг 2: Инициализация директорий

```bash
make data-init
```

**Что создастся:**
```
var/
├── data/
│   ├── source/      # Исходные файлы (сюда класть фото)
│   └── destination/ # Организованные файлы (результат)
├── log/             # Логи приложения
├── cache/           # Кеш
└── database.sqlite  # База метаданных
```

#### Шаг 3: Запуск приложения

```bash
# С Redis (рекомендуется)
make up

# Или с RabbitMQ (для продакшена)
make up-rabbitmq
```

#### Шаг 4: Проверка работы

```bash
# Проверка доступности CLI
make shell
php bin/console --version  # Должно вывести версию приложения (например, "File Sorter 1.0.0")
```

### 📁 Подготовка файлов

#### Вариант 1: Копирование файлов

```bash
# Из домашней директории
cp -r ~/Pictures/* var/data/source/

# Из внешнего диска
cp -r /mnt/external/photos/* var/data/source/

# Из USB-накопителя
cp -r /media/user/PHOTOS/* var/data/source/
```

#### Вариант 2: Монтирование директорий

Используйте переменные окружения для монтирования реальных директорий:

```bash
# Создать .env файл
cp .env.example .env

# Отредактировать пути
nano .env
```

```bash
# Пример .env
SOURCE_DIRECTORY_HOST=/home/user/Pictures
DESTINATION_DIRECTORY_HOST=/mnt/external/sorted
```

```bash
# Перезапустить с новыми путями
make down && make up
```

### 🎯 Первый запуск

```bash
# Проверить, что файлы на месте
ls -la var/data/source/

# Запустить сортировку
make organize-files
```

## 🏠 Локальная установка

### 📋 Требования

- **PHP**: 8.4+
- **Composer**: 2.0+
- **SQLite**: расширение PHP

### 🚀 Установка

```bash
# 1. Установка зависимостей
composer install

# 2. Настройка окружения
cp .env.docker.example .env

# 3. Создание директорий
mkdir -p var/data/source var/data/destination

# 4. Запуск
php bin/console organize:files /path/to/source /path/to/destination
```

## ⚙️ Базовая настройка

### 📄 Конфигурационные файлы

- **`.env`** - переменные окружения
- **`config/parameters.yaml`** - параметры приложения
- **`config/services.yaml`** - DI-контейнер

### 🔧 Основные настройки

#### Директории

```bash
# Пути к директориям (в контейнере)
SOURCE_DIRECTORY=/var/data/source
DESTINATION_DIRECTORY=/var/data/destination

# Пути на хост-машине (для монтирования)
SOURCE_DIRECTORY_HOST=/home/user/Pictures
DESTINATION_DIRECTORY_HOST=/mnt/external/sorted
```

#### Политика организации

```bash
# Доступные варианты:
# date-type: {год}/{месяц}/{категория}/  (по умолчанию)
# date:     {год}/{месяц}/
# type-date: {категория}/{год}/{месяц}/

ORGANIZER_POLICY=date-type
```

#### Режим работы

```bash
# Режим dry-run (без перемещения файлов)
DRY_RUN=false

# Асинхронный режим (с очередью)
ASYNC_MODE=false

# Для параллельной обработки используйте:
# make consume-workers WORKERS=4
```

#### Ограничение скорости I/O (опционально)

```bash
# Ограничение количества операций ввода-вывода
RATE_LIMIT_REQUESTS=100
RATE_LIMIT_PER_SECONDS=1
# Допустимый burst (опционально)
RATE_LIMIT_BURST=50
```

## 📸 Использование

### 🐳 Docker команды

#### Основные операции

```bash
# Синхронная сортировка (рекомендуется для начала)
make organize-files

# Сортировка в режиме dry-run (без изменений)
make organize-files-dry-run

# Только сканирование (без обработки)
make scan
```

#### Асинхронная обработка

```bash
# Запуск с воркером
make up-worker

# В другом терминале: сканирование
make scan

# Воркер автоматически обработает файлы
```

#### Управление данными

```bash
# Очистить метаданные (сброс блокировок)
make metadata-clear

# Очистить destination директорию
make data-clean

# Показать статистику
make show-progress
```

### 🔧 Консольные команды

#### Основная команда сортировки

```bash
# Docker
make organize-files

# Или напрямую
docker compose exec app php bin/console organize:files
```

#### Расширенные опции

```bash
# С указанием конкретных директорий (в контейнере)
docker compose exec app php bin/console organize:files --source=/custom/source --destination=/custom/destination

# В режиме dry-run
docker compose exec app php bin/console organize:files --dry-run

# Политика организации задается переменной окружения:
# ORGANIZER_POLICY=date|date-type|type-date
```

#### Работа с очередью

```bash
# Запуск воркера
docker compose exec app php bin/console messenger:consume

# Несколько воркеров
make consume-workers WORKERS=4

# Стоп воркеров
make workers-stop
```

## 📁 Форматы организации

### 📅 Политики организации

#### `date-type` (По умолчанию)

```
{ГОД}/{МЕСЯЦ}/{КАТЕГОРИЯ}/
```

**Пример:**
```
2023/
├── 01/
│   ├── images/
│   │   ├── photo1.jpg
│   │   └── photo2.png
│   └── video/
│       └── video1.mp4
└── 12/
    ├── images/
    │   ├── christmas.jpg
    │   └── newyear.png
    └── audio/
        └── song.mp3
```

#### `date` (Только по дате)

```
{ГОД}/{МЕСЯЦ}/
```

**Пример:**
```
2023/
├── 01/
│   ├── photo1.jpg
│   ├── photo2.png
│   └── video1.mp4
└── 12/
    ├── christmas.jpg
    ├── newyear.png
    └── song.mp3
```

#### `type-date` (По типу, затем дате)

```
{КАТЕГОРИЯ}/{ГОД}/{МЕСЯЦ}/
```

**Пример:**
```
images/
├── 2023/
│   ├── 01/
│   │   ├── photo1.jpg
│   │   └── photo2.png
│   └── 12/
│       ├── christmas.jpg
│       └── newyear.png
└── 2024/
    └── 06/
        └── vacation.jpg

video/
└── 2023/
    └── 01/
        └── video1.mp4

audio/
└── 2023/
    └── 12/
        └── song.mp3
```

### 📸 Категории файлов

#### Изображения (`images`)
- JPEG, PNG, GIF, WebP, TIFF, BMP, HEIC
- Расширения: `.jpg`, `.jpeg`, `.png`, `.gif`, `.webp`, `.tiff`, `.bmp`, `.heic`

#### Видео (`video`)
- MP4, AVI, MOV, MKV, WMV, FLV, WebM
- Расширения: `.mp4`, `.avi`, `.mov`, `.mkv`, `.wmv`, `.flv`, `.webm`

#### Аудио (`audio`)
- MP3, FLAC, WAV, OGG, AAC, WMA
- Расширения: `.mp3`, `.flac`, `.wav`, `.ogg`, `.aac`, `.wma`

#### Другое (`other`)
- PDF, DOC, DOCX, TXT и другие документы
- Все файлы, не попавшие в вышеуказанные категории

### 📅 Детекция даты

#### Приоритет для изображений

1. **EXIF DateTimeOriginal** - дата съемки
2. **EXIF DateTime** - дата изменения
3. **mtime** - время модификации файла

#### Приоритет для аудио/видео

1. **ID3 TDRC** - год записи
2. **ID3 TYER** - год
3. **mtime** - время модификации файла

#### Приоритет для остальных файлов

1. **mtime** - время модификации
2. **ctime** - время создания

### 🔒 Сохранение метаданных

Приложение **полностью сохраняет**:

- ✅ **Права доступа** (`chmod`)
- ✅ **Временные метки** (`mtime`, `atime`)
- ✅ **Расширенные атрибуты** (`xattr`)
- ✅ **Владелец файла**

### 🛡️ Безопасность и дедупликация

#### Идемпотентность

- Файлы идентифицируются по: `(путь, размер, хеш)`
- Повторная обработка одного файла пропускается
- Безопасные повторные запуски

#### Разрешение коллизий

Если файл с таким именем уже существует:

```
{оригинальное-имя}-{префикс-хеша}.{расширение}
```

**Пример:**
```
photo.jpg          # Оригинал
photo-a1b2c3d4.jpg # Коллизия
```

## 🔄 Асинхронная обработка

### 🚀 Когда использовать async режим?

**Рекомендуется для:**
- 📂 **Большие объемы** (>10k файлов)
- 💾 **Огромные размеры** (>100 GB)
- ⚡ **Высокая скорость** обработки

**Результаты:**
- **4 воркера**: ~40-200 файлов/сек
- **8 воркеров**: ~80-400 файлов/сек

### 🐳 Настройка async режима

#### Шаг 1: Запуск с воркером

```bash
# С Redis
make up-worker

# С RabbitMQ (для продакшена)
make up-rabbitmq
```

#### Шаг 2: Сканирование файлов

```bash
# В другом терминале
make scan
```

#### Шаг 3: Мониторинг

```bash
# Статус воркеров
make workers-status

# Логи воркеров
make workers-logs

# Общая статистика
make show-progress
```

### 🔧 Ручное управление воркерами

```bash
# Запуск 4 воркеров
make consume-workers WORKERS=4

# Стоп всех воркеров
make workers-stop

# Запуск одного воркера
make consume
```

## ☁️ Google Photos интеграция

### 🎯 Возможности

- 📤 **Загрузка больших объемов** (до 2 ТБ+)
- ⏱️ **Resumable upload** с возобновлением
- 📦 **Batch processing** (до 50 элементов)
- 📊 **Управление квотами** API
- 🗜️ **Сжатие изображений** без потери качества
- 🔍 **Быстрый поиск файлов** (locate/find/hybrid стратегии)
- 🎯 **Гибкая фильтрация** (по типу, размеру, дате, regex)
- 📅 **Сохранение EXIF данных** и даты создания при сжатии

### 🚀 Настройка Google Photos

#### Шаг 1: Создание проекта Google Cloud

1. Перейдите на [Google Cloud Console](https://console.cloud.google.com/)
2. Создайте новый проект
3. Включите Google Photos API
4. Создайте OAuth 2.0 credentials

#### Шаг 2: Настройка credentials

```bash
# Поместите credentials.json в директорию
cp ~/credentials.json config/credentials/

# Установите переменную окружения
echo "GOOGLE_PHOTOS_CREDENTIALS_DIR_HOST=$(pwd)/config/credentials" >> .env
```

#### Шаг 3: Авторизация

```bash
# Получить URL для авторизации
make google-photos-authorize

# Перейти по ссылке и скопировать код
# Вставить код
make google-photos-authorize CODE=your_authorization_code
```

#### Шаг 4: Тестирование

```bash
# Тест подключения
make google-photos-test

# Тест альбомов
make google-photos-test-albums
```

#### Шаг 5: Поиск и загрузка файлов

```bash
# Быстрый поиск файлов (без добавления в БД)
bin/console google-photos:search-files --source-dir /path/to/photos --dry-run

# Поиск и добавление найденных файлов в БД
bin/console google-photos:search-files --source-dir /path/to/photos --add-to-db

# Загрузка из директории (с автоматическим поиском)
make google-photos-upload SOURCE=/path/to/photos

# Сканирование без загрузки
make google-photos-scan SOURCE=/path/to/photos

# Исправление файлов, застрявших в статусе IN_BATCH
bin/console google-photos:fix-in-batch --fix-completed-batches --fix-failed-batches
```

### ⚙️ Настройки Google Photos

```bash
# Путь к credentials
GOOGLE_PHOTOS_CREDENTIALS_PATH=/var/www/html/config/credentials/credentials.json

# ID альбома (опционально)
GOOGLE_PHOTOS_ALBUM_ID=your_album_id

# Полный доступ (для создания альбомов)
GOOGLE_PHOTOS_FULL_ACCESS=false
```

### 🔄 Процесс загрузки файлов в Google Photos

Процесс загрузки состоит из **двух этапов**, которые выполняются последовательно:

#### Этап 1: Загрузка файла (Upload)

1. **`PENDING`** - Задача создана, файл готов к загрузке
   - Файл проверяется на существование (если файл не найден → `NOT_FOUND`)
   - Файлы могут быть помечены как `ARCHIVED` для исключения из обработки
2. **`UPLOADING`** - Файл загружается на сервер Google через API `/uploads`
   - Используется **raw upload** для файлов < 100 MB
   - Используется **resumable upload** для файлов > 100 MB
   - Файл сжимается при необходимости (с сохранением EXIF данных)
   - Устанавливается дата создания из метаданных или имени файла
3. **`UPLOADED`** - Файл успешно загружен, получен `uploadToken`
   - ⚠️ **ВАЖНО**: На этом этапе файл **НЕ виден в Google Photos**!
   - Файл находится на серверах Google, но еще не создан как медиа-элемент

#### Этап 2: Создание медиа-элемента (Batch Create)

4. **`IN_BATCH`** - Файл добавлен в батч (до 50 файлов)
   - `BatchCollector` собирает файлы со статусом `UPLOADED` в батчи
   - Максимум 50 файлов или 1 GB на батч
5. **`COMPLETED`** - Медиа-элемент создан в Google Photos
   - Выполняется API запрос `mediaItems:batchCreate` с `uploadToken`
   - Только после этого файл **появляется в Google Photos**!

#### Диаграмма переходов статусов

```
PENDING → UPLOADING → UPLOADED → IN_BATCH → COMPLETED
   ↓         ↓           ↓           ↓
 FAILED   FAILED      FAILED      FAILED
   ↓
NOT_FOUND (файл не существует)
   ↓
ARCHIVED (исключен из обработки)
```

#### Статусы файлов

- **`PENDING`** - Файл готов к обработке
- **`UPLOADING`** - Файл загружается на сервер
- **`UPLOADED`** - Файл загружен, ожидает создания медиа-элемента
- **`IN_BATCH`** - Файл добавлен в батч для создания медиа-элемента
- **`COMPLETED`** - Файл успешно загружен и виден в Google Photos
- **`FAILED`** - Ошибка при загрузке или создании медиа-элемента
- **`PAUSED`** - Загрузка приостановлена (например, из-за квот)
- **`NOT_FOUND`** - Файл не существует на диске (перемещен или диск отключен)
- **`ARCHIVED`** - Файл исключен из обработки (хранится в БД, но не обрабатывается)

#### Почему файлы со статусом `UPLOADED` не видны в Google Photos?

**Это нормальное поведение!** Процесс работает так:

1. **Загрузка файла** (`UPLOADED`) - файл загружен на сервер Google, получен токен
2. **Создание медиа-элемента** (`COMPLETED`) - файл "зарегистрирован" в библиотеке Google Photos

Файлы со статусом `UPLOADED` находятся в промежуточном состоянии - они загружены, но еще не созданы как медиа-элементы. Они появятся в Google Photos только после выполнения `batchCreateMediaItems`.

#### Как проверить статус загрузки?

```bash
# Просмотр статистики по статусам
make google-photos-status

# Детальная информация о файлах
make google-photos-dump-state
```

#### Что делать, если файлы "застряли" в статусе `UPLOADED` или `IN_BATCH`?

1. **Проверьте, работает ли оркестратор:**
   ```bash
   make google-photos-upload SOURCE=/path/to/photos
   ```

2. **Используйте команду исправления:**
   ```bash
   # Исправить файлы из завершенных батчей
   bin/console google-photos:fix-in-batch --fix-completed-batches
   
   # Исправить файлы из провалившихся батчей
   bin/console google-photos:fix-in-batch --fix-failed-batches
   
   # Просмотр статистики
   bin/console google-photos:fix-in-batch
   ```

3. **Проверьте квоты API:**
   - Google Photos API имеет лимиты на количество запросов
   - При превышении квоты процесс автоматически приостанавливается

4. **Проверьте логи:**
   ```bash
   make logs-app | grep -i "batch\|quota\|error"
   ```

5. **Проверьте статус файлов:**
   ```bash
   bin/console google-photos:status
   ```

#### Приоритеты обработки в оркестраторе

Оркестратор обрабатывает задачи в следующем порядке:

1. **Приоритет 1**: Обработка незавершенных батчей (`PROCESSING`, `PAUSED`)
2. **Приоритет 2**: Загрузка новых файлов (`PENDING`, `UPLOADING`)
3. **Приоритет 3**: Сбор новых батчей из загруженных файлов (`UPLOADED`)

Это означает, что сначала загружаются файлы, а затем они собираются в батчи и обрабатываются.

## 📊 Мониторинг и статистика

### 📈 Просмотр прогресса

```bash
# Текущая статистика
make show-progress

# Непрерывный мониторинг
make show-progress INTERVAL=2

# Одноразовый отчет
make show-progress-once
```

### 📋 Что показывает статистика

```
📊 Processing Statistics
═══════════════════════════════

📁 Directories scanned: 15
📄 Files discovered: 1,247
✅ Files processed: 1,089
📂 Files organized: 1,089
❌ Errors: 3

⏱️  Time elapsed: 00:05:42
⚡ Speed: 3.18 files/sec
📊 Progress: 87.3%

🎯 Current file: /var/data/source/vacation/photo_2023_07_15.jpg
🏷️  Status: Processing metadata
```

### 📋 Логирование

#### Просмотр логов

```bash
# Логи приложения
make logs-app

# Логи воркеров
make logs-worker

# Все логи
make logs
```

#### Структура логов

```json
{
  "timestamp": "2024-01-15T10:30:45Z",
  "level": "INFO",
  "message": "File organized successfully",
  "context": {
    "file": "/var/data/source/photo.jpg",
    "destination": "/var/data/destination/2023/07/images/photo.jpg",
    "size": "2.5 MB",
    "hash": "a1b2c3d4..."
  }
}
```

### 🗄️ Работа с базой данных

#### Просмотр метаданных

```bash
# Войти в контейнер
make shell

# SQLite CLI
sqlite3 var/database.sqlite

# Посмотреть таблицы
.tables

# Посмотреть обработанные файлы
SELECT COUNT(*) FROM media_assets;

# Посмотреть ошибки
SELECT * FROM media_assets WHERE status = 'error';
```

#### Очистка базы

```bash
# Очистить все метаданные
make metadata-clear

# Пересоздать базу
rm var/database.sqlite
make organize-files  # База создастся автоматически
```

## 🐛 Решение проблем

### 🔍 Диагностика проблем

#### 1. Проверить статус контейнеров

```bash
docker compose ps

# Должно показать:
# sorting-photos-app     Up
# sorting-photos-redis   Up
```

#### 2. Проверить логи

```bash
make logs-app
make logs-worker
```

#### 3. Проверить файлы

```bash
# Проверить, что файлы на месте
ls -la var/data/source/

# Проверить разрешения
ls -ld var/data/source/
ls -ld var/data/destination/
```

### 🛠️ Распространенные проблемы

#### Проблема: "Permission denied"

**Симптомы:**
```
mkdir(): Permission denied
```

**Решение:**
```bash
# Исправить права доступа
make shell
sudo chown -R www-data:www-data /var/data /var/www/html/var
```

#### Проблема: "No space left on device"

**Симптомы:**
```
write(): No space left on device
```

**Решение:**
```bash
# Проверить место на диске
df -h

# Очистить логи/кеш
make shell
rm -rf var/log/* var/cache/*
```

#### Проблема: "Out of memory"

**Симптомы:**
```
PHP Fatal error: Out of memory
```

**Решение:**
```bash
# Увеличить лимит памяти в docker-compose.yml
environment:
  - PHP_MEMORY_LIMIT=512M
```

#### Проблема: Файлы не сортируются

**Симптомы:**
```
No files found for processing
```

**Проверка:**
```bash
# Проверить, что файлы существуют
ls -la var/data/source/

# Проверить, что директория не пустая
find var/data/source/ -type f | wc -l

# Запустить с verbose
make organize-files VERBOSE=1
```

#### Проблема: Воркеры не запускаются

**Симптомы:**
```
make workers-status
# No workers running
```

**Решение:**
```bash
# Проверить очередь
make redis-cli
KEYS *

# Очистить очередь
make redis-flush

# Перезапустить воркеров
make consume-workers WORKERS=2
```

### 🔧 Восстановление после сбоев

#### После прерывания обработки

```bash
# 1. Проверить статус
make show-progress

# 2. Очистить метаданные прерванных файлов
make metadata-clear

# 3. Перезапустить обработку
make organize-files
```

#### После изменения конфигурации

```bash
# 1. Остановить контейнеры
make down

# 2. Пересобрать
make build

# 3. Запустить заново
make up

# 4. Очистить кеш метаданных
make metadata-clear
```

## ❓ Часто задаваемые вопросы

### 🚀 Общие вопросы

#### Q: Сколько файлов можно обработать?

**A:** Нет строгих ограничений. Тестировалось на:
- 10k файлов - отлично работает
- 100k файлов - требует async режима
- 1M+ файлов - требует кластерной настройки

#### Q: Какие форматы поддерживаются?

**A:** Все основные:
- **Фото**: JPEG, PNG, GIF, WebP, TIFF, BMP, HEIC
- **Видео**: MP4, AVI, MOV, MKV, WMV, FLV, WebM
- **Аудио**: MP3, FLAC, WAV, OGG, AAC, WMA
- **Документы**: PDF, DOC, DOCX, TXT

#### Q: Безопасно ли использовать?

**A:** Да! Приложение:
- ✅ Не удаляет оригиналы без верификации копии
- ✅ Проверяет целостность по SHA-256 хешу
- ✅ Сохраняет все метаданные
- ✅ Поддерживает dry-run режим

### ⚙️ Настройка

#### Q: Как использовать внешние диски?

**A:** Через переменные окружения:

```bash
# Для USB-диска
SOURCE_DIRECTORY_HOST=/mnt/usb/photos
DESTINATION_DIRECTORY_HOST=/mnt/external/sorted

# Для сетевого диска
SOURCE_DIRECTORY_HOST=/mnt/network/photos
DESTINATION_DIRECTORY_HOST=/home/user/sorted
```

#### Q: Как изменить политику организации?

**A:** В `.env` файле:

```bash
# По умолчанию
ORGANIZER_POLICY=date-type

# Только дата
ORGANIZER_POLICY=date

# Тип, затем дата
ORGANIZER_POLICY=type-date
```

### 🚀 Производительность

#### Q: Как ускорить обработку?

**A:**
1. Используйте async режим: `make up-worker`
2. Увеличьте количество воркеров: `make consume-workers WORKERS=8`
3. Используйте SSD диски
4. Увеличьте RAM (минимум 2GB)

#### Q: Сколько памяти нужно?

**A:**
- **Синхронный режим**: 512 MB
- **Async с 4 воркерами**: 1-2 GB
- **Async с 8 воркерами**: 2-4 GB

### 🐳 Docker

#### Q: Как обновить приложение?

**A:**
```bash
# Получить обновления
git pull

# Пересобрать
make build

# Перезапустить
make down && make up
```

#### Q: Как зайти в контейнер?

**A:**
```bash
make shell
# Теперь вы внутри контейнера
ls -la
php --version
```

### ☁️ Google Photos

#### Q: Как получить credentials?

**A:**
1. Создайте проект в [Google Cloud Console](https://console.cloud.google.com/)
2. Включите Google Photos API
3. Создайте OAuth 2.0 Client ID
4. Скачайте `credentials.json`
5. Поместите в `config/credentials/`

#### Q: Что такое квоты API?

**A:** Google Photos ограничивает:
- 10 GB в день для загрузки
- 75 GB в день для чтения
- 1000 запросов в минуту

Приложение автоматически управляет квотами.

---

## 📚 Ссылки

- [🏠 На главную](../README.md)
- [🏗️ Архитектура](ARCHITECTURE.md)
- [☁️ Google Photos API](GOOGLE_PHOTOS_API.md)
- [🔧 Разработка](DEVELOPMENT.md)
- [🔄 Асинхронная обработка](ASYNC_PROCESSING.md)
- [📊 Мониторинг](MONITORING.md)
