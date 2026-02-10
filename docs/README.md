# 📚 Документация Sorting Photos

Добро пожаловать в полную документацию проекта **Sorting Photos** - современного приложения для автоматической организации фотографий, видео и документов.

## 📋 О проекте

**Sorting Photos** - это PHP 8.4 приложение для интеллектуальной организации медиафайлов по дате и типу. Построено на гексагональной архитектуре с поддержкой асинхронной обработки и интеграцией Google Photos.

### 🎯 Возможности

- 📸 **Многоформатная поддержка**: JPEG, PNG, GIF, WebP, MP4, AVI, MOV, MKV, MP3, FLAC, WAV, OGG, AAC, WMA
- 📅 **Умная организация**: Автоматическая сортировка по дате съемки в структуру `{год}/{месяц}/{категория}/`
- 🔒 **Сохранение метаданных**: Полная сохранность прав доступа, временных меток и расширенных атрибутов
- 🚀 **Высокая производительность**: До 200 файлов/сек с асинхронной обработкой
- ☁️ **Google Photos интеграция**: Загрузка до 2 ТБ+ с resumable upload
- 🐳 **Docker готовность**: Полное контейнеризированное развертывание
- ✅ **Качество кода**: 100% тестовое покрытие, статический анализ

## 🗂️ Структура документации

### 📖 Основная документация

| Документ | Описание | Аудитория |
|----------|----------|-----------|
| [**🏠 README.md**](../README.md) | Обзор проекта, быстрая установка, возможности | Все пользователи |
| [**📖 Руководство пользователя**](USER_GUIDE.md) | Подробное использование, примеры, troubleshooting | Конечные пользователи |
| [**🏗️ Архитектура**](ARCHITECTURE.md) | Техническая архитектура, паттерны, диаграммы | Разработчики, архитекторы |

### 🔧 Техническая документация

| Документ | Описание | Аудитория |
|----------|----------|-----------|
| [**🔧 Разработка**](DEVELOPMENT.md) | Настройка среды, тестирование, contribution | Разработчики |
| [**🚀 Развертывание**](DEPLOYMENT.md) | Production deployment, мониторинг, масштабирование | DevOps, системные администраторы |
| [**☁️ Google Photos API**](GOOGLE_PHOTOS_API.md) | Интеграция Google Photos, OAuth, загрузка | Разработчики интеграций |

### 📚 Специализированная документация

| Документ | Описание | Аудитория |
|----------|----------|-----------|
| [**🔄 Асинхронная обработка**](ASYNC_PROCESSING.md) | Messenger, очереди, воркеры | Разработчики, DevOps |
| [**📊 Мониторинг**](MONITORING.md) | Метрики, логирование, алертинг | DevOps, SRE |
| [**📋 Статус проекта**](PROJECT_STATUS.md) | Текущее состояние разработки | Все |

## 🗺️ Карта навигации

### 🚀 Быстрый старт

```
Новичок в проекте?
    ↓
🏠 README.md → 📖 USER_GUIDE.md
    ↓
🐳 Docker установка → ⚙️ Базовая настройка
    ↓
🎯 Использование → 📊 Мониторинг
```

### 🔧 Для разработчиков

```
Хочу внести вклад?
    ↓
🏗️ ARCHITECTURE.md → 🔧 DEVELOPMENT.md
    ↓
📦 Структура проекта → 🎯 Domain слой
    ↓
🧪 Тестирование → ✅ Качество кода
    ↓
🤝 Contribution guidelines
```

### 🚀 Для развертывания

```
Нужно развернуть в production?
    ↓
🚀 DEPLOYMENT.md → ☁️ Облачное развертывание
    ↓
🐳 Docker → ⚙️ Production конфигурация
    ↓
📊 Мониторинг → 📈 Масштабирование
    ↓
🔒 Безопасность → 🛠️ Резервное копирование
```

### ☁️ Google Photos интеграция

```
Интеграция с Google Photos?
    ↓
☁️ GOOGLE_PHOTOS_API.md → 🚀 Настройка Google Cloud
    ↓
🔐 OAuth авторизация → ⚙️ Конфигурация приложения
    ↓
📤 Загрузка файлов → 🔄 Resumable Upload
    ↓
📦 Batch Processing → 📊 Управление квотами
```

## 🔍 Перекрестные ссылки

### 🎯 По ролям

