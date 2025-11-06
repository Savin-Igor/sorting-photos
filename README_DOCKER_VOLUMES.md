# Использование произвольных директорий с Docker

Приложение поддерживает использование любых директорий на хосте в качестве источника и назначения файлов.

## Настройка через переменные окружения

### Способ 1: Через .env файл

Создайте файл `.env` в корне проекта (скопируйте из `.env.example`) и установите:

```bash
# Путь на хосте для исходной директории
SOURCE_DIRECTORY_HOST=/home/user/Pictures

# Путь на хосте для директории назначения
DESTINATION_DIRECTORY_HOST=/mnt/external/sorted
```

### Способ 2: Через переменные окружения при запуске

```bash
export SOURCE_DIRECTORY_HOST="/home/user/Pictures"
export DESTINATION_DIRECTORY_HOST="/mnt/external/sorted"
make up
make run
```

### Способ 3: Прямо в команде

```bash
SOURCE_DIRECTORY_HOST="/home/user/Pictures" \
DESTINATION_DIRECTORY_HOST="/mnt/external/sorted" \
make run
```

## Примеры использования

### USB флешка
```bash
SOURCE_DIRECTORY_HOST="/media/usb/photos" \
DESTINATION_DIRECTORY_HOST="/media/usb/sorted" \
make run
```

### Сетевой диск (NFS/SMB)
```bash
SOURCE_DIRECTORY_HOST="/mnt/network/photos" \
DESTINATION_DIRECTORY_HOST="/mnt/network/sorted" \
make run
```

### Внешний жесткий диск
```bash
SOURCE_DIRECTORY_HOST="/mnt/external/photos" \
DESTINATION_DIRECTORY_HOST="/mnt/external/sorted" \
make run
```

## Важно

- `SOURCE_DIRECTORY_HOST` и `DESTINATION_DIRECTORY_HOST` - это пути **на хосте**
- `SOURCE_DIRECTORY` и `DESTINATION_DIRECTORY` - это пути **внутри контейнера** (обычно `/var/data/source` и `/var/data/destination`)
- Директории должны существовать на хосте перед запуском
- Права доступа должны позволять чтение для source и запись для destination
