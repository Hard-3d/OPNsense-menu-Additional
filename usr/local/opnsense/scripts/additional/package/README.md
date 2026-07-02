# OPNsense Additional menu v0.1.60

## Изменения

- Агент v0.1.60.
- Улучшено сопоставление WireGuard instance с runtime interface `wg0/wg1/...`.
- В статус добавляется поле `wg_interface`.
- Убрана техническая строка `wg_dump_listen_port`.
- Агент принимает `job_uuid` от центрального сервера и отправляет его обратно в result.
- Сохранена совместимость со старым numeric `job_id`.

## Обновление

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.60-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.60-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```

После обновления нажать:

```text
Дополнительно -> Controller connect -> Run once
```
