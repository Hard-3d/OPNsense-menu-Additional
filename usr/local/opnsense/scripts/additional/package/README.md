# OPNsense Additional menu v0.1.56

Обновление агента для OPNsense Central Controller v0.1.6.

## Что изменено

- WireGuard status собирается автоматически при heartbeat/Run once.
- Добавлено определение факта настройки WireGuard даже без peers.
- В payload WireGuard добавлены поля `configured`, `interfaces`, `interface_count`, `config_summary`, `collection_mode`.
- Отдельный job `wireguard.status` больше не нужен для обычного отображения, но сохранён для совместимости.

## Установка

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.56-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.56-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```

После установки:

```text
Дополнительно -> Controller connect -> Run once
```
