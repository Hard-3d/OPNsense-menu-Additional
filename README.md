# OPNsense Additional menu v0.1.61

Техническое обновление агента для синхронизации версий с Central Controller v0.1.11.

- Агент v0.1.61.
- Логика подключения к центральному серверу сохранена.
- Совместимость с job_uuid и numeric job_id сохранена.

## Обновление

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.61-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.61-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```