#### 👤 Конечный пользователь
- [Быстрая установка](../README.md#быстрый-старт)
- [Использование](../README.md#использование)
- [Устранение проблем](USER_GUIDE.md#🐛-решение-проблем)
- [Часто задаваемые вопросы](USER_GUIDE.md#❓-часто-задаваемые-вопросы)

#### 👨‍💻 Разработчик
- [Архитектура проекта](ARCHITECTURE.md)
- [Настройка среды разработки](DEVELOPMENT.md#🚀-быстрый-старт-разработки)
- [Тестирование](DEVELOPMENT.md#🧪-тестирование)
- [Код quality](DEVELOPMENT.md#✅-качество-кода)
- [Contribution](DEVELOPMENT.md#🤝-contribution-guidelines)

#### 👨‍🔧 DevOps/SRE
- [Production развертывание](DEPLOYMENT.md#🐳-docker-развертывание)
- [Мониторинг](DEPLOYMENT.md#📊-мониторинг-и-метрики)
- [Масштабирование](DEPLOYMENT.md#📈-масштабирование)
- [Безопасность](DEPLOYMENT.md#🔒-безопасность)
- [Резервное копирование](DEPLOYMENT.md#🛠️-резервное-копирование)

#### 🏗️ Архитектор
- [Гексагональная архитектура](ARCHITECTURE.md#🏛️-гексагональная-архитектура-ports--adapters)
- [Pipeline обработки](ARCHITECTURE.md#🔄-pipeline-обработки-файлов)
- [Domain слой](ARCHITECTURE.md#🎯-domain-слой)
- [Ports & Adapters](ARCHITECTURE.md#🔌-ports-и-adapters)

### 📋 По темам

#### 🐳 Docker & контейнеризация
- [Docker установка](../README.md#🐳-docker)
- [Production Docker](DEPLOYMENT.md#🐳-docker-развертывание)
- [Docker Swarm](DEPLOYMENT.md#🐳-docker-swarm)
- [Kubernetes](DEPLOYMENT.md#☸️-kubernetes)

#### 🔄 Асинхронная обработка
- [Async режим](../README.md#🔄-асинхронная-обработка)
- [Messenger](ARCHITECTURE.md#🔄-pipeline-обработки-файлов)
- [Воркеры](USER_GUIDE.md#🔧-ручное-управление-воркерами)
- [Очереди](ASYNC_PROCESSING.md)

#### ☁️ Google Photos
- [Быстрая настройка](../README.md#️-google-photos-интеграция)
- [Полная интеграция](GOOGLE_PHOTOS_API.md)
- [OAuth авторизация](GOOGLE_PHOTOS_API.md#🔐-oauth-20-авторизация)
- [Resumable upload](GOOGLE_PHOTOS_API.md#🔄-resumable-upload)
- [Batch processing](GOOGLE_PHOTOS_API.md#📦-batch-processing)

#### 📊 Мониторинг & метрики
- [Статистика обработки](USER_GUIDE.md#📊-мониторинг-и-статистика)
- [Production monitoring](DEPLOYMENT.md#📊-мониторинг-и-метрики)
- [Prometheus метрики](DEPLOYMENT.md#📈-prometheus-метрики)
- [Логирование](DEPLOYMENT.md#🔄-логирование)
- [Alerting](DEPLOYMENT.md#🚨-alerting-правила)

#### 🧪 Тестирование & качество
- [Запуск тестов](DEVELOPMENT.md#🧪-тестирование)
- [Code quality](DEVELOPMENT.md#✅-качество-кода)
- [PHPStan](DEVELOPMENT.md#📋-phpstan---статический-анализ)
- [Psalm](DEVELOPMENT.md#📋-psalm---дополнительный-статический-анализ)

### 🔗 По компонентам

#### Domain слой
- [MediaAsset](ARCHITECTURE.md#mediaasset---агрегатный-корень)
- [Value Objects](ARCHITECTURE.md#value-objects)
- [Domain Events](ARCHITECTURE.md#domain-events)
- [Policies](ARCHITECTURE.md#policies-политики)

#### Application слой
- [CQRS Commands](ARCHITECTURE.md#📋-cqrs-commands)
- [Handlers](ARCHITECTURE.md#🔄-handlers)
- [Use Cases](ARCHITECTURE.md#📱-application-слой)

#### Infrastructure слой
- [Adapters](ARCHITECTURE.md#🛠️-adapters-реализации)
- [Configuration](DEVELOPMENT.md#⚙️-configuration)
- [Messenger](DEVELOPMENT.md#🔄-messenger-очереди)

## 📖 Как читать документацию

### 🎯 По уровням погружения

#### 📋 Уровень 1: Обзор (10 минут)
- [README.md](../README.md) - понять возможности и быструю установку
- [Быстрый старт](../README.md#🚀-быстрый-старт) - запустить и попробовать

#### 📖 Уровень 2: Использование (30 минут)
- [Руководство пользователя](USER_GUIDE.md) - полное использование
- [Установка и настройка](USER_GUIDE.md#⚙️-базовая-настройка)
- [Google Photos интеграция](GOOGLE_PHOTOS_API.md)

#### 🔧 Уровень 3: Разработка (1-2 часа)
- [Архитектура](ARCHITECTURE.md) - понять дизайн
- [Разработка](DEVELOPMENT.md) - настройка среды
- [Тестирование](DEVELOPMENT.md#🧪-тестирование)

#### 🚀 Уровень 4: Production (2+ часа)
- [Развертывание](DEPLOYMENT.md) - production setup
- [Мониторинг](DEPLOYMENT.md#📊-мониторинг-и-метрики)
- [Масштабирование](DEPLOYMENT.md#📈-масштабирование)

### 📚 Рекомендуемые пути чтения

#### Хочу быстро запустить
1. [README](../README.md) → [Быстрый старт](../README.md#🚀-быстрый-старт)
2. [Docker установка](../README.md#🐳-docker)
3. [Базовое использование](../README.md#📖-использование)

#### Хочу понять архитектуру
1. [Обзор архитектуры](ARCHITECTURE.md#🎯-обзор-архитектуры)
2. [Гексагональная архитектура](ARCHITECTURE.md#🏛️-гексагональная-архитектура-ports--adapters)
3. [Pipeline обработки](ARCHITECTURE.md#🔄-pipeline-обработки-файлов)

#### Хочу внести вклад
1. [Разработка](DEVELOPMENT.md) → [Быстрый старт разработки](DEVELOPMENT.md#🚀-быстрый-старт-разработки)
2. [Архитектура](ARCHITECTURE.md) → [Domain слой](ARCHITECTURE.md#🎯-domain-слой)
3. [Тестирование](DEVELOPMENT.md#🧪-тестирование) → [Contribution](DEVELOPMENT.md#🤝-contribution-guidelines)

#### Хочу развернуть в production
1. [Развертывание](DEPLOYMENT.md) → [Docker развертывание](DEPLOYMENT.md#🐳-docker-развертывание)
2. [Production конфигурация](DEPLOYMENT.md#⚙️-конфигурация-production)
3. [Мониторинг](DEPLOYMENT.md#📊-мониторинг-и-метрики)

## 🔍 Поиск информации

### 📑 Алфавитный индекс

#### A
- [Adapters](ARCHITECTURE.md#🛠️-adapters-реализации)
- [Application слой](ARCHITECTURE.md#📱-application-слой)
- [Architecture](ARCHITECTURE.md)
- [Async processing](ASYNC_PROCESSING.md)

#### B
- [Batch processing](GOOGLE_PHOTOS_API.md#📦-batch-processing)
- [Build commands](DEVELOPMENT.md#📋-makefile-команды)

#### C
- [Commands (CQRS)](ARCHITECTURE.md#📋-cqrs-commands)
- [Configuration](USER_GUIDE.md#⚙️-базовая-настройка)
- [Contribution](DEVELOPMENT.md#🤝-contribution-guidelines)

#### D
- [Deployment](DEPLOYMENT.md)
- [Development](DEVELOPMENT.md)
- [Docker](USER_GUIDE.md#🐳-docker-команды)
- [Domain events](ARCHITECTURE.md#🎯-domain-events)
- [Domain слой](ARCHITECTURE.md#🎯-domain-слой)

#### E
- [Environment variables](USER_GUIDE.md#⚙️-переменные-окружения)

#### F
- [File categories](USER_GUIDE.md#📁-категории-файлов)
- [File organization](USER_GUIDE.md#📁-форматы-организации)

#### G
- [Google Photos API](GOOGLE_PHOTOS_API.md)
- [Google Photos integration](USER_GUIDE.md#☁️-google-photos-интеграция)

#### H
- [Handlers](ARCHITECTURE.md#🔄-handlers)
- [Health checks](DEPLOYMENT.md#📊-метрики-endpoints)
- [Hexagonal architecture](ARCHITECTURE.md#🏛️-гексагональная-архитектура-ports--adapters)

#### I
- [Infrastructure слой](ARCHITECTURE.md#🏗️-infrastructure-слой)
- [Installation](USER_GUIDE.md#🐳-установка-с-docker)

#### L
- [Logging](DEPLOYMENT.md#🔄-логирование)

#### M
- [Makefile commands](DEVELOPMENT.md#📋-makefile-команды)
- [MediaAsset](ARCHITECTURE.md#mediaasset---агрегатный-корень)
- [Messenger](ARCHITECTURE.md#🔄-pipeline-обработки-файлов)
- [Metrics](DEPLOYMENT.md#📈-prometheus-метрики)
- [Monitoring](DEPLOYMENT.md#📊-мониторинг-и-метрики)

#### O
- [OAuth](GOOGLE_PHOTOS_API.md#🔐-oauth-20-авторизация)
- [Organization policies](USER_GUIDE.md#📅-политики-организации)

#### P
- [Performance](USER_GUIDE.md#📈-производительность)
- [Pipeline](ARCHITECTURE.md#🔄-pipeline-обработки-файлов)
- [Policies](ARCHITECTURE.md#📋-policies-политики)
- [Ports](ARCHITECTURE.md#🔌-ports-интерфейсы)
- [Production](DEPLOYMENT.md)

#### Q
- [Quality assurance](DEVELOPMENT.md#✅-качество-кода)
- [Queue processing](USER_GUIDE.md#🔄-асинхронная-обработка)
- [Quick start](../README.md#🚀-быстрый-старт)

#### R
- [Resumable upload](GOOGLE_PHOTOS_API.md#🔄-resumable-upload)

#### S
- [Scaling](DEPLOYMENT.md#📈-масштабирование)
- [Security](DEPLOYMENT.md#🔒-безопасность)
- [Statistics](USER_GUIDE.md#📊-мониторинг-и-статистика)

#### T
- [Testing](DEVELOPMENT.md#🧪-тестирование)
- [Troubleshooting](USER_GUIDE.md#🐛-решение-проблем)

#### U
- [Usage](USER_GUIDE.md)
- [User guide](USER_GUIDE.md)

#### V
- [Value Objects](ARCHITECTURE.md#value-objects)

#### W
- [Workers](USER_GUIDE.md#🔧-ручное-управление-воркерами)

## 📞 Поддержка

### 🐛 Сообщение об ошибке
При создании issue указывайте:
- Версию приложения
- Используемую ОС и Docker
- Полные логи ошибки
- Шаги для воспроизведения

### 💬 Обсуждение
- **GitHub Discussions** - вопросы и обсуждения
- **GitHub Issues** - баг-репорты и feature requests

### 🤝 Вклад в проект
См. [Contribution guidelines](DEVELOPMENT.md#🤝-contribution-guidelines)

## 📈 Статус документации

| Документ | Статус | Последнее обновление |
|----------|--------|---------------------|
| [README](../README.md) | ✅ Полностью | Текущая версия |
| [Руководство пользователя](USER_GUIDE.md) | ✅ Полностью | Текущая версия |
| [Архитектура](ARCHITECTURE.md) | ✅ Полностью | Текущая версия |
| [Разработка](DEVELOPMENT.md) | ✅ Полностью | Текущая версия |
| [Развертывание](DEPLOYMENT.md) | ✅ Полностью | Текущая версия |
| [Google Photos API](GOOGLE_PHOTOS_API.md) | ✅ Полностью | Текущая версия |
| [Асинхронная обработка](ASYNC_PROCESSING.md) | ✅ Полностью | Ссылки на новую документацию |
| [Мониторинг](MONITORING.md) | ✅ Полностью | Текущая версия |
| [Статус проекта](PROJECT_STATUS.md) | ✅ Полностью | Ссылки на новую документацию |

**Легенда:**
- ✅ Полностью - полная актуальная документация
- 📝 Частично - базовая документация, нуждается в расширении
- ❌ Отсутствует - документация не создана

---

*Последнее обновление документации: $(date)*
