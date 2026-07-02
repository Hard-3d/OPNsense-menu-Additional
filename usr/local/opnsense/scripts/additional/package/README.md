# OPNsense Additional menu v0.1.52

Дополнительное меню для OPNsense с модулем подключения к OPNsense Central Controller.

## Новое в v0.1.52

- Агент `controller-agent.php` теперь умеет применять aliases типов:
  - `urljson`;
  - `urltable`;
  - `host` — Host(s): IP / FQDN;
  - `network` — Network(s): IP / CIDR.
- Для `host` и `network` агент принимает прямой список значений в payload `content`.
- Перед изменением `/conf/config.xml` создаётся backup:

```text
/conf/config.xml.controller_alias_YYYYmmdd_HHMMSS.bak
```

Центральный сервер для полного функционала должен быть версии `v0.1.2` или новее.

## Установка

```sh
cd /
fetch -o /tmp/opnsense-additional-menu-v0.1.52-root.zip "URL_К_АРХИВУ"
unzip -o /tmp/opnsense-additional-menu-v0.1.52-root.zip -d /
chmod +x /install.sh
/install.sh
configctl webgui restart
```

## Подключение

```text
Дополнительно -> Controller connect
```

Указать:

```text
Central server URL
Device UUID
Registration token
```

После регистрации можно нажать `Run once`, затем включить задачу `Controller agent` в Additional Scheduler.
