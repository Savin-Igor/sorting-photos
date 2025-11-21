# Настройка systemd для автоматического перезапуска

Этот файл содержит инструкции по настройке автоматического перезапуска команды `google-photos:upload` через 10 минут после падения.

## Установка сервиса

1. Скопируйте файл сервиса в системную директорию:
```bash
sudo cp google-photos-upload.service /etc/systemd/system/
```

2. Если нужно запускать от конкретного пользователя, отредактируйте файл:
```bash
sudo nano /etc/systemd/system/google-photos-upload.service
```
Раскомментируйте строку `User=isavins` и укажите нужное имя пользователя.

3. Перезагрузите конфигурацию systemd:
```bash
sudo systemctl daemon-reload
```

4. Включите автозапуск сервиса (опционально):
```bash
sudo systemctl enable google-photos-upload.service
```

5. Запустите сервис:
```bash
sudo systemctl start google-photos-upload.service
```

## Управление сервисом

- Проверить статус:
```bash
sudo systemctl status google-photos-upload.service
```

- Остановить:
```bash
sudo systemctl stop google-photos-upload.service
```

- Перезапустить:
```bash
sudo systemctl restart google-photos-upload.service
```

- Просмотреть логи:
```bash
sudo journalctl -u google-photos-upload.service -f
```

## Настройки перезапуска

В файле сервиса настроено:
- `Restart=on-failure` - перезапуск только при ошибках (не при успешном завершении)
- `RestartSec=600` - задержка 10 минут (600 секунд) перед перезапуском

Если нужно перезапускать всегда (даже при успешном завершении), измените на:
```
Restart=always
```



