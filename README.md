# OPNsense Additional menu v0.1.51

Версия меню для работы с OPNsense Central Controller.

## Новое в v0.1.51

- Агент теперь умеет выполнять задание `alias.apply`.
- `alias.apply` создаёт или обновляет Firewall Alias в `/conf/config.xml`.
- Поддержаны типы:
  - `urljson` — URL Table in JSON format.
  - `urltable` — URL Table.
- Перед изменением создаётся backup:
  - `/conf/config.xml.controller_alias_YYYYmmdd_HHMMSS.bak`
- После изменения выполняется обновление firewall aliases через `configctl filter refresh_aliases` с fallback на `update_tables.py`.

## Подключение к центральному серверу

Меню:

```text
Дополнительно -> Controller connect
```

Заполнить:

```text
Central server URL
Device UUID
Registration token
```

После регистрации включить задачу:

```text
Дополнительно -> Scheduler -> Controller agent
```

Интервал: 1 минута.

## Важно

Центральный сервер для alias.apply должен быть версии `v0.1.1` или новее.
