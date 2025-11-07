# Как получить authorization code из URL

## Пошаговая инструкция

### Шаг 1: Запустите авторизацию
```bash
make google-photos-authorize
```

Команда покажет URL вида:
```
https://accounts.google.com/o/oauth2/v2/auth?response_type=code&access_type=offline&client_id=...&redirect_uri=http%3A%2F%2Flocalhost%3A8080%2Foauth%2Fcallback&...
```

### Шаг 2: Откройте URL в браузере
Скопируйте весь URL и откройте его в браузере.

### Шаг 3: Войдите и предоставьте разрешения
- Войдите в свой Google аккаунт
- Просмотрите запрашиваемые разрешения
- Нажмите "Разрешить" / "Allow"

### Шаг 4: Получите код из redirect URL
После авторизации Google перенаправит вас на redirect URI. URL будет выглядеть так:

```
http://localhost:8080/oauth/callback?code=4/0AeanS1234567890abcdefghijklmnopqrstuvwxyz&scope=https://www.googleapis.com/auth/photoslibrary.appendonly&state=6c9f2ca80467ccbb99212b3b4cc8b782
```

**Код находится в параметре `code`** после знака `?` или `&`.

В примере выше код: `4/0AeanS1234567890abcdefghijklmnopqrstuvwxyz`

### Шаг 5: Скопируйте код
Скопируйте значение параметра `code` из URL (всё что между `code=` и следующим `&`).

### Шаг 6: Завершите авторизацию
```bash
make google-photos-authorize CODE=4/0AeanS1234567890abcdefghijklmnopqrstuvwxyz
```

Замените `4/0AeanS1234567890abcdefghijklmnopqrstuvwxyz` на ваш реальный код.

## Важные замечания

⚠️ **Код действителен только несколько минут** - используйте его сразу после получения.

⚠️ **Redirect URI должен совпадать** - убедитесь, что в Google Cloud Console указан точно такой же redirect URI:
- `http://localhost:8080/oauth/callback`

⚠️ **Если видите ошибку "redirect_uri_mismatch"**:
- Проверьте redirect URI в Google Cloud Console
- Он должен точно совпадать (включая http/https, порт, путь)

## Альтернативный способ (если redirect не работает)

Если redirect на `localhost:8080` не работает, можно:

1. Использовать другой redirect URI (например, `urn:ietf:wg:oauth:2.0:oob`)
2. Или настроить локальный веб-сервер на порту 8080
3. Или использовать Google OAuth Playground для получения токенов

Но самый простой способ - настроить правильный redirect URI в Google Cloud Console.

