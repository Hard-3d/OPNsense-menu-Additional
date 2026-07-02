# OPNsense Additional menu v0.1.54

Обновление агента для OPNsense Central Controller v0.1.4.

## Новые job-типы

- `firewall.rule_template.apply`
- `dns.override.apply`

Существующие job-типы сохранены:

- `status.collect`
- `system.info`
- `config.backup`
- `config.restore`
- `wireguard.status`
- `wireguard.peer.set_enabled`
- `alias.apply`
- `ping`

## Безопасность

Перед изменением `/conf/config.xml` агент делает локальный backup:

```text
/conf/config.xml.controller_firewall_YYYYmmdd_HHMMSS.bak
/conf/config.xml.controller_dns_YYYYmmdd_HHMMSS.bak
```

## Установка

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.54-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.54-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```
