# OPNsense Additional Menu v0.1.53

Версия для работы с OPNsense Central Controller v0.1.3.

Добавлено в агент Controller connect:

- расширенный `status.collect` / `system.info`;
- `config.restore` для rollback config.xml;
- локальный backup перед rollback;
- центральный backup текущей конфигурации перед rollback;
- `wireguard.status`;
- `wireguard.peer.set_enabled`;
- возврат статуса WireGuard peers в центральную панель.

Установка:

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.53-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.53-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```

После обновления проверь:

- Дополнительно -> Controller connect -> Run once.
- В центральной панели создай задания `system.info`, `wireguard.status`, `config.backup`.
