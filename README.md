# OPNsense Additional menu v0.1.55

Обновление агента для OPNsense Central Controller v0.1.5.

## Что исправлено

- Агент передаёт массив `wan_interfaces`, а не только один `wan_ip`.
- Поддерживается несколько WAN/uplink-портов.
- В heartbeat добавлен WireGuard status.
- Улучшен поиск WireGuard peer'ов в `config.xml`.
- Добавлен fallback на runtime-вывод `wg show all dump`, если peer'ы не найдены в XML.
- Для `wireguard.peer.set_enabled` поиск peer'а теперь использует тот же устойчивый парсер.

## Существующие job-типы

- `status.collect`
- `system.info`
- `config.backup`
- `config.restore`
- `wireguard.status`
- `wireguard.peer.set_enabled`
- `alias.apply`
- `firewall.rule_template.apply`
- `dns.override.apply`
- `ping`

## Установка

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.55-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.55-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```

После обновления открой:

```text
Дополнительно -> Controller connect
```

и нажми `Run once`, либо дождись выполнения Scheduler.
