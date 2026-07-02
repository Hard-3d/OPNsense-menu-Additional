# OPNsense Additional menu v0.1.57

## Изменения

- Агент отправляет свою версию в heartbeat/register.
- WireGuard: interfaces/instances отделены от peers.
- WireGuard: для peers рассчитывается статус соединения.
- Добавлен сбор текущих firewall rules из `/conf/config.xml`.
- Добавлен job `firewall.rules.collect`.

## Обновление

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.57-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.57-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```
