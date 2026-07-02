# OPNsense Additional menu v0.1.63

Техническое обновление агента для совместимости с Central Controller v0.1.14.

- Агент v0.1.63.
- Совместимость с `job_uuid` сохранена.
- Функциональных изменений в OPNsense-части нет.

## Установка

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.63-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.63-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```
