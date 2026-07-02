# OPNsense Additional menu v0.1.62

Техническое обновление агента для совместимости с Central Controller v0.1.13.

- Агент v0.1.62.
- Сохраняется поддержка `job_uuid` и старого `job_id` как fallback.
- Изменений в меню OPNsense и логике WireGuard не требуется.

## Обновление

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.62-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.62-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```
