# 🔧 Настройка Google Photos API - Пошаговая Инструкция

## Вариант 1: У вас УЖЕ ЕСТЬ credentials.json

Если вы уже скачали credentials.json из Google Cloud Console:

```bash
# 1. Создайте директорию для credentials
mkdir -p ~/.config/google-photos

# 2. Скопируйте файл в эту директорию (замените путь на ваш)
cp ~/Downloads/credentials.json ~/.config/google-photos/

# 3. Проверьте, что файл на месте
ls -la ~/.config/google-photos/credentials.json

# 4. Добавьте переменную в .env (откройте файл в редакторе или выполните команду)
echo "GOOGLE_PHOTOS_CREDENTIALS_DIR_HOST=/home/isavins/.config/google-photos" >> .env

# 5. Перезапустите контейнеры
make down && make up

# 6. Запустите авторизацию
make google-photos-authorize
```

## Вариант 2: У вас НЕТ credentials.json (нужно получить)

### Шаг 2.1: Создайте проект в Google Cloud Console

1. Откройте: https://console.cloud.google.com/
2. Создайте новый проект или выберите существующий
3. В навигации слева: **APIs & Services > Library**
4. Найдите "Google Photos Library API"
5. Нажмите **Enable** (Включить)

### Шаг 2.2: Создайте OAuth 2.0 Client ID

1. Перейдите в: **APIs & Services > Credentials**
2. Нажмите **+ CREATE CREDENTIALS** > **OAuth client ID**
3. Если появится запрос настроить OAuth consent screen:
   - User Type: **External**
   - App name: укажите любое имя (например, "Photo Sorter")
   - User support email: ваш email
   - Developer contact: ваш email
   - Нажмите **Save and Continue**
   - Scopes: пропустите (нажмите **Save and Continue**)
   - Test users: добавьте свой Google аккаунт
   - Нажмите **Save and Continue**
4. Вернитесь к созданию OAuth client ID:
   - Application type: **Desktop app** или **Web application**
   - Name: укажите любое имя (например, "Photo Sorter Client")
   - Если Web application, добавьте Authorized redirect URIs:
     * http://localhost:8080/oauth/callback
   - Нажмите **CREATE**
5. В появившемся окне нажмите **DOWNLOAD JSON**
6. Сохраните файл (он будет называться примерно `client_secret_xxx.json`)

### Шаг 2.3: Переименуйте и настройте

```bash
# 1. Создайте директорию
mkdir -p ~/.config/google-photos

# 2. Переименуйте скачанный файл в credentials.json и скопируйте
cp ~/Downloads/client_secret_*.json ~/.config/google-photos/credentials.json

# 3. Проверьте содержимое (должен быть валидный JSON)
cat ~/.config/google-photos/credentials.json | python3 -m json.tool | head -20

# 4. Добавьте переменную в .env
cd ~/Desktop/sorting-photos
echo "GOOGLE_PHOTOS_CREDENTIALS_DIR_HOST=/home/isavins/.config/google-photos" >> .env

# 5. Перезапустите контейнеры
make down && make up

# 6. Запустите авторизацию
make google-photos-authorize
```

## Проверка конфигурации

После настройки проверьте:

```bash
# Переменная в .env
grep GOOGLE_PHOTOS_CREDENTIALS_DIR_HOST .env

# Файл существует
ls -la ~/.config/google-photos/credentials.json

# Контейнер может прочитать файл
make google-photos-authorize
```

